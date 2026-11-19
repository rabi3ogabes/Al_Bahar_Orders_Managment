<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\BranchDeliveryZone;
use App\Domain\Catalog\Models\Product;
use App\Domain\Checkout\Services\AbandonedCartService;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Checkout\Services\StaffOrderService;
use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Marketing\Models\Journey;
use App\Domain\Marketing\Models\JourneyDelivery;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Marketing\Services\JourneyService;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Orders\Models\Cart;
use App\Domain\Orders\Models\CartItem;
use App\Domain\Orders\Models\Order;
use App\Livewire\Admin\Sales\AbandonedCartsIndex;
use App\Support\AdminNavigation;
use App\Support\DomainException;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->w = commerceWorld();
    $this->svc = app(AbandonedCartService::class);
    $this->admin = User::factory()->superAdmin()->create();
    $this->mgrA = staffWithRole('branch_manager', $this->w->a);
    $this->mgrB = staffWithRole('branch_manager', $this->w->b);
    $this->viewer = staffWithRole('auditor'); // orders.view everywhere, orders.create nowhere
});

/** An abandoned cart: the customer added the lines, then went away `idle` minutes ago. Branch A and the world's address unless said otherwise. */
function abCart(object $t, array $o = []): Cart
{
    $customer = $o['customer'] ?? $t->w->customer;
    $carts = app(CartService::class);
    $cart = $carts->add($customer, $o['variant'] ?? $t->w->v1->id, $o['qty'] ?? 2);
    foreach ($o['more'] ?? [] as $variantId => $qty) {
        $carts->add($customer, $variantId, $qty);
    }
    Cart::whereKey($cart->id)->update(['last_activity_at' => now()->subMinutes($o['idle'] ?? 120), 'branch_id' => array_key_exists('branch', $o) ? $o['branch'] : $t->w->a->id,
        'address_id' => array_key_exists('address', $o) ? $o['address'] : $t->w->address->id] + ($o['extra'] ?? []));

    return $cart->fresh();
}

function abPromo(string $code = 'SAVE10', array $promotion = []): Coupon
{
    $p = Promotion::create(array_merge(['name_en' => 'Ten percent', 'name_ar' => 'عشرة بالمئة', 'type' => 'percentage', 'value' => '10', 'scope' => 'order',
        'requires_coupon' => true, 'is_active' => true], $promotion));

    return Coupon::create(['promotion_id' => $p->id, 'code' => $code]);
}

function abCustomerQuote(object $t): array
{
    actingAsCustomer($t->w->customer);

    return $t->postJson('/api/v1/checkout/quote', [])->assertOk()->json('data');
}

