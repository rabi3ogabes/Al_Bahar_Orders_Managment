<?php

require_once __DIR__.'/../Orders/PrintSupport.php';

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Printing\PrintKind;
use App\Domain\Orders\Printing\PrintParts;
use App\Domain\Orders\Printing\PrintSettings;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Organization\SettingsIndex;
use App\Livewire\Admin\Sales\DeliveryIndex;
use App\Livewire\Admin\Sales\OrderIndex;
use App\Livewire\Admin\Sales\OrderShow;
use App\Livewire\Admin\Sales\PickupIndex;
use App\Livewire\Admin\Sales\PrintWindow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
| The dashboard side of printing (docs/guides/PICKING_LIST_AND_DELIVERY_NOTE.md): the print buttons and the reprint counter on a part, the "print for the
| branch" window on the Orders, Deliveries and Pickup screens, and Settings > Printing.
*/

beforeEach(function () {
    $this->travelTo(qatar('2026-11-19 12:00'));
    $this->seed(RolesAndPermissionsSeeder::class);
});

// ─── The part: buttons and the reprint counter ─────────────────────────────────────────────────────────────────────────────────

it('shows the picking list and delivery note buttons on a delivery part, each opening the PDF in a new tab', function () {
    $a = printWorld();
    $page = Livewire::actingAs($a->mgr)->test(OrderShow::class, ['order' => $a->order])->assertOk();
    $html = $page->html();

    expect($html)->toContain('href="'.route('admin.print.picking.part', $a->f).'"')->toContain('href="'.route('admin.print.note.part', $a->f).'"')
        ->toContain('target="_blank"')->toContain('Picking list')->toContain('Delivery note')->toContain('Not printed yet')->toContain('data-part-print="'.$a->f->id.'"');
});

it('offers only the picking list on a pickup part and nothing on a cancelled part', function () {
    $w = commerceWorld();
    actingAsCustomer($w->customer);
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1]);
    $order = Order::with('fulfillments')->findOrFail(quoteAndPlace(['fulfillment_type' => 'pickup', 'branch_id' => $w->a->id, 'payment_method' => 'pay_at_pickup'])->assertCreated()->json('data.order.id'));
    $f = $order->fulfillments->first();
    $mgr = staffWithRole('branch_manager', $w->a);

    $html = Livewire::actingAs($mgr)->test(OrderShow::class, ['order' => $order])->html();
    expect($html)->toContain(route('admin.print.picking.part', $f))->not->toContain(route('admin.print.note.part', $f));

    $f->update(['status' => 'cancelled']);
    expect(Livewire::actingAs($mgr)->test(OrderShow::class, ['order' => $order->fresh()])->html())->not->toContain('data-part-print');
});

it('shows how often each document was printed and by whom, and warns on a second delivery note', function () {
    $a = printWorld();
    printPart($a->mgr, 'picking', $a->f)->assertOk();
    printPart($a->mgr, 'note', $a->f)->assertOk();
    printPart($a->mgr, 'note', $a->f)->assertOk();
    $this->travelTo(qatar('2026-11-19 14:05'));
    printPart($a->mgr, 'note', $a->f)->assertOk();

    $html = Livewire::actingAs($a->mgr)->test(OrderShow::class, ['order' => $a->order])->html();

    expect($html)->toContain('Printed once, by '.e($a->mgr->name).' at 12:00')->toContain('Printed 3 times, last by '.e($a->mgr->name).' at 14:05')
        ->and(substr_count($html, 'Printed before: send only one copy with the goods.'))->toBe(1); // only under the delivery note
    // Only the delivery note's counter turns into a warning once it passed one.
    preg_match('/data-print-count="picking_list"[^>]*class="[^"]*"|class="([^"]*)"[^>]*data-print-count="picking_list"/', $html, $picking);
    preg_match('/class="([^"]*)"[^>]*data-print-count="delivery_note"/', $html, $note);
    expect($note[1] ?? '')->toContain('text-amber-soft')->and($picking[1] ?? '')->not->toContain('text-amber-soft');
});

