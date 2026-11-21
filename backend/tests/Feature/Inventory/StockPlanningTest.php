<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\BranchScope;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\BranchInventory;
use App\Domain\Inventory\Models\PurchaseOrder;
use App\Domain\Inventory\Models\Supplier;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Inventory\Services\StockPlanning;
use App\Domain\Inventory\Services\StockPlanningSettings;
use App\Domain\Marketing\Models\Wishlist;
use App\Domain\Marketing\Models\WishlistItem;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Inventory\StockPlanningIndex;
use App\Livewire\Admin\Organization\SettingsIndex;
use App\Support\AdminNavigation;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/../Operations/OpsSupport.php';
require_once __DIR__.'/StockPlanningSupport.php';

beforeEach(function () {
    $this->travelTo(qatar('2026-11-19 12:00'));
    $this->a = Branch::factory()->create(['code' => 'DOH']);
    $this->b = Branch::factory()->create(['code' => 'WKR']);
    $this->planning = app(StockPlanning::class);
    $this->ledger = app(InventoryLedger::class);
    $this->row = fn ($branch, $variant, string $lane = 'all') => planRowOf($branch, $variant, $lane);
});

describe('the numbers', function () {
    it('works out demand, days of cover, reorder point and the suggested order from the last 30 days of sales', function () {
        $v = planVariant();
        planStock($this->a, $v, 12);
        foreach ([5, 15, 25] as $daysAgo) {
            planSale($this->a, $v, 10, $daysAgo);
        }
        planSale($this->a, $v, 10, 40); // older than the window: not counted

        $r = ($this->row)($this->a, $v);
        expect($r->unitsSold)->toBe(30)->and($r->dailyDemand)->toBe(1.0)->and($r->available)->toBe(12)->and($r->coverDays)->toBe(12.0)
            ->and($r->reorderPoint)->toBe(21)             // 1 a day x (14 days to deliver + 7 safety days)
            ->and($r->suggested)->toBe(39)                // up to 51 days of sales (14 + 7 + 30), less the 12 available
            ->and($r->isLow)->toBeTrue()->and($r->isReorder)->toBeTrue()->and($r->isOut)->toBeFalse()->and($r->lane)->toBe('low')
            ->and($r->oneOffOrder)->toBeFalse()->and($r->littleHistory)->toBeFalse();
    });

    it('follows the shop-wide numbers of Settings > Stock', function () {
        $v = planVariant();
        planStock($this->a, $v, 12);
        planSale($this->a, $v, 30, 10); // 1 a day over a 30 day window
        $settings = app(Settings::class);
        $settings->set('stock_planning.lead_time_days', 30);
        $settings->set('stock_planning.safety_days', 10);
        $settings->set('stock_planning.cycle_days', 20);

        $r = ($this->row)($this->a, $v);
        expect($r->reorderPoint)->toBe(40)->and($r->suggested)->toBe(68); // 1 x (30 + 10); up to 60 days of sales, but never less than twice the reorder point (80), less the 12 available

        $settings->set('stock_planning.demand_window_days', 60);
        expect(StockPlanningSettings::all())->toMatchArray(['window_days' => 60, 'lead_days' => 30, 'safety_days' => 10, 'cycle_days' => 20]);
        expect(($this->row)($this->a, $v)->dailyDemand)->toBe(0.5); // the same 30 units over 60 days
    });

    it('pulls a hand-edited setting back into its safe range', function () {
        app(Settings::class)->set('stock_planning.demand_window_days', 0);
        app(Settings::class)->set('stock_planning.lead_time_days', 'abc');
        app(Settings::class)->set('stock_planning.slow_cover_days', 99999);
        expect(StockPlanningSettings::all())->toMatchArray(['window_days' => 7, 'lead_days' => 14, 'slow_days' => 1000]);
    });

    it('leaves out orders that are cancelled, waiting for payment, in a cancelled part, in another branch, and what was cancelled or returned from a line', function () {
        $v = planVariant();
        planStock($this->a, $v, 50);
        planSale($this->a, $v, 4, 3);                                                                  // counted: 4
        planSale($this->a, $v, 9, 3, ['status' => 'cancelled']);
        planSale($this->a, $v, 9, 3, ['status' => 'pending_payment', 'payment_method' => 'sadad']);
        $o = planSale($this->a, $v, 9, 3);
        $o->fulfillments->first()->update(['status' => 'cancelled']);
        planSale($this->b, $v, 9, 3);                                                                  // sold at the other branch
        planSale($this->a, $v, 10, 2, [], ['quantity_cancelled' => 3, 'quantity_handed_over' => 5, 'quantity_returned' => 2]); // 10 - 3 - 2 = 5

        expect(($this->row)($this->a, $v)->unitsSold)->toBe(9);                                          // 4 + 5
    });

    it('divides by the days the item could be sold: a new item counts from its first day, never fewer than seven, and out-of-stock days are left out', function () {
        $new = planVariant();
        planStock($this->a, $new, 20, daysAgo: 2);
        planSale($this->a, $new, 4, 1);
        planSale($this->a, $new, 4, 1);
        $r = ($this->row)($this->a, $new);
        expect($r->littleHistory)->toBeTrue()->and($r->sellingDays)->toBe(7)->and($r->dailyDemand)->toBe(round(8 / 7, 3)); // one busy day is not a trend

        $old = planVariant();
        planStock($this->a, $old, 6);
        planSale($this->a, $old, 6, 20);
        $this->ledger->apply($this->a->id, $old->id, MovementType::AdjustmentOut, -6, reason: 'all gone');
        $this->travelTo(qatar('2026-11-29 12:00')); // out for ten days
        $r = ($this->row)($this->a, $old);
        expect($r->isOut)->toBeTrue()->and($r->sellingDays)->toBe(20)->and($r->dailyDemand)->toBe(0.3); // 6 units over the 20 days it could be sold (30 - 10 out)
    });

    it('counts one big order that is more than half of the sales like an ordinary order, and says so', function () {
        $v = planVariant();
        planStock($this->a, $v, 100);
        planSale($this->a, $v, 20, 3);
        planSale($this->a, $v, 1, 5);
        planSale($this->a, $v, 1, 8);
        $r = ($this->row)($this->a, $v);
        expect($r->unitsSold)->toBe(22)->and($r->oneOffOrder)->toBeTrue()->and($r->dailyDemand)->toBe(0.1); // 22 - 20 + the 1 an ordinary order is = 3 over 30 days

        $small = planVariant();   // five units in one order is just a slow week, not a spike
        planStock($this->a, $small, 100);
        planSale($this->a, $small, 5, 3);
        expect(($this->row)($this->a, $small)->oneOffOrder)->toBeFalse();

        $single = planVariant();  // one order is all there is to compare with
        planStock($this->a, $single, 100);
        planSale($this->a, $single, 20, 3);
        expect(($this->row)($this->a, $single)->oneOffOrder)->toBeFalse()->and(($this->row)($this->a, $single)->unitsSold)->toBe(20);
    });

    it('says nothing is selling for an item with no sales, and calls it slow only after a whole window on the shelf', function () {
        $old = planVariant();
        planStock($this->a, $old, 10);
        $r = ($this->row)($this->a, $old);
        expect($r->unitsSold)->toBe(0)->and($r->dailyDemand)->toBe(0.0)->and($r->coverDays)->toBeNull()->and($r->isSlow)->toBeTrue()->and($r->lane)->toBe('slow')
            ->and($r->suggested)->toBe(0);

        $new = planVariant();
        planStock($this->a, $new, 10, daysAgo: 3);
        expect(($this->row)($this->a, $new)->isSlow)->toBeFalse()->and(($this->row)($this->a, $new)->lane)->toBe('ok');
    });

    it('calls an item slow when it holds more than 120 days of stock', function () {
        $v = planVariant();
        planStock($this->a, $v, 300);
        planSale($this->a, $v, 30, 10); // 1 a day: 300 days of stock
        expect(($this->row)($this->a, $v)->isSlow)->toBeTrue()->and(($this->row)($this->a, $v)->coverDays)->toBe(300.0);
    });

    it('never plans below the item\'s own minimum stock', function () {
        $v = planVariant();
        planStock($this->a, $v, 15);
        BranchInventory::where('branch_id', $this->a->id)->update(['minimum_stock' => 20]);
        $r = ($this->row)($this->a, $v);
        expect($r->reorderPoint)->toBe(20)->and($r->isReorder)->toBeTrue()->and($r->suggested)->toBe(25); // up to double the reorder point
    });

    it('lets one item replace the lead time, the safety days, the reorder point and the order quantity', function () {
        $v = planVariant();
        planStock($this->a, $v, 30);
        planSale($this->a, $v, 30, 10);
        $inv = BranchInventory::where('branch_id', $this->a->id)->where('product_variant_id', $v->id);
        $inv->update(['lead_time_days' => 30, 'safety_days' => 0]);
        $r = ($this->row)($this->a, $v);
        expect($r->reorderPoint)->toBe(30)->and($r->leadFixed)->toBeTrue()->and($r->safetyFixed)->toBeTrue()->and($r->isReorder)->toBeTrue();

        $inv->update(['reorder_point' => 50, 'reorder_quantity' => 100]);
        $r = ($this->row)($this->a, $v);
        expect($r->reorderPoint)->toBe(50)->and($r->reorderPointFixed)->toBeTrue()->and($r->suggested)->toBe(100)->and($r->suggestedFixed)->toBeTrue();
    });

    it('subtracts what is already on order, and stops asking when the order covers it', function () {
        $v = planVariant();
        planStock($this->a, $v, 12);
        planSale($this->a, $v, 30, 10);
        $before = ($this->row)($this->a, $v);
        planPurchase($this->a, $v, 20, 'approved', received: 5);   // 15 still to come
        planPurchase($this->a, $v, 500, 'draft');                  // a draft is not ordered
        planPurchase($this->a, $v, 500, 'cancelled');
        $r = ($this->row)($this->a, $v);
        expect($r->incoming)->toBe(15)->and($r->suggested)->toBe($before->suggested - 15)->and($r->incomingDate)->not->toBeNull();

        planPurchase($this->a, $v, 100, 'partially_received', received: 10);
        $r = ($this->row)($this->a, $v);
        expect($r->incoming)->toBe(105)->and($r->suggested)->toBe(0)->and($r->isReorder)->toBeFalse()->and($r->isLow)->toBeFalse();
        expect(collect($this->planning->rows(BranchScope::all(), 'order'))->contains(fn ($x) => $x->variantId === $v->id))->toBeFalse();
    });
});

