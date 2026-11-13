<?php

namespace App\Providers;

use App\Domain\Auctions\Models\AuctionDeposit;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Notifications\Channels\HttpSmsSender;
use App\Domain\Notifications\Channels\NullSmsSender;
use App\Domain\Notifications\Contracts\SmsSender;
use App\Domain\Orders\Models\Order;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\RequirePermission;
use App\Support\BranchOf;
use App\Support\InvalidMoney;
use App\Support\Phone;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use RuntimeException;

use function Livewire\on;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Per-request permission memo; scoped so long-running workers never reuse stale grants.
        $this->app->scoped(AccessControl::class);

        $this->app->bind(SmsSender::class, fn () => match (config('services.sms.driver')) {
            'http' => new HttpSmsSender,
            default => new NullSmsSender,
        });

        $this->app->register(DomainServiceProvider::class);
    }

    public function boot(): void
    {
        $this->guardProductionSafety();

        // Livewire update requests only re-run its own short list of persistent middleware (auth). Without this, a staff member whose
        // permission was revoked, or whose account was suspended, after a page loaded could keep calling that page's actions.
        Livewire::addPersistentMiddleware([EnsureStaff::class, RequirePermission::class]);

        // An amount the money parser rejects (5 decimals, "1e3") or a number the column cannot hold used to surface as a raw 422 error
        // popup, because the API-style exception mapping does not know which Livewire field to flag. Tell the user in a toast instead.
        on('exception', function ($component, \Throwable $e, callable $stop): void {
            if ($e instanceof InvalidMoney || ($e instanceof QueryException && $e->getCode() === '22003')) {
                $component->dispatch('toast', type: 'error', message: __('errors.invalid_amount'));
                $stop();
            }
        });

        // N+1 protection and mass-assignment safety in dev/test. (Missing-attribute strictness is not enabled:
        // freshly created models legitimately omit nullable columns until refreshed.)
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        Model::automaticallyEagerLoadRelationships(false);
        JsonResource::wrap('data');

        // Domain models live in App\Domain\<Module>\Models; factories in Database\Factories.
        Factory::guessFactoryNamesUsing(fn (string $model) => 'Database\\Factories\\'.class_basename($model).'Factory');
        Factory::guessModelNamesUsing(function (Factory $factory) {
            $name = str_replace('Factory', '', class_basename($factory));
            foreach (glob(app_path('Domain/*/Models/'.$name.'.php')) ?: [] as $file) {
                return 'App\\Domain\\'.basename(dirname($file, 2)).'\\Models\\'.$name;
            }

            return 'App\\Models\\'.$name;
        });

        Relation::enforceMorphMap([
            'user' => User::class,
            'order' => Order::class,
            'auction_deposit' => AuctionDeposit::class,
        ]);

        // RBAC: every ability shaped "module.action" is resolved by AccessControl.
        // Argument may be a branch id string or a model exposing branch_id → branch-scoped check.
        Gate::before(function (?User $user, string $ability, array $arguments) {
            if (! str_contains($ability, '.')) {
                return null;
            }
            $target = $arguments[0] ?? null;
            $branchId = match (true) {
                is_string($target) => $target,
                $target instanceof Model => BranchOf::model($target),
                default => null,
            };

            return app(AccessControl::class)->allows($user, $ability, $branchId) ?: false;
        });

        $this->configureRateLimiting();

        // API docs (/docs/api): open locally, staff-only (docs viewers need settings.view) elsewhere.
        Gate::define('viewApiDocs', fn (?User $user) => $this->app->environment('local') || ($user && app(AccessControl::class)->allows($user, 'settings.view')));
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
        // The limiter runs before request validation, so "+974 5555 1234", "55551234" and "٥٥٥٥١٢٣٤" must all count against one
        // bucket, and a non-string value (phone[]=x) must not crash it.
        $identity = function (Request $r): string {
            $raw = $r->input('phone') ?? $r->input('email');

            return is_string($raw) ? (Phone::normalize($raw) ?? mb_strtolower(trim($raw))) : 'invalid';
        };
        RateLimiter::for('otp', fn (Request $r) => [
            Limit::perMinute(3)->by('otp-ip:'.$r->ip()),
            Limit::perHour(10)->by('otp-phone:'.$identity($r)),
        ]);
        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(5)->by('login:'.$identity($r).'|'.$r->ip()),
            Limit::perMinute(20)->by('login-ip:'.$r->ip()),
        ]);
        RateLimiter::for('password-reset', fn (Request $r) => Limit::perHour(5)->by('reset:'.$r->ip()));
        RateLimiter::for('checkout', fn (Request $r) => Limit::perMinute(10)->by('checkout:'.($r->user()?->id ?: $r->ip())));
        RateLimiter::for('bids', fn (Request $r) => Limit::perMinute((int) config('platform.auctions.bid_rate_limit_per_minute'))->by('bid:'.($r->user()?->id ?: $r->ip())));
        RateLimiter::for('webhooks', fn (Request $r) => Limit::perMinute(300)->by('webhook:'.$r->ip()));
        RateLimiter::for('exports', fn (Request $r) => Limit::perHour(30)->by('export:'.$r->user()?->id));
    }

    private function guardProductionSafety(): void
    {
        if (config('platform.otp.dev_echo') && ! $this->app->environment(['local', 'testing'])) {
            throw new RuntimeException('OTP_DEV_ECHO must never be enabled outside local/testing environments.');
        }
        if ($this->app->isProduction() && config('app.debug')) {
            throw new RuntimeException('APP_DEBUG must be false in production.');
        }
    }
}
