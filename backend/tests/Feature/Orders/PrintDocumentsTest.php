<?php

require_once __DIR__.'/PrintSupport.php';

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\DeliverySlot;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderNote;
use App\Domain\Orders\Models\SubstitutionRequest;
use App\Domain\Orders\Printing\PrintHistory;
use App\Domain\Orders\Printing\PrintKind;
use App\Domain\Orders\Printing\PrintSheets;
use App\Domain\Search\Support\SearchQuery;
use App\Domain\Settings\Services\Settings;
use App\Support\Code128;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/*
| The printed picking list and delivery note of one branch part: what the paper says, who may print it, and the audit trail with the reprint counter
| (docs/guides/PICKING_LIST_AND_DELIVERY_NOTE.md). The PDFs are read back with pdftotext.
*/

beforeEach(function () {
    $this->travelTo(qatar('2026-11-19 12:00'));
});

// ─── The picking list ─────────────────────────────────────────────────────────────────────────────────────────────────────

it('prints the picking list with the lines in bin order, quantities, SKUs and the barcode of the part number', function () {
    $a = printWorld();
    $a->order->update(['customer_notes' => 'Call before arriving, gate code 4421']);
    OrderNote::create(['order_id' => $a->order->id, 'order_fulfillment_id' => $a->f->id, 'body' => 'Sofa box is damaged, check it', 'is_internal' => true]);

    $r = printPart($a->mgr, 'picking', $a->f)->assertOk();
    $bytes = $r->getContent();
    $text = printText($bytes);

    expect($r->headers->get('content-type'))->toBe('application/pdf')->and($r->headers->get('content-disposition'))->toContain('inline')->toContain('picking-list-'.$a->f->number.'.pdf')
        ->and($r->headers->get('cache-control'))->toContain('no-store')->and(substr($bytes, 0, 5))->toBe('%PDF-')
        ->and(printHas($text, 'Picking list'))->toBeTrue()->and(printHas($text, $a->f->number))->toBeTrue()->and(printHas($text, $a->order->number))->toBeTrue()
        ->and(printHas($text, $a->l1->sku))->toBeTrue()->and(printHas($text, $a->l2->sku))->toBeTrue()
        ->and(printHas($text, 'Call before arriving, gate code 4421'))->toBeTrue()->and(printHas($text, 'Sofa box is damaged'))->toBeTrue();
    // Walking order: the lamp's bin (A-3-2) comes before the sofas' bin (B-12-1), although the sofas were ordered first.
    expect(strpos($text, 'A-3-2'))->toBeLessThan(strpos($text, 'B-12-1'))
        ->and(strpos($text, $a->l2->sku))->toBeLessThan(strpos($text, $a->l1->sku));
});

it('builds the sheet with every line, its bin, tick-box data and a Code 128 barcode that carries the part number', function () {
    $a = printWorld();
    $part = $a->f->load('order', 'branch', 'items', 'slot', 'deliveries');
    $sheet = app(PrintSheets::class)->build(PrintKind::Picking, collect([$part]), [$part->id => 1], $a->mgr)[0];

    expect(array_column($sheet['lines'], 'sku'))->toBe([$a->l2->sku, $a->l1->sku])->and(array_column($sheet['lines'], 'bin'))->toBe(['A-3-2', 'B-12-1'])
        ->and(array_column($sheet['lines'], 'qty'))->toBe([1, 2])->and($sheet['units'])->toBe(3)->and($sheet['missing_bins'])->toBe(0)
        ->and($sheet['number'])->toBe($a->f->number)->and($sheet['paper'])->toBe('a4')->and($sheet['copy'])->toBe(1);
    expect(base64_decode(substr((string) $sheet['barcode'], strlen('data:image/svg+xml;base64,'))))->toBe(Code128::svg($a->f->number, 40));

    $html = view('documents.picking-list', ['sheets' => [$sheet], 'paper' => 'a4'])->render();
    expect($html)->toContain('<img src="data:image/svg+xml;base64,')->toContain('class="tick"')->toContain($a->f->number);
});

