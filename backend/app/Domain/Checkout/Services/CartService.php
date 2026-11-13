<?php

namespace App\Domain\Checkout\Services;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\CatalogPresenter;
use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Services\PromotionEngine;
use App\Domain\Orders\Models\Cart;
use App\Domain\Orders\Models\CartItem;
use App\Domain\Pricing\Services\PriceResolver;
use App\Support\DomainException;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Persistent server-side cart. Prices shown here are recalculated on every read for the preferred branch;
 * `price_at_add` is kept only to warn the customer when a price changed — it is never charged.
 */
class CartService
{
    public function __construct(private readonly PriceResolver $prices, private readonly CatalogPresenter $presenter) {}

    public function current(User $user): Cart
    {
        $cart = Cart::where('user_id', $user->id)->where('status', 'active')->first();
        if ($cart) {
            return $cart;
        }
        try {
            return DB::transaction(fn () => Cart::create(['user_id' => $user->id, 'status' => 'active', 'last_activity_at' => now(), 'expires_at' => now()->addDays(config('platform.checkout.cart_ttl_days'))]));
        } catch (UniqueConstraintViolationException) {
            return Cart::where('user_id', $user->id)->where('status', 'active')->firstOrFail(); // concurrent first request
        }
    }

    public function add(User $user, string $variantId, int $quantity): Cart
    {
        return $this->addTo($this->current($user), $variantId, $quantity);
    }

