<?php

namespace App\Domain\Orders\Services;

use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderInvoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;

/**
 * Invoices are issued once and frozen: the snapshot is built from order line snapshots (never live catalog data),
 * so editing a product never changes a historical invoice.
 */
class InvoiceService
{
    public function issue(Order $order): OrderInvoice
    {
        return DB::transaction(function () use ($order) {
            if ($existing = OrderInvoice::where('order_id', $order->id)->first()) {
                return $existing;
            }
            $order->loadMissing(['items', 'fulfillments.branch', 'coupons']);

            return OrderInvoice::create([
                'order_id' => $order->id,
                'number' => 'INV-'.$order->number,
                'issued_at' => now(),
                'snapshot' => [
                    'order' => $order->only(['number', 'placed_at', 'payment_method', 'payment_status', 'fulfillment_type', 'currency', 'subtotal', 'discount_total',
                        'delivery_fee_total', 'service_fee_total', 'tax_total', 'loyalty_discount', 'grand_total', 'paid_total', 'refunded_total', 'customer_name',
                        'customer_phone', 'customer_email', 'address_snapshot', 'tax_snapshot']),
                    'items' => $order->items->map->only(['sku', 'name_en', 'name_ar', 'variant_name_en', 'variant_name_ar', 'quantity', 'unit_final_price', 'discount_amount', 'tax_amount', 'line_total'])->all(),
                    'branches' => $order->fulfillments->map(fn ($f) => ['number' => $f->number, 'name_en' => $f->branch->name_en, 'name_ar' => $f->branch->name_ar])->all(),
                    'discounts' => $order->coupons->map->only(['label_en', 'label_ar', 'code', 'discount_amount'])->all(),
                    'company' => ['name_en' => config('platform.brand.name_en'), 'name_ar' => config('platform.brand.name_ar')],
                ],
            ]);
        });
    }

    public function download(Order $order)
    {
        $invoice = $this->issue($order);
        $pdf = Pdf::loadView('documents.invoice', ['invoice' => $invoice, 's' => $invoice->snapshot, 'locale' => $order->locale ?: app()->getLocale()])
            ->setPaper('a4')->setOption(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false]);

        return $pdf->download($invoice->number.'.pdf');
    }
}
