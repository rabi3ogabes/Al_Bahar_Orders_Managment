<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\BranchInventory;
use App\Domain\Inventory\Models\InventoryAdjustment;
use App\Domain\Inventory\Models\PurchaseOrder;
use App\Domain\Inventory\Models\StocktakingSession;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\Supplier;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Inventory\Services\PurchasingService;
use App\Domain\Inventory\Services\TransferService;
use App\Livewire\Admin\Inventory\AdjustmentIndex;
use App\Livewire\Admin\Inventory\MovementIndex;
use App\Livewire\Admin\Inventory\PurchaseOrderIndex;
use App\Livewire\Admin\Inventory\PurchaseOrderShow;
use App\Livewire\Admin\Inventory\StockIndex;
use App\Livewire\Admin\Inventory\StocktakingIndex;
use App\Livewire\Admin\Inventory\StocktakingShow;
use App\Livewire\Admin\Inventory\SupplierIndex;
use App\Livewire\Admin\Inventory\TransferIndex;
use App\Livewire\Admin\Inventory\TransferShow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    [$this->wb, $this->prl] = Branch::factory()->count(2)->create();
    $this->admin = User::factory()->superAdmin()->create();
    $this->wbManager = staffWithRole('branch_manager', $this->wb);
    $this->wbClerk = staffWithRole('inventory_manager', $this->wb);
    $this->prlManager = staffWithRole('branch_manager', $this->prl);
    $this->variant = Product::factory()->create(['sku' => 'SOFA-1'])->variants()->first();
    $ledger = app(InventoryLedger::class);
    $ledger->apply($this->wb->id, $this->variant->id, MovementType::OpeningBalance, 10, unitCost: '400.00');
    $ledger->apply($this->prl->id, $this->variant->id, MovementType::OpeningBalance, 3, unitCost: '420.00');
    $this->stock = fn (Branch $b) => BranchInventory::where('branch_id', $b->id)->where('product_variant_id', $this->variant->id)->first();
});

it('renders the inventory screens for a super admin', function (string $route) {
    $this->actingAs($this->admin)->get(route($route))->assertOk();
})->with(['admin.inventory.index', 'admin.inventory.movements', 'admin.adjustments.index', 'admin.transfers.index', 'admin.stocktaking.index', 'admin.purchase-orders.index', 'admin.suppliers.index']);

it('forbids inventory screens to staff without inventory permissions', function (string $route) {
    $this->actingAs(staffWithRole('delivery_employee', $this->wb))->get(route($route))->assertForbidden();
})->with(['admin.inventory.index', 'admin.inventory.movements', 'admin.adjustments.index', 'admin.transfers.index', 'admin.stocktaking.index', 'admin.purchase-orders.index', 'admin.suppliers.index']);

it('scopes stock, valuation and movements to the viewer’s branches', function () {
    $this->actingAs($this->wbManager);
    $c = Livewire::test(StockIndex::class);
    expect($c->viewData('rows')->pluck('branch_id')->unique()->all())->toBe([$this->wb->id]);
    expect($c->viewData('totalValue')->toDecimal())->toBe('4000.00');
    // A ?branch filter for another branch yields nothing rather than leaking.
    expect(Livewire::test(StockIndex::class)->set('branch', $this->prl->id)->viewData('rows')->total())->toBe(0);
    expect(Livewire::test(MovementIndex::class)->viewData('movements')->pluck('branch_id')->unique()->all())->toBe([$this->wb->id]);
});

it('edits thresholds only at own branch and never quantities', function () {
    $this->actingAs($this->wbManager);
    Livewire::test(StockIndex::class)->call('openThresholds', ($this->stock)($this->wb)->id)
        ->set('edit.minimum_stock', 4)->set('edit.maximum_stock', 20)->set('edit.bin_location', 'A-1')->call('saveThresholds')->assertHasNoErrors();
    expect(($this->stock)($this->wb)->minimum_stock)->toBe(4)->and(($this->stock)($this->wb)->on_hand)->toBe(10);
    Livewire::test(StockIndex::class)->call('openThresholds', ($this->stock)($this->prl)->id)->assertForbidden();
});