it('prints the part number as a code the dashboard search understands', function () {
    $a = printWorld();

    // "AB100123-1" is read as order 100123: the scanner types the code, the command palette finds the order.
    expect($a->f->number)->toBe($a->order->number.'-1')->and(SearchQuery::from($a->f->number)->order)->toBe(substr($a->order->number, 2))
        ->and(Code128::supports($a->f->number))->toBeTrue();
});

it('lists lines without a bin last and says how many have none', function () {
    $a = printWorld();
    printBin($a->w->a, $a->w->v1, null);
    $text = printText(printPart($a->mgr, 'picking', $a->f)->assertOk()->getContent());

    expect(strpos($text, 'A-3-2'))->toBeLessThan(strpos($text, $a->l1->sku))->and(printHas($text, 'No bin'))->toBeTrue()->and(printHas($text, '1 lines have no bin set'))->toBeTrue();
});

it('flags assembly, two people and the other parts of a split order on the picking list', function () {
    $a = printWorld(['qty' => [0, 1], 'prepare' => function ($w) {
        $w->p1->update(['assembly_fee' => '100.00']);
        test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 2, 'assembly' => true])->assertCreated();
    }]);
    $a->w->p1->update(['is_oversized' => true]);

    $text = printText(printPart($a->mgr, 'picking', $a->f)->assertOk()->getContent());

    expect(printHas($text, 'Assembly requested'))->toBeTrue()->and(printHas($text, 'Two people'))->toBeTrue()->and(printHas($text, 'Oversized'))->toBeTrue();
});

it('shows the assembly the customer paid for on the delivery note, with its price on a cash on delivery note', function () {
    $a = printWorld(['qty' => [0, 1], 'prepare' => function ($w) {
        $w->p1->update(['assembly_fee' => '100.00']);
        test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 2, 'assembly' => true])->assertCreated();
    }]);
    $text = printText(printPart($a->mgr, 'note', $a->f)->assertOk()->getContent());

    expect(printHas($text, 'Assembly requested'))->toBeTrue()->and(printHas($text, 'QAR 200.00'))->toBeTrue();
    $sheet = app(PrintSheets::class)->build(PrintKind::Note, collect([$a->f->load('order', 'branch', 'items', 'slot', 'deliveries')]), [], null)[0];
    expect($sheet['assembly'])->toBeTrue()->and(collect($sheet['lines'])->firstWhere('assembly', true)['assembly_total'])->toBe('200.00');
});

it('shows which part of a split order this is, and names the other parts only to someone who may see them', function () {
    $s = opsSplitOrder();
    printBin($s->w->a, $s->w->v1, 'A-1-1');
    printBin($s->w->b, $s->w->v1, 'Z-9-9');

    $ta = printText(printPart($s->mgrA, 'picking', $s->fa)->assertOk()->getContent());
    $tb = printText(printPart($s->mgrB, 'picking', $s->fb)->assertOk()->getContent());
    $all = printText(printPart(User::factory()->superAdmin()->create(), 'picking', $s->fa)->assertOk()->getContent());

    expect(printHas($ta, $s->fa->number))->toBeTrue()->and(printHas($ta, 'Part 1 of 2'))->toBeTrue()->and(printHas($ta, 'A-1-1'))->toBeTrue()->and(printHas($ta, 'Z-9-9'))->toBeFalse()
        ->and(printHas($ta, $s->fb->number))->toBeFalse()->and(printHas($ta, 'Other parts of this order'))->toBeFalse()   // branch A never reads about branch B's parcel
        ->and(printHas($tb, 'Part 2 of 2'))->toBeTrue()->and(printHas($tb, 'Z-9-9'))->toBeTrue()->and(printHas($tb, 'A-1-1'))->toBeFalse()->and(printHas($tb, $s->fa->number))->toBeFalse()
        ->and(printHas($all, 'Other parts of this order'))->toBeTrue()->and(printHas($all, $s->fb->number))->toBeTrue();
    // Each part lists only its own units (3 at branch A, 2 at branch B).
    $sheets = app(PrintSheets::class);
    expect(collect($sheets->build(PrintKind::Picking, collect([$s->fa->load('order', 'branch', 'items', 'slot', 'deliveries')]), [], null)[0]['lines'])->sum('qty'))->toBe(3)
        ->and(collect($sheets->build(PrintKind::Picking, collect([$s->fb->load('order', 'branch', 'items', 'slot', 'deliveries')]), [], null)[0]['lines'])->sum('qty'))->toBe(2);
});