it('shows the counter in Arabic too', function () {
    $a = printWorld();
    printPart($a->mgr, 'note', $a->f)->assertOk();
    printPart($a->mgr, 'note', $a->f)->assertOk();
    app()->setLocale('ar');

    $html = Livewire::actingAs($a->mgr)->test(OrderShow::class, ['order' => $a->order])->html();

    expect($html)->toContain('طبعت 2 مرات')->toContain(__('print_docs.ui.picking_list'))->toContain(__('print_docs.ui.delivery_note'))->toContain(__('print_docs.ui.printed_never'));
});

it('draws nothing for a person who may not see the part\'s branch', function () {
    $s = opsSplitOrder();
    $html = Livewire::actingAs(User::factory()->superAdmin()->create())->test(OrderShow::class, ['order' => $s->order])->html();

    expect(substr_count($html, 'data-part-print='))->toBe(2); // the admin sees both parts

    $mgrA = Livewire::actingAs($s->mgrA)->test(OrderShow::class, ['order' => $s->order])->html();
    expect($mgrA)->toContain('data-part-print="'.$s->fa->id.'"')->not->toContain($s->fb->id);
});

// ─── The row buttons on the lists ───────────────────────────────────────────────────────────────────────────────────────────────

it('puts a picking list link on the parts waiting for dispatch and a delivery note link on every open delivery', function () {
    $w = commerceWorld();
    $mgr = staffWithRole('order_manager', $w->a);
    $ready = printPlaced((object) ['customer' => $w->customer, 'w' => $w, 'mgr' => $mgr]);
    printWalk((object) ['mgr' => $mgr], $ready, ['accepted', 'preparing', 'ready']);
    $delivery = printDelivery($w, $mgr, driverFor($w->a));

    $toDispatch = Livewire::actingAs($mgr)->test(DeliveryIndex::class)->html();
    $scheduled = Livewire::actingAs($mgr)->test(DeliveryIndex::class, ['lane' => 'scheduled'])->html();

    expect($toDispatch)->toContain(route('admin.print.picking.part', $ready))->and($scheduled)->toContain(route('admin.print.note.part', $delivery->order_fulfillment_id));
});

it('puts a picking list link on the pickup parts that are still being prepared', function () {
    $w = commerceWorld();
    actingAsCustomer($w->customer);
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1]);
    $f = Order::findOrFail(quoteAndPlace(['fulfillment_type' => 'pickup', 'branch_id' => $w->a->id, 'payment_method' => 'pay_at_pickup'])->assertCreated()->json('data.order.id'))->fulfillments->first();
    $mgr = staffWithRole('branch_manager', $w->a);

    expect(Livewire::actingAs($mgr)->test(PickupIndex::class, ['tab' => 'preparing'])->html())->toContain(route('admin.print.picking.part', $f));
});

it('does not show the delivery note link to someone who may not see orders', function () {
    $w = commerceWorld();
    $mgr = staffWithRole('order_manager', $w->a);
    $driver = driverFor($w->a);
    $delivery = printDelivery($w, $mgr, $driver);
    $dispatcher = staffWithRole('delivery_employee', $w->a);

    expect(Livewire::actingAs($dispatcher)->test(DeliveryIndex::class, ['lane' => 'scheduled'])->html())->not->toContain(route('admin.print.note.part', $delivery->order_fulfillment_id));
});

// ─── The window ────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('puts the print button on the Orders, Deliveries and Pickup screens', function () {
    $admin = User::factory()->superAdmin()->create();

    foreach ([OrderIndex::class, DeliveryIndex::class, PickupIndex::class] as $screen) {
        Livewire::actingAs($admin)->test($screen)->assertOk()->assertSeeLivewire(PrintWindow::class)->assertSee('data-testid="print-window-button"', false);
    }
});

it('stays closed until opened, then counts the parts and links to the batch PDF', function () {
    $s = opsSplitOrder();
    $admin = User::factory()->superAdmin()->create();

    $page = Livewire::actingAs($admin)->test(PrintWindow::class, ['context' => 'orders'])->assertSet('show', false)->assertDontSee('data-testid="print-summary"', false);
    $page->call('open')->assertSet('show', true)->assertSee('2 parts')->assertSee('data-testid="print-open-pdf"', false)
        ->assertSee('href="'.route('admin.print.picking.batch').'"', false)->assertSeeText('Everything waiting to be prepared');
});