describe('the list', function () {
    it('lists customer carts idle for 30 minutes or more, with lines, and nothing else', function () {
        $idle = abCart($this, ['idle' => 45]);
        $fresh = abCart($this, ['customer' => User::factory()->create(['name' => 'Fresh Fatima']), 'idle' => 5]);
        $empty = Cart::create(['user_id' => User::factory()->create(['name' => 'Empty Eman'])->id, 'status' => 'active', 'last_activity_at' => now()->subHours(3)]);
        $ordered = abCart($this, ['customer' => User::factory()->create(['name' => 'Ordered Omar']), 'extra' => ['status' => 'converted']]);
        $draft = abCart($this, ['customer' => User::factory()->create(['name' => 'Draft Dana']), 'extra' => ['status' => 'staff_draft']]);
        $guest = Cart::create(['user_id' => null, 'guest_token_hash' => str_repeat('a', 64), 'status' => 'active', 'last_activity_at' => now()->subHours(3)]);
        CartItem::create(['cart_id' => $guest->id, 'product_variant_id' => $this->w->v1->id, 'quantity' => 1]);

        $ids = $this->svc->page($this->admin)['rows']->pluck('id')->all();

        expect($ids)->toBe([$idle->id])->and($ids)->not->toContain($fresh->id, $empty->id, $ordered->id, $draft->id, $guest->id);
    });

    it('filters by idle time, name or phone, coupon and branch, newest idle first', function () {
        abPromo();
        $c = fn (string $name, string $phone) => User::factory()->create(['name' => $name, 'phone' => $phone]);
        $a = abCart($this, ['customer' => $c('Aisha Al Thani', '+97455000001'), 'idle' => 40]);
        $b = abCart($this, ['customer' => $c('Bilal Hamad', '+97455000002'), 'idle' => 90, 'extra' => ['coupon_code' => 'SAVE10']]);
        $d = abCart($this, ['customer' => $c('Dalal Nasser', '+97455000003'), 'idle' => 60 * 30, 'branch' => $this->w->b->id]);
        $e = abCart($this, ['customer' => $c('Essa Khalid', '+97455000004'), 'idle' => 60 * 24 * 9]);
        $ids = fn (array $f) => $this->svc->page($this->admin, $f)['rows']->pluck('id')->all();

        expect($ids([]))->toBe([$a->id, $b->id, $d->id, $e->id])                                       // newest idle first
            ->and($ids(['idle' => '1h']))->toBe([$b->id, $d->id, $e->id])
            ->and($ids(['idle' => '24h']))->toBe([$d->id, $e->id])
            ->and($ids(['idle' => '7d']))->toBe([$e->id])
            ->and($ids(['search' => 'bilal']))->toBe([$b->id])
            ->and($ids(['search' => '55000003']))->toBe([$d->id])
            ->and($ids(['coupon' => true]))->toBe([$b->id])
            ->and($ids(['branch' => $this->w->b->id]))->toBe([$d->id])
            ->and($ids(['idle' => 'forever']))->toBe([$a->id, $b->id, $d->id, $e->id]);                  // unknown choice = the default
    });

    it('does not list a cart older than the cart lifetime: it has expired and cannot be recovered', function () {
        $live = abCart($this, ['idle' => 60 * 24 * 29]);
        $expired = abCart($this, ['customer' => User::factory()->create(), 'idle' => 60 * 24 * 31]);

        expect($this->svc->page($this->admin, ['idle' => '7d'])['rows']->pluck('id')->all())->toBe([$live->id])
            ->and($this->svc->page($this->admin)['rows']->pluck('id')->all())->not->toContain($expired->id)
            ->and($this->svc->stats($this->admin)['carts'])->toBe(1)->and($this->svc->badge($this->admin))->toBe(1);
    });

    it('counts what is waiting, what it is worth and how many were reminded; carts won back come only from reminder results', function () {
        abCart($this, ['qty' => 2, 'extra' => ['abandoned_notifications' => 1, 'abandoned_notified_at' => now()->subHour()]]);   // 2 x 1000.00
        abCart($this, ['customer' => User::factory()->create(), 'variant' => $this->w->v2->id, 'qty' => 3, 'extra' => ['coupon_code' => 'X']]); // 3 x 250.00
        abCart($this, ['customer' => User::factory()->create(), 'idle' => 3]);

        $s = $this->svc->stats($this->admin);

        expect($s)->toBe(['carts' => 2, 'value' => '2750.00', 'reminded' => 1, 'with_coupon' => 1]);
        $page = Livewire::actingAs($this->admin)->test(AbandonedCartsIndex::class)->assertSee(__('abandoned_admin.kpi_carts'))->assertSee('QAR 2,750')
            ->assertSee(__('cart_reminders.res_recovered'));
        expect($page->html())->toContain('data-result="recovered">0<');   // nothing was won back by a reminder: the figure is a real zero, not a guess from the carts
    });

    it('pages with a keyset cursor (25 per page, no OFFSET) and ignores a made-up cursor', function () {
        foreach (range(1, 27) as $i) {
            abCart($this, ['customer' => User::factory()->create(['name' => 'Customer '.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]), 'idle' => 30 + $i]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $first = $this->svc->page($this->admin);
        $second = $this->svc->page($this->admin, [], $first['next']);
        $log = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        expect($first['rows'])->toHaveCount(25)->and($first['next'])->not->toBeNull()
            ->and($second['rows'])->toHaveCount(2)->and($second['next'])->toBeNull()
            ->and($first['rows']->pluck('id')->intersect($second['rows']->pluck('id')))->toHaveCount(0)
            ->and(strtolower($log))->not->toContain('offset')->and($log)->toContain('(carts.last_activity_at, carts.id) < (');
        expect($this->svc->page($this->admin, [], 'not-a-cursor')['rows'])->toHaveCount(25)
            ->and($this->svc->page($this->admin, [], base64_encode('["x","y"]'))['rows'])->toHaveCount(25);
    });

    it('reads a constant number of queries however many carts are on the page', function () {
        foreach (range(1, 3) as $i) {
            abCart($this, ['customer' => User::factory()->create()]);
        }
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->svc->page($this->admin);
            $this->svc->stats($this->admin);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $few = $count();
        foreach (range(1, 15) as $i) {
            abCart($this, ['customer' => User::factory()->create()]);
        }

        expect($count())->toBe($few);
    });
});

describe('who may see and change', function () {
    it('lets orders.view see the page but not change anything; orders.create changes', function () {
        $cart = abCart($this);
        $item = $cart->items()->first();
        $this->actingAs($this->viewer)->get(route('admin.sales.abandoned'))->assertOk()->assertSee($this->w->customer->name);
        $staffer = staffWithRole('sales_employee', $this->w->a);

        expect($this->svc->canView($this->viewer))->toBeTrue()->and($this->svc->canChange($this->viewer))->toBeFalse()
            ->and($this->svc->canChange($staffer))->toBeTrue()
            ->and(fn () => $this->svc->setQuantity($this->viewer, $cart->id, $item->id, 5))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->svc->addItem($this->viewer, $cart->id, $this->w->v2->id))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->svc->setCoupon($this->viewer, $cart->id, 'SAVE10'))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->svc->discard($this->viewer, $cart->id))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->svc->remind($this->viewer, $cart->id))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->svc->placeForCustomer($this->viewer, $cart->id))->toThrow(AuthorizationException::class)
            ->and($item->fresh()->quantity)->toBe(2)->and($cart->fresh()->status)->toBe('active');
        $this->svc->setQuantity($staffer, $cart->id, $item->id, 3);
        expect($item->fresh()->quantity)->toBe(3);
    });

    it('keeps the page and the changes away from people without orders.view', function () {
        $driver = staffWithRole('delivery_employee', $this->w->a);
        $this->actingAs($driver)->get(route('admin.sales.abandoned'))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('admin.sales.abandoned'))->assertRedirect();
        expect(fn () => $this->svc->page($driver))->toThrow(AuthorizationException::class)->and($this->svc->badge($driver))->toBe(0);
    });

    it('only shows a branch-restricted staff member the carts of their branch', function () {
        $own = abCart($this, ['customer' => User::factory()->create()]);
        $other = abCart($this, ['customer' => User::factory()->create(), 'branch' => $this->w->b->id]);
        $none = abCart($this, ['customer' => User::factory()->create(), 'branch' => null, 'address' => null]);

        expect($this->svc->page($this->mgrA)['rows']->pluck('id')->all())->toBe([$own->id])
            ->and($this->svc->page($this->mgrB)['rows']->pluck('id')->all())->toBe([$other->id])
            ->and($this->svc->page($this->admin)['rows']->pluck('id')->sort()->values()->all())->toBe(collect([$own->id, $other->id, $none->id])->sort()->values()->all())
            ->and($this->svc->stats($this->mgrA)['carts'])->toBe(1)
            ->and($this->svc->page($this->mgrA, ['branch' => $this->w->b->id])['rows'])->toHaveCount(0); // the filter can only narrow, never widen
    });

    it('treats a cart with no branch yet by the delivery zone of its address; with neither it is for company-wide roles only', function () {
        $viaZone = abCart($this, ['customer' => User::factory()->create(), 'branch' => null]); // address in the zone branch A serves
        $addr = $this->w->customer->addresses()->first();
        $addr->update(['delivery_zone_id' => $this->w->zone->id]);
        BranchDeliveryZone::where('branch_id', $this->w->b->id)->update(['is_active' => false]);
        $noSignal = abCart($this, ['customer' => User::factory()->create(), 'branch' => null, 'address' => null]);

        $mgrA = $this->svc->page($this->mgrA)['rows']->pluck('id')->all();
        $mgrB = $this->svc->page($this->mgrB)['rows']->pluck('id')->all();

        expect($mgrA)->toContain($viaZone->id)->not->toContain($noSignal->id)
            ->and($mgrB)->not->toContain($viaZone->id)->not->toContain($noSignal->id)        // B's zone setting is switched off
            ->and($this->svc->page($this->admin)['rows']->pluck('id')->all())->toContain($noSignal->id);
    });

    it('never opens or changes a cart outside the staff member’s scope, however the id arrives', function () {
        $other = abCart($this, ['branch' => $this->w->b->id]);
        $item = $other->items()->first();

        expect(fn () => $this->svc->find($this->mgrA, $other->id))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->svc->setQuantity($this->mgrA, $other->id, $item->id, 9))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->svc->discard($this->mgrA, $other->id))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->svc->placeForCustomer($this->mgrA, $other->id))->toThrow(AuthorizationException::class)
            ->and(fn () => app(StaffOrderService::class)->adoptCart($other, $this->mgrA))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->svc->find($this->mgrA, 'not-a-uuid'))->toThrow(DomainException::class)
            ->and($item->fresh()->quantity)->toBe(2)->and($other->fresh()->status)->toBe('active');
        Livewire::actingAs($this->mgrA)->test(AbandonedCartsIndex::class)->call('openCart', $other->id)->assertSet('showCart', false)->assertSet('cartId', '')->assertDispatched('toast', type: 'error');
        Livewire::actingAs($this->mgrA)->withQueryParams(['cart' => $other->id])->test(AbandonedCartsIndex::class)->assertSet('showCart', false)->assertSet('cartId', '');
    });

    it('counts the menu badge in the staff member’s own scope', function () {
        abCart($this);
        abCart($this, ['customer' => User::factory()->create(), 'branch' => $this->w->b->id]);
        $routes = fn (User $u) => collect(AdminNavigation::for($u))->flatMap(fn ($g) => array_column($g['items'], 'route'));

        expect($this->svc->badge($this->mgrA))->toBe(1)->and($this->svc->badge($this->admin))->toBe(2)
            ->and($routes($this->mgrA))->toContain('admin.sales.abandoned')->and($routes(staffWithRole('delivery_employee', $this->w->a)))->not->toContain('admin.sales.abandoned');
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->assertSee(__('nav.abandoned'));
    });
});

