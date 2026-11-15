<?php

require_once __DIR__.'/AdjustmentFixtures.php';

use App\Domain\Orders\Models\OrderInvoice;
use App\Domain\Orders\Services\InvoiceService;
use Illuminate\Database\UniqueConstraintViolationException;

/** The invoice follows the order: a new revision under the same number each time the branch changes the items, the latest one is what is served. */

/** The text of a PDF, as a person reads it (poppler's pdftotext). */
function pdfText(string $bytes): string
{
    $file = tempnam(sys_get_temp_dir(), 'inv').'.pdf';
    file_put_contents($file, $bytes);
    $text = (string) shell_exec('pdftotext -layout '.escapeshellarg($file).' - 2>/dev/null');
    @unlink($file);

    // Letter-spaced headings come out as "O R D E R": keep the words, and a squashed copy to find them by.
    return $text."\n".strtoupper(preg_replace('/\s+/u', '', $text));
}

function downloadInvoice(object $a, bool $asCustomer = true)
{
    actingAsCustomer($a->customer);

    return test()->get("/api/v1/orders/{$a->order->id}/invoice");
}

it('issues a new revision under the same number when an invoice already exists, and serves the latest', function () {
    $a = adjustmentWorld(['coupon' => ['percentage', '10'], 'tax' => '5']);
    $first = downloadInvoice($a)->assertOk();
    expect($first->headers->get('content-disposition'))->toContain('INV-'.$a->order->number.'.pdf')->not->toContain('rev');
    expect(OrderInvoice::where('order_id', $a->order->id)->count())->toBe(1);

    actingAsStaff($a->mgr);
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertOk()->assertJsonPath('data.invoice_revision', 2);
    postAdjust($a, [['order_item_id' => $a->l2->id, 'action' => 'remove']], 'damaged')->assertOk()->assertJsonPath('data.invoice_revision', 3);

    $invoices = OrderInvoice::where('order_id', $a->order->id)->orderBy('revision')->get();
    expect($invoices->pluck('revision')->all())->toBe([1, 2, 3])->and($invoices->pluck('number')->unique()->all())->toBe(['INV-'.$a->order->number])
        // older revisions are untouched (audit): revision 1 still shows what the customer first received
        ->and(collect($invoices[0]->snapshot['items'])->sum('quantity'))->toBe(3)->and($invoices[0]->snapshot['changes'])->toBe([])
        ->and(collect($invoices[1]->snapshot['items'])->sum('quantity'))->toBe(2)->and($invoices[1]->snapshot['changes'])->toHaveCount(1)
        ->and(collect($invoices[2]->snapshot['items'])->sum('quantity'))->toBe(1)->and($invoices[2]->snapshot['changes'])->toHaveCount(2)
        ->and($invoices[2]->snapshot['order']['grand_total'])->toBe($a->order->fresh()->grand_total);

    $latest = downloadInvoice($a)->assertOk();
    expect($latest->headers->get('content-disposition'))->toContain('INV-'.$a->order->number.'-rev3.pdf')->and(substr($latest->getContent(), 0, 5))->toBe('%PDF-')
        ->and(OrderInvoice::where('order_id', $a->order->id)->count())->toBe(3); // downloading again issues nothing
});

it('says "revised invoice · revision N" and lists what changed, in the order\'s language', function (string $locale) {
    $a = adjustmentWorld(['coupon' => ['percentage', '10'], 'locale' => $locale]);
    $a->order->forceFill(['locale' => $locale])->save();
    downloadInvoice($a)->assertOk();
    [, $v] = substituteFor($a->w, '400.00');
    actingAsStaff($a->mgr);
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1], ['order_item_id' => $a->l2->id, 'action' => 'replace', 'variant_id' => $v->id, 'quantity' => 1]], 'out_of_stock',
        ['note' => 'Only one left on the shelf'])->assertOk();

    $order = $a->order->fresh();
    $text = pdfText(downloadInvoice($a)->assertOk()->getContent());
    expect($text)->toContain('REVISED INVOICE')->toContain('revision 2')->toContain('INV-'.$order->number)->toContain('ORDERCHANGES')->toContain('Revision 1')
        ->toContain($locale === 'en' ? 'Out of stock' : 'Revision 1')->toContain('Only one left on the shelf')->toContain('QAR 2,050.00')->toContain('QAR 1,150.00') // before → after
        ->and(pdfText(app(InvoiceService::class)->pdf(OrderInvoice::where('order_id', $order->id)->where('revision', 1)->first(), $order)->output()))->not->toContain('REVISED');
})->with(['en', 'ar'])->skip(trim((string) shell_exec('command -v pdftotext')) === '', 'pdftotext (poppler) is not installed');