it('marks a replacement that waits for the customer, with what it replaces and until when', function () {
    $a = printWorld();
    [$p, $v] = substituteFor($a->w, '600.00');
    printBin($a->w->a, $v, 'C-7-7');
    actingAsStaff($a->mgr);
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'replace', 'variant_id' => $v->id, 'quantity' => 2]])->assertOk();
    $q = SubstitutionRequest::where('order_id', $a->order->id)->firstOrFail();

    $text = printText(printPart($a->mgr, 'picking', $a->f)->assertOk()->getContent());

    expect($q->status)->toBe('waiting')->and(printHas($text, 'Waiting for the customer'))->toBeTrue()->and(printHas($text, 'the customer has not answered yet (until 12:30)'))->toBeTrue()
        ->and(printHas($text, 'Instead of'))->toBeTrue()->and(printHas($text, 'C-7-7'))->toBeTrue()->and(printHas($text, 'Keep preparing'))->toBeTrue();
    // The line that was replaced is gone from the list: only what the picker has to take down is printed.
    expect(printHas($text, 'B-12-1'))->toBeFalse();
});

it('says "reduced from" for a line the branch took fewer of', function () {
    $a = printWorld();
    actingAsStaff($a->mgr);
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertOk();

    expect(printHas(printText(printPart($a->mgr, 'picking', $a->f)->assertOk()->getContent()), 'Reduced from 2'))->toBeTrue();
});

it('shows the slot the customer chose', function () {
    $a = printWorld();
    $slot = DeliverySlot::create(['branch_id' => $a->w->a->id, 'fulfillment_type' => 'delivery', 'day_of_week' => 5, 'starts_at' => '16:00:00', 'ends_at' => '18:00:00', 'capacity' => 5]);
    $a->f->update(['delivery_slot_id' => $slot->id, 'slot_date' => '2026-11-20']);

    $text = printText(printPart($a->mgr, 'picking', $a->f)->assertOk()->getContent());

    expect(printHas($text, 'Fri 20 Nov 2026'))->toBeTrue()->and(printHas($text, '16:00–18:00'))->toBeTrue();
});

it('lists a pickup part with its pickup time and no delivery note', function () {
    $w = commerceWorld();
    actingAsCustomer($w->customer);
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1]);
    $order = Order::with('fulfillments')->findOrFail(quoteAndPlace(['fulfillment_type' => 'pickup', 'branch_id' => $w->a->id, 'payment_method' => 'pay_at_pickup'])->assertCreated()->json('data.order.id'));
    $f = $order->fulfillments->first();
    $mgr = staffWithRole('order_manager', $w->a);

    $text = printText(printPart($mgr, 'picking', $f)->assertOk()->getContent());

    expect(printHas($text, 'Pickup time'))->toBeTrue()->and(printHas($text, 'Pickup'))->toBeTrue();
    printPart($mgr, 'note', $f)->assertNotFound();
    expect(printAuditCount($f, PrintKind::Note))->toBe(0)->and(printAuditCount($f, PrintKind::Picking))->toBe(1);
});

// ─── The delivery note ────────────────────────────────────────────────────────────────────────────────────────────────