describe('editing a customer’s cart', function () {
    it('changes quantity, adds, removes and handles coupons through the cart rules, with the exact customer quote', function () {
        abPromo();
        $cart = abCart($this);
        $item = $cart->items()->first();
        $svc = $this->svc;

        $svc->setQuantity($this->mgrA, $cart->id, $item->id, 3);
        $svc->addItem($this->mgrA, $cart->id, $this->w->v2->id, 2);
        $svc->setCoupon($this->mgrA, $cart->id, ' save10 ');

        $fresh = $cart->fresh();
        $staff = $svc->present($this->mgrA, $cart->id);
        $customer = abCustomerQuote($this);

        expect($fresh->coupon_code)->toBe('SAVE10')->and($fresh->items()->count())->toBe(2)->and((int) $fresh->items()->sum('quantity'))->toBe(5)
            ->and($staff['quote']->subtotal()->toDecimal())->toBe('3500.00')                                  // 3 x 1000 + 2 x 250
            ->and($staff['quote']->subtotal()->toDecimal())->toBe($customer['totals']['subtotal']['amount'])
            ->and($staff['quote']->discountTotal()->toDecimal())->toBe($customer['totals']['discount']['amount'])
            ->and($staff['quote']->deliveryFeeTotal()->toDecimal())->toBe($customer['totals']['delivery_fee']['amount'])
            ->and($staff['quote']->grandTotal()->toDecimal())->toBe($customer['totals']['grand_total']['amount'])
            ->and($staff['quote']->hash)->toBe($customer['quote_hash'])
            ->and($staff['quote']->itemDiscountTotal()->toDecimal())->toBe('350.00');

        $r = $svc->removeItem($this->mgrA, $cart->id, $item->id);
        $svc->setCoupon($this->mgrA, $cart->id, null);
        $after = $svc->present($this->mgrA, $cart->id);
        $customerAfter = abCustomerQuote($this);

        expect($r['removed'])->toBe(['variant_id' => $this->w->v1->id, 'quantity' => 3])
            ->and($cart->fresh()->coupon_code)->toBeNull()->and($cart->items()->count())->toBe(1)
            ->and($after['quote']->grandTotal()->toDecimal())->toBe($customerAfter['totals']['grand_total']['amount'])->and($after['quote']->grandTotal()->toDecimal())->toBe('515.00')
            ->and($after['quote']->hash)->toBe($customerAfter['quote_hash']);
    });

    it('applies a free-delivery coupon through the real quote and shows the fee struck through in the window', function () {
        abPromo('FREESHIP', ['type' => 'free_delivery', 'value' => '0']);
        $cart = abCart($this, ['variant' => $this->w->v2->id, 'qty' => 1]);
        $this->svc->setCoupon($this->mgrA, $cart->id, 'freeship');
        $q = $this->svc->present($this->mgrA, $cart->id)['quote'];

        expect($q->deliveryFeeTotal()->toDecimal())->toBe('15.00')->and($q->deliveryDiscountTotal()->toDecimal())->toBe('15.00')->and($q->grandTotal()->toDecimal())->toBe('250.00');
        Livewire::actingAs($this->mgrA)->test(AbandonedCartsIndex::class)->call('openCart', $cart->id)
            ->assertSee(__('abandoned_admin.free_with', ['code' => 'FREESHIP']))->assertSee('<s class="me-1 text-ink-400">', false);
    });

    it('does not move the customer’s idle clock, reminder counters or expiry; a customer’s own edit still does', function () {
        $cart = abCart($this, ['extra' => ['abandoned_notifications' => 1, 'abandoned_notified_at' => now()->subHours(3), 'expires_at' => now()->addDays(5)]]);
        $before = $cart->only(['last_activity_at', 'abandoned_notified_at', 'abandoned_notifications', 'expires_at']);
        $item = $cart->items()->first();
        abPromo();

        $this->svc->setQuantity($this->mgrA, $cart->id, $item->id, 4);
        $this->svc->addItem($this->mgrA, $cart->id, $this->w->v2->id);
        $this->svc->setCoupon($this->mgrA, $cart->id, 'SAVE10');
        $this->svc->removeItem($this->mgrA, $cart->id, $cart->items()->where('product_variant_id', $this->w->v2->id)->first()->id);

        expect($cart->fresh()->only(['last_activity_at', 'abandoned_notified_at', 'abandoned_notifications', 'expires_at']))->toEqual($before);

        app(CartService::class)->update($this->w->customer, $item->id, 5); // the customer comes back
        $back = $cart->fresh();
        expect($back->last_activity_at->gt(now()->subMinute()))->toBeTrue()->and($back->abandoned_notifications)->toBe(0)->and($back->abandoned_notified_at)->toBeNull();
    });

    it('refuses what the customer app refuses: too many, unavailable products, products the branch does not sell', function () {
        $cart = abCart($this);
        $item = $cart->items()->first();
        $this->w->p2->update(['status' => 'draft']);
        $bOnly = Product::factory()->create(['price' => '10.00', 'available_in_all_branches' => false]);

        expect(fn () => $this->svc->setQuantity($this->mgrA, $cart->id, $item->id, 51))->toThrow(DomainException::class, __('errors.quantity_invalid'))
            ->and(fn () => $this->svc->addItem($this->mgrA, $cart->id, $this->w->v2->id))->toThrow(DomainException::class, __('errors.item_unavailable_simple'))
            ->and(fn () => $this->svc->addItem($this->mgrA, $cart->id, $bOnly->variants()->first()->id))->toThrow(DomainException::class, __('abandoned_admin.err_item_unavailable_branch'))
            ->and(fn () => $this->svc->addItem($this->mgrA, $cart->id, '00000000-0000-7000-8000-000000000000'))->toThrow(DomainException::class)
            ->and($item->fresh()->quantity)->toBe(2)->and($cart->items()->count())->toBe(1);
    });

    it('flags lines that are out of stock or unavailable, like the customer cart does', function () {
        $cart = abCart($this, ['qty' => 12]); // branch A holds 5 and branch B 5: not even together
        $line = $this->svc->present($this->mgrA, $cart->id)['lines']->first();

        expect($line['issue'])->toBe('insufficient_stock')->and($line['stock'])->toBe(5)->and($line['quantity'])->toBe(12);
        $quote = $this->svc->present($this->mgrA, $cart->id)['quote'];
        expect(collect($quote->errors)->pluck('code'))->toContain('insufficient_stock');
        Livewire::actingAs($this->mgrA)->test(AbandonedCartsIndex::class)->call('openCart', $cart->id)->assertSee(__('abandoned_admin.issue_insufficient_stock', ['count' => 5]));
    });

    it('rejects a coupon the customer cannot use, with the plain reason, and keeps the cart as it was', function () {
        $cart = abCart($this);

        expect(fn () => $this->svc->setCoupon($this->mgrA, $cart->id, 'NOPE'))->toThrow(ValidationException::class);
        expect($cart->fresh()->coupon_code)->toBeNull();
        Livewire::actingAs($this->mgrA)->test(AbandonedCartsIndex::class)->call('openCart', $cart->id)->set('couponInput', 'NOPE')->call('applyCoupon')
            ->assertHasErrors(['couponInput'])->assertSee(__('checkout.coupon_invalid'))
            ->set('couponInput', '')->call('applyCoupon')->assertHasErrors(['couponInput']);
        expect(AuditLog::where('action', 'cart.staff_edited')->count())->toBe(0);
    });

    it('limits edits to 240 a minute per staff member', function () {
        $cart = abCart($this);
        $item = $cart->items()->first();
        foreach (range(1, 240) as $i) {
            RateLimiter::hit('abandoned:edit:'.$this->mgrA->id, 60);
        }

        expect(fn () => $this->svc->setQuantity($this->mgrA, $cart->id, $item->id, 3))->toThrow(DomainException::class, __('abandoned_admin.err_too_many_requests'))
            ->and($item->fresh()->quantity)->toBe(2);
        $this->svc->setQuantity($this->admin, $cart->id, $item->id, 3); // someone else still can
        Livewire::actingAs($this->mgrA)->test(AbandonedCartsIndex::class)->call('openCart', $cart->id)->call('bump', $item->id, 1)
            ->assertDispatched('toast', type: 'error', message: __('abandoned_admin.err_too_many_requests'));
    });

    it('audits every change with what it was and what it became, and never tells the customer', function () {
        $cart = abCart($this);
        $item = $cart->items()->first();
        $this->svc->setQuantity($this->mgrA, $cart->id, $item->id, 6);

        $log = AuditLog::where('action', 'cart.staff_edited')->where('auditable_id', $cart->id)->firstOrFail();
        expect($log->actor_id)->toBe($this->mgrA->id)->and($log->old_values['items'][0]['qty'])->toBe(2)->and($log->new_values['items'][0]['qty'])->toBe(6)
            ->and($log->metadata['change'])->toBe('quantity')->and($log->branch_id)->toBe($this->w->a->id)
            ->and(Notification::where('user_id', $this->w->customer->id)->count())->toBe(0);
    });
});

