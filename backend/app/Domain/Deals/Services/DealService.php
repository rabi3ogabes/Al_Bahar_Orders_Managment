<?php

namespace App\Domain\Deals\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Catalog\Models\Product;
use App\Domain\Checkout\Data\QuoteLine;
use App\Domain\Cms\Services\HomepageService;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\DealProduct;
use App\Domain\Deals\Models\DealRedemption;
use App\Domain\Deals\Support\DealDesign;
use App\Domain\Deals\Support\DealPricing;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Orders\Models\Cart;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Pricing\Services\PriceResolver;
use App\Support\BusinessTime;
use App\Support\DomainException;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Deals: administration (create, edit, pause, resume, end, duplicate, delete) and the money-safe parts of checkout (lock, claim, release).
 * The rules are written down in docs/api/DEALS.md; the short version:
 *
 *  - A product can be in only one switched-on deal at a time (windows may not overlap): the price a customer sees is never a guess.
 *  - Placing an order CLAIMS its deal units under a row lock on the deal's product rows (taken before the quote is built), so two orders for the
 *    last unit can never both get it and a per-account limit cannot be beaten by two orders at once. The database refuses `quantity_sold`
 *    above `quantity_total` as a last line of defence.
 *  - Cancelling, expiring or shrinking an order RELEASES the units it no longer holds; releasing twice gives them back once.
 *  - Lock order everywhere: the order or cart row, then the deal product rows (by id), then the stock rows. Keep it, or two requests can deadlock.
 */
class DealService
{
    /** A deal can name this many products. */
    public const MAX_PRODUCTS = 200;

    /** How long a deal that just ended still names itself on a line ("The deal ended"). */
    public const RECENTLY_ENDED_MINUTES = 30;

    public function __construct(private readonly AuditLogger $audit, private readonly AccessControl $access) {}

    // ─── Administration ─────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Create (`$deal` null) or change a deal together with its products. Everything is checked here, so the dashboard and any future API share the rules.
     * Plain-words failures: a ValidationException keyed by field (`name_en`, `products.0.quantity_total`, `design.colors.text`, …) or a DomainException
     * (`deal_invalid_window`, `deal_product_overlap`).
     *
     * @param  array<string, mixed>  $data  name_en/ar, tagline_en/ar, is_active, starts_at, ends_at, discount_type, discount_value, limit_per_order, limit_per_customer,
     *                                      show_on_home, sort_order, design, products[{product_id, deal_price, quantity_total, limit_per_order, limit_per_customer}]
     */
    public function save(User $actor, ?Deal $deal, array $data): Deal
    {
        $this->access->authorize($actor, $deal ? 'marketing.edit' : 'marketing.create');

        $saved = DB::transaction(function () use ($actor, $deal, $data) {
            $this->lockOverlapCheck();
            if ($deal !== null) {
                $deal = $this->lockDeal($deal->id);
                if ($deal->ended_at !== null) {
                    throw new DomainException('deal_invalid_window', __('deals.err_ended_locked'), 422);
                }
            }
            $existing = $deal ? $deal->products()->get()->keyBy('product_id') : collect();
            [$attrs, $rows] = $this->clean($deal, $data, $existing);

            $old = $deal ? $this->summary($deal) : [];
            $deal ??= new Deal(['created_by' => $actor->id]);
            $deal->fill($attrs)->save();
            $this->writeProducts($deal, $rows, $existing);
            $deal->unsetRelation('products');
            $this->syncSoldOut($deal);

            $new = $this->summary($deal->refresh());
            $changed = $old ? array_diff_assoc($new, $old) : $new;
            if ($old === [] || $changed !== []) {
                $this->audit->log($old === [] ? 'deals.created' : 'deals.updated', $deal, $old === [] ? [] : array_intersect_key($old, $changed), $changed,
                    ['products' => collect($rows)->pluck('product_id')->all()], actor: $actor);
            }

            return $deal;
        });
        $this->changed();

        return $saved;
    }