    /** Add to a specific cart (the customer's active cart, or a staff draft). */
    public function addTo(Cart $cart, string $variantId, int $quantity): Cart
    {
        $max = (int) config('platform.checkout.max_quantity_per_line');
        if ($quantity < 1 || $quantity > $max) {
            throw DomainException::make('quantity_invalid');
        }
        $variant = ProductVariant::with('product')->findOrFail($variantId);
        if (! $this->prices->availability($variant, null)->purchasable) {
            throw DomainException::make('item_unavailable_simple');
        }

        DB::transaction(function () use ($cart, $variant, $quantity, $max) {
            $item = CartItem::where('cart_id', $cart->id)->where('product_variant_id', $variant->id)->lockForUpdate()->first();
            $branch = $cart->branch_id ? Branch::find($cart->branch_id) : null;
            $price = $this->prices->resolve($variant, $branch) ?? $this->prices->resolve($variant, null);
            if ($item) {
                $item->update(['quantity' => min($max, $item->quantity + $quantity)]);
            } else {
                CartItem::create(['cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'quantity' => $quantity, 'price_at_add' => $price?->final()->toDecimal()]);
            }
            $this->touch($cart);
        });

        return $cart->refresh();
    }

    public function update(User $user, string $itemId, int $quantity): Cart
    {
        return $this->updateIn($this->current($user), $itemId, $quantity);
    }

    public function updateIn(Cart $cart, string $itemId, int $quantity): Cart
    {
        $item = CartItem::where('cart_id', $cart->id)->findOrFail($itemId);
        if ($quantity <= 0) {
            $item->delete();
        } else {
            if ($quantity > (int) config('platform.checkout.max_quantity_per_line')) {
                throw DomainException::make('quantity_invalid');
            }
            $item->update(['quantity' => $quantity]);
        }
        $this->touch($cart);

        return $cart->refresh();
    }

    public function remove(User $user, string $itemId): Cart
    {
        return $this->update($user, $itemId, 0);
    }

    public function setOptions(User $user, array $o): Cart
    {
        return $this->setOptionsOn($this->current($user), $user, $o);
    }

    /** @param User|null $user the cart's customer (addresses and coupons are checked against them); null for a draft without one */
    public function setOptionsOn(Cart $cart, ?User $user, array $o): Cart
    {
        $data = [];
        if (array_key_exists('branch_id', $o)) {
            $data['branch_id'] = $o['branch_id'] && Branch::active()->whereKey($o['branch_id'])->exists() ? $o['branch_id'] : null;
        }
        if (isset($o['fulfillment_type'])) {
            $data['fulfillment_type'] = $o['fulfillment_type'];
        }
        if (array_key_exists('address_id', $o)) {
            $data['address_id'] = $o['address_id'] && $user?->addresses()->whereKey($o['address_id'])->exists() ? $o['address_id'] : null;
        }
        if (array_key_exists('coupon_code', $o)) {
            $data['coupon_code'] = $o['coupon_code'] ? strtoupper(trim($o['coupon_code'])) : null;
            if ($data['coupon_code'] && $user && ($error = app(PromotionEngine::class)->couponError($data['coupon_code'], $user))) {
                throw ValidationException::withMessages(['coupon_code' => [__('checkout.'.$error)]]);
            }
        }
        if (array_key_exists('loyalty_points', $o)) {
            $data['loyalty_points_to_redeem'] = max(0, (int) $o['loyalty_points']);
        }
        $cart->update($data + ['last_activity_at' => now()]);

        return $cart->refresh();
    }

    /** Merge a guest cart (identified by its opaque token) into the user's cart after sign-in. */
    public function mergeGuest(User $user, string $guestToken): Cart
    {
        $guest = Cart::where('guest_token_hash', hash('sha256', $guestToken))->where('status', 'active')->whereNull('user_id')->with('items')->first();
        $cart = $this->current($user);
        if (! $guest) {
            return $cart;
        }
        DB::transaction(function () use ($guest, $cart) {
            foreach ($guest->items as $gi) {
                $existing = CartItem::where('cart_id', $cart->id)->where('product_variant_id', $gi->product_variant_id)->first();
                $existing
                    ? $existing->update(['quantity' => min((int) config('platform.checkout.max_quantity_per_line'), max($existing->quantity, $gi->quantity))])
                    : CartItem::create(['cart_id' => $cart->id, 'product_variant_id' => $gi->product_variant_id, 'quantity' => $gi->quantity, 'price_at_add' => $gi->price_at_add]);
            }
            $guest->update(['status' => 'merged']);
            $this->touch($cart);
        });

        return $cart->refresh();
    }

    /** API representation with live prices/availability for the preferred branch and clear issues per line. */
    public function present(Cart $cart): array
    {
        $cart->load(['items.variant.product.translations', 'items.variant.product.brand', 'items.variant.product.images', 'items.variant.attributeValues', 'items.variant.product.variants']);
        $branch = $cart->branch_id ? Branch::active()->find($cart->branch_id) : null;
        $variants = $cart->items->pluck('variant');
        $this->prices->prime($variants, $branch?->id);
        $stock = $this->presenter->stockMap($variants->pluck('id')->all(), $branch?->id);
        $subtotal = Money::zero();
        $warnings = [];

        $items = $cart->items->sortBy('created_at')->map(function (CartItem $item) use ($branch, $stock, &$subtotal, &$warnings) {
            $v = $item->variant;
            $price = $this->prices->resolve($v, $branch);
            $available = max(0, $stock[$v->id] ?? 0);
            $issue = match (true) {
                ! $v->product->isActive() || ! $v->isActive() => 'inactive',
                $price === null => 'unavailable_in_branch',
                $available < $item->quantity => 'insufficient_stock',
                default => null,
            };
            $changed = $price && $item->price_at_add !== null && ! Money::of($item->price_at_add)->equals($price->final());
            if ($changed) {
                $warnings[] = __('checkout.price_changed', ['name' => $v->product->trans('name'), 'from' => Money::of($item->price_at_add)->format(), 'to' => $price->final()->format()]);
            }
            $line = $price ? $price->final()->times($item->quantity) : Money::zero();
            if ($issue === null) {
                $subtotal = $subtotal->plus($line);
            }

            return [
                'id' => $item->id,
                'variant_id' => $v->id,
                'product' => $this->presenter->card($v->product->setRelation('variants', collect([$v])), $branch, $stock),
                'variant_name' => $v->displayName(),
                'quantity' => $item->quantity,
                'unit_price' => $price?->toArray(),
                'line_total' => $line->jsonSerialize(),
                'available' => $issue === null,
                'available_quantity' => min(10, $available),
                'price_changed' => $changed,
                'previous_price' => $changed ? Money::of($item->price_at_add)->jsonSerialize() : null,
                'issue' => $issue,
            ];
        })->values();

        return [
            'id' => $cart->id,
            'branch_id' => $cart->branch_id,
            'fulfillment_type' => $cart->fulfillment_type,
            'address_id' => $cart->address_id,
            'coupon_code' => $cart->coupon_code,
            'loyalty_points' => $cart->loyalty_points_to_redeem,
            'items' => $items,
            'items_count' => $cart->items->sum('quantity'),
            'subtotal' => $subtotal->jsonSerialize(),
            'warnings' => $warnings,
        ];
    }

    private function touch(Cart $cart): void
    {
        $cart->update(['last_activity_at' => now(), 'abandoned_notified_at' => null, 'abandoned_notifications' => 0,
            'expires_at' => now()->addDays(config('platform.checkout.cart_ttl_days'))]);
    }
}