describe('two people at the same cart', function () {
    it('refuses an edit made on an out-of-date view, saves nothing and says to refresh', function () {
        $cart = abCart($this);
        $item = $cart->items()->first();
        $seen = $this->svc->present($this->mgrA, $cart->id)['stamp'];
        $this->svc->setQuantity($this->admin, $cart->id, $item->id, 7); // a colleague changed it meanwhile

        expect(fn () => $this->svc->setQuantity($this->mgrA, $cart->id, $item->id, 3, $seen))->toThrow(DomainException::class, __('abandoned_admin.err_cart_changed'))
            ->and(fn () => $this->svc->addItem($this->mgrA, $cart->id, $this->w->v2->id, 1, $seen))->toThrow(DomainException::class)
            ->and(fn () => $this->svc->setCoupon($this->mgrA, $cart->id, null, $seen))->toThrow(DomainException::class)
            ->and($item->fresh()->quantity)->toBe(7)->and($cart->items()->count())->toBe(1);
        $now = $this->svc->present($this->mgrA, $cart->id)['stamp'];
        $this->svc->setQuantity($this->mgrA, $cart->id, $item->id, 3, $now);
        expect($item->fresh()->quantity)->toBe(3);
    });

    it('gives the staff window a warning, refreshes it and lets the next click work', function () {
        $cart = abCart($this);
        $item = $cart->items()->first();
        $page = Livewire::actingAs($this->mgrA)->test(AbandonedCartsIndex::class)->call('openCart', $cart->id)->assertSet('showCart', true);
        $this->svc->setQuantity($this->admin, $cart->id, $item->id, 7);

        $page->call('bump', $item->id, 1)->assertDispatched('toast', type: 'warning', message: __('abandoned_admin.err_cart_changed'));
        expect($item->fresh()->quantity)->toBe(7);
        $page->call('bump', $item->id, 1)->assertNotDispatched('toast', type: 'warning');
        expect($item->fresh()->quantity)->toBe(8);
    });

    it('stops at once when the customer is shopping again, in the service and in the window', function () {
        $cart = abCart($this);
        $item = $cart->items()->first();
        $page = Livewire::actingAs($this->mgrA)->test(AbandonedCartsIndex::class)->call('openCart', $cart->id);
        app(CartService::class)->update($this->w->customer, $item->id, 5); // the customer edits the cart right now

        expect(fn () => $this->svc->setQuantity($this->mgrA, $cart->id, $item->id, 1))->toThrow(DomainException::class, __('abandoned_admin.err_cart_active_again'))
            ->and(fn () => $this->svc->discard($this->mgrA, $cart->id))->toThrow(DomainException::class)
            ->and(fn () => $this->svc->remind($this->mgrA, $cart->id))->toThrow(DomainException::class)
            ->and($item->fresh()->quantity)->toBe(5)->and($cart->fresh()->status)->toBe('active');
        $page->call('bump', $item->id, 1)->assertDispatched('toast', type: 'warning')->assertSee(__('abandoned_admin.err_cart_active_again'));
        expect($item->fresh()->quantity)->toBe(5);
    });

    it('locks the cart row for every edit and for the customer’s own edits too', function () {
        $cart = abCart($this);
        $item = $cart->items()->first();
        DB::enableQueryLog();
        $this->svc->setQuantity($this->mgrA, $cart->id, $item->id, 3);
        app(CartService::class)->add($this->w->customer, $this->w->v2->id, 1);
        $locks = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains(strtolower($q), 'from "carts"') && str_contains(strtolower($q), 'for update'));
        DB::disableQueryLog();

        expect($locks->count())->toBeGreaterThanOrEqual(2);
    });
});