    /** Switch a deal off (pause) or on (resume). Switching on checks the window and the one-deal-per-product rule again. */
    public function setActive(User $actor, Deal $deal, bool $active): Deal
    {
        $this->access->authorize($actor, 'marketing.edit');

        $saved = DB::transaction(function () use ($actor, $deal, $active) {
            if ($active) {
                $this->lockOverlapCheck();
            }
            $deal = $this->lockDeal($deal->id);
            if ($deal->is_active === $active) {
                return $deal;
            }
            if ($active) {
                $this->assertCanRun($deal);
                $this->assertNoOverlap($deal, $deal->starts_at, $deal->ends_at, DealProduct::where('deal_id', $deal->id)->pluck('product_id')->all());
            }
            $deal->forceFill(['is_active' => $active])->save();
            $this->audit->log($active ? 'deals.resumed' : 'deals.paused', $deal, ['is_active' => ! $active], ['is_active' => $active], ['name' => $deal->name_en], actor: $actor);

            return $deal;
        });
        $this->changed();

        return $saved;
    }

    /** "End now": the countdown stops for everyone at once. Orders already placed keep their deal prices. Ending twice changes nothing. */
    public function end(User $actor, Deal $deal): Deal
    {
        $this->access->authorize($actor, 'marketing.edit');

        $saved = DB::transaction(function () use ($actor, $deal) {
            $deal = $this->lockDeal($deal->id);
            if ($deal->ended_at !== null || $deal->ends_at->lessThanOrEqualTo(now())) {
                return $deal;
            }
            $deal->forceFill(['ended_at' => now()])->save();
            $this->audit->log('deals.ended', $deal, [], ['ended_at' => $deal->ended_at?->toIso8601String()], ['name' => $deal->name_en], actor: $actor);

            return $deal;
        });
        $this->changed();

        return $saved;
    }

    /** A switched-off copy of a deal: same products, price rule, limits and look; nothing sold, a fresh countdown of the same length. */
    public function duplicate(User $actor, Deal $deal): Deal
    {
        $this->access->authorize($actor, 'marketing.create');

        $copy = DB::transaction(function () use ($actor, $deal) {
            $length = $deal->starts_at && $deal->ends_at ? $deal->starts_at->diffInSeconds($deal->ends_at) : max(3600, (int) now()->diffInSeconds($deal->ends_at, false));
            $copy = new Deal($deal->only(['tagline_en', 'tagline_ar', 'discount_type', 'discount_value', 'limit_per_order', 'limit_per_customer', 'show_on_home', 'sort_order', 'design']));
            $copy->forceFill([
                'name_en' => mb_substr($deal->name_en, 0, 240).' (copy)', 'name_ar' => mb_substr($deal->name_ar, 0, 240).' (نسخة)',
                'is_active' => false, 'starts_at' => null, 'ends_at' => now()->addSeconds(max(3600, (int) $length)), 'created_by' => $actor->id,
            ])->save();
            foreach ($deal->products()->get() as $row) {
                DealProduct::create(['deal_id' => $copy->id, 'product_id' => $row->product_id, 'deal_price' => $row->deal_price, 'quantity_total' => $row->quantity_total,
                    'limit_per_order' => $row->limit_per_order, 'limit_per_customer' => $row->limit_per_customer, 'sort_order' => $row->sort_order]);
            }
            $this->audit->log('deals.created', $copy, [], $this->summary($copy), ['duplicate_of' => $deal->id], actor: $actor);

            return $copy;
        });
        $this->changed();

        return $copy;
    }

    /** Only a deal nobody ordered from can go; the dashboard hides the button otherwise and this refuses it anyway. */
    public function delete(User $actor, Deal $deal): void
    {
        $this->access->authorize($actor, 'marketing.delete');

        DB::transaction(function () use ($actor, $deal) {
            $deal = $this->lockDeal($deal->id);
            if (DealRedemption::where('deal_id', $deal->id)->exists()) {
                throw DomainException::make('deal_has_orders', 409);
            }
            $this->audit->log('deals.deleted', $deal, $this->summary($deal), [], ['name' => $deal->name_en], actor: $actor);
            $deal->delete();
        });
        $this->changed();
    }

