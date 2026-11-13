<?php

// Side windows in the catalog, inventory, marketing, content, organization and insights screens: record windows open from ?view= or a click,
// show the right record under the same scoping as the page they replace, audit what they change and clear their state when closed.

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\StocktakingSession;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Inventory\Services\PurchasingService;
use App\Domain\Inventory\Services\StocktakingService;
use App\Domain\Inventory\Services\TransferService;
use App\Domain\Issues\Models\IssueReport;
use App\Domain\Support\Models\SupportMessage;
use App\Domain\Support\Models\SupportTicket;
use App\Livewire\Admin\Catalog\ProductIndex;
use App\Livewire\Admin\Catalog\ProductQuickView;
use App\Livewire\Admin\Insights\IssueIndex;
use App\Livewire\Admin\Inventory\PurchaseOrderIndex;
use App\Livewire\Admin\Inventory\PurchaseOrderShow;
use App\Livewire\Admin\Inventory\StocktakingIndex;
use App\Livewire\Admin\Inventory\TransferIndex;
use App\Livewire\Admin\Inventory\TransferShow;
use App\Livewire\Admin\Marketing\SupportIndex;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    [$this->wb, $this->prl] = Branch::factory()->count(2)->create();
    $this->admin = User::factory()->superAdmin()->create();
    $this->wbManager = staffWithRole('branch_manager', $this->wb);
    $this->prlManager = staffWithRole('branch_manager', $this->prl);
    $this->product = Product::factory()->create(['sku' => 'OSLO-1', 'price' => '1000.00']);
    $this->variant = $this->product->variants()->first();
    $ledger = app(InventoryLedger::class);
    $ledger->apply($this->wb->id, $this->variant->id, MovementType::OpeningBalance, 10, unitCost: '400.00');
    $ledger->apply($this->prl->id, $this->variant->id, MovementType::OpeningBalance, 3, unitCost: '420.00');
    $this->uuid = '00000000-0000-7000-8000-000000000000';
});

describe('the shared dialog component', function () {
    it('renders a side window (role dialog, end side, width from max-width, bottom sheet on phones) by default', function () {
        $html = Blade::render('<x-admin.dialog wire:model="show" title="Edit brand" max-width="max-w-3xl">body</x-admin.dialog>');
        expect($html)->toContain('role="dialog"')->not->toContain('role="alertdialog"')
            ->toContain('md:w-[48rem]')->toContain('md:end-0')->toContain('md:start-auto')->toContain('rounded-t-3xl')->toContain('max-h-[92dvh]')
            ->toContain('z-[68]')->toContain('drawer-body')->toContain('aria-label="Edit brand"')
            ->toContain('md:ltr:translate-x-full')->toContain('md:rtl:-translate-x-full') // slides in from the right in English, from the left in Arabic
            ->toContain("entangle('show', false)")->toContain('requestClose()');
    });

    it('renders a decision (role alertdialog, centred, above windows, no scrim dismissal) for variant modal', function () {
        $html = Blade::render('<x-admin.dialog variant="modal" wire:model="ask" title="Delete this?" max-width="max-w-md">body</x-admin.dialog>');
        expect($html)->toContain('role="alertdialog"')->not->toContain('role="dialog"')
            ->toContain('z-[72]')->not->toContain('md:w-[')->not->toContain('drawer-body')->toContain('max-w-md');
        expect($html)->not->toMatch('/class="absolute inset-0 bg-ink-950\/45[^"]*"[^>]*@click/'); // the scrim of a decision is not clickable
    });

    it('maps every max-width to its window width and syncs at once for wire:model.live', function (string $max, string $width) {
        expect(Blade::render('<x-admin.dialog wire:model.live="show" max-width="'.$max.'">x</x-admin.dialog>'))->toContain($width)->toContain("entangle('show', true)");
    })->with([['max-w-md', 'md:w-[26rem]'], ['max-w-lg', 'md:w-[30rem]'], ['max-w-xl', 'md:w-[34rem]'], ['max-w-2xl', 'md:w-[40rem]'], ['max-w-3xl', 'md:w-[48rem]'], ['max-w-4xl', 'md:w-[56rem]'], ['max-w-5xl', 'md:w-[64rem]']]);

    it('has a pinned-row CSS rule that never matches a decision modal nested in a window', function () {
        $css = file_get_contents(resource_path('css/app.css'));
        expect($css)->toContain('.drawer-body form:not(.card, [role=alertdialog] form) > div:last-child');
        // every rule that targets the pinned row carries the exclusion
        preg_match_all('/^\s*\.drawer-body\s*(?:>\s*)?form[^{]*\{/m', $css, $rules);
        expect($rules[0])->not->toBeEmpty();
        foreach ($rules[0] as $rule) {
            expect($rule)->toContain('[role=alertdialog] form');
        }
    });
});