describe('discard and undo', function () {
    it('discards with no confirm, stops being listed, and an undo brings it back as it was', function () {
        $cart = abCart($this, ['extra' => ['abandoned_notifications' => 1]]);
        $before = $cart->last_activity_at;

        $this->svc->discard($this->mgrA, $cart->id);
        expect($cart->fresh()->status)->toBe('abandoned')->and($this->svc->page($this->admin)['rows'])->toHaveCount(0)
            ->and(fn () => $this->svc->find($this->admin, $cart->id))->toThrow(DomainException::class, __('abandoned_admin.err_cart_closed'));

        $this->svc->restore($this->mgrA, $cart->id);
        $back = $cart->fresh();
        expect($back->status)->toBe('active')->and($back->last_activity_at->equalTo($before))->toBeTrue()->and($back->abandoned_notifications)->toBe(1)
            ->and($this->svc->page($this->admin)['rows'])->toHaveCount(1)
            ->and(AuditLog::whereIn('action', ['cart.discarded', 'cart.restored'])->pluck('action')->sort()->values()->all())->toBe(['cart.discarded', 'cart.restored']);
    });

    it('stops the automatic reminders for a discarded cart', function () {
        $this->w->customer->forceFill(['marketing_consent_at' => now()])->save();
        Journey::query()->delete();
        app(JourneyService::class)->ensureDefaults();
        Journey::where('key', 'abandoned_cart')->update(['is_enabled' => true]);
        $cart = abCart($this);
        $this->svc->discard($this->mgrA, $cart->id);
        app(JourneyService::class)->tick();

        expect(Notification::where('user_id', $this->w->customer->id)->count())->toBe(0)->and(JourneyDelivery::count())->toBe(0);
    });

    it('replaces an empty new cart on undo but never overwrites one with items', function () {
        $cart = abCart($this);
        $this->svc->discard($this->mgrA, $cart->id);
        $fresh = app(CartService::class)->current($this->w->customer); // the customer opened the app: an empty cart appears
        $this->svc->restore($this->mgrA, $cart->id);
        expect($cart->fresh()->status)->toBe('active')->and(Cart::find($fresh->id))->toBeNull();

        $this->svc->discard($this->mgrA, $cart->id);
        app(CartService::class)->add($this->w->customer, $this->w->v2->id, 1);
        expect(fn () => $this->svc->restore($this->mgrA, $cart->id))->toThrow(DomainException::class, __('abandoned_admin.err_restore_blocked'))
            ->and($cart->fresh()->status)->toBe('abandoned');
    });

    it('does the same from the page: a toast with Undo, no confirm, and the window closes', function () {
        $cart = abCart($this);
        $page = Livewire::actingAs($this->mgrA)->test(AbandonedCartsIndex::class)->call('openCart', $cart->id)
            ->call('discard', $cart->id)->assertSet('showCart', false)->assertSet('cartId', '')
            ->assertDispatched('toast', type: 'success', undoEvent: 'abandoned-undo-discard', undoParams: ['id' => $cart->id]);
        expect($cart->fresh()->status)->toBe('abandoned');
        $page->call('undoDiscard', $cart->id)->assertDispatched('toast', type: 'success', message: __('abandoned_admin.restored'));
        expect($cart->fresh()->status)->toBe('active');
    });

    it('removes a line with Undo that puts the same item and quantity back', function () {
        $cart = abCart($this, ['qty' => 4, 'more' => [$this->w->v2->id => 1]]);
        $item = $cart->items()->where('product_variant_id', $this->w->v1->id)->first();
        $page = Livewire::actingAs($this->mgrA)->test(AbandonedCartsIndex::class)->call('openCart', $cart->id)->call('removeLine', $item->id)
            ->assertDispatched('toast', type: 'success', undoEvent: 'abandoned-undo-remove', undoParams: ['id' => $cart->id, 'variantId' => $this->w->v1->id, 'quantity' => 4]);
        expect($cart->items()->count())->toBe(1);
        $page->call('undoRemove', $cart->id, $this->w->v1->id, 4);

        expect($cart->items()->where('product_variant_id', $this->w->v1->id)->value('quantity'))->toBe(4)->and($cart->fresh()->last_activity_at->lt(now()->subHour()))->toBeTrue();
    });
});

