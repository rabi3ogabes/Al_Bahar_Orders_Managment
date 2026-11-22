<?php

namespace App\Domain\Orders\Services;

use App\Domain\Checkout\Support\Assembly;
use App\Domain\Fulfillment\Enums\DeliveryStatus as DS;
use App\Domain\Fulfillment\Services\DeliveryFollowUps;
use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\OrderRevision;
use App\Domain\Orders\Models\ReturnRequest;
use App\Domain\Settings\Services\Settings;
use App\Support\BusinessTime;
use App\Support\Money;
use App\Support\Phone;
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
        $substitutions = app(SubstitutionService::class)->present($o);
        $returns = $this->returnRequests($o);
        $returnable = $this->returnableQuantities($o, $returns);
        // New times after a failed delivery, the proof of delivery and the assembly answer, per part (docs/guides/DELIVERY_FOLLOW_UPS.md).
        $followUps = app(DeliveryFollowUps::class)->forOrder($o);

        return $this->summary($o) + [
            'totals' => [
                'subtotal' => $m('subtotal'), 'discount' => $m('discount_total'), 'delivery_fee' => $m('delivery_fee_total'),
                // The part of the delivery fee that comes from bulky products ("0.00" when none); see `delivery_extras` for which ones.
                'extra_delivery_fee' => $o->extraDeliveryFee()->jsonSerialize(),
                // Assembly and installation the customer bought ("0.00" when none); already inside `grand_total`. Each line shows its own share.
                'assembly_fee' => $m('assembly_fee_total'),
                'service_fee' => $m('service_fee_total'), 'tax' => $m('tax_total'), 'loyalty_discount' => $m('loyalty_discount'),
                'grand_total' => $m('grand_total'), 'paid' => $m('paid_total'), 'refunded' => $m('refunded_total'),
                // The part of `discount` that came from deal prices ("0.00" when none).
                'deal_savings' => $o->coupons->whereNotNull('deal_id')->reduce(fn (Money $c, $d) => $c->plus(Money::of($d->discount_amount)), Money::zero())->jsonSerialize(),
            ],
            'delivery_extras' => $this->deliveryExtras($o),
            'discounts' => $o->coupons->map(fn ($c) => ['source' => $c->deal_id ? 'deal' : 'promotion', 'label' => app()->getLocale() === 'ar' ? $c->label_ar : $c->label_en, 'code' => $c->code,
                'amount' => Money::of($c->discount_amount)->jsonSerialize(), 'free_delivery' => $c->free_delivery])->values(),
            'address' => $o->address_snapshot,
            'customer_notes' => $o->customer_notes,
            'items' => $this->orderedItems($o)->map(fn ($i) => [
                'id' => $i->id, 'product_id' => $i->product_id, 'variant_id' => $i->product_variant_id, 'sku' => $i->sku,
                'name' => $i->name(), 'variant_name' => $i->variantName(), 'image_url' => $this->imageUrl($i->image_path),
                'quantity' => $i->quantity, 'quantity_cancelled' => $i->quantity_cancelled, 'quantity_returned' => $i->quantity_returned,
                // How many of this line the customer can still ask to return right now (delivered or collected, inside the window, not already in a request).
                'returnable_quantity' => $returnable[$i->id] ?? 0,
                'unit_price' => Money::of($i->unit_final_price)->jsonSerialize(), 'discount' => Money::of($i->discount_amount)->jsonSerialize(),
                'line_total' => Money::of($i->line_total)->jsonSerialize(), 'branch_id' => $i->branch_id, 'fulfillment_id' => $i->order_fulfillment_id,
                // Assembly and installation bought for this line (null = none): the fee per unit as placed and what the line owes for it now.
                'assembly' => Assembly::placedFee($i) !== null ? ['fee_each' => Assembly::placedFee($i)->jsonSerialize(), 'amount' => Money::of((string) $i->assembly_total)->jsonSerialize()] : null,
                'adjustment' => $this->adjustment($i, $byId, $replacedBy),
                // The deal price the line got, as placed (null when none): `units` of the line were sold at the deal price, `unit_savings` each.
                'deal' => $i->deal_id !== null && $i->deal_units > 0 ? ['deal_id' => $i->deal_id, 'name' => app()->getLocale() === 'ar' ? ($i->deal_name_ar ?: $i->deal_name_en) : $i->deal_name_en,
                    'units' => $i->deal_units, 'unit_savings' => Money::of($i->deal_unit_savings ?? '0')->jsonSerialize()] : null,
            ])->values(),
            'was_adjusted' => $o->wasAdjusted(),
            // Replacements the branch offered that the customer is asked about (newest first), and whether one still waits for their answer.
            'substitution_requests' => $substitutions,
            'waiting_for_customer' => collect($substitutions)->contains(fn (array $s) => $s['status'] === 'waiting'),
            'revisions' => $o->wasAdjusted() ? $o->revisions->map(fn ($r) => $this->revision($r))->values() : [],
            'fulfillments' => $o->fulfillments->map(fn (OrderFulfillment $f) => $this->fulfillment($f) + $followUps[$f->id])->values(),
            'can_cancel' => ! $o->status->isTerminal() && ! $handedOver && $o->status->value !== 'cancellation_requested',
            'can_pay' => $o->status->value === 'pending_payment' && $o->payment_method === 'sadad' && ! $o->payment_due_at?->isPast(),
            // Open while some piece can still be reported: delivered or collected, inside the claim window and in no other claim. `return_request` is the
            // latest claim (null when there was none) and `returns` every claim, newest first, each with its photos and timeline.
            'can_return' => self::returnsEnabled() && array_sum($returnable) > 0,
            'return_window' => $this->returnWindow($o, $returnable),
            'return_request' => $returns->isEmpty() ? null : app(ReturnClaimPresenter::class)->detail($returns->first()),
            'returns' => $returns->map(fn (ReturnRequest $r) => app(ReturnClaimPresenter::class)->detail($r))->values()->all(),
            'can_review' => $o->fulfillments->contains(fn ($f) => in_array($f->status, [FS::Delivered, FS::PickedUp], true)),
            'payment_due_at' => BusinessTime::iso($o->payment_due_at),
            // The live order card (null for an order with nothing to show); never waits on the routing server.
            'progress' => app(OrderProgress::class)->forOrder($o, remote: false),
        ];
    }

    /**
     * The bulky-item fees inside the delivery fee, as placed: [{product_id, name, mode, quantity, fee_each, amount}] (empty list when none).
     *
     * @return list<array<string, mixed>>
     */
    private function deliveryExtras(Order $o): array
    {
        $ar = app()->getLocale() === 'ar';

        return array_map(fn (array $l) => [
            'product_id' => $l['product_id'] ?? null, 'name' => ($ar ? ($l['name_ar'] ?? null) : null) ?: ($l['name_en'] ?? ''), 'mode' => $l['mode'] ?? 'per_unit',
            'quantity' => (int) ($l['quantity'] ?? 0), 'fee_each' => Money::of((string) ($l['fee_each'] ?? '0'))->jsonSerialize(),
            'amount' => Money::of((string) ($l['amount'] ?? '0'))->jsonSerialize(),
        ], $o->deliveryExtras());
    }

    /** The delivery stages in which the customer may call the driver: exactly those of the live order card (OrderProgress), which a test keeps equal. */
    public const DRIVER_CALLABLE = [DS::Assigned, DS::Accepted, DS::PickedUp, DS::OutForDelivery];

    /** Days after delivery/collection during which a customer may report a problem or ask to return goods, until the dashboard setting says otherwise (Settings → Checkout & fees). */
    public const RETURN_WINDOW_DAYS = 14;

    public static function returnWindowDays(): int
    {
        $days = app(Settings::class)->get('returns.window_days', self::RETURN_WINDOW_DAYS);

        return is_numeric($days) && (int) $days >= 1 ? min(90, (int) $days) : self::RETURN_WINDOW_DAYS;
    }

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

        return $since !== null && $since->gt(now()->subDays(self::returnWindowDays()));
    }

    /**
     * Every return request of the order, newest first: one query for the order (the detail is built for one order at a time, lists use summary()).
     *
     * @return Collection<int, ReturnRequest>
     */
    private function returnRequests(Order $o): Collection
    {
        return ReturnRequest::where('order_id', $o->id)->with(['photos', 'events', 'branch'])->orderByDesc('created_at')->orderByDesc('number')->get();
    }

    /**
     * Per order line, how many units can still be asked back: handed over, not returned, not held by an open request, inside the window.
     * The same rule the request endpoint enforces, so the apps never offer what it would refuse.
     *
     * @param  Collection<int, ReturnRequest>  $returns
     * @return array<string, int> keyed by order item id; lines with nothing to return are left out
     */
    private function returnableQuantities(Order $o, Collection $returns): array
    {
        if (! self::returnsEnabled()) {
            return [];
        }
        $claimed = [];
        foreach ($returns->filter(fn (ReturnRequest $r) => $r->holdsUnits()) as $r) {
            foreach ((array) $r->items as $line) {
                $claimed[$line['order_item_id'] ?? ''] = ($claimed[$line['order_item_id'] ?? ''] ?? 0) + (int) ($line['quantity'] ?? 0);
            }
        }
        $fulfillments = $o->fulfillments->keyBy('id');
        $out = [];
        foreach ($o->items as $i) {
            $f = $i->order_fulfillment_id ? $fulfillments->get($i->order_fulfillment_id) : null;
            $left = $f !== null && self::returnWindowOpen($f) ? $i->quantity_handed_over - $i->quantity_returned - ($claimed[$i->id] ?? 0) : 0;
            if ($left > 0) {
                $out[$i->id] = $left;
            }
        }

        return $out;
    }

    /**
     * "You can report a problem until <date>": the last day of the claim window across the parcels that were delivered or collected and still have
     * a piece to report (null when nothing can be reported now).
     *
     * @param  array<string, int>  $returnable
     * @return array{days: int, until: string|null}
     */
    private function returnWindow(Order $o, array $returnable): array
    {
        $days = self::returnWindowDays();
        $until = null;
        if (self::returnsEnabled() && $returnable !== []) {
            $fulfillments = $o->fulfillments->keyBy('id');
            foreach ($o->items as $i) {
                $f = isset($returnable[$i->id]) && $i->order_fulfillment_id ? $fulfillments->get($i->order_fulfillment_id) : null;
                $since = $f !== null ? ($f->delivered_at ?? $f->picked_up_at ?? $f->updated_at) : null;
                if ($since !== null && ($until === null || $since->gt($until))) {
                    $until = $since;
                }
            }
        }

        return ['days' => $days, 'until' => $until !== null ? BusinessTime::iso($until->addDays($days)) : null];
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
                // The driver's number only while the driver has the order (the same moments as the live order card), never after it is delivered or failed.
                'driver_phone' => in_array($d->status, self::DRIVER_CALLABLE, true) ? Phone::normalize($d->driver?->phone) : null,
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