    public function hasOrders(Deal $deal): bool
    {
        return DealRedemption::where('deal_id', $deal->id)->exists();
    }

    // ─── Checkout: lock, claim, release ─────────────────────────────────────────────────────────────────────────────

    /**
     * Take the row locks of every open deal that covers a product in the cart, in id order. Called first thing inside the order transaction (after the
     * cart row), so the quote that follows is read from rows nobody else can change: two orders for the last unit take turns instead of both seeing it.
     */
    public function lockForCart(Cart $cart): void
    {
        DealProduct::query()->join('deals', 'deals.id', '=', 'deal_products.deal_id')
            ->whereNull('deals.ended_at')->where('deals.ends_at', '>', now())
            ->whereIn('deal_products.product_id', fn ($q) => $q->select('product_variants.product_id')->from('cart_items')
                ->join('product_variants', 'product_variants.id', '=', 'cart_items.product_variant_id')->where('cart_items.cart_id', $cart->id))
            ->orderBy('deal_products.id')->lock('for update of deal_products')->pluck('deal_products.id');
    }

    /** Lock the deal rows an order still holds units of (cancelling or adjusting it): the same order as checkout, before any stock row. */
    public function lockForOrder(string $orderId): void
    {
        DealProduct::query()->whereIn('id', DealRedemption::query()->where('order_id', $orderId)->whereNull('released_at')->select('deal_product_id'))
            ->orderBy('id')->lockForUpdate()->pluck('id');
    }

    /** The same, starting from a branch parcel of the order (an item adjustment knows the fulfillment first). */
    public function lockForFulfillment(string $fulfillmentId): void
    {
        $orderId = DB::table('order_fulfillments')->where('id', $fulfillmentId)->value('order_id');
        if ($orderId !== null) {
            $this->lockForOrder((string) $orderId);
        }
    }

    /**
     * Take the deal units a quote promised out of the deals, inside the order transaction. The deal rows are already locked (lockForCart); the claim
     * is still an atomic guarded update, so even a missed lock cannot oversell. Anything that no longer fits is `409 deal_changed`.
     *
     * @param  list<QuoteLine>  $lines
     *
     * @throws DomainException deal_changed
     */
    public function claim(Order $order, User $user, array $lines): void
    {
        /** @var array<string, array{row: DealProduct, deal: Deal, units: int, savings: Money}> $wanted by deal product id */
        $wanted = [];
        foreach ($lines as $line) {
            $d = $line->deal;
            if ($d === null || $d->units < 1) {
                continue;
            }
            $key = (string) $d->row->id;
            $wanted[$key] = ['row' => $d->row, 'deal' => $d->deal, 'units' => ($wanted[$key]['units'] ?? 0) + $d->units,
                'savings' => ($wanted[$key]['savings'] ?? Money::zero())->plus($d->savings())];
        }
        if (count($wanted) === 0) {
            return;
        }
        ksort($wanted);
        $ids = array_keys($wanted);
        $rows = DealProduct::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $deals = Deal::whereIn('id', $rows->pluck('deal_id')->unique())->get()->keyBy('id');
        $used = $this->usedBy($user->id, $ids);
        $now = now();

        foreach ($wanted as $id => $w) {
            $changed = new DomainException('deal_changed', __('errors.deal_changed'), 409, ['deal_id' => $w['deal']->id, 'product_id' => $w['row']->product_id]);
            $row = $rows->get($id);
            $deal = $row ? $deals->get($row->deal_id) : null;
            if ($row === null || $deal === null || ! $this->isRunning($deal, $now)) {
                throw $changed;
            }
            $perOrder = $row->perOrderLimit($deal);
            $perCustomer = $row->perCustomerLimit($deal);
            if (($perOrder !== null && $w['units'] > $perOrder) || ($perCustomer !== null && ($used[$id] ?? 0) + $w['units'] > $perCustomer)) {
                throw $changed;
            }
            $claimed = DB::update('UPDATE deal_products SET quantity_sold = quantity_sold + ?, updated_at = ? WHERE id = ? AND (quantity_total IS NULL OR quantity_sold + ? <= quantity_total)',
                [$w['units'], $now, $id, $w['units']]);
            if ($claimed === 0) {
                throw $changed;
            }
            DealRedemption::create(['deal_id' => $deal->id, 'deal_product_id' => $id, 'order_id' => $order->id, 'user_id' => $user->id, 'product_id' => $row->product_id,
                'units' => $w['units'], 'unit_savings' => Money::ofMinor((int) round($w['savings']->minor / $w['units']))->toDecimal()]);
        }
        foreach ($deals as $deal) {
            $this->syncSoldOut($deal);
        }
    }