it('prints the delivery note of a cash on delivery order: who, where, the items with prices, the amount to collect big, a signature line and the branch phone', function () {
    $a = printWorld();
    $a->w->a->update(['phone' => '+97444112233']);
    $a->order->update(['customer_notes' => 'Ring the bell twice']);
    $a->w->address->update(['landmark' => 'Behind Al Meera', 'instructions' => 'Gate code 4421, second floor']);
    $a->order->update(['address_snapshot' => array_merge($a->w->address->fresh()->snapshot(), ['latitude' => 25.285447, 'longitude' => 51.53104])]);
    $order = $a->order->fresh();

    $r = printPart($a->mgr, 'note', $a->f)->assertOk();
    $text = printText($r->getContent());

    expect($r->headers->get('content-disposition'))->toContain('delivery-note-'.$a->f->number.'.pdf')
        ->and(printHas($text, 'Delivery note'))->toBeTrue()->and(printHas($text, $a->f->number))->toBeTrue()
        ->and(printHas($text, 'Noor'))->toBeTrue()->and(printHas($text, '+97455123456'))->toBeTrue()
        ->and(printHas($text, 'Zone 66, Street 850, Building 12'))->toBeTrue()->and(printHas($text, 'Behind Al Meera'))->toBeTrue()->and(printHas($text, 'Gate code 4421, second floor'))->toBeTrue()
        ->and(printHas($text, 'Ring the bell twice'))->toBeTrue()
        ->and(printHas($text, 'https://www.google.com/maps/search/?api=1&query=25.285447,51.53104'))->toBeTrue()
        ->and(printHas($text, $a->l1->sku))->toBeTrue()->and(printHas($text, '1,000.00'))->toBeTrue()->and(printHas($text, '250.00'))->toBeTrue()->and(printHas($text, '2,000.00'))->toBeTrue()
        // the amount to collect is the whole of what is still due
        ->and(printHas($text, 'Collect cash'))->toBeTrue()->and(printHas($text, 'QAR '.number_format((float) $order->grand_total, 2)))->toBeTrue()
        ->and(printHas($text, 'Received by'))->toBeTrue()->and(printHas($text, 'Signature'))->toBeTrue()->and(printHas($text, 'Date'))->toBeTrue()
        ->and(printHas($text, 'Problem with this delivery? Call'))->toBeTrue()->and(printHas($text, '+97444112233'))->toBeTrue();
});

it('uses the support phone when the branch has none', function () {
    $a = printWorld();
    $a->w->a->update(['phone' => null]);
    app(Settings::class)->set('contact.support_phone', '+97444000111');

    expect(printHas(printPart($a->mgr, 'note', $a->f)->assertOk()->getContent(), '+97444000111'))->toBeTrue();
});

it('shows no prices on the note of an order that is already paid, unless the dashboard switch says so', function () {
    $a = printWorld(['payment' => 'sadad']);
    $text = printText(printPart($a->mgr, 'note', $a->f)->assertOk()->getContent());

    expect(printHas($text, $a->l1->sku))->toBeTrue()->and(printHas($text, 'Paid: nothing to collect'))->toBeTrue()
        ->and(printHas($text, '1,000.00'))->toBeFalse()->and(printHas($text, '250.00'))->toBeFalse()->and(printHas($text, 'Collect cash'))->toBeFalse();

    app(Settings::class)->set('printing.note_prices', true);
    $withPrices = printText(printPart($a->mgr, 'note', $a->f)->assertOk()->getContent());
    expect(printHas($withPrices, '1,000.00'))->toBeTrue()->and(printHas($withPrices, 'Paid: nothing to collect'))->toBeTrue()->and(printHas($withPrices, 'Collect cash'))->toBeFalse();
});

it('tells the driver not to hand over when an online order is not paid yet', function () {
    $a = printWorld(['payment' => 'sadad']);
    $a->order->update(['payment_status' => 'pending']);

    expect(printHas(printPart($a->mgr, 'note', $a->f)->assertOk()->getContent(), 'Payment not confirmed'))->toBeTrue();
});

it('shows nothing to collect for a cash on delivery order that is already settled, and what was paid before for a part-paid one', function () {
    $a = printWorld();
    $delivery = Delivery::create(['order_fulfillment_id' => $a->f->id, 'branch_id' => $a->w->a->id, 'mode' => 'internal', 'status' => 'pending', 'tracking_number' => 'ABDTEST1',
        'quoted_fee' => '15.00', 'cod_amount' => '1500.00', 'requires_proof' => true]);
    $a->order->update(['paid_total' => '765.00']);

    $text = printText(printPart($a->mgr, 'note', $a->f)->assertOk()->getContent());
    // Part paid: the driver collects what the delivery says, and the note shows what was paid before.
    expect(printHas($text, 'QAR 1,500.00'))->toBeTrue()->and(printHas($text, 'Already paid'))->toBeTrue()->and(printHas($text, 'QAR 765.00'))->toBeTrue();

    $delivery->update(['cod_amount' => '0.00']);
    expect(printHas(printPart($a->mgr, 'note', $a->f)->assertOk()->getContent(), 'Nothing to collect: already settled'))->toBeTrue();
});