it('requires a second person to approve an adjustment', function () {
    $this->actingAs($this->wbClerk);
    Livewire::test(AdjustmentIndex::class)->call('create')->set('form.branch_id', $this->wb->id)->set('form.type', 'damaged')->set('form.reason', 'Water damage')
        ->call('pickVariant', $this->variant->id)->set('lines.0.quantity', 2)->call('save')->assertHasNoErrors();
    $adj = InventoryAdjustment::firstOrFail();
    expect($adj->status)->toBe('pending_approval')->and($adj->items()->first()->quantity)->toBe(-2);

    // The maker cannot approve.
    Livewire::test(AdjustmentIndex::class)->call('approve', $adj->id)->assertDispatched('toast', type: 'error');
    expect($adj->refresh()->status)->toBe('pending_approval');
    // Another branch's manager cannot either.
    $this->actingAs($this->prlManager);
    Livewire::test(AdjustmentIndex::class)->call('approve', $adj->id)->assertForbidden();
    // A second person at the branch can.
    $this->actingAs($this->wbManager);
    Livewire::test(AdjustmentIndex::class)->assertSee($adj->number)->call('approve', $adj->id);
    expect($adj->refresh()->status)->toBe('posted')->and(($this->stock)($this->wb)->on_hand)->toBe(8);
});

it('rejects adjustments with a reason and blocks creating at other branches', function () {
    $this->actingAs($this->wbClerk);
    Livewire::test(AdjustmentIndex::class)->call('create')->set('form.branch_id', $this->prl->id)->set('form.reason', 'test')
        ->call('pickVariant', $this->variant->id)->call('save')->assertForbidden();
    Livewire::test(AdjustmentIndex::class)->call('create')->set('form.branch_id', $this->wb->id)->set('form.type', 'adjustment')->set('form.reason', 'Recount')
        ->call('pickVariant', $this->variant->id)->set('lines.0.direction', 'in')->call('save');
    $adj = InventoryAdjustment::firstOrFail();
    $this->actingAs($this->wbManager);
    Livewire::test(AdjustmentIndex::class)->call('startReject', $adj->id)->set('rejectReason', 'Not verified')->call('reject');
    expect($adj->refresh()->status)->toBe('rejected')->and(($this->stock)($this->wb)->on_hand)->toBe(10);
});

it('runs a full transfer lifecycle through the UI', function () {
    $this->actingAs($this->wbClerk);
    Livewire::test(TransferIndex::class)->call('create')->set('form.source_branch_id', $this->wb->id)->set('form.destination_branch_id', $this->prl->id)
        ->call('pickVariant', $this->variant->id)->set('lines.0.quantity', 5)->call('save')->assertHasNoErrors()->assertNoRedirect()->assertSet('showView', true); // the new transfer opens beside the list
    $t = StockTransfer::firstOrFail();
    expect($t->status)->toBe('pending_approval');
    $item = $t->items()->first();

    Livewire::test(TransferShow::class, ['transfer' => $t])->call('approve')->assertDispatched('toast', type: 'error'); // maker ≠ checker

    $this->actingAs($this->wbManager);
    Livewire::test(TransferShow::class, ['transfer' => $t])->set("qty.{$item->id}", 4)->call('approve');
    expect($t->refresh()->status)->toBe('approved')->and(($this->stock)($this->wb)->reserved)->toBe(4);
    Livewire::test(TransferShow::class, ['transfer' => $t])->set("qty.{$item->id}", 3)->call('dispatchTransfer');
    expect($t->refresh()->status)->toBe('in_transit')->and(($this->stock)($this->wb)->on_hand)->toBe(7)->and(($this->stock)($this->wb)->reserved)->toBe(0);

    // Source manager cannot receive at the destination.
    Livewire::test(TransferShow::class, ['transfer' => $t])->call('receive')->assertForbidden();

    $this->actingAs($this->prlManager);
    Livewire::test(TransferShow::class, ['transfer' => $t])->set("receipt.{$item->id}", ['received' => 1, 'damaged' => 1, 'note' => 'Leg scratched'])->call('receive');
    expect($t->refresh()->status)->toBe('partially_received')->and(($this->stock)($this->prl)->on_hand)->toBe(4);
    Livewire::test(TransferShow::class, ['transfer' => $t])->assertViewHas('inTransit', fn ($m) => $m[$item->id] === 1)->call('complete');
    expect($t->refresh()->status)->toBe('completed');
});

it('hides other branches’ transfers and blocks their detail page', function () {
    $other = Branch::factory()->create();
    app(InventoryLedger::class)->apply($other->id, $this->variant->id, MovementType::OpeningBalance, 5);
    $t = app(TransferService::class)->create($this->admin, $other->id, $this->prl->id, [['variant_id' => $this->variant->id, 'quantity' => 1]]);
    $this->actingAs($this->wbManager);
    expect(Livewire::test(TransferIndex::class)->viewData('transfers')->total())->toBe(0);
    $this->get(route('admin.transfers.show', $t))->assertForbidden();
});

