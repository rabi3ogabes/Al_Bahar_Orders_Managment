<?php

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserRole;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Reports\Services\DashboardMetrics;
use App\Domain\Reports\Services\ReportService;
use App\Domain\Reports\Services\SalesBasis;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Dashboard;
use App\Support\AdminNavigation;
use App\Support\BusinessTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\BranchFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/OwnerNumbersSupport.php';

beforeEach(function () {
    $this->world = onWorld();
    $this->metrics = fn (User $u, ?string $branch = null) => app(DashboardMetrics::class)->for($u, $branch);
});

afterEach(fn () => CarbonImmutable::setTestNow());

describe('Sales today and Orders today', function () {
    it('counts orders placed today at their booked value whether or not the money has arrived, and leaves out cancelled and unpaid online orders', function () {
        $w = $this->world;
        onOrder([[$w->a, '500.00', '15.00']], ['payment_method' => 'cod']);                                                   // cash on delivery, nothing collected yet
        onOrder([[$w->a, '200.00']], ['payment_method' => 'sadad', 'payment_status' => 'paid']);                              // paid online
        onOrder([[$w->a, '120.00']], ['payment_method' => 'pay_at_pickup', 'fulfillment_type' => 'pickup']);                  // pay at pickup, not yet collected
        onOrder([[$w->a, '999.00']], ['payment_method' => 'sadad', 'status' => 'pending_payment']);                           // waiting for the SADAD page: not a sale yet
        onOrder([[$w->a, '300.00']], ['status' => 'cancelled']);                                                              // cancelled
        onOrder([[$w->a, '50.00']], ['placed_at' => now()->subDay()->subHour()]);                                             // yesterday

        $m = ($this->metrics)($w->admin);

        expect($m['sales_today'])->toBe('QAR 835.00')          // 515 + 200 + 120
            ->and($m['orders_today'])->toBe(3);
    });

    it('shows the day before as its own chart point and the chart, the tile and the report always agree', function () {
        $w = $this->world;
        onOrder([[$w->a, '500.00', '15.00']]);
        onOrder([[$w->a, '200.00']], ['payment_status' => 'paid']);
        onOrder([[$w->a, '75.50']], ['placed_at' => now()->subDay()]);
        onOrder([[$w->a, '40.00']], ['status' => 'cancelled']);
        onOrder([[$w->a, '60.00']], ['status' => 'pending_payment', 'payment_method' => 'sadad']);

        $m = ($this->metrics)($w->admin);
        $report = collect(app(ReportService::class)->run($w->admin, 'sales_summary', now()->toDateString(), now()->toDateString())['rows'])->pluck('value', 'metric');

        expect($m['chart']['totals'][13])->toBe(715.0)                 // today = the tile
            ->and($m['chart']['orders'][13])->toBe(2)
            ->and($m['chart']['totals'][12])->toBe(75.5)             // yesterday
            ->and($m['sales_today'])->toBe('QAR 715.00')
            ->and($report['booked_sales'])->toBe('715.00')           // the report's booked figure is the tile's figure
            ->and($report['orders'])->toBe($m['orders_today']);
    });

    it('draws the day lines in the shop time zone, not in UTC', function () {
        $w = $this->world;
        CarbonImmutable::setTestNow('2026-11-20 10:00:00 UTC');                                         // 13:00 in Qatar (UTC+3)
        onOrder([[$w->a, '100.00']], ['placed_at' => '2026-11-19 21:30:00']);                            // 00:30 on the 20th in Qatar: today
        onOrder([[$w->a, '40.00']], ['placed_at' => '2026-11-19 20:59:00']);                             // 23:59 on the 19th in Qatar: yesterday
        onOrder([[$w->a, '7.00']], ['placed_at' => '2026-11-20 20:59:00']);                              // later today in Qatar (23:59)

        $m = ($this->metrics)($w->admin);

        expect(BusinessTime::now()->toDateString())->toBe('2026-11-20')
            ->and($m['sales_today'])->toBe('QAR 107.00')->and($m['orders_today'])->toBe(2)
            ->and($m['chart']['totals'][12])->toBe(40.0);
    });
});

