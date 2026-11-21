<?php

require_once __DIR__.'/AdjustmentFixtures.php';
require_once __DIR__.'/OpsFixtures.php';
require_once __DIR__.'/../Operations/OpsSupport.php';

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Inventory\Models\BranchInventory;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Printing\PrintKind;
use App\Support\ArabicShaper;

/*
| Shared fixtures of the picking list and delivery note tests (docs/guides/PICKING_LIST_AND_DELIVERY_NOTE.md). Names start with "print" so they never clash.
*/

/** The text of a PDF as a person reads it (poppler's pdftotext), with the direction marks removed; pages are separated by a form feed. */
function printText(string $bytes): string
{
    $file = tempnam(sys_get_temp_dir(), 'prt').'.pdf';
    file_put_contents($file, $bytes);
    $text = (string) shell_exec('pdftotext -layout '.escapeshellarg($file).' - 2>/dev/null');
    @unlink($file);

    return (string) preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}]/u', '', $text);
}

/** The text with every blank gone and in capitals: letter-spaced headings come out as "O R D E R", this finds them anyway. */
function printSquash(string $text): string
{
    return mb_strtoupper((string) preg_replace('/\s+/u', '', $text));
}

/** Does the PDF text hold $needle (blanks and case ignored)? */
function printHas(string $bytesOrText, string $needle): bool
{
    $text = str_starts_with($bytesOrText, '%PDF') ? printText($bytesOrText) : $bytesOrText;

    return str_contains(printSquash($text), printSquash($needle));
}

/**
 * The Arabic as pdftotext gives it back: the joined letter forms in reading order (the PDF itself holds them in visual order, poppler turns each word back).
 * Words are cut at blanks; a phrase is as many words in reading order.
 */
function printAr(string $logical): string
{
    return implode(' ', array_map(fn (string $word) => implode('', array_reverse(mb_str_split(ArabicShaper::shape($word)))), preg_split('/\s+/u', trim($logical)) ?: []));
}

function printPages(string $bytes): int
{
    return substr_count(printText($bytes), "\f");
}

/** @return array{0: float, 1: float} the page size in points (A4 is about 595 x 842, A5 about 420 x 595) */
function printPageSize(string $bytes): array
{
    preg_match('/\/MediaBox\s*\[\s*[\d.]+\s+[\d.]+\s+([\d.]+)\s+([\d.]+)\s*\]/', $bytes, $m);

    return [(float) ($m[1] ?? 0), (float) ($m[2] ?? 0)];
}

/** Sets the bin of a variant at a branch. */
function printBin(object $branch, object $variant, ?string $bin): void
{
    BranchInventory::where('branch_id', $branch->id)->where('product_variant_id', $variant->id)->update(['bin_location' => $bin]);
}

/** @return int how many prints of a kind the audit log holds for a part */
function printAuditCount(OrderFulfillment|string $part, ?PrintKind $kind = null): int
{
    $id = $part instanceof OrderFulfillment ? $part->id : $part;

    return AuditLog::where('auditable_type', 'OrderFulfillment')->where('auditable_id', $id)->whereIn('action', $kind ? [$kind->auditAction()] : PrintKind::auditActions())->count();
}

function printAuditTotal(): int
{
    return AuditLog::whereIn('action', PrintKind::auditActions())->count();
}

/**
 * Adds a line to a part (a copy of $like with another SKU, name, quantity and variant): the order's totals are not touched, the printed documents
 * read the lines only.
 *
 * @param  array<string, mixed>  $attributes
 */
function printAddLine(OrderItem $like, array $attributes): OrderItem
{
    $copy = $like->replicate();
    $copy->fill($attributes)->save();

    return $copy;
}

/** A cash on delivery order of branch A in preparation: 2 sofas (bin B-12-1) and a lamp (bin A-3-2). */
function printWorld(array $o = []): object
{
    $a = adjustmentWorld($o);
    printBin($a->w->a, $a->w->v1, 'B-12-1');
    printBin($a->w->a, $a->w->v2, 'A-3-2');

    return $a;
}

function printPart($user, string $kind, $part)
{
    return test()->actingAs($user)->get(route($kind === 'note' ? 'admin.print.note.part' : 'admin.print.picking.part', $part));
}

/** A placed cash on delivery order of branch A that nobody has touched (its part is `pending`). The customer is signed in afterwards. */
function printPlaced(object $a, string $payment = 'cod'): OrderFulfillment
{
    actingAsCustomer($a->customer);
    test()->postJson('/api/v1/cart/items', ['variant_id' => $a->w->v1->id, 'quantity' => 1])->assertSuccessful();
    $id = quoteAndPlace(['address_id' => $a->w->address->id, 'payment_method' => $payment])->assertCreated()->json('data.order.id');

    return Order::findOrFail($id)->fulfillments->first();
}

function printWalk(object $a, OrderFulfillment $f, array $steps): void
{
    actingAsStaff($a->mgr);
    foreach ($steps as $s) {
        test()->postJson("/api/v1/admin/fulfillments/{$f->id}/transition", ['status' => $s])->assertOk();
    }
}

function printBatch($user, string $kind, array $query = [])
{
    return test()->actingAs($user)->get(route($kind === 'note' ? 'admin.print.note.batch' : 'admin.print.picking.batch', $query));
}

/** A delivery of branch A (an order walked to ready) given to a driver, or left without one. */
function printDelivery(object $w, $mgr, $driver = null): Delivery
{
    actingAsCustomer($w->customer);
    [$f] = readyOrder($w);
    actingAsStaff($mgr);
    if ($driver) {
        test()->postJson("/api/v1/admin/dispatch/fulfillments/{$f->id}/assign", ['driver_id' => $driver->id])->assertOk();
    } else {
        test()->postJson("/api/v1/admin/fulfillments/{$f->id}/deliveries", ['mode' => 'internal'])->assertStatus(201);
    }

    return Delivery::where('order_fulfillment_id', $f->id)->firstOrFail();
}