    /**
     * Give back every deal unit an order no longer holds. What still counts as held: the deal units of the lines that were not cancelled (a fulfillment
     * that already left the branch keeps its units); with `$everything` (a full refund) nothing does. Safe to repeat: the second call finds nothing to give back.
     *
     * @return int units given back to the deals
     */
    public function release(Order|string $order, bool $everything = false): int
    {
        $orderId = $order instanceof Order ? $order->id : $order;

        $given = DB::transaction(function () use ($orderId, $everything) {
            $redemptions = DealRedemption::where('order_id', $orderId)->whereNull('released_at')->orderBy('deal_product_id')->lockForUpdate()->get();
            if ($redemptions->isEmpty()) {
                return 0;
            }
            DealProduct::whereIn('id', $redemptions->pluck('deal_product_id'))->orderBy('id')->lockForUpdate()->get();
            $lines = OrderItem::where('order_id', $orderId)->where('deal_units', '>', 0)->where('quantity', '>', 0)
                ->get(['id', 'order_fulfillment_id', 'product_id', 'deal_id', 'deal_units', 'quantity', 'quantity_cancelled']);
            // A parcel that was cancelled or came back holds nothing, whatever its lines say: a delivery that failed and was cancelled, or a delivered parcel that
            // was returned, has its goods on the shelf again (its lines still read "handed over", so only the parcel's own state tells).
            $gone = DB::table('order_fulfillments')->where('order_id', $orderId)->whereIn('status', ['cancelled', 'returned'])->pluck('id')->all();
            $given = 0;
            foreach ($redemptions as $r) {
                $alive = $everything ? 0 : (int) $lines->where('deal_id', $r->deal_id)->where('product_id', $r->product_id)
                    ->reject(fn (OrderItem $i) => in_array($i->order_fulfillment_id, $gone, true))
                    ->sum(fn (OrderItem $i) => $this->share($i->deal_units, $i->quantity - $i->quantity_cancelled, $i->quantity));
                $alive = min($alive, $r->units);
                $back = $r->units - $alive;
                if ($back > 0) {
                    DB::update('UPDATE deal_products SET quantity_sold = GREATEST(quantity_sold - ?, 0), updated_at = ? WHERE id = ?', [$back, now(), $r->deal_product_id]);
                }
                $r->forceFill(['units' => $alive, 'released_at' => $alive === 0 ? now() : null])->save();
                $given += $back;
            }
            Deal::whereIn('id', $redemptions->pluck('deal_id')->unique())->get()->each(fn (Deal $d) => $this->syncSoldOut($d));

            return $given;
        });
        if ($given > 0) {
            $this->changed();
        }

        return $given;
    }