describe('products quick view', function () {
    it('opens from the ?view= deep link with the right product and offers the full editor', function () {
        $this->actingAs($this->admin);
        Livewire::withQueryParams(['view' => $this->product->id])->test(ProductIndex::class)
            ->assertSet('showView', true)->assertSet('viewId', $this->product->id)
            ->assertSee('OSLO-1')->assertSee(__('drawer_admin.open_full_editor'))
            ->assertSeeHtml(route('admin.products.edit', $this->product));
    });

    it('opens on click, keeps the list state and clears the id when closed', function () {
        $this->actingAs($this->admin);
        Livewire::test(ProductIndex::class)->set('search', 'OSLO')->call('openView', $this->product->id)
            ->assertSet('showView', true)->assertSet('viewId', $this->product->id)->assertSet('search', 'OSLO')
            ->set('showView', false)->assertSet('viewId', '')->assertSet('search', 'OSLO')
            ->call('openView', $this->product->id)->call('closeView')->assertSet('showView', false)->assertSet('viewId', '');
    });

    it('opens nothing for a made-up id or a value that is not an id', function (string $id) {
        $this->actingAs($this->admin);
        Livewire::withQueryParams(['view' => $id])->test(ProductIndex::class)->assertSet('showView', false)->assertSet('viewId', '')->assertOk();
        Livewire::test(ProductIndex::class)->call('openView', $id)->assertSet('showView', false)->assertSet('viewId', '');
    })->with(['not-a-uuid', '00000000-0000-7000-8000-000000000000', "' OR 1=1 --"]);

    it('cannot be opened by someone without catalog access', function () {
        $this->actingAs(staffWithRole('delivery_employee', $this->wb));
        $this->get(route('admin.products.index', ['view' => $this->product->id]))->assertForbidden();
    });

    it('saves a switch at once, audits it and tells the list to refresh', function () {
        $this->actingAs($this->admin);
        Livewire::test(ProductQuickView::class, ['productId' => $this->product->id])
            ->set('flags.is_featured', true)->assertDispatched('toast')->assertDispatched('product-changed')
            ->assertSet('flags.is_featured', true);
        expect($this->product->refresh()->is_featured)->toBeTrue();
        expect(AuditLog::where('action', 'catalog.product_updated')->where('auditable_id', $this->product->id)->exists())->toBeTrue();
    });

    it('hides and shows a product with the visible-in-store switch', function () {
        $this->actingAs($this->admin);
        $this->product->update(['status' => 'active']);
        Livewire::test(ProductQuickView::class, ['productId' => $this->product->id])->assertSet('visible', true)
            ->set('visible', false)->assertDispatched('product-changed');
        expect($this->product->refresh()->status)->toBe('inactive');
        Livewire::test(ProductQuickView::class, ['productId' => $this->product->id])->set('visible', true);
        expect($this->product->refresh()->status)->toBe('active');
    });

    it('does not restore an archived product from the quick view', function () {
        $this->actingAs($this->admin);
        $this->product->update(['status' => 'archived']);
        Livewire::test(ProductQuickView::class, ['productId' => $this->product->id])->set('visible', true);
        expect($this->product->refresh()->status)->toBe('archived');
    });

    it('is read-only for staff who cannot edit the catalog, and rejects a tampered switch name', function () {
        $this->actingAs($this->wbManager); // catalog.view only
        Livewire::test(ProductQuickView::class, ['productId' => $this->product->id])->set('flags.is_featured', true)->assertForbidden();
        expect($this->product->refresh()->is_featured)->toBeFalse();
        $this->actingAs($this->admin);
        Livewire::test(ProductQuickView::class, ['productId' => $this->product->id])->set('flags.sku', true)->assertStatus(422);
    });

    it('shows stock only for the viewer’s branches', function () {
        $this->actingAs($this->wbManager);
        $rows = Livewire::test(ProductQuickView::class, ['productId' => $this->product->id])->viewData('stock')['rows'];
        expect($rows)->toHaveCount(1)->and($rows->first()['branch']->id)->toBe($this->wb->id)->and($rows->first()['available'])->toBe(10);
        $this->actingAs($this->admin);
        $all = Livewire::test(ProductQuickView::class, ['productId' => $this->product->id])->viewData('stock')['rows'];
        expect($all->pluck('available')->sort()->values()->all())->toBe([3, 10]);
    });

    it('shows no stock section to staff without inventory access', function () {
        $this->actingAs(staffWithRole('marketing_manager')); // catalog.view, no inventory.view
        expect(Livewire::test(ProductQuickView::class, ['productId' => $this->product->id])->viewData('stock')['visible'])->toBeFalse();
    });
});