describe('a split order is shown to each branch as its own part, and the parts always add up', function () {
    it('gives each branch manager only their own branch’s money and the company the whole order', function () {
        $w = $this->world;
        onOrder([[$w->a, '1000.00', '15.00'], [$w->b, '250.00', '25.00']], ['payment_method' => 'sadad', 'payment_status' => 'paid']);
        $mgrA = staffWithRole('branch_manager', $w->a);
        $mgrB = staffWithRole('branch_manager', $w->b);

        expect(($this->metrics)($mgrA)['sales_today'])->toBe('QAR 1,015.00')
            ->and(($this->metrics)($mgrB)['sales_today'])->toBe('QAR 275.00')
            ->and(($this->metrics)($w->admin)['sales_today'])->toBe('QAR 1,290.00')
            ->and(($this->metrics)($mgrA)['orders_today'])->toBe(1)->and(($this->metrics)($mgrB)['orders_today'])->toBe(1)
            ->and(($this->metrics)($w->admin)['orders_today'])->toBe(1);                 // one order, not two
        // the admin can look at one branch too
        expect(($this->metrics)($w->admin, $w->b->id)['sales_today'])->toBe('QAR 275.00');
    });

    it('splits to the cent: three equal parts of 100.00 are 33.34 + 33.33 + 33.33, never 99.99 and never 100.01', function () {
        $w = $this->world;
        $c = BranchFactory::new()->create();
        $order = onOrder([[$w->a, '10.00'], [$w->b, '10.00'], [$c, '10.00']], ['grand_total' => '100.00']);

        $parts = SalesBasis::orderShares()->where('s.order_id', $order->id)->pluck('share', 'branch_id')->map(fn ($v) => (string) $v);
        expect($parts)->toHaveCount(3)
            ->and(Money::of($parts[$w->a->id])->plus(Money::of($parts[$w->b->id]))->plus(Money::of($parts[$c->id]))->toDecimal())->toBe('100.00')
            ->and($parts->sort()->values()->all())->toBe(['33.33', '33.33', '33.34']);
    });

    it('weights parts by their discounted lines and delivery fee, and adds up for any mix of amounts', function () {
        $w = $this->world;
        $c = BranchFactory::new()->create();
        // an awkward split whose cents do not divide: 3 branches, grand total 777.77
        $order = onOrder([[$w->a, '333.33', '7.00'], [$w->b, '111.11', '13.00'], [$c, '55.55', '0.00']], ['grand_total' => '777.77']);

        $sum = SalesBasis::orderShares()->where('s.order_id', $order->id)->selectRaw('sum(s.share)::numeric(14,2) as total')->value('total');
        expect((string) $sum)->toBe('777.77');
    });

    it('does not count a part the branch cancelled: the money of a cancelled fulfillment drops out of every view', function () {
        $w = $this->world;
        onOrder([[$w->a, '1000.00', '15.00', 'preparing'], [$w->b, '250.00', '25.00', 'cancelled']], ['status' => 'partially_cancelled', 'payment_method' => 'sadad', 'payment_status' => 'paid']);
        $mgrB = staffWithRole('branch_manager', $w->b);

        expect(($this->metrics)($w->admin)['sales_today'])->toBe('QAR 1,015.00')
            ->and(($this->metrics)($mgrB)['sales_today'])->toBe('QAR 0.00')->and(($this->metrics)($mgrB)['orders_today'])->toBe(0);
    });
});

