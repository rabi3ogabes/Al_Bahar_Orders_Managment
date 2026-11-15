<?php

namespace App\Domain\Orders\Services;

use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderInvoice;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\OrderRevision;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Invoices are frozen snapshots built from order line snapshots (never live catalog data), so editing a product never changes a
 * historical invoice. An invoice has revisions: the first is issued when it is first needed (or already carries what the branch changed
 * before that); each later change of the order's items (docs/api/ORDER_ADJUSTMENTS.md) issues the next revision under the same number,
 * and the latest revision is the invoice. Older revisions are kept for audit.
 */
class InvoiceService
{
    /** The latest revision; issued from the order as it is now when there is none yet. */
    public function issue(Order $order): OrderInvoice
    {
        try {
            return DB::transaction(function () use ($order) {
                // Same lock the adjustment holds: an invoice issued at the moment items change is either before it (and then revised) or after it.
                Order::whereKey($order->id)->lockForUpdate()->first(['id']);

                return $this->latest($order) ?? $this->create($order->newQuery()->findOrFail($order->id), 1);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->latest($order) ?? throw new \LogicException('Invoice revision conflict without an invoice.');
        }
    }

    /** After the branch changed the items: the next revision when an invoice was already issued (the order row is locked by the caller); null otherwise. */
    public function reviseIfIssued(Order $order): ?OrderInvoice
    {
        $latest = $this->latest($order);

        return $latest ? $this->create($order->newQuery()->findOrFail($order->id), $latest->revision + 1, $latest->number) : null;
    }

    public function latest(Order $order): ?OrderInvoice
    {
        return OrderInvoice::where('order_id', $order->id)->orderByDesc('revision')->first();
    }

    public function download(Order $order)
    {
        $invoice = $this->issue($order);

        return $this->pdf($invoice, $order)->download($invoice->fileName());
    }

    /** The invoice as a PDF document (the order's language leads), without sending it anywhere. */
    public function pdf(OrderInvoice $invoice, Order $order): \Barryvdh\DomPDF\PDF
    {
        return Pdf::loadView('documents.invoice', ['invoice' => $invoice, 's' => $invoice->snapshot, 'locale' => $order->locale ?: app()->getLocale()])
            ->setPaper('a4')->setOption(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false]);
    }

    private function create(Order $order, int $revision, ?string $number = null): OrderInvoice
    {
        $order->load(['items', 'fulfillments.branch', 'coupons', 'revisions.lines', 'revisions.branch']);
        $ids = $order->items->keyBy('id');

        return OrderInvoice::create([
            'order_id' => $order->id,
            'number' => $number ?? 'INV-'.$order->number,
            'revision' => $revision,
            'issued_at' => now(),
            'snapshot' => [
                'order' => $order->only(['number', 'placed_at', 'payment_method', 'payment_status', 'fulfillment_type', 'currency', 'subtotal', 'discount_total',
                    'delivery_fee_total', 'service_fee_total', 'tax_total', 'loyalty_discount', 'grand_total', 'paid_total', 'refunded_total', 'customer_name',
                    'customer_phone', 'customer_email', 'address_snapshot', 'tax_snapshot']),
                // Only what the customer receives: lines the branch removed or swapped out are listed under "Order changes" instead.
                'items' => $order->items->filter(fn (OrderItem $i) => $i->quantity > 0)->map(fn (OrderItem $i) => $i->only(['sku', 'name_en', 'name_ar', 'variant_name_en', 'variant_name_ar', 'quantity', 'unit_final_price',
                    'discount_amount', 'tax_amount', 'line_total', 'adjustment_kind', 'original_quantity']) + [
                        'replaces_name_en' => $i->replaces_order_item_id ? $ids->get($i->replaces_order_item_id)?->name_en : null,
                        'replaces_name_ar' => $i->replaces_order_item_id ? $ids->get($i->replaces_order_item_id)?->name_ar : null,
                    ])->values()->all(),
                'branches' => $order->fulfillments->map(fn ($f) => ['number' => $f->number, 'name_en' => $f->branch->name_en, 'name_ar' => $f->branch->name_ar])->all(),
                'discounts' => $order->coupons->map->only(['label_en', 'label_ar', 'code', 'discount_amount'])->all(),
                'company' => ['name_en' => config('platform.brand.name_en'), 'name_ar' => config('platform.brand.name_ar')],
                'changes' => $order->revisions->map(fn (OrderRevision $r) => [
                    'number' => $r->number, 'at' => $r->created_at?->toIso8601String(), 'reason_code' => $r->reason_code, 'note' => $r->note,
                    'branch_name_en' => $r->branch?->name_en, 'branch_name_ar' => $r->branch?->name_ar,
                    'before_total' => $r->before['grand_total'] ?? null, 'after_total' => $r->after['grand_total'] ?? null, 'difference' => $r->difference,
                    'order_cancelled' => $r->order_cancelled,
                    'lines' => $r->lines->map(fn ($l) => ['action' => $l->action, 'sku' => $l->sku, 'name_en' => $l->name_en, 'name_ar' => $l->name_ar,
                        'variant_name_en' => $l->variant_name_en, 'variant_name_ar' => $l->variant_name_ar, 'from_quantity' => $l->from_quantity,
                        'to_quantity' => $l->to_quantity, 'replacement' => $l->replacement])->all(),
                ])->all(),
            ],
        ]);
    }
}
