<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\StockAlert;
use App\Domain\Identity\Models\User;
use App\Support\BusinessTime;
use App\Support\DomainException;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * What a customer can do with their alerts: ask for one, see them all, cancel one; and what the owner can read: how many customers wait for a product.
 * Sending is {@see StockAlertNotifier}. Asking twice changes nothing (the alert keeps its date and its starting price); asking again after the alert fired
 * or expired starts it over.
 */
final class StockAlertService
{
    /** A back-in-stock alert that already fired is kept this many days (so a retry never tells twice), then cleaned up. */
    public const FIRED_KEEP_DAYS = 30;

    public function __construct(
        private readonly StockAlertSettings $settings,
        private readonly StockAlertPricing $pricing,
        private readonly CatalogPresenter $catalog,
    ) {}

    /**
     * @param  'back_in_stock'|'price_drop'  $type
     * @return array{alert: StockAlert, created: bool} created = false when the customer already had this alert (nothing was changed)
     */
    public function subscribe(User $user, ProductVariant $variant, string $type, ?Branch $branch): array
    {
        if (! $this->settings->enabled($type)) {
            throw DomainException::make('alerts_disabled');
        }
        $variant->loadMissing('product');
        if (! $variant->product->isActive() || ! $variant->isActive()) {
            throw DomainException::make('product_not_found', 404);
        }
        if ($type === StockAlert::BACK_IN_STOCK && ($this->catalog->stockMap([$variant->id], $branch?->id)[$variant->id] ?? 0) > 0) {
            throw DomainException::make('already_available'); // nothing to wait for: the button should have been "add to cart"
        }
        $existing = StockAlert::where(['user_id' => $user->id, 'product_variant_id' => $variant->id, 'type' => $type])->first();
        if ($existing !== null && $this->isActive($existing)) {
            return ['alert' => $existing, 'created' => false];
        }
        if ($existing === null && StockAlert::active()->where('user_id', $user->id)->count() >= StockAlertSettings::MAX_PER_CUSTOMER) {
            throw DomainException::make('too_many_alerts', 422, ['max' => StockAlertSettings::MAX_PER_CUSTOMER]);
        }

        $reference = $type === StockAlert::PRICE_DROP
            ? $this->pricing->forPairs(['now' => ['variant_id' => $variant->id, 'branch_id' => $branch?->id]])['now']['price']?->toDecimal()
            : null;
        $fields = ['product_id' => $variant->product_id, 'branch_id' => $branch?->id, 'reference_price' => $reference, 'notified_at' => null,
            'expires_at' => now()->addDays($this->settings->retentionDays())];
        try {
            if ($existing !== null) {
                $existing->forceFill($fields + ['created_at' => now()])->save(); // fired or expired: it starts over, with today's date and today's price
                $alert = $existing;
            } else {
                $alert = StockAlert::create($fields + ['user_id' => $user->id, 'product_variant_id' => $variant->id, 'type' => $type]);
            }
        } catch (UniqueConstraintViolationException) {
            // The same customer pressed twice at the same moment: the other request made it.
            return ['alert' => StockAlert::where(['user_id' => $user->id, 'product_variant_id' => $variant->id, 'type' => $type])->firstOrFail(), 'created' => false];
        }

        return ['alert' => $alert, 'created' => true];
    }

    /** Cancels one alert of this customer; true when it existed. Somebody else's alert is never touched (and not told apart from one that is gone). */
    public function cancel(User $user, string $alertId): bool
    {
        return StockAlert::where('user_id', $user->id)->whereKey($alertId)->delete() > 0;
    }

    /** Cancels the alert of this customer for a variant and type (the product page's toggle); true when it existed. @param  'back_in_stock'|'price_drop'  $type */
    public function cancelFor(User $user, string $variantId, string $type): bool
    {
        return StockAlert::where(['user_id' => $user->id, 'product_variant_id' => $variantId, 'type' => $type])->delete() > 0;
    }

    /** True while the alert still waits: not expired, and a back-in-stock alert not yet fired. */
    public function isActive(StockAlert $a): bool
    {
        return $a->expires_at->isFuture() && ($a->type === StockAlert::PRICE_DROP || $a->notified_at === null);
    }

    /**
     * The customer's active alerts, newest first, as the apps show them (at most {@see StockAlertSettings::MAX_PER_CUSTOMER}): the product, the variant,
     * the price they started from and what it costs now, and whether it can be bought.
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(User $user): array
    {
        $alerts = StockAlert::active()->where('user_id', $user->id)->with(['product.translations', 'product.images', 'variant'])
            ->orderByDesc('created_at')->orderByDesc('id')->limit(StockAlertSettings::MAX_PER_CUSTOMER)->get()
            ->filter(fn (StockAlert $a) => $a->product !== null && $a->variant !== null && $a->product->isActive())->values();
        $now = $this->pricing->forAlerts($alerts);

        return $alerts->map(fn (StockAlert $a) => $this->present($a, $now[$a->id] ?? ['price' => null, 'in_stock' => false]))->all();
    }

    /**
     * @param  array{price: ?Money, in_stock: bool}  $state
     * @return array<string, mixed>
     */
    public function present(StockAlert $a, array $state): array
    {
        $p = $a->product;
        $image = $p->images->firstWhere('product_variant_id', null) ?? $p->images->first();

        return [
            'id' => $a->id, 'type' => $a->type,
            'product' => ['id' => $p->id, 'slug' => $p->trans('slug'), 'name' => $p->trans('name'), 'image_url' => $image?->url(), 'thumb_url' => $image?->url(640)],
            'variant' => ['id' => $a->variant->id, 'name' => $a->variant->displayName()],
            'branch_id' => $a->branch_id,
            'reference_price' => $a->reference_price !== null ? Money::of($a->reference_price)->jsonSerialize() : null,
            'current_price' => $state['price']?->jsonSerialize(),
            'in_stock' => $state['in_stock'],
            'since' => BusinessTime::iso($a->created_at), 'expires_at' => BusinessTime::iso($a->expires_at),
        ];
    }

    /**
     * How many customers wait for a product: the demand signal on the product page of the dashboard. Each customer counts once per kind, however many
     * variants they asked about.
     *
     * @return array{back_in_stock: int, price_drop: int}
     */
    public function waiting(string $productId): array
    {
        $rows = StockAlert::active()->where('product_id', $productId)->selectRaw('type, count(distinct user_id) as customers')->groupBy('type')->pluck('customers', 'type');

        return ['back_in_stock' => (int) ($rows[StockAlert::BACK_IN_STOCK] ?? 0), 'price_drop' => (int) ($rows[StockAlert::PRICE_DROP] ?? 0)];
    }

    /** Daily clean-up: alerts past their expiry, and back-in-stock alerts that fired more than {@see self::FIRED_KEEP_DAYS} days ago. @return int rows removed */
    public function prune(): int
    {
        return DB::table('stock_alerts')->where(fn ($q) => $q->where('expires_at', '<=', now())
            ->orWhere(fn ($w) => $w->where('type', StockAlert::BACK_IN_STOCK)->where('notified_at', '<', now()->subDays(self::FIRED_KEEP_DAYS))))->delete();
    }
}
