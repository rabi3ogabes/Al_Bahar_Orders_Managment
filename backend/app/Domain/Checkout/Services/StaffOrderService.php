<?php

namespace App\Domain\Checkout\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\UpsellService;
use App\Domain\Checkout\Data\Quote;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Enums\UserType;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Identity\Support\BranchScope;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Notifications\Services\WhatsAppClient;
use App\Domain\Orders\Models\Cart;
use App\Domain\Orders\Models\CartItem;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\UserAddress;
use App\Domain\Orders\Services\OrderService;
use App\Domain\Payments\Services\PaymentService;
use App\Domain\Pricing\Services\PriceResolver;
use App\Rules\PhoneNumber;
use App\Support\DomainException;
use App\Support\Money;
use App\Support\Phone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Admin-assisted ordering. A staff member builds a "staff draft" cart on behalf of a customer — the customer's own
 * active cart is never touched — then quotes and places it through the very same QuoteService/CheckoutService the
 * customer app uses, so prices, stock reservation, fees, coupons and slots behave identically. Staff cannot set prices.
 *
 * Every public method re-checks `orders.create` (branch-scoped) and draft ownership server-side; the UI is a convenience.
 */
class StaffOrderService
{
    public const SESSION_KEY = 'staff_order_draft';

    public const MAX_DRAFTS = 20;

    /** Checkout choices kept in carts.staff_options (the cart itself holds branch, fulfilment type, address and coupon). */
    private const OPTION_KEYS = ['payment_method', 'delivery_slot_id', 'delivery_date', 'pickup_slot_id', 'pickup_date', 'express', 'notes', 'internal_note'];

    public function __construct(
        private readonly CartService $carts,
        private readonly QuoteService $quotes,
        private readonly CheckoutService $checkout,
        private readonly AccessControl $access,
        private readonly AuditLogger $audit,
        private readonly PaymentService $payments,
        private readonly NotificationService $notifications,
        private readonly OrderService $orders,
        private readonly PriceResolver $prices,
    ) {}

    // ── Access ───────────────────────────────────────────────────────────────

    public function canCreate(?User $staff): bool
    {
        return $this->access->allows($staff, 'orders.create');
    }

    public function scope(User $staff): BranchScope
    {
        return $this->access->scope($staff, 'orders.create');
    }

    /** Branches this staff member may take orders for. @return Collection<int, Branch> */
    public function branches(User $staff): Collection
    {
        $scope = $this->scope($staff);

        return Branch::active()->when(! $scope->all, fn ($q) => $q->whereIn('id', $scope->branchIds))->orderBy('sort_order')->get(['id', 'code', 'name_en', 'name_ar']);
    }

    private function authorize(User $staff, ?string $branchId = null): void
    {
        $this->access->authorize($staff, 'orders.create', $branchId);
    }

    private function fail(string $code, int $status = 422, array $replace = []): never
    {
        throw new DomainException($code, __('neworder_admin.err_'.$code, $replace), $status);
    }