it('counts only the parts of the user\'s own branch and links with their branch filter', function () {
    $s = opsSplitOrder();

    Livewire::actingAs($s->mgrA)->test(PrintWindow::class, ['context' => 'orders'])->call('open')->assertSee('1 part')->assertDontSee('2 parts');
    $admin = User::factory()->superAdmin()->create();
    Livewire::actingAs($admin)->test(PrintWindow::class, ['context' => 'orders'])->call('open')->set('branch', $s->w->b->id)->assertSee('1 part')
        ->assertSee('href="'.e(route('admin.print.picking.batch', ['branch' => $s->w->b->id])).'"', false)
        ->set('branch', (string) Str::uuid())->assertSee('2 parts'); // a branch the user may not pick is ignored, never widened
});

it('offers the branch choice only to someone with more than one branch', function () {
    $s = opsSplitOrder();

    Livewire::actingAs($s->mgrA)->test(PrintWindow::class, ['context' => 'orders'])->call('open')->assertDontSee('All my branches');
    Livewire::actingAs(User::factory()->superAdmin()->create())->test(PrintWindow::class, ['context' => 'orders'])->call('open')->assertSee('All my branches');
});

it('says so and disables the link when there is nothing to print', function () {
    $a = printWorld();
    printWalk($a, $a->f, ['ready']);

    Livewire::actingAs($a->mgr)->test(PrintWindow::class, ['context' => 'orders'])->call('open')->assertSee('No parts')->assertSee('Nothing to print for this choice.')
        ->assertDontSee('data-testid="print-open-pdf"', false)->assertSee('aria-disabled="true"', false);
});

it('switches to delivery notes with a day and a driver and links with them', function () {
    $w = commerceWorld();
    $mgr = staffWithRole('order_manager', $w->a);
    $driver = driverFor($w->a);
    printDelivery($w, $mgr, $driver);
    printDelivery($w, $mgr);

    $page = Livewire::actingAs($mgr)->test(PrintWindow::class, ['context' => 'orders'])->call('open')->set('kind', 'note')->assertSee('2 parts')->assertSee($driver->name.' (1)')->assertSee('No driver yet (1)');
    $page->set('driver', $driver->id)->assertSee('1 part')->assertSee('href="'.e(route('admin.print.note.batch', ['date' => '2026-11-19', 'driver' => $driver->id])).'"', false);
    $page->set('driver', 'none')->assertSee('1 part')->set('driver', 'nonsense')->assertSee('2 parts');
    $page->set('date', '2026-11-20')->assertSet('driver', '')->assertSee('No parts')->set('date', 'garbage')->assertSet('date', '2026-11-19');
});

it('fits the window to the screen it sits on', function () {
    $a = printWorld();
    $kinds = fn (string $context) => Livewire::actingAs($a->mgr)->test(PrintWindow::class, ['context' => $context])->call('open')->assertSet('show', true);

    $kinds('deliveries')->assertSet('kind', 'note')->assertSee('Picking list')->assertSee('Delivery note');
    $kinds('pickup')->assertSet('kind', 'picking')->assertDontSee('Delivery note')->assertSee('No parts')->set('kind', 'note')->assertSet('kind', 'picking')->assertSee('No parts');
    $kinds('orders')->assertSet('kind', 'picking')->assertSee('1 part')->set('kind', 'nonsense')->assertSet('kind', 'picking');
    $kinds('whatever')->assertSet('context', 'orders');
});

it('tells how many will print when the list is longer than one PDF holds', function () {
    $a = printWorld();
    $mock = Mockery::mock(PrintParts::class);
    $mock->shouldReceive('countWaiting')->andReturn(120);
    app()->instance(PrintParts::class, $mock);

    Livewire::actingAs($a->mgr)->test(PrintWindow::class, ['context' => 'orders'])->call('open')->assertSee('120 parts')
        ->assertSee('The first '.PrintParts::MAX_PARTS.' of 120 parts will print, oldest first.');
});

it('does not draw the button for staff without order access, and refuses to open', function () {
    $w = commerceWorld();
    $driver = driverFor($w->a);
    $page = Livewire::actingAs($driver)->test(PrintWindow::class, ['context' => 'orders'])->assertDontSee('data-testid="print-window-button"', false);

    $page->call('open')->assertForbidden();
});