describe('what is out of stock, and who is waiting', function () {
    it('keeps "out since" in the ledger: set when the last unit goes, kept while it stays out, cleared on restock', function () {
        $v = planVariant();
        planStock($this->a, $v, 4);
        $inv = fn () => BranchInventory::where('branch_id', $this->a->id)->where('product_variant_id', $v->id)->firstOrFail();
        expect($inv()->out_since)->toBeNull();

        $this->ledger->apply($this->a->id, $v->id, MovementType::AdjustmentOut, -4, reason: 'sold out');
        $started = $inv()->out_since;
        expect($started)->not->toBeNull();

        $this->travelTo(qatar('2026-11-22 12:00'));
        expect(($this->row)($this->a, $v)->daysOut())->toBe(3)->and(($this->row)($this->a, $v)->isOut)->toBeTrue();

        $this->ledger->apply($this->a->id, $v->id, MovementType::AdjustmentIn, 2, reason: 'found two');
        expect($inv()->out_since)->toBeNull()->and(($this->row)($this->a, $v)->isOut)->toBeFalse();

        $this->ledger->apply($this->a->id, $v->id, MovementType::AdjustmentOut, -2, reason: 'out again');
        expect($inv()->out_since->gt($started))->toBeTrue(); // a new spell, counted from now
    });

    it('treats reserved units as not available, and a branch that never held the item as not out', function () {
        $v = planVariant();
        planStock($this->a, $v, 3);
        $this->ledger->apply($this->a->id, $v->id, MovementType::Reservation, 0, 3, 'order_item', 'x');
        expect(($this->row)($this->a, $v)->isOut)->toBeTrue()->and(($this->row)($this->a, $v)->available)->toBe(0);
        expect(($this->row)($this->b, $v))->toBeNull(); // the other branch never held it: it is not "out" there
        $this->ledger->lock($this->b->id, $v->id); // a checkout once looked at it: a stock row exists with nothing ever in it
        expect(($this->row)($this->b, $v))->toBeNull();
    });

    it('shows the customers waiting for an item next to its stock-out, once each, and the wish lists that hold it', function () {
        $v = planVariant();
        planStock($this->a, $v, 2);
        $this->ledger->apply($this->a->id, $v->id, MovementType::AdjustmentOut, -2, reason: 'sold out');
        planWaiting($v, 3);                                                   // asked for any branch
        planWaiting($v, 2, $this->a);                                         // asked for this branch
        planWaiting($v, 4, $this->b);                                         // asked for the other branch: not waiting here
        planWaiting($v, 1, null, ['notified_at' => now()]);                   // already told
        planWaiting($v, 1, null, ['expires_at' => now()->subDay()]);          // forgotten
        foreach (range(1, 2) as $_) {
            $w = Wishlist::create(['user_id' => User::factory()->create()->id, 'name' => 'Default']);
            WishlistItem::create(['wishlist_id' => $w->id, 'product_id' => $v->product_id, 'product_variant_id' => null]);
        }

        $r = ($this->row)($this->a, $v);
        expect($r->waiting)->toBe(5)->and($r->lane)->toBe('out');
        expect($this->planning->counts(BranchScope::all())['customers'])->toBe(5)
            ->and($this->planning->wishlistCounts([$v->id]))->toBe([$v->id => 2])
            ->and($this->planning->wishlistCounts([]))->toBe([]);
        expect($r->suggested)->toBeGreaterThanOrEqual(5); // at least one for every customer who asked
    });

    it('lists the stock-outs most urgent first: more customers waiting, then the longest out; then the fewest days of cover', function () {
        [$one, $three, $older] = [planVariant(), planVariant(), planVariant()];
        foreach ([$one, $three, $older] as $v) {
            planStock($this->a, $v, 2);
        }
        $this->ledger->apply($this->a->id, $older->id, MovementType::AdjustmentOut, -2, reason: 'sold out');
        $this->travelTo(qatar('2026-11-21 12:00'));
        $this->ledger->apply($this->a->id, $one->id, MovementType::AdjustmentOut, -2, reason: 'sold out');
        $this->ledger->apply($this->a->id, $three->id, MovementType::AdjustmentOut, -2, reason: 'sold out');
        planWaiting($one, 1);
        planWaiting($older, 1);
        planWaiting($three, 3);
        $lowFast = planVariant();
        $lowSlow = planVariant();
        foreach ([[$lowFast, 6], [$lowSlow, 12]] as [$v, $qty]) {
            planStock($this->a, $v, $qty);
            planSale($this->a, $v, 30, 10);
        }

        $order = array_map(fn ($r) => $r->variantId, $this->planning->rows(BranchScope::all(), 'order'));
        expect($order)->toBe([$three->id, $older->id, $one->id, $lowFast->id, $lowSlow->id]);
    });

    it('shows a stock-out nobody is waiting for and nothing sold of, but does not ask to order it', function () {
        $v = planVariant();
        planStock($this->a, $v, 2);
        $this->ledger->apply($this->a->id, $v->id, MovementType::AdjustmentOut, -2, reason: 'sold out');
        $r = ($this->row)($this->a, $v);
        expect($r->isOut)->toBeTrue()->and($r->suggested)->toBe(0);
        expect(collect($this->planning->rows(BranchScope::all(), 'order'))->pluck('variantId')->all())->not->toContain($v->id)
            ->and(collect($this->planning->rows(BranchScope::all(), 'out'))->pluck('variantId')->all())->toContain($v->id);
    });

    it('counts every lane once and finds the item by SKU, barcode or name', function () {
        $out = planVariant(['sku' => 'SOFA-RED']);
        $slow = planVariant();
        $ok = planVariant();
        planStock($this->a, $out, 1);
        $this->ledger->apply($this->a->id, $out->id, MovementType::AdjustmentOut, -1, reason: 'sold out');
        planWaiting($out, 1);
        planStock($this->a, $slow, 5);
        planStock($this->a, $ok, 5, daysAgo: 2);
        $c = $this->planning->counts(BranchScope::all());
        expect($c)->toMatchArray(['out' => 1, 'slow' => 1, 'low' => 0, 'reorder' => 0, 'all' => 3, 'order' => 1]);
        expect($this->planning->counts(BranchScope::all(), 'SOFA-RED')['all'])->toBe(1)
            ->and($this->planning->counts(BranchScope::all(), 'nothing-like-this')['all'])->toBe(0)
            ->and(count($this->planning->rows(BranchScope::all(), 'all', 'sofa-red')))->toBe(1);
        $name = $slow->product->trans('name');
        expect(count($this->planning->rows(BranchScope::all(), 'all', mb_substr($name, 0, 6))))->toBeGreaterThanOrEqual(1);
    });
});

