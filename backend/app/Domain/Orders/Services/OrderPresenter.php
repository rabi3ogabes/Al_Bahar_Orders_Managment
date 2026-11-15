<?php

namespace App\Domain\Orders\Services;

use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\OrderRevision;
use App\Domain\Settings\Services\Settings;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Customer-facing order representation (never exposes internal notes, reservation or staff data). */
class OrderPresenter
{
    public function summary(Order $o): array
    {
        return [
            'id' => $o->id, 'number' => $o->number, 'status' => $o->status->value, 'status_label' => __('status.'.$o->status->value),
            'seq' => (int) $o->progress_seq,
            'payment_status' => $o->payment_status->value, 'payment_method' => $o->payment_method, 'fulfillment_type' => $o->fulfillment_type,
            'items_count' => $o->relationLoaded('items') ? $o->items->sum('quantity') : null,
            'grand_total' => Money::of($o->grand_total)->jsonSerialize(), 'placed_at' => BusinessTime::iso($o->placed_at),
            'thumbnail_url' => $o->relationLoaded('items') ? $this->imageUrl(($o->items->firstWhere('quantity', '>', 0) ?? $o->items->first())?->image_path) : null,
            // The branch changed what is in the order (an item was short): the apps show a "changed" badge, the detail says exactly what changed.
            'was_adjusted' => $o->wasAdjusted(),
        ];
    }