describe('reminders', function () {
    beforeEach(function () {
        // The automatic reminders hold their messages in the quiet hours (22:00 to 08:00 in Qatar): fix the clock at midday so this never depends on when the suite runs.
        $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'Asia/Qatar'));
        $this->w->customer->forceFill(['marketing_consent_at' => now()])->save();
    });

    it('sends the abandoned-cart message, counts it, audits it and leaves the cart idle', function () {
        $cart = abCart($this);
        $idleSince = $cart->last_activity_at;

        $r = $this->svc->remind($this->mgrA, $cart->id);

        $fresh = $cart->fresh();
        expect($r['status'])->toBe('sent')->and($r['channels'])->toContain('in_app')
            ->and($fresh->abandoned_notifications)->toBe(1)->and($fresh->abandoned_notified_at)->not->toBeNull()->and($fresh->last_activity_at->equalTo($idleSince))->toBeTrue()
            ->and(Notification::where('user_id', $this->w->customer->id)->where('event', 'cart.abandoned')->count())->toBe(1)
            ->and(AuditLog::where('action', 'cart.reminder_sent')->where('auditable_id', $cart->id)->where('actor_id', $this->mgrA->id)->exists())->toBeTrue();
    });

    it('counts the automatic reminders on the same counters as the staff ones, and holds a staff reminder back after them', function () {
        $cart = abCart($this);
        app(JourneyService::class)->ensureDefaults();
        Journey::where('key', 'abandoned_cart')->update(['is_enabled' => true]);
        app(JourneyService::class)->tick(); // the first reminder: one step sent on push and the in-app inbox is ONE reminder

        $row = $this->svc->page($this->admin)['rows']->first();
        $summary = AbandonedCartService::reminderSummary($row);

        expect($cart->fresh()->abandoned_notifications)->toBe(1)->and($summary['sent'])->toBe(1)->and($summary['last']->gt(now()->subMinute()))->toBeTrue()
            ->and($this->svc->stats($this->admin)['reminded'])->toBe(1)
            ->and($this->svc->present($this->admin, $cart->id)['reminders']['sent'])->toBe(1)
            ->and($this->svc->remind($this->admin, $cart->id)['status'])->toBe('too_soon');
    });

    it('does not message a customer who has not agreed, and says so', function () {
        $this->w->customer->forceFill(['marketing_consent_at' => null])->save();
        $cart = abCart($this);

        expect($this->svc->remind($this->mgrA, $cart->id)['status'])->toBe('no_consent')
            ->and(Notification::count())->toBe(0)->and($cart->fresh()->abandoned_notifications)->toBe(0)->and(AuditLog::where('action', 'cart.reminder_sent')->exists())->toBeFalse();
        Livewire::actingAs($this->mgrA)->test(AbandonedCartsIndex::class)->call('remind', $cart->id)->assertDispatched('toast', type: 'warning', message: __('abandoned_admin.reminder_no_consent'))
            ->call('openCart', $cart->id)->assertSee(__('abandoned_admin.reminder_no_consent'));
    });

    it('keeps to the frequency limits: the gap between messages and the most per cart', function () {
        $cart = abCart($this);
        $this->svc->remind($this->mgrA, $cart->id);

        $again = $this->svc->remind($this->mgrA, $cart->id);
        expect($again['status'])->toBe('too_soon')->and($again['next_at'])->not->toBeNull()->and($cart->fresh()->abandoned_notifications)->toBe(1);

        $this->travel(30)->hours(); // the gap is over (the limit is counted from what was really sent, so time has to pass, not a counter edited)
        DB::table('notifications')->delete();
        expect($this->svc->remind($this->mgrA, $cart->id)['status'])->toBe('sent');

        $this->travel(30)->hours();
        $limit = $this->svc->remind($this->mgrA, $cart->id);
        expect($limit['status'])->toBe('limit_reached')->and($cart->fresh()->abandoned_notifications)->toBe(2);
    });

    it('does not pile on when a message reached the customer recently by another path', function () {
        $cart = abCart($this);
        $batch = (string) Str::uuid();
        DB::table('customer_message_batches')->insert(['id' => $batch, 'created_by' => $this->admin->id, 'template_name' => 'Manual', 'kind' => 'custom', 'source' => 'single',
            'channels' => json_encode(['whatsapp']), 'content' => json_encode([]), 'status' => 'done', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('customer_messages')->insert(['id' => (string) Str::uuid(), 'batch_id' => $batch, 'user_id' => $this->w->customer->id, 'channel' => 'whatsapp',
            'locale' => 'en', 'status' => 'sent', 'sent_at' => now()->subHour(), 'created_at' => now()->subHour(), 'updated_at' => now()->subHour(), 'body' => 'Hello', 'title' => 'Hi']);

        expect($this->svc->remind($this->mgrA, $cart->id)['status'])->toBe('too_soon');
    });

    it('is rate limited to 20 reminders a minute per staff member', function () {
        $cart = abCart($this);
        foreach (range(1, 20) as $i) {
            RateLimiter::hit('abandoned:remind:'.$this->mgrA->id, 60);
        }

        expect(fn () => $this->svc->remind($this->mgrA, $cart->id))->toThrow(DomainException::class, __('abandoned_admin.err_too_many_requests'));
        expect($this->svc->remind($this->admin, $cart->id)['status'])->toBe('sent'); // another staff member has their own allowance
    });
});

