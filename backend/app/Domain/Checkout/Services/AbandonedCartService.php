<?php

namespace App\Domain\Checkout\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Contracts\ProductSearch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\CatalogPresenter;
use App\Domain\Checkout\Data\Quote;
use App\Domain\Checkout\Support\AbandonedCarts;
use App\Domain\Checkout\Support\CartVisibility;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Identity\Support\BranchScope;
use App\Domain\Marketing\Services\AbandonedCartReminders;
use App\Domain\Marketing\Services\CampaignService;
use App\Domain\Orders\Models\Cart;
use App\Domain\Orders\Models\CartItem;
use App\Domain\Pricing\Services\PriceResolver;
use App\Support\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The abandoned orders manager: customer carts (`status = active`, at least one line) that have been idle for MIN_IDLE minutes or more.
 * Staff can list them, open one, fix it, remind the customer, discard it or place the order for the customer.
 *
 * Rules, enforced here on every call (the Livewire page is only a convenience):
 *  - `orders.view` to see, `orders.create` to change anything; branch-restricted staff only reach carts in their scope (CartVisibility);
 *  - edits go through the very same CartService methods as the customer app (stock flags, per-line limit, variants, price rules), with
 *    `recordActivity: false`: a staff edit never resets the customer's idle clock, reminder counters or expiry;
 *  - every edit runs in one transaction that locks the cart row, re-checks the cart is still abandoned and (when the caller sends the stamp
 *    of what the staff member was looking at) that it did not change meanwhile; otherwise a plain "refresh" DomainException (HTTP 409 style);
 *  - everything is audited (`cart.staff_edited`, `cart.discarded`, `cart.restored`, `cart.reminder_sent`, and `cart.adopted` by StaffOrderService);
 *  - the customer is not told about staff edits.
 */
class AbandonedCartService
{
    /** Idle choices of the list: key => minutes ("idle for at least"). */
    public const IDLE = ['30m' => 30, '1h' => 60, '24h' => 1440, '7d' => 10080];

    /** A cart is abandoned once its customer has not touched it for this many minutes. */
    public const MIN_IDLE = AbandonedCarts::MIN_IDLE;

    public const PAGE_SIZE = 25;

    public function __construct(
        private readonly CartService $carts,
        private readonly QuoteService $quotes,
        private readonly StaffOrderService $staffOrders,
        private readonly CampaignService $campaigns,
        private readonly AbandonedCartReminders $reminders,
        private readonly CatalogPresenter $presenter,
        private readonly PriceResolver $prices,
        private readonly AccessControl $access,
        private readonly AuditLogger $audit,
    ) {}

    // ── Access ───────────────────────────────────────────────────────────────

    public function canView(?User $staff): bool
    {
        return $this->access->allows($staff, 'orders.view');
    }

    public function canChange(?User $staff): bool
    {
        return $this->access->allows($staff, 'orders.create');
    }

    private function scope(User $staff, string $permission): BranchScope
    {
        return $this->access->scope($staff, $permission);
    }

    private function fail(string $code, int $status = 409): never
    {
        throw new DomainException($code, __('abandoned_admin.err_'.$code), $status);
    }