it('splits the cash of a split order between its parcels: each note collects its own share and the shares add up', function () {
    $s = opsSplitOrder();
    $order = $s->order->fresh();
    $sum = 0.0;
    foreach ([[$s->mgrA, $s->fa], [$s->mgrB, $s->fb]] as [$mgr, $part]) {
        $sheet = app(PrintSheets::class)->build(PrintKind::Note, collect([$part->load('order', 'branch', 'items', 'slot', 'deliveries')]), [], $mgr)[0];
        $sum += (float) $sheet['pay']['collect'];
        $text = printText(printPart($mgr, 'note', $part)->assertOk()->getContent());
        expect($sheet['pay']['mode'])->toBe('cod')->and($sheet['pay']['share'])->toBeTrue()->and($sheet['pay']['parcels'])->toBe(2)
            ->and(printHas($text, 'QAR '.number_format((float) $sheet['pay']['collect'], 2)))->toBeTrue()->and(printHas($text, 'of 2 parcels'))->toBeTrue();
    }
    expect(round($sum, 2))->toBe(round((float) $order->grand_total, 2));
});

it('prints the note in Arabic, shaped and in reading order, when the customer\'s language is Arabic', function () {
    $a = printWorld();
    $a->order->update(['locale' => 'ar']);
    $a->w->address->update(['recipient_name' => 'نورة العلي', 'landmark' => 'خلف مجمع الميرة', 'instructions' => 'رمز البوابة 4421، الطابق الثاني']);
    $a->order->update(['address_snapshot' => array_merge($a->w->address->fresh()->snapshot(), ['area' => 'الدفنة', 'city' => 'الدوحة'])]);

    $text = printText(printPart($a->mgr, 'note', $a->f)->assertOk()->getContent());

    expect(printHas($text, printAr('إشعار التسليم')))->toBeTrue()->and(printHas($text, printAr('التوقيع')))->toBeTrue()->and(printHas($text, printAr('اسم المستلم')))->toBeTrue()
        ->and(printHas($text, printAr('نورة العلي')))->toBeTrue()->and(printHas($text, printAr('خلف مجمع الميرة')))->toBeTrue()
        ->and(printHas($text, printAr('المبلغ المطلوب تحصيله نقدا')))->toBeTrue()
        ->and(printHas($text, 'Delivery note'))->toBeTrue(); // the English label is there too
    // The address keeps its numbers readable: 850 and 12, never 058 and 21.
    expect($text)->toContain('850')->toContain('12')->not->toContain('058');
});

it('breaks a very long Arabic address into lines that read top to bottom', function () {
    $a = printWorld();
    $a->order->update(['locale' => 'ar']);
    $long = 'شارع الاستقلال بجانب مسجد الشيخ محمد بن عبدالله آل ثاني قرب البوابة الزرقاء خلف مجمع الميرة مقابل مدرسة الدوحة الدولية الطابق الثالث الشقة رقم ٣٠٢ اتصل عند الوصول ولا تطرق الجرس';
    $a->w->address->update(['instructions' => $long]);
    $a->order->update(['address_snapshot' => $a->w->address->fresh()->snapshot()]);

    $text = printText(printPart($a->mgr, 'note', $a->f)->assertOk()->getContent());
    $first = strpos($text, printAr('شارع'));
    $last = strpos($text, printAr('الجرس'));

    expect($first)->not->toBeFalse()->and($last)->not->toBeFalse()->and($first)->toBeLessThan($last)
        ->and(substr_count($text, "\n"))->toBeGreaterThan(10);
});

// ─── Paper and pages ─────────────────────────────────────────────────────────────────────────────────────────────────────

