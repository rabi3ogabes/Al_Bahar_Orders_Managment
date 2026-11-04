<?php

namespace App\Providers;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Notifications\Channels\HttpSmsSender;
use App\Domain\Notifications\Channels\NullSmsSender;
use App\Domain\Notifications\Contracts\SmsSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

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
            'order' => \App\Domain\Orders\Models\Order::class,
            'auction_deposit' => \App\Domain\Auctions\Models\AuctionDeposit::class,
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
                $target instanceof Model => \App\Support\BranchOf::model($target),
                default => null,
            };

            return app(AccessControl::class)->allows($user, $ability, $branchId) ?: false;
        });

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('otp', fn (Request $r) => [
            Limit::perMinute(3)->by('otp-ip:'.$r->ip()),
            Limit::perHour(10)->by('otp-phone:'.preg_replace('/\D/', '', (string) $r->input('phone'))),
        ]);
        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(5)->by('login:'.strtolower((string) ($r->input('phone') ?? $r->input('email'))).'|'.$r->ip()),
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