it('speaks Arabic', function () {
    $a = printWorld();
    app()->setLocale('ar');

    Livewire::actingAs($a->mgr)->test(PrintWindow::class, ['context' => 'orders'])->call('open')->assertSee(__('print_docs.ui.window_title'))->assertSee(__('print_docs.ui.for_prepare'))
        ->assertSee('جزء واحد')->assertSee(__('print_docs.ui.open_pdf'));
});

// ─── Settings > Printing ─────────────────────────────────────────────────────────────────────────────────────────────────────

it('has a Printing tab with the paper of each document and the prices switch, with their defaults', function (string $locale) {
    app()->setLocale($locale);
    $admin = User::factory()->superAdmin()->create();
    $page = Livewire::actingAs($admin)->test(SettingsIndex::class)->set('tab', 'printing')->assertOk();
    $html = $page->html();

    $page->assertSet('values.printing.printing__picking_paper', 'a4')->assertSet('values.printing.printing__note_paper', 'a4')->assertSet('values.printing.printing__note_prices', false);
    foreach (['picking_paper', 'note_paper', 'note_prices'] as $field) {
        expect($html)->toContain(e(__('print_docs.setting.'.$field, [], $locale)))->toContain(e(__('print_docs.setting_hint.'.$field, [], $locale)));
    }
    expect($html)->toContain(e(__('org_admin.settings_tab.printing', [], $locale)))->toContain(e(__('org_admin.settings_tab_hint.printing', [], $locale)))
        ->toContain(e(__('org_admin.option.a4', [], $locale)))->toContain(e(__('org_admin.option.a5', [], $locale)))->not->toContain('print_docs.')->not->toContain('org_admin.');
})->with(['en', 'ar']);

it('saves the paper and the prices switch, audited, and the documents follow', function () {
    $admin = User::factory()->superAdmin()->create();

    Livewire::actingAs($admin)->test(SettingsIndex::class)->set('tab', 'printing')
        ->set('values.printing.printing__picking_paper', 'a5')->set('values.printing.printing__note_paper', 'a5')->set('values.printing.printing__note_prices', true)->call('save')->assertHasNoErrors();

    $settings = app(PrintSettings::class);
    expect($settings->paper(PrintKind::Picking))->toBe('a5')->and($settings->paper(PrintKind::Note))->toBe('a5')->and($settings->notePricesAlways())->toBeTrue()
        ->and(AuditLog::where('action', 'settings.updated')->get()->pluck('metadata.key')->all())->toContain('printing.picking_paper', 'printing.note_paper', 'printing.note_prices');
});

it('refuses a paper size that is not offered, and falls back to A4 when a stored value is not one', function () {
    $admin = User::factory()->superAdmin()->create();
    Livewire::actingAs($admin)->test(SettingsIndex::class)->set('tab', 'printing')->set('values.printing.printing__note_paper', 'a3')->call('save')
        ->assertHasErrors(['values.printing.printing__note_paper']);

    app(Settings::class)->set('printing.note_paper', 'letter');
    expect(app(PrintSettings::class)->paper(PrintKind::Note))->toBe('a4');
});

it('keeps the Printing tab to people who may change settings', function () {
    $mgr = staffWithRole('branch_manager', commerceWorld()->a);

    Livewire::actingAs($mgr)->test(SettingsIndex::class)->assertForbidden();
});

// ─── Words ───────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('labels the two audit actions in both languages and keeps English and Arabic words side by side', function () {
    foreach (PrintKind::cases() as $kind) {
        $key = 'org_admin.act.'.str_replace('.', '__', $kind->auditAction());
        expect(Lang::has($key, 'en'))->toBeTrue()->and(Lang::has($key, 'ar'))->toBeTrue()->and(__($key, [], 'en'))->not->toBe(__($key, [], 'ar'));
    }
    $keys = fn (string $locale) => collect(Arr::dot(require base_path("lang/{$locale}/print_docs.php")))->keys()->sort()->values()->all();

    expect($keys('ar'))->toBe($keys('en'))->and(Lang::has('org_admin.option.a4', 'ar'))->toBeTrue()->and(Lang::has('org_admin.option.a5', 'ar'))->toBeTrue();
});