it('prints on A4 by default and on A5 when the dashboard says so, per document', function () {
    $a = printWorld();
    $a4 = printPart($a->mgr, 'note', $a->f)->assertOk()->getContent();
    app(Settings::class)->set('printing.note_paper', 'a5');
    $a5 = printPart($a->mgr, 'note', $a->f)->assertOk()->getContent();
    $picking = printPart($a->mgr, 'picking', $a->f)->assertOk()->getContent();

    [$w4, $h4] = printPageSize($a4);
    [$w5, $h5] = printPageSize($a5);
    expect($w4)->toBeGreaterThan(590)->and($h4)->toBeGreaterThan(835)->and($w5)->toBeBetween(415, 425)->and($h5)->toBeBetween(590, 600)
        ->and(printPageSize($picking)[0])->toBeGreaterThan(590); // the picking list still follows its own switch (A4)
    expect(printPages($a5))->toBe(1);
});

it('flows 50 lines onto more pages, keeps the bin order across the page break and repeats the part number on every page', function () {
    $a = printWorld();
    $variants = [];
    foreach (['A-1-1', 'A-2-1', 'A-10-1', 'B-1-1', 'C-3-1', null] as $bin) {
        [, $v] = substituteFor($a->w);
        printBin($a->w->a, $v, $bin);
        $variants[] = $v;
    }
    $a->l1->delete();
    $a->l2->delete();
    $skus = [];
    for ($i = 1; $i <= 50; $i++) {
        $sku = sprintf('LINE-%03d', $i);
        $skus[] = $sku;
        printAddLine($a->l1, ['sku' => $sku, 'name_en' => sprintf('Item %03d', $i), 'quantity' => 1, 'product_variant_id' => $variants[($i - 1) % 6]->id, 'line_subtotal' => '10.00', 'line_total' => '10.00']);
    }

    $bytes = printPart($a->mgr, 'picking', $a->f)->assertOk()->getContent();
    $text = printText($bytes);
    preg_match_all('/LINE-\d{3}/', $text, $found);
    $pages = printPages($bytes);

    expect($pages)->toBeGreaterThan(1)->and(array_unique($found[0]))->toHaveCount(50)
        ->and(substr_count(printSquash($text), printSquash($a->f->number)))->toBeGreaterThanOrEqual($pages);
    // The order of the lines on paper is the bin order: A-1-1, A-2-1, A-10-1, B-1-1, C-3-1, then the lines without a bin.
    $bins = ['A-1-1', 'A-2-1', 'A-10-1', 'B-1-1', 'C-3-1', null];
    $expected = [];
    foreach ($bins as $bin) {
        $expected = array_merge($expected, array_values(array_filter($skus, fn ($s) => $variants[(((int) substr($s, 5)) - 1) % 6] === $variants[array_search($bin, $bins, true)])));
    }
    expect(array_values(array_unique($found[0])))->toBe($expected);
});

it('prints a delivery note with many lines over more than one page without losing a line', function () {
    $a = printWorld();
    for ($i = 1; $i <= 40; $i++) {
        printAddLine($a->l1, ['sku' => sprintf('NOTE-%03d', $i), 'name_en' => sprintf('Cushion %03d', $i), 'quantity' => 1]);
    }
    $bytes = printPart($a->mgr, 'note', $a->f)->assertOk()->getContent();
    preg_match_all('/NOTE-\d{3}/', printText($bytes), $found);

    expect(printPages($bytes))->toBeGreaterThan(1)->and(array_unique($found[0]))->toHaveCount(40)->and(printHas($bytes, 'Signature'))->toBeTrue();
});

// ─── Who may print, and the audit trail ───────────────────────────────────────────────────────────────────────────────────────

it('refuses a user of another branch, a user without order access and a guest, and logs nothing', function () {
    $a = printWorld();
    $otherBranch = staffWithRole('branch_manager', $a->w->b);
    $noOrders = staffWithRole('inventory_manager', $a->w->a);
    $driver = driverFor($a->w->a);

    foreach (['picking', 'note'] as $kind) {
        printPart($otherBranch, $kind, $a->f)->assertForbidden();
        printPart($noOrders, $kind, $a->f)->assertForbidden();
        printPart($driver, $kind, $a->f)->assertForbidden();
    }
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
    $this->get(route('admin.print.picking.part', $a->f))->assertRedirect();
    $this->get(route('admin.print.note.part', $a->f))->assertRedirect();
    // A customer account never reaches the dashboard.
    expect(printPart($a->w->customer, 'picking', $a->f)->status())->toBeIn([302, 403]);

    expect(printAuditTotal())->toBe(0);
});

