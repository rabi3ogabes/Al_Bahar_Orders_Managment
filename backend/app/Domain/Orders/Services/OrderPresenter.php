<?php

namespace App\Domain\Orders\Services;

use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Customer-facing order representation (never exposes internal notes, reservation or staff data). */
class OrderPresenter
{
    public function summary(Order $o): array
    {
        return [
            'id' => $o->id, 'number' => $o->number, 'status' => $o->status->value, 'status_label' => __('status.'.$o->status->value),
            'payment_status' => $o->payment_status->value, 'payment_method' => $o->payment_method, 'fulfillment_type' => $o->fulfillment_type,
            'items_count' => $o->relationLoaded('items') ? $o->items->sum('quantity') : null,
            'grand_total' => Money::of($o->grand_total)->jsonSerialize(), 'placed_at' => BusinessTime::iso($o->placed_at),
            'thumbnail_url' => $o->relationLoaded('items') ? $this->imageUrl($o->items->first()?->image_path) : null,
        ];
    }

    public function detail(Order $o): array
    {
        $o->loadMissing(['items', 'fulfillments.branch', 'fulfillments.pickup', 'fulfillments.deliveries.driver', 'fulfillments.slot', 'coupons']);
        $m = fn (string $f) => Money::of($o->{$f} ?? '0')->jsonSerialize();
        $handedOver = $o->fulfillments->contains(fn ($f) => $f->status->stockHandedOver());

        return $this->summary($o) + [
            'totals' => [
                'subtotal' => $m('subtotal'), 'discount' => $m('discount_total'), 'delivery_fee' => $m('delivery_fee_total'),
                'service_fee' => $m('service_fee_total'), 'tax' => $m('tax_total'), 'loyalty_discount' => $m('loyalty_discount'),
                'grand_total' => $m('grand_total'), 'paid' => $m('paid_total'), 'refunded' => $m('refunded_total'),
            ],
            'discounts' => $o->coupons->map(fn ($c) => ['label' => app()->getLocale() === 'ar' ? $c->label_ar : $c->label_en, 'code' => $c->code,
                'amount' => Money::of($c->discount_amount)->jsonSerialize(), 'free_delivery' => $c->free_delivery])->values(),
            'address' => $o->address_snapshot,
            'customer_notes' => $o->customer_notes,
            'items' => $o->items->map(fn ($i) => [
                'id' => $i->id, 'product_id' => $i->product_id, 'variant_id' => $i->product_variant_id, 'sku' => $i->sku,
                'name' => $i->name(), 'variant_name' => $i->variantName(), 'image_url' => $this->imageUrl($i->image_path),
                'quantity' => $i->quantity, 'quantity_cancelled' => $i->quantity_cancelled, 'quantity_returned' => $i->quantity_returned,
                'unit_price' => Money::of($i->unit_final_price)->jsonSerialize(), 'discount' => Money::of($i->discount_amount)->jsonSerialize(),
                'line_total' => Money::of($i->line_total)->jsonSerialize(), 'branch_id' => $i->branch_id, 'fulfillment_id' => $i->order_fulfillment_id,
            ])->values(),
            'fulfillments' => $o->fulfillments->map(fn (OrderFulfillment $f) => $this->fulfillment($f))->values(),
            'can_cancel' => ! $o->status->isTerminal() && ! $handedOver && $o->status->value !== 'cancellation_requested',
            'can_pay' => $o->status->value === 'pending_payment' && $o->payment_method === 'sadad' && ! $o->payment_due_at?->isPast(),
            'can_return' => $o->fulfillments->contains(fn ($f) => self::returnWindowOpen($f)),
            'can_review' => $o->fulfillments->contains(fn ($f) => in_array($f->status, [FS::Delivered, FS::PickedUp], true)),
            'payment_due_at' => BusinessTime::iso($o->payment_due_at),
        ];
    }

    /** Days after delivery/collection during which a customer may ask to return goods. */
    public const RETURN_WINDOW_DAYS = 14;

    /** True while a delivered/collected fulfillment is still inside the return window (the API enforces it, the app only shows it). */
    public static function returnWindowOpen(OrderFulfillment $f): bool
    {
        if (! in_array($f->status, [FS::Delivered, FS::PickedUp], true)) {
            return false;
        }
        $since = $f->delivered_at ?? $f->picked_up_at ?? $f->updated_at;

        return $since !== null && $since->gt(now()->subDays(self::RETURN_WINDOW_DAYS));
    }

    public function timeline(Order $o): array
    {
        return $o->history()->where('is_customer_visible', true)->get()->map(fn ($h) => [
            'kind' => $h->kind, 'status' => $h->to_status, 'label' => __('status.'.$h->to_status),
            'fulfillment_id' => $h->order_fulfillment_id, 'at' => BusinessTime::iso($h->created_at),
        ])->values()->all();
    }

    private function fulfillment(OrderFulfillment $f): array
    {
        $d = $f->activeDelivery();

        return [
            'id' => $f->id, 'number' => $f->number, 'type' => $f->type, 'status' => $f->status->value, 'status_label' => __('status.'.$f->status->value),
            'branch' => ['id' => $f->branch->id, 'name' => $f->branch->localized('name'), 'phone' => $f->branch->phone,
                'latitude' => $f->branch->latitude !== null ? (float) $f->branch->latitude : null, 'longitude' => $f->branch->longitude !== null ? (float) $f->branch->longitude : null],
            'subtotal' => Money::of($f->subtotal)->jsonSerialize(), 'delivery_fee' => Money::of($f->delivery_fee)->jsonSerialize(),
            'slot' => $f->slot ? ['date' => $f->slot_date?->toDateString(), 'starts_at' => substr($f->slot->starts_at, 0, 5), 'ends_at' => substr($f->slot->ends_at, 0, 5)] : null,
            'delivery' => $d ? ['tracking_number' => $d->tracking_number, 'status' => $d->status->value, 'status_label' => __('status.'.$d->status->value),
                'provider' => $d->mode, 'driver_name' => $d->driver ? Str::before($d->driver->name, ' ') : null,
                'eta' => BusinessTime::iso($d->scheduled_for)] : null,
            'pickup' => $f->pickup ? ['status' => $f->pickup->status, 'token_expires_at' => BusinessTime::iso($f->pickup->token_expires_at)] : null,
            'timestamps' => ['ready_at' => BusinessTime::iso($f->ready_at), 'dispatched_at' => BusinessTime::iso($f->dispatched_at),
                'delivered_at' => BusinessTime::iso($f->delivered_at), 'picked_up_at' => BusinessTime::iso($f->picked_up_at)],
        ];
    }

    private function imageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return str_starts_with($path, 'http') ? $path : Storage::disk(config('platform.media.disk'))->url($path);
    }
}