describe('place the order for the customer', function () {
    it('copies the cart into one staff draft (and reuses it), without touching the customer’s own cart', function () {
        abPromo();
        $cart = abCart($this, ['more' => [$this->w->v2->id => 1], 'extra' => ['coupon_code' => 'SAVE10', 'fulfillment_type' => 'delivery']]);
        $orders = app(StaffOrderService::class);

        $draft = $orders->adoptCart($cart, $this->mgrA);
        $again = $this->svc->placeForCustomer($this->mgrA, $cart->id);

        expect($draft->status)->toBe('staff_draft')->and($draft->staff_id)->toBe($this->mgrA->id)->and($draft->user_id)->toBe($this->w->customer->id)
            ->and($draft->branch_id)->toBe($this->w->a->id)->and($draft->address_id)->toBe($this->w->address->id)->and($draft->coupon_code)->toBe('SAVE10')
            ->and($draft->staff_options[StaffOrderService::SOURCE_KEY])->toBe($cart->id)
            ->and($draft->items()->pluck('quantity', 'product_variant_id')->all())->toBe([$this->w->v1->id => 2, $this->w->v2->id => 1])
            ->and($again->id)->toBe($draft->id)
            ->and(Cart::where('status', 'staff_draft')->count())->toBe(1)
            ->and($cart->fresh()->status)->toBe('active')->and($cart->items()->count())->toBe(2)
            ->and(session(StaffOrderService::SESSION_KEY))->toBe($draft->id)
            ->and(AuditLog::where('action', 'cart.adopted')->where('auditable_id', $cart->id)->where('actor_id', $this->mgrA->id)->count())->toBe(1);
    });

    it('ends with the customer’s own cart converted once the order is placed, and it leaves the list', function () {
        $cart = abCart($this);
        $orders = app(StaffOrderService::class);
        $draft = $orders->adoptCart($cart, $this->mgrA);
        $orders->setOptions($this->mgrA, $draft, ['address_id' => $this->w->address->id, 'payment_method' => 'cod']);
        $quote = $orders->quote($this->mgrA, $draft->fresh());

        $res = $orders->place($this->mgrA, $draft->fresh(), $quote->hash, 'abandoned-key-'.str_repeat('b', 12));

        $source = $cart->fresh();
        expect($res['order']->source)->toBe('admin')->and($res['order']->user_id)->toBe($this->w->customer->id)
            ->and($source->status)->toBe('converted')->and($source->converted_order_id)->toBe($res['order']->id)
            ->and($draft->fresh()->status)->toBe('converted')
            ->and($this->svc->page($this->admin)['rows'])->toHaveCount(0)
            ->and(app(CartService::class)->current($this->w->customer)->id)->not->toBe($cart->id);
        // a repeated submit changes nothing
        $orders->place($this->mgrA, $draft->fresh(), $quote->hash, 'abandoned-key-'.str_repeat('b', 12));
        expect(Order::where('user_id', $this->w->customer->id)->count())->toBe(1);
    });

    it('refuses to place a second order when the customer already ordered from that cart', function () {
        $cart = abCart($this);
        $orders = app(StaffOrderService::class);
        $draft = $orders->adoptCart($cart, $this->mgrA);
        $orders->setOptions($this->mgrA, $draft, ['address_id' => $this->w->address->id, 'payment_method' => 'cod']);
        $quote = $orders->quote($this->mgrA, $draft->fresh());

        Cart::whereKey($cart->id)->update(['status' => 'converted']); // the customer ordered on their own

        expect(fn () => $orders->place($this->mgrA, $draft->fresh(), $quote->hash, 'abandoned-key-'.str_repeat('c', 12)))->toThrow(DomainException::class, __('abandoned_admin.err_source_already_ordered'))
            ->and(Order::count())->toBe(0);
    });

    it('does not start from a cart that is no longer open, and respects the 20-draft limit', function () {
        $cart = abCart($this);
        $orders = app(StaffOrderService::class);
        foreach (range(1, StaffOrderService::MAX_DRAFTS) as $i) {
            $orders->create($this->admin, $this->w->a->id);
        }
        expect(fn () => $orders->adoptCart($cart, $this->admin))->toThrow(DomainException::class);
        expect(Cart::where('status', 'staff_draft')->where('staff_options->'.StaffOrderService::SOURCE_KEY, $cart->id)->exists())->toBeFalse();

        Cart::whereKey($cart->id)->update(['status' => 'abandoned']);
        expect(fn () => $orders->adoptCart($cart, $this->mgrA))->toThrow(DomainException::class, __('abandoned_admin.err_cart_not_active'));
    });

    it('stops being linked to the customer’s cart when the staff member switches the draft to another customer', function () {
        $cart = abCart($this);
        $orders = app(StaffOrderService::class);
        $draft = $orders->adoptCart($cart, $this->mgrA);
        $other = User::factory()->create();

        $orders->setCustomer($this->mgrA, $draft, $other->id);

        expect($draft->fresh()->user_id)->toBe($other->id)->and($draft->fresh()->staff_options)->toBeNull()->and($cart->fresh()->status)->toBe('active');
    });

    it('opens the order cart panel at the checkout step from the window', function () {
        $cart = abCart($this);
        Livewire::actingAs($this->mgrA)->test(AbandonedCartsIndex::class)->call('openCart', $cart->id)->call('placeOrder')
            ->assertSet('showCart', false)->assertDispatched('open-order-cart', step: 'checkout')->assertDispatched('order-cart-updated');

        expect(Cart::where('status', 'staff_draft')->where('staff_id', $this->mgrA->id)->count())->toBe(1);
    });
});