    /**
     * An order line shrank from `$from` to `$to` units (an item adjustment): its deal units shrink in the same proportion the discount does
     * (rounded up, the customer's way, exactly like AdjustmentMath). A replaced line hands the share of its deal units that the substitute
     * carries to the substitute line; the rest are free to be given back by release().
     */
    public function adjusted(OrderItem $item, int $from, int $to, ?OrderItem $substitute = null, int $substituteQuantity = 0): void
    {
        $units = (int) $item->deal_units;
        if ($units < 1 || $from < 1) {
            return;
        }
        $keep = $to > 0 ? $this->share($units, $to, $from) : 0;
        $item->forceFill(['deal_units' => $keep])->save();
        $carried = $substitute !== null && $substituteQuantity > 0 ? min($units - $keep, $this->share($units, $substituteQuantity, $from)) : 0;
        // Only a replacement of the SAME product keeps the deal: a different product was never in the deal, so its units go back to the pool.
        if ($substitute !== null && $carried > 0 && $substitute->product_id === $item->product_id) {
            $substitute->forceFill(['deal_id' => $item->deal_id, 'deal_name_en' => $item->deal_name_en, 'deal_name_ar' => $item->deal_name_ar,
                'deal_units' => $carried, 'deal_unit_savings' => $item->deal_unit_savings])->save();
        }
    }

    // ─── Reading ────────────────────────────────────────────────────────────────────────────────────────────────────

    public function isRunning(Deal $deal, ?CarbonInterface $now = null): bool
    {
        $now ??= now();

        return $deal->is_active && $deal->ended_at === null && $deal->ends_at->greaterThan($now) && ($deal->starts_at === null || $deal->starts_at->lessThanOrEqualTo($now));
    }

    /**
     * Units each deal product has been bought by this customer (orders that were not released).
     *
     * @param  list<string>  $dealProductIds
     * @return array<string, int> deal product id → units
     */
    public function usedBy(string $userId, array $dealProductIds): array
    {
        if ($dealProductIds === []) {
            return [];
        }

        return DealRedemption::where('user_id', $userId)->whereIn('deal_product_id', $dealProductIds)->whereNull('released_at')
            ->groupBy('deal_product_id')->selectRaw('deal_product_id, sum(units) as used')->pluck('used', 'deal_product_id')->map(fn ($v) => (int) $v)->all();
    }

    /** Keep `sold_out_at` honest: set when the last unit goes, cleared when a unit comes back or the admin adds units. */
    public function syncSoldOut(Deal $deal): void
    {
        $deal->unsetRelation('products');
        $sold = $deal->isSoldOut();
        if ($sold && $deal->sold_out_at === null) {
            $deal->forceFill(['sold_out_at' => now()])->save();
            $this->changed(); // the home feed (cached for a minute) must stop offering it
        } elseif (! $sold && $deal->sold_out_at !== null) {
            $deal->forceFill(['sold_out_at' => null])->save();
            $this->changed();
        }
    }

