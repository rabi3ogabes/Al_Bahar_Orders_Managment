<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\CategoryService;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\UserRole;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Inventory\Models\BranchInventory;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Models\Supplier;
use App\Domain\Inventory\Services\AdjustmentService;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Inventory\Services\PurchasingService;
use App\Domain\Inventory\Services\ReservationService;
use App\Domain\Inventory\Services\StocktakingService;
use App\Domain\Inventory\Services\TransferService;
use App\Support\DomainException;
use Illuminate\Database\QueryException;

beforeEach(function () {
    [$this->a, $this->b] = Branch::factory()->count(2)->create();
    $this->variant = Product::factory()->create()->variants()->first();
    $this->ledger = app(InventoryLedger::class);
    $this->res = app(ReservationService::class);
    $this->stock = fn (Branch $b) => BranchInventory::where('branch_id', $b->id)->where('product_variant_id', $this->variant->id)->first();
    $this->ledger->apply($this->a->id, $this->variant->id, MovementType::OpeningBalance, 10, reason: 'opening');
});

it('keeps branch stock independent and records every change in the ledger', function () {
    expect(($this->stock)($this->a)->on_hand)->toBe(10)->and(($this->stock)($this->b))->toBeNull();
    $m = StockMovement::firstOrFail();
    expect($m->type)->toBe('opening_balance')->and($m->on_hand_after)->toBe(10);
    expect(fn () => $m->update(['quantity' => 99]))->toThrow(LogicException::class);
});

it('refuses direct quantity edits outside the ledger', function () {
    $row = ($this->stock)($this->a);
    expect(fn () => $row->forceFill(['on_hand' => 500])->save())->toThrow(LogicException::class);
});

it('never allows available stock below zero', function () {
    expect(fn () => $this->ledger->apply($this->a->id, $this->variant->id, MovementType::AdjustmentOut, -11))->toThrow(DomainException::class);
    $this->res->reserve([['branch_id' => $this->a->id, 'variant_id' => $this->variant->id, 'quantity' => 8, 'reference_type' => 'order_item', 'reference_id' => 'x1']]);
    // 2 available; removing 3 on-hand would put reserved > on_hand.
    expect(fn () => $this->ledger->apply($this->a->id, $this->variant->id, MovementType::Damaged, -3))->toThrow(DomainException::class);
    expect(($this->stock)($this->a)->available())->toBe(2);
});

it('reserves atomically across lines and idempotently per reference', function () {
    $other = Product::factory()->create()->variants()->first();
    $this->ledger->apply($this->a->id, $other->id, MovementType::OpeningBalance, 1);
    $lines = [
        ['branch_id' => $this->a->id, 'variant_id' => $this->variant->id, 'quantity' => 4, 'reference_type' => 'order_item', 'reference_id' => 'l1'],
        ['branch_id' => $this->a->id, 'variant_id' => $other->id, 'quantity' => 2, 'reference_type' => 'order_item', 'reference_id' => 'l2'],
    ];
    expect(fn () => $this->res->reserve($lines))->toThrow(InsufficientStock::class);
    expect(($this->stock)($this->a)->reserved)->toBe(0); // first line was NOT reserved

    $lines[1]['quantity'] = 1;
    $this->res->reserve($lines);
    $this->res->reserve($lines); // retry — no double reservation
    expect(($this->stock)($this->a)->reserved)->toBe(4)->and(StockReservation::count())->toBe(2);
});

it('releases on cancellation and expiry exactly once', function () {
    [$r] = $this->res->reserve([['branch_id' => $this->a->id, 'variant_id' => $this->variant->id, 'quantity' => 3, 'reference_type' => 'order_item', 'reference_id' => 'c1']], now()->addMinutes(30));
    expect($this->res->release($r, 'cancelled'))->toBeTrue()->and($this->res->release($r, 'cancelled'))->toBeFalse();
    expect(($this->stock)($this->a)->reserved)->toBe(0);

    [$r2] = $this->res->reserve([['branch_id' => $this->a->id, 'variant_id' => $this->variant->id, 'quantity' => 2, 'reference_type' => 'order_item', 'reference_id' => 'e1']], now()->addMinutes(30));
    $this->travel(31)->minutes();
    expect($this->res->expireDue())->toBe(1)->and($this->res->expireDue())->toBe(0);
    expect($r2->fresh()->status)->toBe('expired')->and(($this->stock)($this->a)->reserved)->toBe(0)
        ->and($this->res->consume($r2))->toBeFalse(); // expired reservations cannot be consumed
});