describe('who sees what', function () {
    it('shows a branch-limited manager only their branch, in the rows, the counters and the CSV', function () {
        [$va, $vb] = [planVariant(), planVariant()];
        planStock($this->a, $va, 5);
        planStock($this->b, $vb, 5);
        $mgr = staffWithRole('branch_manager', $this->a);
        $c = Livewire::actingAs($mgr)->test(StockPlanningIndex::class)->set('lane', 'all');
        expect($c->viewData('rows')->pluck('variantId')->all())->toBe([$va->id])->and($c->viewData('counts')['all'])->toBe(1);
        // a ?branch filter for another branch yields nothing rather than leaking
        $c->set('branch', $this->b->id);
        expect($c->viewData('rows')->total())->toBe(0)->and($c->viewData('counts')['all'])->toBe(0);
        expect($this->planning->counts(BranchScope::none())['all'])->toBe(0)->and($this->planning->rows(BranchScope::none(), 'all'))->toBe([]);
    });

    it('is closed to people without stock access', function () {
        $this->actingAs(staffWithRole('delivery_employee', $this->a))->get(route('admin.stock-planning.index'))->assertForbidden();
        $this->actingAs(staffWithRole('inventory_manager', $this->a))->get(route('admin.stock-planning.index'))->assertOk();
    });

    it('sits in the Inventory menu for people who can see stock', function () {
        $items = collect(AdminNavigation::for(staffWithRole('inventory_manager', $this->a)))->flatMap(fn ($g) => $g['items'])->pluck('route');
        expect($items)->toContain('admin.stock-planning.index');
        expect(collect(AdminNavigation::for(staffWithRole('delivery_employee', $this->a)))->flatMap(fn ($g) => $g['items'])->pluck('route'))->not->toContain('admin.stock-planning.index');
    });
});