    public function detail(Order $o): array
    {
        $o->loadMissing(['items', 'fulfillments.branch', 'fulfillments.pickup', 'fulfillments.deliveries.driver', 'fulfillments.slot', 'coupons']);
        if ($o->wasAdjusted()) {
            $o->loadMissing(['revisions.lines', 'revisions.branch']);
        }
        $byId = $o->items->keyBy('id');
        $replacedBy = $o->items->whereNotNull('replaces_order_item_id')->keyBy('replaces_order_item_id');
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
            'items' => $this->orderedItems($o)->map(fn ($i) => [
                'id' => $i->id, 'product_id' => $i->product_id, 'variant_id' => $i->product_variant_id, 'sku' => $i->sku,
                'name' => $i->name(), 'variant_name' => $i->variantName(), 'image_url' => $this->imageUrl($i->image_path),
                'quantity' => $i->quantity, 'quantity_cancelled' => $i->quantity_cancelled, 'quantity_returned' => $i->quantity_returned,
                'unit_price' => Money::of($i->unit_final_price)->jsonSerialize(), 'discount' => Money::of($i->discount_amount)->jsonSerialize(),
                'line_total' => Money::of($i->line_total)->jsonSerialize(), 'branch_id' => $i->branch_id, 'fulfillment_id' => $i->order_fulfillment_id,
                'adjustment' => $this->adjustment($i, $byId, $replacedBy),
            ])->values(),
            'was_adjusted' => $o->wasAdjusted(),
            'revisions' => $o->wasAdjusted() ? $o->revisions->map(fn ($r) => $this->revision($r))->values() : [],
            'fulfillments' => $o->fulfillments->map(fn (OrderFulfillment $f) => $this->fulfillment($f))->values(),
            'can_cancel' => ! $o->status->isTerminal() && ! $handedOver && $o->status->value !== 'cancellation_requested',
            'can_pay' => $o->status->value === 'pending_payment' && $o->payment_method === 'sadad' && ! $o->payment_due_at?->isPast(),
            'can_return' => self::returnsEnabled() && $o->fulfillments->contains(fn ($f) => self::returnWindowOpen($f)),
            'can_review' => $o->fulfillments->contains(fn ($f) => in_array($f->status, [FS::Delivered, FS::PickedUp], true)),
            'payment_due_at' => BusinessTime::iso($o->payment_due_at),
            // The live order card (null for an order with nothing to show); never waits on the routing server.
            'progress' => app(OrderProgress::class)->forOrder($o, remote: false),
        ];
    }

    /** Days after delivery/collection during which a customer may ask to return goods. */
    public const RETURN_WINDOW_DAYS = 14;

    /** The dashboard switch (Settings → Checkout & fees): off hides "Return order" everywhere and the API refuses new requests. */
    public static function returnsEnabled(): bool
    {
        return (bool) app(Settings::class)->get('returns.enabled', true);
    }

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

    /**
     * The order's lines in a reading order: each substitute right after the line it replaced (the creation order puts it last).
     * An order nobody adjusted keeps the order its lines came in.
     *
     * @return Collection<int, OrderItem>
     */
    private function orderedItems(Order $o): Collection
    {
        if (! $o->wasAdjusted()) {
            return $o->items;
        }
        $items = $o->items->sortBy('id')->values();
        $substitutes = $items->whereNotNull('replaces_order_item_id')->groupBy('replaces_order_item_id');
        $out = collect();
        $walk = function (OrderItem $i) use (&$walk, $substitutes, $out): void {
            $out->push($i);
            foreach ($substitutes->get($i->id, []) as $s) {
                $walk($s);
            }
        };
        foreach ($items->whereNull('replaces_order_item_id') as $i) {
            $walk($i);
        }

        return $out->values();
    }

    /**
     * What happened to a line, for the order page (null: nothing). `replaced` = swapped out (quantity 0); `replacement` = this line took the place of
     * `original_name`. The reason is translated; original_quantity is what was ordered.
     *
     * @param  Collection<string, OrderItem>  $byId
     * @param  Collection<string, OrderItem>  $replacedBy  keyed by the id of the line each substitute replaced
     * @return array<string, mixed>|null
     */
    private function adjustment(OrderItem $i, Collection $byId, Collection $replacedBy): ?array
    {
        if ($i->adjustment_kind === null) {
            return null;
        }
        $replaced = $i->replaces_order_item_id ? $byId->get($i->replaces_order_item_id) : null;
        $substitute = $replacedBy->get($i->id);

        return [
            'kind' => $i->adjustment_kind, 'original_quantity' => $i->original_quantity ?? $i->quantity,
            'original_name' => ($replaced ?? $i)->name(), 'reason_code' => $i->adjustment_reason, 'reason_label' => $i->adjustment_reason ? __('adjustments.reason.'.$i->adjustment_reason) : null,
            'replaces_name' => $replaced?->name(), 'replaced_by_name' => $substitute?->name(),
        ];
    }

    /** @return array<string, mixed> one revision as the customer reads it: when, why, which branch, each change as a sentence, the money. */
    private function revision(OrderRevision $r): array
    {
        $locale = app()->getLocale();

        return [
            'number' => $r->number, 'at' => BusinessTime::iso($r->created_at), 'reason_code' => $r->reason_code, 'reason_label' => AdjustmentText::reason($r->reason_code, $locale),
            'note' => $r->note, 'branch' => $r->branch?->localized('name'),
            'lines' => $r->lines->map(fn ($l) => [
                'order_item_id' => $l->order_item_id, 'action' => $l->action, 'name' => $l->name(), 'variant_name' => $l->variantName(),
                'from_quantity' => $l->from_quantity, 'to_quantity' => $l->to_quantity, 'text' => AdjustmentText::line($l, $locale),
                'replacement' => $l->replacement === null ? null : ['variant_id' => $l->replacement['variant_id'] ?? null, 'name' => $l->replacementName(), 'variant_name' => $l->replacementVariantName(),
                    'quantity' => (int) ($l->replacement['quantity'] ?? 0), 'unit_price' => Money::of($l->replacement['unit_price'] ?? '0')->jsonSerialize()],
            ])->values(),
            'difference' => Money::of($r->difference)->jsonSerialize(), 'total_before' => Money::of($r->before['grand_total'] ?? '0')->jsonSerialize(),
            'total_after' => Money::of($r->after['grand_total'] ?? '0')->jsonSerialize(), 'order_cancelled' => $r->order_cancelled,
        ];
    }

    public function imageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return str_starts_with($path, 'http') ? $path : Storage::disk(config('platform.media.disk'))->url($path);
    }
}