it('builds the first invoice from the order as it is now, with the changes listed but not called revised', function () {
    $a = adjustmentWorld();
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertOk()->assertJsonPath('data.invoice_revision', null);
    expect(OrderInvoice::where('order_id', $a->order->id)->count())->toBe(0); // nobody asked for an invoice yet: none is made up

    $invoice = app(InvoiceService::class)->issue($a->order->fresh());
    expect($invoice->revision)->toBe(1)->and($invoice->snapshot['order']['grand_total'])->toBe('1275.00')->and($invoice->snapshot['changes'])->toHaveCount(1)
        ->and(collect($invoice->snapshot['items'])->pluck('quantity')->all())->toBe([1, 1]);
    $text = pdfText(downloadInvoice($a)->assertOk()->getContent());
    expect($text)->not->toContain('REVISED INVOICE')->not->toContain('REVISEDINVOICE')->toContain('ORDERCHANGES')->toContain('QAR 1,275.00');
})->skip(trim((string) shell_exec('command -v pdftotext')) === '', 'pdftotext (poppler) is not installed');

it('lists only the lines the customer receives and says where a replacement came from', function () {
    $a = adjustmentWorld();
    downloadInvoice($a)->assertOk();
    [$p, $v] = substituteFor($a->w, '100.00');
    actingAsStaff($a->mgr);
    postAdjust($a, [['order_item_id' => $a->l2->id, 'action' => 'replace', 'variant_id' => $v->id, 'quantity' => 1], ['order_item_id' => $a->l1->id, 'action' => 'remove']])->assertOk();

    $items = collect(OrderInvoice::where('order_id', $a->order->id)->orderByDesc('revision')->first()->snapshot['items']);
    expect($items)->toHaveCount(1)->and($items[0]['sku'])->toBe($v->sku)->and($items[0]['adjustment_kind'])->toBe('replacement')->and($items[0]['replaces_name_en'])->toBe($a->l2->name_en)
        ->and($items[0]['unit_final_price'])->toBe('100.00')->and($items[0]['quantity'])->toBe(1);
});

it('keeps refusing an invoice while payment is pending, and does not revise an invoice of a cancelled order', function () {
    $a = adjustmentWorld();
    downloadInvoice($a)->assertOk();
    actingAsStaff($a->mgr);
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'remove'], ['order_item_id' => $a->l2->id, 'action' => 'remove']])->assertOk()->assertJsonPath('data.order_cancelled', true)->assertJsonPath('data.invoice_revision', null);
    expect(OrderInvoice::where('order_id', $a->order->id)->count())->toBe(1); // cancelled orders keep the invoice they had, as always

    $a->order->forceFill(['status' => 'pending_payment'])->save();
    downloadInvoice($a)->assertStatus(409);
});

it('serves the latest revision to staff in the dashboard too, and never allows two invoices with the same revision', function () {
    $a = adjustmentWorld();
    downloadInvoice($a)->assertOk();
    actingAsStaff($a->mgr);
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertOk();

    actingAsStaff($a->mgr);
    $r = $this->get(route('admin.orders.invoice', $a->order))->assertOk();
    expect($r->headers->get('content-disposition'))->toContain('-rev2.pdf');
    expect(fn () => OrderInvoice::create(['order_id' => $a->order->id, 'number' => 'INV-X', 'revision' => 2, 'snapshot' => [], 'issued_at' => now()]))->toThrow(UniqueConstraintViolationException::class);
});