describe('purchase order window', function () {
    beforeEach(function () {
        $this->po = app(PurchasingService::class)->create($this->admin, App\Domain\Inventory\Models\Supplier::factory()->create()->id, $this->wb->id,
            [['variant_id' => $this->variant->id, 'quantity' => 5, 'unit_cost' => '400.00']]);
    });

    it('opens from ?view= for staff of that branch and shows the order with the same actions as its page', function () {
        $this->actingAs($this->wbManager);
        Livewire::withQueryParams(['view' => $this->po->id])->test(PurchaseOrderIndex::class)
            ->assertSet('showView', true)->assertSee($this->po->number)->assertSeeHtml(route('admin.purchase-orders.show', $this->po));
        Livewire::test(PurchaseOrderShow::class, ['po' => $this->po, 'embedded' => true])->assertSet('embedded', true)->assertSee(__('inventory_admin.timeline'));
    });

    it('opens nothing for another branch’s order, and the embedded component refuses it too', function () {
        $this->actingAs($this->prlManager);
        Livewire::withQueryParams(['view' => $this->po->id])->test(PurchaseOrderIndex::class)->assertSet('showView', false)->assertSet('viewId', '');
        Livewire::test(PurchaseOrderIndex::class)->call('openView', $this->po->id)->assertSet('showView', false);
        Livewire::test(PurchaseOrderShow::class, ['po' => $this->po, 'embedded' => true])->assertForbidden();
    });

    it('audits a change made in the window, refreshes the list and does not let the client switch embedded mode', function () {
        $this->actingAs($this->wbManager);
        $c = Livewire::test(PurchaseOrderShow::class, ['po' => $this->po, 'embedded' => true])->call('submit')->assertDispatched('inventory-doc-changed');
        expect($this->po->refresh()->status)->toBe('pending_approval');
        expect(fn () => $c->set('embedded', false))->toThrow('Cannot update locked property');
    });

    it('opens the new order beside the list after creating it (no page change) and clears state on close', function () {
        $this->actingAs($this->wbManager);
        $c = Livewire::test(PurchaseOrderIndex::class)->call('create')->set('form.supplier_id', $this->po->supplier_id)->set('form.branch_id', $this->wb->id)
            ->call('pickVariant', $this->variant->id)->set('lines.0.unit_cost', '410')->call('save')->assertHasNoErrors()->assertNoRedirect()
            ->assertSet('showEditor', false)->assertSet('showView', true);
        expect($c->get('viewId'))->not->toBe('')->and(App\Domain\Inventory\Models\PurchaseOrder::whereKey($c->get('viewId'))->exists())->toBeTrue();
        $c->set('showView', false)->assertSet('viewId', '');
    });
});