describe('the page', function () {
    beforeEach(function () {
        $this->mgr = staffWithRole('branch_manager', $this->a);
        $this->soldOut = planVariant();
        $this->running = planVariant();
        planStock($this->a, $this->soldOut, 2);
        $this->ledger->apply($this->a->id, $this->soldOut->id, MovementType::AdjustmentOut, -2, reason: 'sold out');
        planWaiting($this->soldOut, 14);
        planStock($this->a, $this->running, 12);
        planSale($this->a, $this->running, 30, 10);
    });

    it('answers "what do I have to order today?" first: the stock-out with its waiting customers, then the item that is running low', function () {
        Livewire::actingAs($this->mgr)->test(StockPlanningIndex::class)->assertOk()
            ->assertSeeInOrder([$this->soldOut->sku, $this->running->sku])
            ->assertSee('14 customers are waiting')->assertSee('Out since today')->assertDontSee('Fine');
    });

    it('shows each lane and its empty state', function () {
        $c = Livewire::actingAs($this->mgr)->test(StockPlanningIndex::class)->assertSet('lane', 'order');
        expect($c->viewData('counts'))->toMatchArray(['out' => 1, 'low' => 1, 'reorder' => 1, 'order' => 2]);
        $c->set('lane', 'slow')->assertSee('No slow movers')->set('lane', 'out')->assertSee($this->soldOut->sku)->assertDontSee($this->running->sku)
            ->set('lane', 'bogus')->assertSet('lane', 'order');
        $empty = Livewire::actingAs(staffWithRole('branch_manager', $this->b))->test(StockPlanningIndex::class)->assertSee('Nothing to order today');
        expect($empty->viewData('rows')->total())->toBe(0);
        $empty->set('search', 'zzzz')->assertSee('Nothing matches your search');
    });

    it('is written in Arabic too', function () {
        app()->setLocale('ar');
        Livewire::actingAs($this->mgr)->test(StockPlanningIndex::class)->assertSee('تخطيط المخزون')->assertSee('نفد من المخزون الآن')->assertSee('14 عميلاً ينتظرونه');
    });

    it('uses a handful of queries however many items there are', function () {
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::actingAs($this->mgr)->test(StockPlanningIndex::class)->set('lane', 'all');
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $count();                                                                // the first look also warms the settings cache
        $few = $count();
        foreach (range(1, 12) as $_) {
            $v = planVariant();
            planStock($this->a, $v, 5);
            planSale($this->a, $v, 2, 3);
        }
        expect($count())->toBe($few);
    });

    it('turns a suggestion into a draft purchase order with the existing purchasing code, once', function () {
        $supplier = Supplier::factory()->create();
        planPurchase($this->a, $this->running, 5, 'received', received: 5, supplier: $supplier, cost: '55.5000'); // last time: this supplier, this cost
        $row = BranchInventory::where('branch_id', $this->a->id)->where('product_variant_id', $this->running->id)->firstOrFail();
        $c = Livewire::actingAs($this->mgr)->test(StockPlanningIndex::class)->call('openOrder', $row->id)
            ->assertSet('showOrder', true)->assertSet('draft.supplier_id', $supplier->id)->assertSet('lines.0.unit_cost', '55.5')->assertSet('lines.0.quantity', 39);
        $c->set('lines.0.quantity', 40)->call('createOrder')->assertHasNoErrors()->assertRedirect();
        $po = PurchaseOrder::where('status', 'draft')->firstOrFail();
        expect($po->branch_id)->toBe($this->a->id)->and($po->supplier_id)->toBe($supplier->id)->and($po->items()->first()->quantity_ordered)->toBe(40)
            ->and(AuditLog::where('action', 'purchasing.po_created')->exists())->toBeTrue();
        $c->call('createOrder')->assertHasErrors('lines'); // the same press again: nothing left to order
        expect(PurchaseOrder::where('status', 'draft')->count())->toBe(1);
    });

    it('orders everything suggested for one branch, and asks for a branch first when there are several', function () {
        $two = staffWithRole('admin');
        $c = Livewire::actingAs($two)->test(StockPlanningIndex::class)->call('openOrder')->assertSet('showOrder', false)->assertDispatched('toast', type: 'warning');
        $c->set('branch', $this->a->id)->call('openOrder')->assertSet('showOrder', true);
        expect(collect($c->get('lines'))->pluck('variant_id')->all())->toEqualCanonicalizing([$this->soldOut->id, $this->running->id]);
        $supplier = Supplier::factory()->create();
        $c->set('draft.supplier_id', $supplier->id)->set('lines.0.unit_cost', '10')->set('lines.1.unit_cost', '12.5')->call('createOrder')->assertHasNoErrors();
        expect(PurchaseOrder::first()->items()->count())->toBe(2);
        $c->call('removeLine', 0); // no lines left closes the window
    });

    it('refuses to order for someone without purchasing rights, and for another branch', function () {
        $row = BranchInventory::where('branch_id', $this->a->id)->where('product_variant_id', $this->running->id)->firstOrFail();
        Livewire::actingAs(staffWithRole('sales_employee', $this->a))->test(StockPlanningIndex::class)->call('openOrder', $row->id)->assertForbidden();
        Livewire::actingAs(staffWithRole('branch_manager', $this->b))->test(StockPlanningIndex::class)->call('openOrder', $row->id)->assertForbidden();
        $c = Livewire::actingAs($this->mgr)->test(StockPlanningIndex::class);
        $c->assertDontSeeHtml('wire:click="openOrder(\''.BranchInventory::where('branch_id', $this->b->id)->value('id').'\')"');
        expect(PurchaseOrder::count())->toBe(0);
    });

    it('does not offer to order something that is already on order', function () {
        planPurchase($this->a, $this->running, 100, 'approved');
        planPurchase($this->a, $this->soldOut, 100, 'approved');
        $c = Livewire::actingAs($this->mgr)->test(StockPlanningIndex::class);
        expect($c->viewData('rows')->total())->toBe(0);
        $c->set('lane', 'out')->assertSee($this->soldOut->sku)->assertSee('Already on order');
    });

    it('saves the rules of one item with an audit entry, and nothing else', function () {
        $row = BranchInventory::where('branch_id', $this->a->id)->where('product_variant_id', $this->running->id)->firstOrFail();
        $onHand = $row->on_hand;
        $c = Livewire::actingAs($this->mgr)->test(StockPlanningIndex::class)->call('openRules', $row->id)->assertSet('showRules', true)
            ->set('plan.lead_time_days', '30')->set('plan.reorder_quantity', '60')->set('plan.safety_days', '')->call('saveRules')->assertHasNoErrors();
        $row->refresh();
        expect($row->lead_time_days)->toBe(30)->and($row->reorder_quantity)->toBe(60)->and($row->safety_days)->toBeNull()->and($row->on_hand)->toBe($onHand)
            ->and(AuditLog::where('action', 'inventory.planning_rules_updated')->count())->toBe(1);
        $c->call('openRules', $row->id)->set('plan.reorder_quantity', '0')->call('saveRules')->assertHasErrors('plan.reorder_quantity')
            ->set('plan.reorder_quantity', '60')->set('plan.lead_time_days', '999')->call('saveRules')->assertHasErrors('plan.lead_time_days');
        Livewire::actingAs(staffWithRole('branch_manager', $this->b))->test(StockPlanningIndex::class)->call('openRules', $row->id)->assertForbidden();
        Livewire::actingAs(staffWithRole('auditor'))->test(StockPlanningIndex::class)->call('openRules', $row->id)->assertForbidden();
    });

    it('downloads the list as CSV for people who may export, with no customer data, and says so in the audit log', function () {
        $c = Livewire::actingAs($this->mgr)->test(StockPlanningIndex::class)->set('lane', 'all')->call('export')->assertFileDownloaded('stock-planning-20261119-120000.csv');
        $csv = base64_decode((string) data_get($c->effects, 'download.content'));
        $rows = array_map('str_getcsv', array_filter(explode("\n", ltrim($csv, "\xEF\xBB\xBF"))));
        expect($rows[0])->toBe(['branch', 'sku', 'product', 'status', 'on_hand', 'reserved', 'available', 'sold_in_window', 'per_day', 'days_of_cover', 'reorder_point', 'on_order', 'customers_waiting', 'out_since_qatar', 'suggested_order'])
            ->and(count($rows))->toBe(3)
            ->and($rows[1][0])->toBe('DOH')->and($rows[1][1])->toBe($this->soldOut->sku)->and($rows[1][3])->toBe('out')->and($rows[1][12])->toBe('14')->and($rows[1][13])->toBe('2026-11-19')
            ->and($rows[2][1])->toBe($this->running->sku)->and($rows[2][9])->toBe('12');
        expect($csv)->not->toContain('Noor Ali'); // no customer name, phone or address ever
        expect(AuditLog::where('action', 'inventory.planning_exported')->count())->toBe(1);
        Livewire::actingAs(staffWithRole('sales_employee', $this->a))->test(StockPlanningIndex::class)->call('export')->assertForbidden();
    });

    it('keeps spreadsheet formulas in product names from running', function () {
        $v = planVariant();
        $v->product->translations()->update(['name' => '=HYPERLINK("http://x","Click")']);
        planStock($this->a, $v, 5);
        $c = Livewire::actingAs($this->mgr)->test(StockPlanningIndex::class)->set('lane', 'all')->set('search', $v->sku)->call('export');
        expect(base64_decode((string) data_get($c->effects, 'download.content')))->toContain("\"'=HYPERLINK");
    });
});