it('deducts on-hand and reserved together when a reservation is consumed', function () {
    [$r] = $this->res->reserve([['branch_id' => $this->a->id, 'variant_id' => $this->variant->id, 'quantity' => 3, 'reference_type' => 'order_item', 'reference_id' => 's1']]);
    $this->res->consume($r, 2);
    $row = ($this->stock)($this->a);
    expect($row->on_hand)->toBe(8)->and($row->reserved)->toBe(0); // 1 unit remainder released
    expect(StockMovement::where('type', 'sale_deduction')->value('quantity'))->toBe(-2);
});

it('runs a transfer with partial dispatch, partial receipt and discrepancies', function () {
    $requester = staffWithRole('inventory_manager', $this->a);
    BranchEmployee::create(['branch_id' => $this->b->id, 'user_id' => $requester->id, 'is_active' => true]);
    UserRole::create(['user_id' => $requester->id, 'role_id' => Role::where('key', 'inventory_manager')->value('id'), 'branch_id' => $this->b->id]);
    $approver = staffWithRole('branch_manager', $this->a);
    $svc = app(TransferService::class);

    $t = $svc->create($requester, $this->a->id, $this->b->id, [['variant_id' => $this->variant->id, 'quantity' => 6]]);
    $t = $svc->submit($requester, $t);
    expect(fn () => $svc->approve($requester, $t))->toThrow(DomainException::class); // maker ≠ checker
    $t = $svc->approve($approver, $t);
    expect(($this->stock)($this->a)->reserved)->toBe(6);

    $item = $t->items->first();
    $t = $svc->dispatch($requester, $t, [$item->id => 5]); // partial dispatch
    expect(($this->stock)($this->a)->on_hand)->toBe(5)->and(($this->stock)($this->a)->reserved)->toBe(0);

    $t = $svc->receive($requester, $t, [$item->id => ['received' => 3]]);
    expect($t->status)->toBe('partially_received')->and(($this->stock)($this->b)->on_hand)->toBe(3)
        ->and($svc->inTransit($t->items->first()))->toBe(2);
    expect(fn () => $svc->receive($requester, $t, [$item->id => ['received' => 3]]))->toThrow(DomainException::class); // over-receipt

    $t = $svc->receive($requester, $t, [$item->id => ['received' => 1, 'damaged' => 1, 'note' => 'Corner chipped']]);
    expect($t->status)->toBe('received')->and(($this->stock)($this->b)->on_hand)->toBe(4);
    // Closing (and accepting discrepancies) is the destination branch's decision.
    expect(fn () => $svc->complete($approver->fresh(), $t))->toThrow(DomainException::class);
    $t = $svc->complete(staffWithRole('branch_manager', $this->b), $t);
    expect($t->status)->toBe('completed');
    expect(AuditLog::where('action', 'transfer.discrepancy')->exists())->toBeTrue();
});

it('adds stock from partial goods receipts with weighted average cost', function () {
    $creator = staffWithRole('inventory_manager', $this->a);
    $approver = staffWithRole('branch_manager', $this->a);
    $svc = app(PurchasingService::class);
    $po = $svc->create($creator, Supplier::factory()->create()->id, $this->a->id, [['variant_id' => $this->variant->id, 'quantity' => 10, 'unit_cost' => '400.00']]);
    expect($po->subtotal)->toBe('4000.00');
    $po = $svc->approve($approver, $svc->submit($creator, $po));

    $svc->receive($creator, $po, [$po->items[0]->id => ['received' => 4, 'rejected' => 1, 'rejection_reason' => 'Torn fabric']], 'INV-889');
    expect($po->fresh()->status)->toBe('partially_received')->and(($this->stock)($this->a)->on_hand)->toBe(14)
        ->and(StockMovement::where('type', 'purchase_receipt')->value('quantity'))->toBe(4);
    // avg cost: opening 10 units had no cost → avg seeds from the first known cost
    expect((float) ($this->stock)($this->a)->average_cost)->toBe(400.0);

    expect(fn () => $svc->receive($creator, $po->fresh(), [$po->items[0]->id => ['received' => 7]]))->toThrow(DomainException::class);
    $svc->receive($creator, $po->fresh(), [$po->items[0]->id => ['received' => 6]]);
    expect($po->fresh()->status)->toBe('received');
});