    // ─── Internals ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Two admins saving or switching on deals at the same moment must not both pass the "one running deal per product" check: one lock, held to the end of the
     * transaction, makes the check and the write one step. (Checkout never takes it.)
     */
    private function lockOverlapCheck(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['deals:overlap']);
    }

    /**
     * Lock a deal the way checkout does: its product rows first (by id), then the deal row. claim() and release() hold the product rows and then update the
     * deal row, so taking them the other way round here could make an admin's Save and a customer's last-unit order wait for each other for ever.
     */
    private function lockDeal(string $id): Deal
    {
        DealProduct::where('deal_id', $id)->orderBy('id')->lockForUpdate()->pluck('id');

        return Deal::whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Validate and normalise a save request.
     *
     * @param  array<string, mixed>  $data
     * @param  Collection<string, DealProduct>  $existing  the deal's current rows by product id
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function clean(?Deal $deal, array $data, $existing): array
    {
        // An upper-case id from a crafted request names the same product but would miss the existing row (and its sold units) in the checks below.
        if (isset($data['products']) && is_array($data['products'])) {
            $data['products'] = array_map(fn ($row) => is_array($row) && is_string($row['product_id'] ?? null) ? ['product_id' => strtolower($row['product_id'])] + $row : $row, $data['products']);
        }
        $type = (string) ($data['discount_type'] ?? '');
        // Money typed as plain decimals only: up to 8 digits and 2 decimals. "1e3" or "0.004" would pass `numeric` and then break Money or a CHECK constraint.
        $decimal = 'regex:/^\d{1,8}(\.\d{1,2})?$/';
        $value = $type === Deal::PERCENT
            ? ['required', 'numeric', $decimal, 'min:'.DealPricing::MIN_PERCENT, 'max:'.DealPricing::MAX_PERCENT]
            : ($type === Deal::AMOUNT_OFF ? ['required', 'numeric', $decimal, 'gt:0', 'max:10000000'] : ['nullable']);
        $integer = fn (int $max) => ['nullable', 'integer', 'min:1', 'max:'.$max];
        $v = Validator::make($data, [
            'name_en' => ['required', 'string', 'max:255'], 'name_ar' => ['required', 'string', 'max:255'],
            'tagline_en' => ['nullable', 'string', 'max:255'], 'tagline_ar' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'], 'show_on_home' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'date'], 'ends_at' => ['required', 'date'],
            'discount_type' => ['required', Rule::in(Deal::DISCOUNT_TYPES)], 'discount_value' => $value,
            'limit_per_order' => $integer(999), 'limit_per_customer' => $integer(9999), 'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'products' => ['required', 'array', 'min:1', 'max:'.self::MAX_PRODUCTS],
            'products.*.product_id' => ['required', 'uuid', 'distinct', 'exists:products,id'],
            'products.*.deal_price' => ['nullable', 'numeric', $decimal, 'gt:0', 'max:10000000'],
            'products.*.quantity_total' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'products.*.limit_per_order' => $integer(999), 'products.*.limit_per_customer' => $integer(9999),
        ], ['discount_value.regex' => __('deals.err_decimal'), 'products.*.deal_price.regex' => __('deals.err_decimal')],
            ['name_en' => __('deals.f_name_en'), 'name_ar' => __('deals.f_name_ar'), 'ends_at' => __('deals.f_ends_at'), 'discount_value' => __('deals.f_discount_value')]);
        $v->after(function ($v) use ($data, $type, $existing, $deal) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            foreach (array_values($data['products']) as $i => $row) {
                $given = $row['deal_price'] ?? null;
                if ($type === Deal::FIXED_PRICE && ($given === null || $given === '')) {
                    $v->errors()->add("products.$i.deal_price", __('deals.err_price_required'));
                }
                $sold = (int) ($existing->get($row['product_id'])?->quantity_sold ?? 0);
                $total = $row['quantity_total'] ?? null;
                if ($total !== null && $total !== '' && (int) $total < $sold) {
                    $v->errors()->add("products.$i.quantity_total", __('deals.err_quantity_below_sold', ['n' => $sold]));
                }
            }
            if ($deal !== null) {
                $kept = collect($data['products'])->pluck('product_id')->all();
                $blocked = $existing->filter(fn (DealProduct $r) => ! in_array($r->product_id, $kept, true) && $r->redemptions()->exists());
                if ($blocked->isNotEmpty()) {
                    $names = Product::with('translations')->whereIn('id', $blocked->keys())->get()->map(fn (Product $p) => $p->trans('name') ?: $p->sku)->implode(', ');
                    $v->errors()->add('products', __('deals.err_product_has_orders', ['names' => $names]));
                }
            }
        });
        $input = $v->validate();

        $archived = Product::whereIn('id', collect($input['products'])->pluck('product_id'))->where('status', 'archived')->whereNotIn('id', $existing->keys()->all())->exists();
        if ($archived) {
            throw ValidationException::withMessages(['products' => [__('deals.err_product_archived')]]);
        }
        $this->assertPriceFloor($input, $type);
        $design = DealDesign::validate($data['design'] ?? $deal?->design);

        $starts = filled($input['starts_at'] ?? null) ? BusinessTime::toUtc($input['starts_at']) : null;
        $ends = BusinessTime::toUtc($input['ends_at']);
        $active = array_key_exists('is_active', $input) ? (bool) $input['is_active'] : (bool) ($deal?->is_active ?? false);
        if ($starts !== null && ! $ends->greaterThan($starts)) {
            throw new DomainException('deal_invalid_window', __('errors.deal_invalid_window'), 422);
        }
        if ($active && $ends->lessThanOrEqualTo(now())) {
            throw new DomainException('deal_invalid_window', __('deals.err_ends_in_past'), 422);
        }
        $ids = collect($input['products'])->pluck('product_id')->all();
        if ($active) {
            $this->assertNoOverlap($deal, $starts, $ends, $ids);
        }

        $attrs = [
            'name_en' => trim($input['name_en']), 'name_ar' => trim($input['name_ar']),
            'tagline_en' => $this->text($input['tagline_en'] ?? null), 'tagline_ar' => $this->text($input['tagline_ar'] ?? null),
            'is_active' => $active, 'show_on_home' => (bool) ($input['show_on_home'] ?? false), 'sort_order' => (int) ($input['sort_order'] ?? 0),
            'starts_at' => $starts, 'ends_at' => $ends,
            'discount_type' => $input['discount_type'], 'discount_value' => $input['discount_type'] === Deal::FIXED_PRICE ? '0.00' : Money::of((string) $input['discount_value'])->toDecimal(),
            'limit_per_order' => $this->number($input['limit_per_order'] ?? null), 'limit_per_customer' => $this->number($input['limit_per_customer'] ?? null),
            'design' => $design,
        ];
        $rows = [];
        foreach (array_values($input['products']) as $i => $row) {
            $rows[] = ['product_id' => $row['product_id'], 'deal_price' => filled($row['deal_price'] ?? null) ? Money::of((string) $row['deal_price'])->toDecimal() : null,
                'quantity_total' => $this->number($row['quantity_total'] ?? null), 'limit_per_order' => $this->number($row['limit_per_order'] ?? null),
                'limit_per_customer' => $this->number($row['limit_per_customer'] ?? null), 'sort_order' => $i];
        }

        return [$attrs, $rows];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<string, DealProduct>  $existing
     */
    private function writeProducts(Deal $deal, array $rows, $existing): void
    {
        $keep = [];
        foreach ($rows as $row) {
            $keep[] = $row['product_id'];
            $current = $existing->get($row['product_id']);
            $current ? $current->forceFill($row)->save() : DealProduct::create($row + ['deal_id' => $deal->id]);
        }
        foreach ($existing as $productId => $current) {
            if (! in_array($productId, $keep, true)) {
                $current->delete();
            }
        }
    }

    /**
     * A deal price below 5 % of the normal price is almost surely a typo (400 instead of 40 off a 350 sofa would sell it for 0.01): refuse it, naming the product.
     * Checked for every active variant of every listed product, for all three price rules and for a product's own price; a variant the rule gives no deal to is skipped.
     *
     * @param  array<string, mixed>  $input  the validated request
     */
    private function assertPriceFloor(array $input, string $type): void
    {
        $products = Product::with(['translations', 'variants' => fn ($q) => $q->where('status', 'active')])->whereIn('id', collect($input['products'])->pluck('product_id'))->get()->keyBy('id');
        $prices = app(PriceResolver::class);
        $rule = new Deal(['discount_type' => $type, 'discount_value' => $type === Deal::FIXED_PRICE ? '0.00' : Money::of((string) ($input['discount_value'] ?? '0'))->toDecimal()]);
        $errors = [];
        foreach (array_values($input['products']) as $i => $row) {
            $product = $products->get($row['product_id']);
            if ($product === null) {
                continue;
            }
            $own = filled($row['deal_price'] ?? null) ? Money::of((string) $row['deal_price'])->toDecimal() : null;
            $dealRow = new DealProduct(['deal_price' => $own]);
            foreach ($product->variants as $variant) {
                $variant->setRelation('product', $product);
                $normal = $prices->price($variant, null)->final();
                $price = DealPricing::unitPrice($rule, $dealRow, $normal);
                if ($price !== null && $price->minor * 100 < $normal->minor * 5) {
                    $errors[$own !== null ? "products.$i.deal_price" : 'discount_value'][] = __('deals.err_price_too_low', [
                        'product' => $product->trans('name') ?: $product->sku, 'min' => Money::ofMinor((int) ceil($normal->minor * 5 / 100))->format()]);
                    break;
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** One product can be in only one switched-on deal at a time: refuse a window that overlaps another, naming it. */
    private function assertNoOverlap(?Deal $self, ?CarbonInterface $starts, CarbonInterface $ends, array $productIds): void
    {
        if ($productIds === []) {
            return;
        }
        $clash = DealProduct::query()->whereIn('product_id', $productIds)
            ->whereHas('deal', fn ($q) => $q->open()->when($self, fn ($w) => $w->where('id', '!=', $self?->id))
                ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<', $ends))
                ->when($starts, fn ($w) => $w->where('ends_at', '>', $starts)))
            ->with(['deal', 'product.translations'])->orderBy('deal_id')->first();
        if ($clash === null) {
            return;
        }
        $other = $clash->deal;
        throw new DomainException('deal_product_overlap', null, 422, [
            'product' => $clash->product?->trans('name') ?: $clash->product?->sku, 'deal' => $other->localized('name'),
            'from' => $other->starts_at ? BusinessTime::display($other->starts_at) : __('deals.now'), 'to' => BusinessTime::display($other->ends_at),
        ]);
    }

    private function assertCanRun(Deal $deal): void
    {
        if ($deal->ended_at !== null || $deal->ends_at->lessThanOrEqualTo(now())) {
            throw new DomainException('deal_invalid_window', __('deals.err_cannot_resume_ended'), 422);
        }
    }

    /** @return array<string, mixed> the facts an audit entry keeps */
    private function summary(Deal $deal): array
    {
        return ['name_en' => $deal->name_en, 'name_ar' => $deal->name_ar, 'is_active' => $deal->is_active, 'starts_at' => $deal->starts_at?->toIso8601String(),
            'ends_at' => $deal->ends_at->toIso8601String(), 'discount_type' => $deal->discount_type, 'discount_value' => (string) $deal->discount_value,
            'limit_per_order' => $deal->limit_per_order, 'limit_per_customer' => $deal->limit_per_customer, 'show_on_home' => $deal->show_on_home,
            'products' => $deal->products()->count()];
    }

    /** What changed is what the public sees: the home feed (cached for a minute) is dropped at once. */
    private function changed(): void
    {
        HomepageService::flush();
        Cache::forget('storefront:sitemap'); // the sitemap lists the live deals
    }

    /** ceil($units × $part / $whole): the share of a line that stays, rounded up the customer's way (the same rule as AdjustmentMath::keptShare). */
    private function share(int $units, int $part, int $whole): int
    {
        if ($units <= 0 || $part <= 0 || $whole <= 0) {
            return 0;
        }

        return min($units, intdiv($units * $part + $whole - 1, $whole));
    }

    private function text(mixed $value): ?string
    {
        $t = trim(strip_tags((string) $value));

        return $t === '' ? null : $t;
    }

    private function number(mixed $value): ?int
    {
        return filled($value) ? (int) $value : null;
    }

    /** Whether a given moment is inside the "sold out, still visible" window. */
    public static function soldOutStillVisible(Deal $deal, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();
        $since = $deal->sold_out_at ?? $deal->updated_at;

        return $since !== null && $since->greaterThan($now->subMinutes(Deal::SOLD_OUT_VISIBLE_MINUTES));
    }
}