describe('read-only viewers and the settings', function () {
    it('shows a person who may only look the list, with no order, rules or download buttons', function () {
        $v = planVariant();
        planStock($this->a, $v, 12);
        planSale($this->a, $v, 30, 10);
        $c = Livewire::actingAs(staffWithRole('sales_employee', $this->a))->test(StockPlanningIndex::class)->assertSee($v->sku)
            ->assertDontSee('Download CSV')->assertDontSee('Order everything suggested');
        expect($c->html())->not->toContain('wire:click="openOrder')->and($c->html())->not->toContain('wire:click="openRules');
    });

    it('keeps the settings in Settings > Stock: the six numbers and the two stock-count settings, validated, saved and audited', function () {
        $admin = staffWithRole('admin');
        $c = Livewire::actingAs($admin)->test(SettingsIndex::class, ['tab' => 'stock'])->assertSee('Days of sales to look at')->assertSee('A second person must approve stock counts')
            ->assertSee('Post small differences without approval, up to')->assertSee('Default: 30');
        $c->set('tab', 'stock')->set('values.stock.stock_planning__demand_window_days', '3')->call('save')->assertHasErrors('values.stock.stock_planning__demand_window_days');
        $c->set('values.stock.stock_planning__demand_window_days', '45')->set('values.stock.stock_planning__lead_time_days', '21')->set('values.stock.stocktake__second_person_required', false)
            ->set('values.stock.stocktake__auto_post_up_to', '250.50')->call('save')->assertHasNoErrors();
        $settings = app(Settings::class);
        expect($settings->get('stock_planning.demand_window_days'))->toBe(45)->and($settings->get('stock_planning.lead_time_days'))->toBe(21)
            ->and($settings->get('stocktake.second_person_required'))->toBeFalse()->and($settings->get('stocktake.auto_post_up_to'))->toBe('250.50')
            ->and(StockPlanningSettings::all()['window_days'])->toBe(45);
        expect(AuditLog::where('action', 'settings.updated')->where('metadata->key', 'stocktake.second_person_required')->count())->toBe(1);
        $c->set('values.stock.stocktake__auto_post_up_to', '-5')->call('save')->assertHasErrors('values.stock.stocktake__auto_post_up_to');
        app()->setLocale('ar');
        Livewire::actingAs($admin)->test(SettingsIndex::class, ['tab' => 'stock'])->assertSee('المخزون')->assertSee('أيام المبيعات التي يُنظر فيها');
        $manager = staffWithRole('branch_manager', $this->a);
        $this->actingAs($manager)->get(route('admin.settings.index'))->assertForbidden();
    });
});