it('posts adjustments only after a second person approves', function () {
    $clerk = staffWithRole('inventory_manager', $this->a);
    $mgr = staffWithRole('branch_manager', $this->a);
    $svc = app(AdjustmentService::class);
    $adj = $svc->create($clerk, $this->a->id, 'damaged', 'Water damage in storeroom', [['variant_id' => $this->variant->id, 'quantity' => -2]]);
    expect($adj->status)->toBe('pending_approval');
    expect(fn () => $svc->post($clerk, $adj))->toThrow(DomainException::class);
    $svc->post($mgr, $adj);
    expect(($this->stock)($this->a)->on_hand)->toBe(8)->and(StockMovement::where('type', 'damaged')->exists())->toBeTrue();
    expect(fn () => $svc->create($clerk, $this->a->id, 'damaged', 'x', [['variant_id' => $this->variant->id, 'quantity' => 2]]))->toThrow(DomainException::class);
});

it('applies stocktake variances against the snapshot while preserving sales made during counting', function () {
    $mgr = staffWithRole('branch_manager', $this->a);
    $svc = app(StocktakingService::class);
    $session = $svc->start($mgr, $this->a->id);
    // A sale of 1 happens while counting.
    [$r] = $this->res->reserve([['branch_id' => $this->a->id, 'variant_id' => $this->variant->id, 'quantity' => 1, 'reference_type' => 'order_item', 'reference_id' => 'during']]);
    $this->res->consume($r);
    $svc->count($mgr, $session, $this->variant->id, 9); // counted 9 vs expected 10 at snapshot → shrinkage of 1
    $svc->post($mgr, $svc->submit($mgr, $session));
    expect(($this->stock)($this->a)->on_hand)->toBe(8) // 10 − 1 sold − 1 shrinkage
        ->and(StockMovement::where('type', 'stocktake_correction')->value('quantity'))->toBe(-1)
        ->and(($this->stock)($this->a)->last_counted_at)->not->toBeNull();
    expect(fn () => $svc->start($mgr, $this->a->id) && $svc->start($mgr, $this->a->id))->toThrow(QueryException::class);
});

it('prevents Branch A staff from adjusting or reading Branch B inventory', function () {
    $this->ledger->apply($this->b->id, $this->variant->id, MovementType::OpeningBalance, 5);
    $mgrA = actingAsStaff(staffWithRole('branch_manager', $this->a));

    $this->postJson('/api/v1/admin/inventory/adjustments', ['branch_id' => $this->b->id, 'type' => 'adjustment', 'reason' => 'x',
        'items' => [['variant_id' => $this->variant->id, 'quantity' => 5]]])->assertForbidden();
    $this->getJson("/api/v1/admin/inventory/stock?branch_id={$this->b->id}")->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/admin/inventory/stock')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.branch.id', $this->a->id);
    $this->getJson('/api/v1/admin/inventory/movements')->assertOk()->assertJsonCount(1, 'data');
    $rowB = ($this->stock)($this->b);
    $this->patchJson("/api/v1/admin/inventory/stock/{$rowB->id}", ['minimum_stock' => 2])->assertForbidden();
});

it('lists low-stock rows', function () {
    BranchInventory::where('branch_id', $this->a->id)->update(['minimum_stock' => 12]);
    actingAsStaff(staffWithRole('admin'));
    $this->getJson('/api/v1/admin/inventory/stock?filter=low')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_low', true);
});

it('includes sub-categories in a category stocktake and audits submit and cancel', function () {
    $cats = app(CategoryService::class);
    $root = $cats->create(['slug' => 'living', 'translations' => ['en' => ['name' => 'Living'], 'ar' => ['name' => 'المعيشة']]]);
    $child = $cats->create(['slug' => 'sofas', 'parent_id' => $root->id, 'translations' => ['en' => ['name' => 'Sofas'], 'ar' => ['name' => 'كنب']]]);
    $this->variant->product->update(['category_id' => $child->id]);
    $other = Product::factory()->create()->variants()->first();
    $this->ledger->apply($this->a->id, $other->id, MovementType::OpeningBalance, 4, reason: 'opening');

    $mgr = staffWithRole('branch_manager', $this->a);
    $svc = app(StocktakingService::class);
    $session = $svc->start($mgr, $this->a->id, $root->id);
    expect($session->items()->pluck('product_variant_id')->all())->toBe([$this->variant->id]);

    $svc->cancel($mgr, $svc->submit($mgr, $session));
    expect(AuditLog::whereIn('action', ['stocktaking.submitted', 'stocktaking.cancelled'])->count())->toBe(2);
});
