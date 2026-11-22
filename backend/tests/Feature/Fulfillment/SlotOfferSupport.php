<?php

use App\Domain\Branches\Models\DeliverySlot;
use App\Domain\Fulfillment\Models\DeliverySlotOffer;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Str;

require_once __DIR__.'/../Operations/OpsSupport.php';

/** Shared builders of the "new times after a failed delivery" tests (docs/guides/DELIVERY_FOLLOW_UPS.md). */

/** A delivery of branch A on the road, with a 16:00-18:00 delivery slot (capacity 2) on every weekday. The session is left as the driver. */
function fuSlotWorld(string $now = '2026-11-19 12:00', int $capacity = 2): object
{
    test()->travelTo(qatar($now));
    $x = deliveryAt('out_for_delivery');
    $slots = [];
    foreach (range(0, 6) as $dow) {
        $slots[$dow] = DeliverySlot::create(['branch_id' => $x->w->a->id, 'fulfillment_type' => 'delivery', 'day_of_week' => $dow, 'starts_at' => '16:00', 'ends_at' => '18:00', 'capacity' => $capacity, 'cutoff_minutes' => 60]);
    }
    $x->slots = $slots;

    return $x;
}

/** The driver (signed in) reports the failed attempt; returns the offer the system made, if any. */
function fuFail(object $x, string $reason = 'customer_absent'): ?DeliverySlotOffer
{
    actingAsStaff($x->driver);
    test()->postJson("/api/v1/admin/deliveries/{$x->delivery->id}/fail", ['reason_code' => $reason, 'notes' => 'Nobody opened the door'])->assertOk();

    return DeliverySlotOffer::where('delivery_id', $x->delivery->id)->latest('created_at')->first();
}

function fuDetail(object $x): array
{
    actingAsCustomer($x->customer);

    return test()->getJson("/api/v1/orders/{$x->orderId}")->assertOk()->json('data');
}

function fuChoose(object $x, DeliverySlotOffer $offer, array $time, ?string $key = null, ?User $as = null)
{
    actingAsCustomer($as ?? $x->customer);

    return test()->postJson("/api/v1/orders/{$x->orderId}/slot-offers/{$offer->id}/choose", $time, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
}