describe('transfer window', function () {
    beforeEach(function () {
        $this->transfer = app(TransferService::class)->create($this->admin, $this->wb->id, $this->prl->id, [['variant_id' => $this->variant->id, 'quantity' => 2]]);
    });

    it('opens for staff at the source or the destination and for nobody else', function () {
        foreach ([$this->wbManager, $this->prlManager] as $user) {
            $this->actingAs($user);
            Livewire::withQueryParams(['view' => $this->transfer->id])->test(TransferIndex::class)->assertSet('showView', true)->assertSee($this->transfer->number);
        }
        $other = Branch::factory()->create();
        $this->actingAs(staffWithRole('branch_manager', $other));
        Livewire::withQueryParams(['view' => $this->transfer->id])->test(TransferIndex::class)->assertSet('showView', false)->assertSet('viewId', '');
        Livewire::test(TransferShow::class, ['transfer' => $this->transfer, 'embedded' => true])->assertForbidden();
    });

    it('runs an action in the window, audits it and refreshes the list', function () {
        $this->actingAs($this->wbManager);
        app(TransferService::class)->submit($this->admin, $this->transfer);
        $item = $this->transfer->items()->first();
        Livewire::test(TransferShow::class, ['transfer' => $this->transfer->refresh(), 'embedded' => true])->set("qty.{$item->id}", 2)->call('approve')->assertDispatched('inventory-doc-changed');
        expect($this->transfer->refresh()->status)->toBe('approved');
    });

    it('opens the new transfer beside the list after creating it', function () {
        $this->actingAs($this->wbManager);
        $c = Livewire::test(TransferIndex::class)->call('create')->set('form.source_branch_id', $this->wb->id)->set('form.destination_branch_id', $this->prl->id)
            ->call('pickVariant', $this->variant->id)->set('lines.0.quantity', 1)->call('save')->assertHasNoErrors()->assertNoRedirect()
            ->assertSet('showEditor', false)->assertSet('showView', true);
        expect(StockTransfer::whereKey($c->get('viewId'))->exists())->toBeTrue();
    });
});

describe('stock count window', function () {
    beforeEach(function () {
        $this->session = app(StocktakingService::class)->start($this->admin, $this->wb->id);
    });

    it('shows the count summary for staff of that branch, offers the count screen and nothing for other branches', function () {
        $this->actingAs($this->wbManager);
        Livewire::withQueryParams(['view' => $this->session->id])->test(StocktakingIndex::class)
            ->assertSet('showView', true)->assertSee($this->session->number)->assertSeeHtml(route('admin.stocktaking.show', $this->session))
            ->assertSee(__('drawer_admin.continue_count'));
        $this->actingAs($this->prlManager);
        Livewire::withQueryParams(['view' => $this->session->id])->test(StocktakingIndex::class)->assertSet('showView', false)->assertSet('viewId', '');
        expect(StocktakingSession::count())->toBe(1);
    });
});