it('lets a branch manager, an order manager and an admin print a part of their branch', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $a = printWorld();
    foreach ([staffWithRole('branch_manager', $a->w->a), $a->mgr, User::factory()->superAdmin()->create()] as $user) {
        printPart($user, 'picking', $a->f)->assertOk();
        printPart($user, 'note', $a->f)->assertOk();
    }

    expect(printAuditCount($a->f))->toBe(6);
});

it('answers 404 for a part that does not exist and 422 for a part that was cancelled, and logs nothing for either', function () {
    $s = opsSplitOrder();
    printPart($s->mgrA, 'picking', (string) Str::uuid())->assertNotFound();

    actingAsStaff($s->mgrB);
    test()->postJson("/api/v1/admin/fulfillments/{$s->fb->id}/transition", ['status' => 'cancelled'])->assertOk();
    printPart($s->mgrB, 'picking', $s->fb)->assertStatus(422);

    expect(printAuditTotal())->toBe(0);
});

it('writes one audit entry per printed part with who, which part, the copy number and the paper, and counts reprints', function () {
    $a = printWorld();
    printPart($a->mgr, 'picking', $a->f)->assertOk();
    $note1 = printPart($a->mgr, 'note', $a->f)->assertOk();
    printPart($a->mgr, 'note', $a->f)->assertOk();
    $note3 = printPart($a->mgr, 'note', $a->f)->assertOk();

    $entries = AuditLog::where('auditable_id', $a->f->id)->whereIn('action', PrintKind::auditActions())->orderBy('id')->get();
    expect($entries->pluck('action')->all())->toBe(['order.picking_list_printed', 'order.delivery_note_printed', 'order.delivery_note_printed', 'order.delivery_note_printed'])
        ->and($entries->pluck('metadata.copy')->all())->toBe([1, 1, 2, 3])
        ->and($entries->every(fn ($e) => $e->actor_id === $a->mgr->id && $e->branch_id === $a->f->branch_id && $e->auditable_type === 'OrderFulfillment'))->toBeTrue()
        ->and($entries[1]->metadata)->toMatchArray(['number' => $a->f->number, 'order' => $a->order->number, 'paper' => 'a4', 'parts_in_request' => 1]);

    $summary = app(PrintHistory::class)->summaries([$a->f->id])[$a->f->id];
    expect($summary['delivery_note']['count'])->toBe(3)->and($summary['delivery_note']['last_by'])->toBe($a->mgr->name)->and($summary['picking_list']['count'])->toBe(1)
        ->and($summary['delivery_note']['last_at']->equalTo(now()))->toBeTrue();
    // The paper says so: the first copy is plain, the third is stamped.
    expect(printHas($note1->getContent(), 'Reprint'))->toBeFalse()->and(printHas($note3->getContent(), 'Reprint'))->toBeTrue()->and(printHas($note3->getContent(), 'Copy 3'))->toBeTrue();
});

it('counts the prints of each part and each document on its own', function () {
    $s = opsSplitOrder();
    printPart($s->mgrA, 'note', $s->fa)->assertOk();
    printPart($s->mgrA, 'note', $s->fa)->assertOk();
    printPart($s->mgrB, 'note', $s->fb)->assertOk();

    $all = app(PrintHistory::class)->summaries([$s->fa->id, $s->fb->id]);
    expect($all[$s->fa->id]['delivery_note']['count'])->toBe(2)->and($all[$s->fb->id]['delivery_note']['count'])->toBe(1)->and(isset($all[$s->fa->id]['picking_list']))->toBeFalse()
        ->and(app(PrintHistory::class)->summaries([]))->toBe([])->and(app(PrintHistory::class)->nextCopies(PrintKind::Note, [$s->fa->id, $s->fb->id]))->toBe([$s->fa->id => 3, $s->fb->id => 2]);
});

it('does not log a print that failed to render', function () {
    $a = printWorld();
    app()->bind(PrintSheets::class, fn () => throw new RuntimeException('render failed'));
    $this->withoutExceptionHandling();

    expect(fn () => printPart($a->mgr, 'picking', $a->f))->toThrow(RuntimeException::class);
    expect(printAuditTotal())->toBe(0);
});