    private function throttle(User $staff, string $bucket, int $perMinute): void
    {
        $key = 'staff-order:'.$bucket.':'.$staff->id;
        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            $this->fail('too_many_requests', 429);
        }
        RateLimiter::hit($key, 60);
    }

    // ── Drafts ───────────────────────────────────────────────────────────────

    /** Own drafts (a super admin sees everyone's), newest first. @return Collection<int, Cart> */
    public function drafts(User $staff): Collection
    {
        $this->authorize($staff);

        return Cart::query()->where('status', Cart::STAFF_DRAFT)->when(! $staff->isSuperAdmin(), fn ($q) => $q->where('staff_id', $staff->id))
            ->withSum('items as units', 'quantity')->with(['user:id,name,phone', 'branch:id,code,name_en,name_ar', 'staff:id,name'])
            ->orderByDesc('updated_at')->limit(50)->get();
    }

    /** A draft the staff member owns (or any draft for a super admin). */
    public function find(User $staff, string $id): Cart
    {
        $this->authorize($staff);
        $cart = Cart::where('status', Cart::STAFF_DRAFT)->findOrFail($id);
        if ($cart->staff_id !== $staff->id && ! $staff->isSuperAdmin()) {
            throw new AuthorizationException(__('errors.forbidden'));
        }
        if ($cart->branch_id) {
            $this->authorize($staff, $cart->branch_id);
        }

        return $cart;
    }

    /** The draft the staff member is working on: the one remembered in the session, else their latest. */
    public function current(User $staff): ?Cart
    {
        if (! $this->canCreate($staff)) {
            return null;
        }
        $id = session(self::SESSION_KEY);
        $cart = $id ? Cart::where('status', Cart::STAFF_DRAFT)->where('staff_id', $staff->id)->find($id) : null;
        $cart ??= Cart::where('status', Cart::STAFF_DRAFT)->where('staff_id', $staff->id)->orderByDesc('updated_at')->first();
        if ($cart && $cart->branch_id && ! $this->access->allows($staff, 'orders.create', $cart->branch_id)) {
            return null;
        }

        return $cart;
    }

    public function setCurrent(User $staff, Cart $draft): Cart
    {
        $draft = $this->find($staff, $draft->id);
        if ($draft->staff_id !== $staff->id) {
            throw new AuthorizationException(__('errors.forbidden')); // a super admin can inspect others' drafts but not work in them
        }
        session([self::SESSION_KEY => $draft->id]);

        return $draft;
    }

    /** Number of units in the current draft — one cheap query for the header badge. */
    public function count(User $staff): int
    {
        if (! $this->canCreate($staff)) {
            return 0;
        }
        $id = session(self::SESSION_KEY);
        $q = DB::table('carts')->where('status', Cart::STAFF_DRAFT)->where('staff_id', $staff->id);
        $cartId = ($id ? (clone $q)->where('id', $id)->value('id') : null) ?? $q->orderByDesc('updated_at')->value('id');

        return $cartId ? (int) CartItem::where('cart_id', $cartId)->sum('quantity') : 0;
    }

    public function create(User $staff, ?string $branchId = null, ?string $customerId = null): Cart
    {
        $this->authorize($staff);
        $this->throttle($staff, 'draft', 30);
        if (Cart::where('status', Cart::STAFF_DRAFT)->where('staff_id', $staff->id)->count() >= self::MAX_DRAFTS) {
            $this->fail('draft_limit', 422, ['max' => self::MAX_DRAFTS]);
        }
        $scope = $this->scope($staff);
        $branchId = $branchId ?: ($scope->all ? null : ($scope->branchIds[0] ?? null));
        if ($branchId !== null) {
            $this->assertBranch($staff, $branchId);
        }
        $customer = $customerId ? $this->customer($customerId) : null;

        $draft = Cart::create(['status' => Cart::STAFF_DRAFT, 'staff_id' => $staff->id, 'user_id' => $customer?->id, 'branch_id' => $branchId,
            'fulfillment_type' => 'delivery', 'last_activity_at' => now()]);
        session([self::SESSION_KEY => $draft->id]);

        return $draft;
    }

    /** The current draft, created on demand (first add / customer pick). */
    public function ensureCurrent(User $staff): Cart
    {
        return $this->current($staff) ?? $this->create($staff);
    }

    public function discard(User $staff, Cart $draft): void
    {
        $draft = $this->find($staff, $draft->id);
        $units = (int) $draft->items()->sum('quantity');
        $this->audit->log('order_draft.discarded', $draft, [], ['units' => $units, 'customer_id' => $draft->user_id], actor: $staff);
        $draft->delete(); // items cascade; a draft holds no stock reservation
        if (session(self::SESSION_KEY) === $draft->id) {
            session()->forget(self::SESSION_KEY);
        }
    }

    // ── Customer ─────────────────────────────────────────────────────────────

    public function setCustomer(User $staff, Cart $draft, ?string $customerId): Cart
    {
        $draft = $this->owned($staff, $draft);
        $customer = $customerId ? $this->customer($customerId) : null;
        if ($customer?->id !== $draft->user_id) {
            // Addresses, coupons and loyalty belong to a customer: start those choices again.
            $draft->update(['user_id' => $customer?->id, 'address_id' => null, 'coupon_code' => null, 'loyalty_points_to_redeem' => 0]);
        }

        return $this->touch($draft);
    }

    /** Live search by name or phone: id, name and masked phone only. @return list<array{id: string, name: string, phone: ?string, locale: string}> */
    public function searchCustomers(User $staff, string $term): array
    {
        $this->authorize($staff);
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }
        $this->throttle($staff, 'customer-search', 90);
        $like = '%'.addcslashes($term, '%_\\').'%';
        $digits = preg_replace('/\D+/', '', Phone::normalize($term) ?? $term) ?? '';

        return User::query()->where('type', UserType::Customer->value)->where('status', UserStatus::Active->value)
            ->where(fn ($w) => $w->where('name', 'ilike', $like)->when(strlen($digits) >= 3, fn ($x) => $x->orWhere('phone', 'like', '%'.$digits.'%')))
            ->orderBy('name')->limit(8)->get(['id', 'name', 'phone', 'locale'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'phone' => Phone::mask($u->phone), 'locale' => $u->locale])->all();
    }

    /**
     * Quick-create a walk-in / phone customer. Never duplicates: an existing account with the same normalised phone is
     * reused. Marketing consent stays off.
     *
     * @param  array{name?: string, phone?: string, locale?: string}  $data
     * @return array{user: User, existed: bool}
     */
    public function createCustomer(User $staff, array $data): array
    {
        $this->authorize($staff);
        $this->throttle($staff, 'customer-create', 10);
        $v = Validator::make($data, ['name' => 'required|string|min:2|max:120', 'phone' => ['required', 'string', new PhoneNumber], 'locale' => 'required|in:en,ar'])->validate();
        $phone = Phone::normalize($v['phone']);
        $existing = User::withTrashed()->where('phone', $phone)->first();
        if ($existing) {
            if ($existing->trashed() || ! $existing->isCustomer() || ! $existing->isActive()) {
                throw ValidationException::withMessages(['phone' => [__('neworder_admin.err_phone_unusable')]]);
            }

            return ['user' => $existing, 'existed' => true];
        }
        $user = User::create(['type' => UserType::Customer, 'status' => UserStatus::Active, 'name' => trim($v['name']), 'phone' => $phone, 'locale' => $v['locale'],
            'referral_code' => $this->referralCode(), 'marketing_consent_at' => null]);
        $this->audit->log('customer.created_by_staff', $user, [], ['phone' => $user->phone], actor: $staff);

        return ['user' => $user, 'existed' => false];
    }

    private function customer(string $id): User
    {
        $u = User::where('type', UserType::Customer->value)->where('status', UserStatus::Active->value)->find($id);

        return $u ?? $this->fail('customer_not_found', 404);
    }

    private function referralCode(): string
    {
        do {
            $code = 'AB'.Str::upper(Str::random(6));
        } while (User::where('referral_code', $code)->exists());

        return $code;
    }

    // ── Lines ────────────────────────────────────────────────────────────────

    public function add(User $staff, Cart $draft, string $variantId, int $quantity = 1): Cart
    {
        $draft = $this->owned($staff, $draft);
        $this->throttle($staff, 'edit', 240);
        $variant = ProductVariant::with('product')->find($variantId) ?? $this->fail('item_unavailable', 404);
        $branch = $draft->branch_id ? Branch::active()->find($draft->branch_id) : null;
        if ($draft->branch_id && ! $branch) {
            $this->fail('branch_unavailable');
        }
        if ($branch && $this->prices->resolve($variant, $branch) === null) {
            $this->fail('item_unavailable_branch');
        }
        if (! $branch && ! $this->scope($staff)->all) {
            $this->fail('branch_required');
        }
        $this->carts->addTo($draft, $variant->id, $quantity);

        return $this->touch($draft);
    }

    public function update(User $staff, Cart $draft, string $itemId, int $quantity): Cart
    {
        $draft = $this->owned($staff, $draft);
        $this->throttle($staff, 'edit', 240);
        $this->carts->updateIn($draft, $itemId, $quantity);

        return $this->touch($draft);
    }

    /** Remove a line; the caller shows Undo, which re-adds `$variantId` x `$quantity`. */
    public function remove(User $staff, Cart $draft, string $itemId): Cart
    {
        return $this->update($staff, $draft, $itemId, 0);
    }

    /** Variants of "you may also need" suggestions that can be added with one tap (single-variant products only). @return list<array<string, mixed>> */
    public function suggestions(User $staff, Cart $draft): array
    {
        $draft = $this->owned($staff, $draft);
        $branch = $draft->branch_id ? Branch::active()->find($draft->branch_id) : null;
        $cards = app(UpsellService::class)->forCart($draft, $branch);
        $ids = collect($cards)->where('has_variants', false)->pluck('id')->all();
        $variants = $ids ? ProductVariant::where('status', 'active')->whereIn('product_id', $ids)->get()->groupBy('product_id') : collect();

        return collect($cards)->where('has_variants', false)->map(fn ($c) => $c + ['variant_id' => $variants->get($c['id'])?->first()?->id])->filter(fn ($c) => $c['variant_id'])->values()->take(4)->all();
    }

    // ── Options ──────────────────────────────────────────────────────────────

    /**
     * Branch, fulfilment type, address, coupon and the checkout choices. Unknown keys are ignored; prices cannot be set here.
     *
     * @param  array<string, mixed>  $o
     */
    public function setOptions(User $staff, Cart $draft, array $o): Cart
    {
        $draft = $this->owned($staff, $draft);
        $this->throttle($staff, 'edit', 240);
        $customer = $draft->user_id ? User::find($draft->user_id) : null;
        $opts = $draft->staff_options ?? [];
        $cartPart = [];

        if (array_key_exists('branch_id', $o)) {
            $branchId = $o['branch_id'] ?: null;
            if ($branchId === null && ! $this->scope($staff)->all) {
                $this->fail('branch_required');
            }
            if ($branchId !== null) {
                $this->assertBranch($staff, $branchId);
            }
            if ($branchId !== $draft->branch_id) {
                $opts = array_diff_key($opts, array_flip(['delivery_slot_id', 'delivery_date', 'pickup_slot_id', 'pickup_date']));
            }
            $cartPart['branch_id'] = $branchId;
        }
        if (isset($o['fulfillment_type'])) {
            if (! in_array($o['fulfillment_type'], ['delivery', 'pickup'], true)) {
                $this->fail('invalid_option');
            }
            if ($o['fulfillment_type'] !== $draft->fulfillment_type) {
                $opts = array_diff_key($opts, array_flip(['payment_method', 'delivery_slot_id', 'delivery_date', 'pickup_slot_id', 'pickup_date', 'express']));
            }
            $cartPart['fulfillment_type'] = $o['fulfillment_type'];
        }
        foreach (['address_id', 'coupon_code'] as $k) {
            if (array_key_exists($k, $o)) {
                $cartPart[$k] = $o[$k];
            }
        }
        $draft = $this->carts->setOptionsOn($draft, $customer, $cartPart);

        foreach (self::OPTION_KEYS as $k) {
            if (! array_key_exists($k, $o)) {
                continue;
            }
            $val = $o[$k];
            $val = match ($k) {
                'payment_method' => in_array($val, ['cod', 'sadad', 'pay_at_pickup', null, ''], true) ? ($val ?: null) : $this->fail('invalid_option'),
                'express' => (bool) $val,
                'notes', 'internal_note' => ($val = trim((string) $val)) === '' ? null : mb_substr($val, 0, 1000),
                'delivery_date', 'pickup_date' => $val && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $val) ? $val : null,
                default => $val && Str::isUuid((string) $val) ? $val : null, // slot ids
            };
            $val === null || $val === false ? $opts = array_diff_key($opts, [$k => 1]) : $opts[$k] = $val;
        }
        $draft->update(['staff_options' => $opts ?: null]);

        return $this->touch($draft);
    }

    /** @param array<string, mixed> $data delivery address fields, same rules as the customer API */
    public function addAddress(User $staff, Cart $draft, array $data): UserAddress
    {
        $draft = $this->owned($staff, $draft);
        $this->throttle($staff, 'edit', 240);
        $customer = $draft->user_id ? User::find($draft->user_id) : $this->fail('customer_required');
        if (isset($data['recipient_phone'])) {
            $data['recipient_phone'] = Phone::normalize($data['recipient_phone']) ?? $data['recipient_phone'];
        }
        $v = Validator::make($data, [
            'label' => 'nullable|string|max:48', 'recipient_name' => 'required|string|max:120', 'recipient_phone' => ['required', 'string', new PhoneNumber],
            'delivery_zone_id' => 'nullable|uuid|exists:delivery_zones,id', 'zone_number' => 'nullable|string|max:8|required_without_all:latitude,delivery_zone_id',
            'street_number' => 'nullable|string|max:16|required_with:zone_number', 'building_number' => 'nullable|string|max:16|required_with:zone_number',
            'unit_number' => 'nullable|string|max:16', 'area' => 'nullable|string|max:120', 'city' => 'nullable|string|max:120', 'landmark' => 'nullable|string|max:255',
            'instructions' => 'nullable|string|max:500',
        ])->validate();
        $address = DB::transaction(function () use ($customer, $v) {
            $first = ! $customer->addresses()->exists();

            return $customer->addresses()->create($v + ['is_default' => $first]);
        });
        $this->audit->log('customer.address_added_by_staff', $address, [], ['customer_id' => $customer->id], actor: $staff);
        $this->carts->setOptionsOn($draft, $customer, ['address_id' => $address->id]);

        return $address;
    }

    // ── Quote & place ────────────────────────────────────────────────────────

    /** The real quote, or null while the draft has no customer. */
    public function quote(User $staff, Cart $draft): ?Quote
    {
        $draft = $this->owned($staff, $draft);
        $customer = $draft->user_id ? User::find($draft->user_id) : null;

        return $customer ? $this->quotes->quote($customer, $draft, $this->quoteOptions($staff, $draft)) : null;
    }

    /**
     * @return array{order: Order, replayed: bool, payment_link: ?string, link_sent: list<string>}
     */
    public function place(User $staff, Cart $draft, string $quoteHash, string $idempotencyKey): array
    {
        $this->authorize($staff);
        $prior = Order::where('placed_by_staff_id', $staff->id)->where('idempotency_key', $idempotencyKey)->first();
        if ($prior) { // double submit / retry after the draft was already converted
            return ['order' => $prior, 'replayed' => true, 'payment_link' => $prior->payment_method === 'sadad' ? $this->paymentLink($staff, $prior) : null, 'link_sent' => []];
        }
        $draft = $this->owned($staff, $draft);
        $this->throttle($staff, 'place', 10);
        if (! preg_match('/^[A-Za-z0-9\-_]{16,128}$/', $idempotencyKey)) {
            $this->fail('invalid_option');
        }
        $customer = $draft->user_id ? User::find($draft->user_id) : $this->fail('customer_required');
        $opts = $draft->staff_options ?? [];
        $result = $this->checkout->placeCart($customer, $draft, $this->quoteOptions($staff, $draft), $quoteHash, $idempotencyKey, $opts['notes'] ?? null, $staff);
        $order = $result['order'];

        if (! $result['replayed']) {
            if (! empty($opts['internal_note'])) {
                $this->orders->addNote($order, $staff, $opts['internal_note']);
            }
            if (session(self::SESSION_KEY) === $draft->id) {
                session()->forget(self::SESSION_KEY);
            }
        }
        $link = $order->payment_method === 'sadad' ? $this->paymentLink($staff, $order) : null;

        return ['order' => $order, 'replayed' => $result['replayed'], 'payment_link' => $link, 'link_sent' => []];
    }

    /** A fresh signed SADAD checkout link for an unpaid online order this staff member placed. */
    public function paymentLink(User $staff, Order $order): ?string
    {
        $this->authorizeOrder($staff, $order);
        $payment = $order->payments()->where('status', 'pending')->where('method', 'sadad')->latest('attempt')->first();
        if (! $payment) {
            return null;
        }
        try {
            return $this->payments->action($payment)['redirect_url'] ?? null;
        } catch (DomainException) {
            return null; // gateway not configured: the order stays pending-payment and the link can be made later
        }
    }

    /**
     * Push the payment link to the customer (in-app + push, plus WhatsApp when it is configured).
     *
     * @return list<string> channels used
     */
    public function sendPaymentLink(User $staff, Order $order): array
    {
        $this->authorizeOrder($staff, $order);
        $this->throttle($staff, 'send-link', 10);
        $link = $this->paymentLink($staff, $order);
        if (! $link || ! $order->user) {
            $this->fail('link_unavailable');
        }
        $channels = ['in_app', 'push'];
        if (app(WhatsAppClient::class)->configured()) {
            $channels[] = 'whatsapp';
        }
        $this->audit->log('order.payment_link_sent', $order, [], ['channels' => $channels], actor: $staff);

        return $this->notifications->notify($order->user, 'payment.link', ['order_number' => $order->number, 'name' => $order->customer_name,
            'total' => Money::of($order->grand_total)->format(), 'link' => $link, 'deep_link' => $link, 'reference' => $order->id], $channels);
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /** Server-built quote options. Branch restriction comes from the staff member's role scope, never from the client. */
    private function quoteOptions(User $staff, Cart $draft): array
    {
        $so = $draft->staff_options ?? [];
        $o = ['fulfillment_type' => $draft->fulfillment_type, 'branch_id' => $draft->branch_id, 'address_id' => $draft->address_id, 'coupon_code' => $draft->coupon_code,
            'payment_method' => $so['payment_method'] ?? null, 'allow_split' => true, 'express' => (bool) ($so['express'] ?? false)];
        foreach (['delivery_slot_id', 'delivery_date', 'pickup_slot_id', 'pickup_date'] as $k) {
            $o[$k] = $so[$k] ?? null;
        }
        $scope = $this->scope($staff);
        if (! $scope->all) {
            $o['restrict_branch_ids'] = $scope->branchIds;
        }

        return $o;
    }

    private function owned(User $staff, Cart $draft): Cart
    {
        $fresh = $this->find($staff, $draft->id);
        if ($fresh->staff_id !== $staff->id) {
            throw new AuthorizationException(__('errors.forbidden'));
        }

        return $fresh;
    }

    private function assertBranch(User $staff, string $branchId): void
    {
        $this->authorize($staff, $branchId);
        if (! Branch::active()->whereKey($branchId)->exists()) {
            $this->fail('branch_unavailable');
        }
    }

    private function authorizeOrder(User $staff, Order $order): void
    {
        $this->authorize($staff);
        if ($order->placed_by_staff_id !== $staff->id && ! $staff->isSuperAdmin()) {
            throw new AuthorizationException(__('errors.forbidden'));
        }
    }

    private function touch(Cart $draft): Cart
    {
        $draft->forceFill(['last_activity_at' => now()])->save();

        return $draft->refresh();
    }
}