    private function throttle(User $staff, string $bucket, int $perMinute): void
    {
        $key = 'abandoned:'.$bucket.':'.$staff->id;
        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            $this->fail('too_many_requests', 429);
        }
        RateLimiter::hit($key, 60);
    }

    // ── The list ─────────────────────────────────────────────────────────────

    /**
     * Abandoned carts the staff member may see, narrowed by the filters (idle, search, coupon, branch). Never widened: the branch filter can
     * only narrow the server-derived scope.
     *
     * @param  array{idle?: string, search?: string, coupon?: bool, branch?: string}  $filters
     * @return Builder<Cart>
     */
    public function filtered(User $staff, array $filters = []): Builder
    {
        $this->access->authorize($staff, 'orders.view');
        $minutes = self::IDLE[$filters['idle'] ?? '30m'] ?? self::MIN_IDLE;
        $scope = $this->scope($staff, 'orders.view')->narrow($filters['branch'] ?? null);
        $q = AbandonedCarts::query($scope, $minutes);

        if (! empty($filters['coupon'])) {
            $q->whereNotNull('carts.coupon_code');
        }
        $term = trim((string) ($filters['search'] ?? ''));
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $digits = preg_replace('/\D+/', '', $term) ?? '';
            $q->whereIn('carts.user_id', fn ($s) => $s->select('users.id')->from('users')->whereNull('users.deleted_at')
                ->where(fn ($w) => $w->where('users.name', 'ilike', $like)->when(strlen($digits) >= 3, fn ($x) => $x->orWhere('users.phone', 'like', '%'.$digits.'%'))));
        }

        return $q;
    }

    /**
     * One page of the list, newest idle first, keyset paginated (no OFFSET). `$after` is the cursor of the previous page's last row.
     *
     * @param  array{idle?: string, search?: string, coupon?: bool, branch?: string}  $filters
     * @return array{rows: Collection<int, Cart>, next: ?string}
     */
    public function page(User $staff, array $filters = [], string $after = ''): array
    {
        $q = $this->filtered($staff, $filters)->select('carts.*')
            ->with(['user:id,name,phone,locale,marketing_consent_at', 'branch:id,code,name_en,name_ar'])
            ->withSum('items as units', 'quantity')->withCount('items as lines')
            ->selectSub(DB::table('cart_items')->selectRaw('coalesce(sum(price_at_add * quantity), 0)')->whereColumn('cart_items.cart_id', 'carts.id'), 'value')
            ->orderByDesc('carts.last_activity_at')->orderByDesc('carts.id');
        if ($cursor = $this->decodeCursor($after)) {
            $q->whereRaw('(carts.last_activity_at, carts.id) < (?, ?)', $cursor);
        }
        $rows = $q->limit(self::PAGE_SIZE + 1)->get();
        $more = $rows->count() > self::PAGE_SIZE;
        $rows = $rows->take(self::PAGE_SIZE)->values();
        $last = $rows->last();

        return ['rows' => $rows, 'next' => $more && $last ? $this->encodeCursor($last) : null];
    }

    /**
     * The numbers above the list: abandoned carts now (idle for the minimum or more), what they hold at the prices the customers saw, and how many
     * were reminded already (automatic or by staff: both move the cart's counters). The orders a reminder won are counted from the reminder runs, not from
     * the carts (see AbandonedCartReminders::results): a customer's return resets the counters on the cart.
     *
     * @return array{carts: int, value: string, reminded: int, with_coupon: int}
     */
    public function stats(User $staff, string $branch = ''): array
    {
        // A join (not a sub-select per cart): the planner picks an index loop for a few carts and a hash join for many.
        $row = $this->filtered($staff, ['branch' => $branch])->toBase()->join('cart_items as i', 'i.cart_id', '=', 'carts.id')->selectRaw(
            'count(distinct carts.id) as carts, coalesce(sum(i.price_at_add * i.quantity), 0) as value, '
            .'count(distinct carts.id) filter (where carts.abandoned_notifications > 0) as reminded, count(distinct carts.id) filter (where carts.coupon_code is not null) as with_coupon'
        )->first();

        return ['carts' => (int) ($row->carts ?? 0), 'value' => number_format((float) ($row->value ?? 0), 2, '.', ''), 'reminded' => (int) ($row->reminded ?? 0),
            'with_coupon' => (int) ($row->with_coupon ?? 0)];
    }

    /** The small count on the menu item (see AbandonedCarts::badge). */
    public function badge(User $staff): int
    {
        return AbandonedCarts::badge($staff);
    }

    /** @return array{0: string, 1: string}|null */
    private function decodeCursor(string $cursor): ?array
    {
        if ($cursor === '') {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);
        if (! is_array($data) || count($data) !== 2 || ! is_string($data[0]) || ! is_string($data[1]) || ! Str::isUuid($data[1]) || strtotime($data[0]) === false) {
            return null;
        }

        return [$data[0], $data[1]];
    }

    private function encodeCursor(Cart $cart): string
    {
        return rtrim(strtr(base64_encode((string) json_encode([$cart->last_activity_at?->format('Y-m-d H:i:s.uP'), $cart->id])), '+/', '-_'), '=');
    }

    /**
     * How many reminders the customer got about this cart in its current idle period and when the last one went out. Automatic reminders and the ones
     * staff send both move the cart's own counters, and a customer's return resets them.
     *
     * @return array{sent: int, last: ?CarbonImmutable}
     */
    public static function reminderSummary(Cart $cart): array
    {
        return ['sent' => (int) $cart->abandoned_notifications, 'last' => $cart->abandoned_notified_at];
    }

    // ── One cart ─────────────────────────────────────────────────────────────

    /**
     * The cart for a side window: it must exist, be in the viewer's scope, still be an open customer cart and still be idle.
     *
     * @throws AuthorizationException when the viewer may not reach it
     * @throws DomainException when it is gone, closed or the customer is shopping again
     */
    public function find(User $staff, string $cartId, string $permission = 'orders.view'): Cart
    {
        $this->access->authorize($staff, $permission);
        $cart = Str::isUuid($cartId) ? Cart::find($cartId) : null;
        $this->assertAbandoned($staff, $cart, $permission);

        return $cart;
    }

    /** @phpstan-assert Cart $cart */
    private function assertAbandoned(User $staff, ?Cart $cart, string $permission): void
    {
        if (! $cart || $cart->user_id === null) {
            $this->fail('cart_unavailable', 404);
        }
        if (! CartVisibility::allows($cart, $this->scope($staff, $permission))) {
            throw new AuthorizationException(__('errors.forbidden'));
        }
        if ($cart->status !== 'active') {
            $this->fail('cart_closed');
        }
        if (! $cart->last_activity_at || $cart->last_activity_at->gt(now()->subMinutes(self::MIN_IDLE))) {
            $this->fail('cart_active_again');
        }
    }

    /** Everything a side window shows: the customer, the lines with stock, the REAL quote, the reminder state and the stamp of this view. */
    public function present(User $staff, string $cartId): array
    {
        $cart = $this->find($staff, $cartId);
        $customer = User::withTrashed()->find($cart->user_id) ?? $this->fail('cart_unavailable', 404);
        $view = $this->carts->present($cart);
        $branch = $cart->branch_id ? Branch::active()->find($cart->branch_id) : null;
        $stock = $this->presenter->stockMap($view['items']->pluck('variant_id')->all(), $branch?->id);
        $lines = $view['items']->map(fn (array $l) => $l + ['stock' => max(0, $stock[$l['variant_id']] ?? 0)])->values();

        return [
            'cart' => $cart,
            'customer' => $customer,
            'branch' => $cart->branch_id ? Branch::find($cart->branch_id, ['id', 'code', 'name_en', 'name_ar']) : null,
            'address' => $cart->address_id ? $cart->address()->with('zone')->first() : null,
            'lines' => $lines,
            'subtotal' => $view['subtotal'],
            'units' => (int) $view['items_count'],
            'quote' => $this->quote($cart, $customer),
            'reminder' => $this->campaigns->reminderStatus($cart),
            'reminders' => self::reminderSummary($cart),
            'history' => $this->reminders->history($cart),
            'stamp' => $this->stamp($cart),
            'idle_since' => $cart->last_activity_at,
            'max_quantity' => (int) config('platform.checkout.max_quantity_per_line'),
        ];
    }

    /**
     * The quote of the order the team can place for this customer right now: the same engine and the same saved choices, nothing the
     * staff member can set. Deals are left out because "Place order for customer" makes a staff order, which never gets deal prices
     * (the same switch the staff New order panel uses), so the total shown here is the total that order will have.
     */
    public function quote(Cart $cart, User $customer): Quote
    {
        return $this->quotes->quote($customer, $cart, ['apply_deals' => false]);
    }

    /** A short fingerprint of what the cart holds (lines, quantities, coupon, branch, address, type): it changes when anyone changes the cart. */
    public function stamp(Cart $cart): string
    {
        $lines = CartItem::where('cart_id', $cart->id)->orderBy('id')->get(['id', 'product_variant_id', 'quantity'])
            ->map(fn (CartItem $i) => [$i->id, $i->product_variant_id, $i->quantity])->all();

        return substr(sha1((string) json_encode([$cart->status, $cart->coupon_code, $cart->branch_id, $cart->address_id, $cart->fulfillment_type, $lines])), 0, 20);
    }

    /** Variants a staff member can add to this cart: active products matching the term, with the price and stock at the cart's branch. */
    public function searchProducts(User $staff, string $cartId, string $term, int $limit = 8): array
    {
        $cart = $this->find($staff, $cartId, 'orders.create');
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }
        $branch = $cart->branch_id ? Branch::active()->find($cart->branch_id) : null;
        $q = Product::query()->active()->with(['translations', 'images' => fn ($i) => $i->whereNull('product_variant_id')->limit(1),
            'variants' => fn ($v) => $v->where('status', 'active')->orderByDesc('is_default')->with('attributeValues')]);
        app(ProductSearch::class)->apply($q, $term);
        $products = $q->limit($limit)->get();
        $variants = $products->flatMap->variants;
        $this->prices->prime($variants, $branch?->id);
        $stock = $this->presenter->stockMap($variants->pluck('id')->all(), $branch?->id);
        $inCart = CartItem::where('cart_id', $cart->id)->pluck('quantity', 'product_variant_id');

        return $products->map(function ($p) use ($branch, $stock, $inCart) {
            $rows = $p->variants->map(function (ProductVariant $v) use ($p, $branch, $stock, $inCart) {
                $v->setRelation('product', $p);
                $price = $this->prices->resolve($v, $branch);
                $available = max(0, $stock[$v->id] ?? 0);

                return ['id' => $v->id, 'name' => $v->displayName(), 'sku' => $v->sku, 'price' => $price?->final()->toDecimal(), 'stock' => $available,
                    'ok' => $price !== null && $available > 0, 'in_cart' => (int) ($inCart[$v->id] ?? 0)];
            })->values();

            return ['id' => $p->id, 'name' => $p->trans('name'), 'sku' => $p->sku, 'image' => $p->images->first()?->url(320), 'multi' => $rows->count() > 1, 'variants' => $rows->all()];
        })->all();
    }

    // ── Edits (orders.create) ────────────────────────────────────────────────

    /**
     * Set one line's quantity (0 removes it). Same CartService rules as the customer app.
     *
     * @return array{stamp: string, removed: ?array{variant_id: string, quantity: int}}
     */
    public function setQuantity(User $staff, string $cartId, string $itemId, int $quantity, ?string $stamp = null): array
    {
        $r = $this->edit($staff, $cartId, $stamp, $quantity <= 0 ? 'line_removed' : 'quantity', function (Cart $cart) use ($itemId, $quantity) {
            $item = Str::isUuid($itemId) ? CartItem::where('cart_id', $cart->id)->find($itemId) : null;
            if (! $item) {
                $this->fail('cart_changed'); // the line is gone: somebody changed the cart
            }
            $removed = $quantity <= 0 ? ['variant_id' => $item->product_variant_id, 'quantity' => (int) $item->quantity] : null;
            $this->carts->updateIn($cart, $item->id, $quantity, recordActivity: false);

            return $removed;
        });

        return ['stamp' => $r['stamp'], 'removed' => $r['result']];
    }

    public function removeItem(User $staff, string $cartId, string $itemId, ?string $stamp = null): array
    {
        return $this->setQuantity($staff, $cartId, $itemId, 0, $stamp);
    }

    /** Add a variant (or raise its quantity), refused like the customer app refuses it (unavailable, limit) and for a branch that does not sell it. */
    public function addItem(User $staff, string $cartId, string $variantId, int $quantity = 1, ?string $stamp = null): string
    {
        return $this->edit($staff, $cartId, $stamp, 'line_added', function (Cart $cart) use ($variantId, $quantity) {
            $variant = Str::isUuid($variantId) ? ProductVariant::with('product')->find($variantId) : null;
            if (! $variant) {
                $this->fail('item_unavailable', 404);
            }
            if (! $this->prices->availability($variant, null)->purchasable) {
                throw DomainException::make('item_unavailable_simple'); // the very refusal the customer app gives
            }
            $branch = $cart->branch_id ? Branch::active()->find($cart->branch_id) : null;
            if ($branch && $this->prices->resolve($variant, $branch) === null) {
                $this->fail('item_unavailable_branch', 422);
            }
            $this->carts->addTo($cart, $variant->id, $quantity, recordActivity: false);
        })['stamp'];
    }

    /**
     * Apply a coupon code with the real validation (a ValidationException on `coupon_code` carries the plain-language reason), or remove it (null).
     */
    public function setCoupon(User $staff, string $cartId, ?string $code, ?string $stamp = null): string
    {
        $code = $code === null ? null : trim($code);
        $code = $code === '' ? null : $code;

        return $this->edit($staff, $cartId, $stamp, $code ? 'coupon_applied' : 'coupon_removed', function (Cart $cart) use ($code) {
            $customer = User::find($cart->user_id);
            $this->carts->setOptionsOn($cart, $customer, ['coupon_code' => $code], recordActivity: false);
        })['stamp'];
    }

    /**
     * @param  callable(Cart): mixed  $change
     * @return array{stamp: string, result: mixed}
     */
    private function edit(User $staff, string $cartId, ?string $stamp, string $kind, callable $change): array
    {
        $this->access->authorize($staff, 'orders.create');
        $this->throttle($staff, 'edit', 240);

        return DB::transaction(function () use ($staff, $cartId, $stamp, $kind, $change) {
            $cart = $this->lock($staff, $cartId);
            if ($stamp !== null && ! hash_equals($stamp, $this->stamp($cart))) {
                $this->fail('cart_changed');
            }
            $before = $this->summary($cart);
            $result = $change($cart);
            $cart->refresh();
            $this->audit->log('cart.staff_edited', $cart, $before, $this->summary($cart), ['change' => $kind, 'customer_id' => $cart->user_id], actor: $staff);

            return ['stamp' => $this->stamp($cart), 'result' => $result];
        });
    }

    /** The cart row locked for this transaction and re-checked: still the customer's open cart, still idle, still in the staff member's scope. */
    private function lock(User $staff, string $cartId): Cart
    {
        $cart = Str::isUuid($cartId) ? Cart::whereKey($cartId)->lockForUpdate()->first() : null;
        $this->assertAbandoned($staff, $cart, 'orders.create');

        return $cart;
    }

    /** @return array{coupon: ?string, items: list<array{sku: ?string, qty: int}>} */
    private function summary(Cart $cart): array
    {
        return ['coupon' => $cart->coupon_code, 'items' => $cart->items()->with('variant:id,sku')->get()
            ->map(fn (CartItem $i) => ['sku' => $i->variant->sku, 'qty' => (int) $i->quantity])->values()->all()];
    }

    // ── Discard / restore / remind / place ───────────────────────────────────

    /** Stop treating the cart as open: `status = abandoned`, so no reminder is sent. Reversible with restore(). */
    public function discard(User $staff, string $cartId): void
    {
        $this->access->authorize($staff, 'orders.create');
        $this->throttle($staff, 'edit', 240);
        DB::transaction(function () use ($staff, $cartId) {
            $cart = $this->lock($staff, $cartId);
            $units = (int) $cart->items()->sum('quantity');
            $cart->update(['status' => 'abandoned']);
            $this->audit->log('cart.discarded', $cart, ['status' => 'active'], ['status' => 'abandoned'], ['units' => $units, 'customer_id' => $cart->user_id], actor: $staff);
        });
        AbandonedCarts::forgetBadge($staff);
    }

    /**
     * Undo of discard(): the cart is open again, with its idle time and reminder history as they were. When the customer already started a new cart
     * meanwhile: an empty one is replaced, one that holds items is never overwritten (plain message).
     */
    public function restore(User $staff, string $cartId): void
    {
        $this->access->authorize($staff, 'orders.create');
        $this->throttle($staff, 'edit', 240);
        DB::transaction(function () use ($staff, $cartId) {
            $cart = Str::isUuid($cartId) ? Cart::whereKey($cartId)->lockForUpdate()->first() : null;
            if (! $cart || $cart->user_id === null) {
                $this->fail('cart_unavailable', 404);
            }
            if (! CartVisibility::allows($cart, $this->scope($staff, 'orders.create'))) {
                throw new AuthorizationException(__('errors.forbidden'));
            }
            if ($cart->status !== 'abandoned') {
                $this->fail('cart_closed');
            }
            $fresh = Cart::where('user_id', $cart->user_id)->where('status', 'active')->lockForUpdate()->first();
            if ($fresh) {
                if ($fresh->items()->exists()) {
                    $this->fail('restore_blocked');
                }
                $fresh->delete();
            }
            $cart->update(['status' => 'active']);
            $this->audit->log('cart.restored', $cart, ['status' => 'abandoned'], ['status' => 'active'], ['customer_id' => $cart->user_id], actor: $staff);
        });
        AbandonedCarts::forgetBadge($staff);
    }

    /**
     * Send the customer the abandoned-cart reminder (consent and frequency limits are CampaignService's). Audited when it goes out.
     *
     * @return array{status: string, channels: list<string>, next_at: ?CarbonImmutable, limit: int}
     */
    public function remind(User $staff, string $cartId): array
    {
        $this->access->authorize($staff, 'orders.create');
        $this->throttle($staff, 'remind', 20);

        return DB::transaction(function () use ($staff, $cartId) {
            $cart = $this->lock($staff, $cartId);
            $result = $this->campaigns->remindCart($cart);
            if ($result['status'] === 'sent') {
                $this->audit->log('cart.reminder_sent', $cart, [], ['channels' => $result['channels'], 'reminders' => $cart->abandoned_notifications + 1],
                    ['customer_id' => $cart->user_id, 'source' => 'staff'], actor: $staff);
            }

            return $result;
        });
    }

    /** "Place order for customer": the staff draft made from this cart (see StaffOrderService::adoptCart); the same draft again if there is one. */
    public function placeForCustomer(User $staff, string $cartId): Cart
    {
        $cart = $this->find($staff, $cartId, 'orders.create');

        return $this->staffOrders->adoptCart($cart, $staff);
    }
}