describe('the page', function () {
    it('renders the list, the window, the row menu and the footer actions for staff who can change', function () {
        $cart = abCart($this, ['extra' => ['coupon_code' => 'WELCOME']]);
        $page = Livewire::actingAs($this->mgrA)->test(AbandonedCartsIndex::class)
            ->assertSee(__('abandoned_admin.title'))->assertSee($this->w->customer->name)->assertSee('WELCOME')->assertSee(__('abandoned_admin.send_reminder'))
            ->assertSee(__('abandoned_admin.actions_for', ['name' => $this->w->customer->name]))->assertSet('showCart', false);

        $page->call('openCart', $cart->id)->assertSet('showCart', true)->assertSet('cartId', $cart->id)
            ->assertSee(__('abandoned_admin.window_title', ['name' => $this->w->customer->name]))->assertSee(__('abandoned_admin.place_order'))
            ->assertSee(__('abandoned_admin.discard'))->assertSee(__('abandoned_admin.add_item'))->assertSee(__('abandoned_admin.remove'))->assertDontSee(__('abandoned_admin.apply'))
            ->assertSee(__('abandoned_admin.zone', ['number' => '66']))
            ->call('closeCart')->assertSet('showCart', false)->assertSet('cartId', '');
    });

    it('opens from the ?cart= link and keeps the filters in the address bar', function () {
        $cart = abCart($this);
        Livewire::actingAs($this->mgrA)->withQueryParams(['cart' => $cart->id, 'idle' => '1h', 'search' => 'Noor', 'coupon' => true])->test(AbandonedCartsIndex::class)
            ->assertSet('showCart', true)->assertSet('idle', '1h')->assertSet('search', 'Noor')->assertSet('withCoupon', true);
        Livewire::actingAs($this->mgrA)->withQueryParams(['idle' => 'bogus', 'branch' => 'nope'])->test(AbandonedCartsIndex::class)->assertSet('idle', '30m')->assertSet('branch', '');
        $this->actingAs($this->mgrA)->get(route('admin.sales.abandoned', ['cart' => $cart->id]))->assertOk()->assertSee(__('abandoned_admin.window_title', ['name' => $this->w->customer->name]));
    });

    it('shows read-only staff the cart without any change control', function () {
        $cart = abCart($this);
        $page = Livewire::actingAs($this->viewer)->test(AbandonedCartsIndex::class)->call('openCart', $cart->id)->assertSet('showCart', true)
            ->assertSee($this->w->customer->name)->assertDontSee(__('abandoned_admin.place_order'))->assertDontSee(__('abandoned_admin.add_item'))
            ->assertDontSee(__('abandoned_admin.send_reminder'))->assertDontSee(__('abandoned_admin.apply'));
        $page->call('discard', $cart->id)->assertDispatched('toast', type: 'error', message: __('abandoned_admin.err_forbidden'));
        expect($cart->fresh()->status)->toBe('active');
    });

    it('shows what changed when the cart is gone instead of an error page', function () {
        $cart = abCart($this);
        $page = Livewire::actingAs($this->mgrA)->test(AbandonedCartsIndex::class)->call('openCart', $cart->id);
        Cart::whereKey($cart->id)->update(['status' => 'converted']);

        $page->call('$refresh')->assertSee(__('abandoned_admin.err_cart_closed'))->call('closeCart')->assertSet('showCart', false);
    });

    it('searches products by name, adds a variant and lists the cart’s change', function () {
        $cart = abCart($this);
        $name = $this->w->p2->trans('name');
        $page = Livewire::actingAs($this->mgrA)->test(AbandonedCartsIndex::class)->call('openCart', $cart->id)->call('toggleAdd')->assertSet('addOpen', true)
            ->set('addSearch', mb_substr($name, 0, 6))->assertSee($name);
        $page->call('addVariant', $this->w->v2->id)->assertDispatched('toast', type: 'success', message: __('abandoned_admin.line_added'));

        expect($cart->items()->count())->toBe(2)->and($cart->fresh()->last_activity_at->lt(now()->subHour()))->toBeTrue();
    });

    it('has an empty state, a filtered empty state and a clear-filters way out', function () {
        Livewire::actingAs($this->admin)->test(AbandonedCartsIndex::class)->assertSee(__('abandoned_admin.empty'))
            ->set('search', 'nobody')->assertSee(__('abandoned_admin.empty_filtered'))->call('clearFilters')->assertSet('search', '')->assertSee(__('abandoned_admin.empty'));
    });

    it('moves through keyset pages with next and previous, and resets the page when a filter changes', function () {
        foreach (range(1, 27) as $i) {
            abCart($this, ['customer' => User::factory()->create(), 'idle' => 30 + $i]);
        }
        $page = Livewire::actingAs($this->admin)->test(AbandonedCartsIndex::class);
        $next = $page->viewData('next');
        expect($page->viewData('rows'))->toHaveCount(25)->and($next)->not->toBeNull();

        $page->call('nextPage', $next)->assertSet('after', $next);
        expect($page->viewData('rows'))->toHaveCount(2)->and($page->viewData('next'))->toBeNull();
        $page->call('previousPage')->assertSet('after', '');
        expect($page->viewData('rows'))->toHaveCount(25);
        $page->call('nextPage', $next)->call('setIdle', '1h')->assertSet('after', '')->assertSet('trail', []);
    });

    it('escapes everything it prints and keeps the customer’s e-mail off the page', function () {
        $evil = User::factory()->create(['name' => '<script>alert(1)</script>', 'email' => 'secret.person@example.test']);
        $cart = abCart($this, ['customer' => $evil]);
        $page = Livewire::actingAs($this->admin)->test(AbandonedCartsIndex::class)->call('openCart', $cart->id);

        $page->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('secret.person@example.test');
    });

    it('speaks Arabic with real translations and an identical set of messages in both languages', function () {
        $cart = abCart($this);
        app()->setLocale('ar');
        Livewire::actingAs($this->mgrA)->test(AbandonedCartsIndex::class)->assertSee('الطلبات المتروكة')->assertSee('إرسال تذكير')
            ->call('openCart', $cart->id)->assertSee('إنشاء الطلب للعميل')->assertSee('كود الخصم');
        app()->setLocale('en');

        $flat = fn (array $a, string $p = '') => collect($a)->flatMap(fn ($v, $k) => is_array($v) ? $flat($v, $p.$k.'.') : [$p.$k => $v])->all();
        $en = array_keys($flat(require lang_path('en/abandoned_admin.php')));
        $ar = array_keys($flat(require lang_path('ar/abandoned_admin.php')));
        expect($ar)->toEqualCanonicalizing($en);
        foreach (require lang_path('ar/abandoned_admin.php') as $k => $v) {
            expect($v)->not->toBe((require lang_path('en/abandoned_admin.php'))[$k] ?? null, "Arabic text of {$k} is still English");
        }
    });
});