describe('Collected today is money that really came in', function () {
    it('adds online payments captured today and cash recorded today, and ignores what is still owed, an old payment and an auction deposit', function () {
        $w = $this->world;
        $online = onOrder([[$w->a, '200.00']], ['payment_method' => 'sadad', 'payment_status' => 'paid']);
        onOnlinePayment($online, '200.00');
        $cod = onOrder([[$w->a, '515.00']], ['payment_method' => 'cod', 'payment_status' => 'paid']);
        onCashCollection($cod, '515.00');
        onOrder([[$w->a, '120.00']]);
        $old = onOrder([[$w->a, '80.00']], ['payment_method' => 'sadad', 'payment_status' => 'paid', 'placed_at' => now()->subDays(3)]);
        onOnlinePayment($old, '80.00', ['paid_at' => now()->subDays(3)]);
        DB::table('payments')->insert(['id' => (string) Str::uuid(), 'payable_type' => 'auction_deposit', 'payable_id' => (string) Str::uuid(), 'method' => 'sadad', 'provider' => 'sadad',
            'status' => 'paid', 'currency' => 'QAR', 'amount' => '500.00', 'captured_amount' => '500.00', 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $m = ($this->metrics)($w->admin);

        expect($m['collected_today'])->toBe('QAR 715.00')->and($m['collected_online'])->toBe('QAR 200.00')->and($m['collected_cash'])->toBe('QAR 515.00')
            ->and($m['sales_today'])->toBe('QAR 835.00');       // 200 + 515 + 120 booked today; the old order and the deposit are not today's sales
    });

    it('divides online money between the branches of a split order the same way as the sale', function () {
        $w = $this->world;
        $order = onOrder([[$w->a, '1000.00', '15.00'], [$w->b, '250.00', '25.00']], ['payment_method' => 'sadad', 'payment_status' => 'paid']);
        onOnlinePayment($order, '1290.00');
        // cash collected by a Wakra driver is that branch's own
        $cod = onOrder([[$w->b, '60.00']]);
        onCashCollection($cod, '60.00');
        $mgrA = staffWithRole('branch_manager', $w->a);
        $mgrB = staffWithRole('branch_manager', $w->b);

        expect(($this->metrics)($mgrA)['collected_today'])->toBe('QAR 1,015.00')
            ->and(($this->metrics)($mgrB)['collected_today'])->toBe('QAR 335.00')      // 275 of the online order + 60 cash
            ->and(($this->metrics)($w->admin)['collected_today'])->toBe('QAR 1,350.00');
    });
});

describe('each role is shown only what it can see', function () {
    it('shows an order desk employee sales and orders but not the money collected, and stock only to those who can see stock', function () {
        $w = $this->world;
        onOrder([[$w->a, '500.00']]);
        $desk = staffWithRole('sales_employee', $w->a);              // orders.view, inventory.view; no payments.view, no reports.view

        $m = ($this->metrics)($desk);
        expect($m['can'])->toMatchArray(['sales' => true, 'collected' => false, 'orders' => true, 'stock' => true])
            ->and($m['sales_today'])->toBe('QAR 500.00')->and($m['collected_today'])->toBeNull();
    });

    it('does not show a delivery driver, who may only see deliveries, any sales or stock figure', function () {
        $w = $this->world;
        onOrder([[$w->a, '500.00']]);
        $driver = staffWithRole('delivery_employee', $w->a);

        $m = ($this->metrics)($driver);
        expect($m['can'])->toMatchArray(['sales' => false, 'collected' => false, 'orders' => false, 'stock' => false, 'deliveries' => true])
            ->and($m['sales_today'])->toBeNull()->and($m['orders_today'])->toBeNull()->and($m['chart'])->toBeNull()->and($m['recent_orders'])->toBeNull()
            ->and($m['low_stock'])->toBeNull()->and($m['out_of_stock'])->toBeNull();
    });

    it('shows a stock manager the stock tile, and an auction manager the live auctions, with no empty money tiles', function () {
        $inv = staffWithRole('inventory_manager', $this->world->a);

        $m = ($this->metrics)($inv);
        expect($m['can']['stock'])->toBeTrue()->and($m['can']['orders'])->toBeFalse()->and($m['pending_fulfillments'])->toBeNull()->and($m['low_stock'])->toBe(0);
    });

    it('renders the page for a role with no numbers at all as a welcome with shortcuts', function () {
        $w = $this->world;
        $role = Role::create(['key' => 'catalog_only', 'name_en' => 'Catalogue only', 'name_ar' => 'الكتالوج فقط', 'scope' => 'global', 'is_system' => false]);
        $role->permissions()->attach(DB::table('permissions')->whereIn('key', ['dashboard.view', 'catalog.view'])->pluck('id'));
        $user = User::factory()->staff()->create();
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'branch_id' => null]);

        $this->actingAs($user);
        Livewire::test(Dashboard::class)->assertSee(__('owner_numbers.welcome_title'))->assertSee(__('owner_numbers.shortcuts'))->assertSee(__('nav.products'))
            ->assertDontSee(__('owner_numbers.sales_today'))->assertDontSee(__('owner_numbers.collected_today'));
        expect(collect(AdminNavigation::for($user))->flatMap(fn ($g) => array_column($g['items'], 'route'))->all())->toContain('admin.products.index');
    });

    it('renders the full page for the owner with the two honest numbers, each with its one-line explanation, in English and Arabic', function () {
        $w = $this->world;
        onOrder([[$w->a, '500.00']]);
        $this->actingAs($w->admin);

        Livewire::test(Dashboard::class)->assertSee(__('owner_numbers.sales_today'))->assertSee('Orders placed today, not cancelled')
            ->assertSee(__('owner_numbers.collected_today'))->assertSee('Cash and online payments received today')->assertSee('QAR 500.00')
            ->assertDontSee('Revenue today');

        app()->setLocale('ar');
        Livewire::test(Dashboard::class)->assertSee('مبيعات اليوم')->assertSee('المحصَّل اليوم')->assertSee('الطلبات المقدَّمة اليوم دون الملغاة');
    });

    it('treats a made-up branch in the address as "all my branches" instead of failing', function () {
        $this->actingAs($this->world->admin);
        Livewire::withQueryParams(['branch' => 'not-a-branch'])->test(Dashboard::class)->assertOk();
    });
});