describe('issue log window', function () {
    beforeEach(function () {
        $this->issue = IssueReport::create(['reference' => 'ISS-009001', 'kind' => 'bug', 'app' => 'dashboard', 'severity' => 'high', 'status' => 'open', 'title' => 'Cart badge is wrong', 'reported_by' => $this->admin->id]);
    });

    it('opens from ?view= with the issue and its resolution box', function () {
        $this->issue->update(['resolution' => 'Fixed in 2.1']);
        $this->actingAs($this->admin);
        Livewire::withQueryParams(['view' => $this->issue->id])->test(IssueIndex::class)
            ->assertSet('showView', true)->assertSet('resolution', 'Fixed in 2.1')->assertSee('Cart badge is wrong')->assertSee('ISS-009001');
    });

    it('changes the status from the window with the typed resolution, then clears state when closed', function () {
        $this->actingAs($this->admin);
        Livewire::test(IssueIndex::class)->call('openView', $this->issue->id)->set('resolution', 'Patched')->call('setStatus', 'resolved')
            ->assertDispatched('toast')->set('showView', false)->assertSet('viewId', '');
        expect($this->issue->refresh()->status)->toBe('resolved')->and($this->issue->resolution)->toBe('Patched')->and($this->issue->resolved_by)->toBe($this->admin->id);
    });

    it('lets a read-only viewer look but not change', function () {
        $viewer = staffWithRole('auditor');
        $this->actingAs($viewer);
        Livewire::test(IssueIndex::class)->call('openView', $this->issue->id)->assertSet('showView', true)->call('setStatus', 'resolved')->assertForbidden();
        expect($this->issue->refresh()->status)->toBe('open');
    });

    it('opens nothing for a made-up id', function () {
        $this->actingAs($this->admin);
        Livewire::withQueryParams(['view' => $this->uuid])->test(IssueIndex::class)->assertSet('showView', false)->assertSet('viewId', '');
    });
});

describe('support ticket window', function () {
    beforeEach(function () {
        $this->customer = User::factory()->create();
        $mk = fn (Branch $b, string $n) => SupportTicket::create(['number' => $n, 'user_id' => $this->customer->id, 'branch_id' => $b->id, 'category' => 'order', 'subject' => 'Where is my order '.$n, 'priority' => 'normal', 'status' => 'open']);
        $this->wbTicket = $mk($this->wb, 'TK-900001');
        $this->prlTicket = $mk($this->prl, 'TK-900002');
        SupportMessage::create(['support_ticket_id' => $this->wbTicket->id, 'author_id' => $this->customer->id, 'author_type' => 'customer', 'body' => 'Any news?', 'is_internal' => false]);
        $this->agent = staffWithRole('customer_support');
    });

    it('opens from ?ticket= with the conversation and the reply box', function () {
        $this->actingAs($this->agent);
        Livewire::withQueryParams(['ticket' => $this->wbTicket->id])->test(SupportIndex::class)
            ->assertSet('showThread', true)->assertSee('Where is my order TK-900001')->assertSee('Any news?');
    });

    it('opens on click, replies without closing the window, and clears the id when closed', function () {
        $this->actingAs($this->agent);
        Livewire::test(SupportIndex::class)->call('open', $this->wbTicket->id)->assertSet('showThread', true)->assertSet('selected', $this->wbTicket->id)
            ->set('reply', 'We are on it')->call('send')->assertHasNoErrors()->assertSet('showThread', true)
            ->set('showThread', false)->assertSet('selected', '');
        expect(SupportMessage::where('support_ticket_id', $this->wbTicket->id)->where('body', 'We are on it')->exists())->toBeTrue();
    });

    it('keeps branch scoping: a branch agent never gets another branch’s ticket', function () {
        $agent = staffWithRole('order_manager', $this->wb); // support.view at WB only
        $this->actingAs($agent);
        Livewire::withQueryParams(['ticket' => $this->prlTicket->id])->test(SupportIndex::class)->assertSet('showThread', false)->assertSet('selected', '');
        Livewire::withQueryParams(['ticket' => 'not-a-uuid'])->test(SupportIndex::class)->assertSet('showThread', false)->assertSet('selected', '')->assertOk();
        Livewire::test(SupportIndex::class)->call('open', $this->prlTicket->id)->assertStatus(404);
    });
});