it('counts stock by scanning and posts variances', function () {
    $this->actingAs($this->wbClerk);
    Livewire::test(StocktakingIndex::class)->call('create')->set('form.branch_id', $this->wb->id)->call('start')->assertRedirect();
    $session = StocktakingSession::firstOrFail();
    Livewire::test(StocktakingShow::class, ['session' => $session])
        ->set('code', 'SOFA-1')->call('scanCode')->assertSet('activeVariant', $this->variant->id)->assertDispatched('stocktake-focus', target: 'qty')
        ->set('countQty', '9')->call('submitCount')->assertSet('activeVariant', null)->assertDispatched('stocktake-focus', target: 'scan')
        ->set('perScan', true)->set('code', 'SOFA-1')->call('scanCode');
    expect($session->items()->first()->counted_quantity)->toBe(10);
    Livewire::test(StocktakingShow::class, ['session' => $session])->set('code', 'NOPE')->call('scanCode')->assertDispatched('toast', type: 'warning');

    Livewire::test(StocktakingShow::class, ['session' => $session])->set("counts.{$session->items()->first()->id}", 8)->call('setCount', $session->items()->first()->id)->call('submitSession');
    expect($session->refresh()->status)->toBe('submitted');
    $this->actingAs($this->wbManager);
    Livewire::test(StocktakingShow::class, ['session' => $session])->call('post');
    expect($session->refresh()->status)->toBe('posted')->and(($this->stock)($this->wb)->on_hand)->toBe(8);

    $this->actingAs($this->prlManager);
    $this->get(route('admin.stocktaking.show', $session))->assertForbidden();
});

it('creates, approves and receives a purchase order with partial and rejected quantities', function () {
    $supplier = Supplier::factory()->create();
    $this->actingAs($this->wbClerk);
    Livewire::test(PurchaseOrderIndex::class)->call('create')->set('form.supplier_id', $supplier->id)->set('form.branch_id', $this->wb->id)
        ->call('pickVariant', $this->variant->id)->set('lines.0.quantity', 6)->set('lines.0.unit_cost', '500')->call('save')->assertHasNoErrors()->assertNoRedirect()->assertSet('showView', true); // the new order opens beside the list
    $po = PurchaseOrder::firstOrFail();
    expect($po->subtotal)->toBe('3000.00');
    Livewire::test(PurchaseOrderShow::class, ['po' => $po])->call('submit')->call('approve')->assertDispatched('toast', type: 'error');
    expect($po->refresh()->status)->toBe('pending_approval');

    $this->actingAs($this->wbManager);
    Livewire::test(PurchaseOrderShow::class, ['po' => $po])->call('approve');
    expect($po->refresh()->status)->toBe('approved');
    $item = $po->items()->first();
    Livewire::test(PurchaseOrderShow::class, ['po' => $po])
        ->set("receipt.{$item->id}.received", 4)->set("receipt.{$item->id}.rejected", 1)->call('receive')->assertHasErrors("receipt.{$item->id}.rejection_reason")
        ->set("receipt.{$item->id}.rejection_reason", 'Torn fabric')->set('invoice', 'INV-778')->call('receive')->assertHasNoErrors();
    expect($po->refresh()->status)->toBe('partially_received')->and(($this->stock)($this->wb)->on_hand)->toBe(14);
    Livewire::test(PurchaseOrderShow::class, ['po' => $po])->call('close');
    expect($po->refresh()->status)->toBe('closed');

    $this->actingAs($this->prlManager);
    $this->get(route('admin.purchase-orders.show', $po))->assertForbidden();
});

it('manages suppliers and protects ones with orders', function () {
    $this->actingAs($this->wbClerk);
    Livewire::test(SupplierIndex::class)->call('create')->set('form.code', 'nordic')->set('form.name', 'Nordic Living Co.')->set('form.email', 'sales@nordic.example')->call('save')->assertHasNoErrors();
    $s = Supplier::where('code', 'NORDIC')->firstOrFail();
    app(PurchasingService::class)->create($this->admin, $s->id, $this->wb->id, [['variant_id' => $this->variant->id, 'quantity' => 1, 'unit_cost' => '1']]);
    Livewire::test(SupplierIndex::class)->call('delete', $s->id)->assertDispatched('toast', type: 'error');
    expect(Supplier::find($s->id))->not->toBeNull();
    $this->actingAs($this->wbManager); // branch managers can only view suppliers
    Livewire::test(SupplierIndex::class)->call('create')->assertForbidden();
});