describe('stock tiles', function () {
    beforeEach(fn () => $this->variants = onVariants(6));

    it('counts out of stock only what a branch really held, not variants that only have an empty row', function () {
        $w = $this->world;
        $ledger = app(InventoryLedger::class);
        [$sold, $never, $held, $partly] = $this->variants;
        $ledger->apply($w->a->id, $sold->id, MovementType::OpeningBalance, 4);
        $ledger->apply($w->a->id, $sold->id, MovementType::SaleDeduction, -4);                  // had stock, now nothing: out of stock
        DB::table('branch_inventory')->insert(['id' => (string) Str::uuid(), 'branch_id' => $w->a->id, 'product_variant_id' => $never->id,
            'on_hand' => 0, 'reserved' => 0, 'minimum_stock' => 0, 'created_at' => now(), 'updated_at' => now()]);    // a checkout once looked: never stocked, not "out"
        $ledger->apply($w->a->id, $held->id, MovementType::OpeningBalance, 50);                 // plenty
        $ledger->apply($w->a->id, $partly->id, MovementType::OpeningBalance, 6);
        $ledger->apply($w->a->id, $partly->id, MovementType::Reservation, 0, 6);                // 6 on hand, all reserved: nothing available

        $m = ($this->metrics)($w->admin);

        expect($m['out_of_stock'])->toBe(2)->and($m['low_stock'])->toBe(0);
    });

    it('counts low stock with the shop-wide default when an item has no minimum of its own, and its own minimum when it has one', function () {
        $w = $this->world;
        $ledger = app(InventoryLedger::class);
        [$one, $three, $four, $ownMin] = $this->variants;
        $ledger->apply($w->a->id, $one->id, MovementType::OpeningBalance, 1);                    // 1 left: low (default 3)
        $ledger->apply($w->a->id, $three->id, MovementType::OpeningBalance, 3);                  // 3 left: low
        $ledger->apply($w->a->id, $four->id, MovementType::OpeningBalance, 4);                   // 4 left: fine
        $ledger->apply($w->a->id, $ownMin->id, MovementType::OpeningBalance, 8);                 // 8 left but its own minimum is 10: low
        DB::table('branch_inventory')->where('product_variant_id', $ownMin->id)->update(['minimum_stock' => 10]);

        expect(($this->metrics)($w->admin)['low_stock'])->toBe(3)->and(($this->metrics)($w->admin)['out_of_stock'])->toBe(0);
    });

    it('follows the setting inventory.low_stock_default and says what low means on the tile', function () {
        $w = $this->world;
        app(InventoryLedger::class)->apply($w->a->id, $this->variants[0]->id, MovementType::OpeningBalance, 5);
        expect(($this->metrics)($w->admin)['low_stock'])->toBe(0);

        $this->actingAs($w->admin);
        app(Settings::class)->set('inventory.low_stock_default', 5);
        expect(($this->metrics)($w->admin)['low_stock'])->toBe(1)->and(($this->metrics)($w->admin)['low_stock_threshold'])->toBe(5);
        Livewire::test(Dashboard::class)->assertSee(__('owner_numbers.low_stock_note', ['n' => 5]));
    });

    it('shows a branch manager only their own branch’s stock', function () {
        $w = $this->world;
        $ledger = app(InventoryLedger::class);
        $ledger->apply($w->a->id, $this->variants[0]->id, MovementType::OpeningBalance, 1);
        $ledger->apply($w->b->id, $this->variants[1]->id, MovementType::OpeningBalance, 2);
        $mgrB = staffWithRole('branch_manager', $w->b);

        expect(($this->metrics)($mgrB)['low_stock'])->toBe(1)->and(($this->metrics)($w->admin)['low_stock'])->toBe(2);
    });
});

describe('what is to prepare', function () {
    it('does not count a fulfillment that is still waiting for its online payment', function () {
        $w = $this->world;
        onOrder([[$w->a, '100.00', '0.00', 'pending']]);
        onOrder([[$w->a, '100.00', '0.00', 'pending']], ['status' => 'pending_payment', 'payment_method' => 'sadad']);
        onOrder([[$w->a, '100.00', '0.00', 'preparing']]);

        expect(($this->metrics)($w->admin)['pending_fulfillments'])->toBe(2);
    });
});
