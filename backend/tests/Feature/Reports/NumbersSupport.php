<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Marketing\Models\CouponUsage;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Operations\Services\LiveBoard;
use App\Domain\Reports\Services\CustomerValue;
use App\Domain\Reports\Services\DailyCloseNumbers;
use App\Domain\Reports\Services\DashboardMetrics;
use App\Domain\Reports\Services\OfferReturn;
use App\Domain\Reports\Services\OwnerPackData;
use App\Domain\Reports\Services\ReportService;
use App\Domain\Reports\Services\ScorecardPeriod;
use App\Domain\Reports\Services\ScorecardService;
use App\Domain\Reports\Services\ScorecardSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/ScorecardSupport.php';
require_once __DIR__.'/OwnerNumbersSupport.php';

/*
 | THE ONE FIXTURE behind "one definition per number" (docs/guides/NUMBER_DEFINITIONS.md): several branches, drivers, late / failed / cancelled / unpaid / COD /
 | refunded / returned / split / assembly orders, written straight into the tables with concrete Qatar moments so every figure can be checked by hand. The golden
 | test (NumbersGoldenTest) and the agreement test (NumbersAgreementTest) both read it.
 |
 | "Today" is Thursday 19 Nov 2026, midday in Qatar. The week looked at is ISO week 46 (Mon 9 to Sun 15 Nov), the week before is week 45 (2 to 8 Nov), the week
 | after is week 47 (16 to 22 Nov).
 |
 |   DOH  Doha, no opening hours (plain clock minutes)       WKR  Wakra, no opening hours
 |   HRS  has opening hours 10:00 to 22:00 (Fri from 14:00)  EMP  nothing ever happens here
 */

/** The world: four branches, two drivers, a customer, an owner. @return object{a: Branch, b: Branch, c: Branch, d: Branch, d1: User, d2: User, cust1: User, admin: User, svc: ScorecardService} */
function nbWorld(int $minSample = 1): object
{
    $w = scWorld($minSample);
    $w->c = opsBranch(null, ['code' => 'HRS', 'name_en' => 'Hours Branch', 'name_ar' => 'فرع الدوام']);
    $w->d = opsBranch([], ['code' => 'EMP', 'name_en' => 'Empty Branch', 'name_ar' => 'فرع فارغ']);
    foreach ([[$w->a, 1], [$w->b, 2], [$w->c, 3], [$w->d, 4]] as [$branch, $order]) {
        DB::table('branches')->where('id', $branch->id)->update(['sort_order' => $order]);
    }
    $w->d1 = scDriver([$w->a], 'Dina Driver');
    $w->d2 = scDriver([$w->a, $w->b], 'Omar Driver');
    $w->cust1 = User::factory()->create(['name' => 'Nbtest Customer One']);
    // The owner-numbers helpers (onOrder, onCashCollection) read these two from the test's own "world".
    test()->world = (object) ['customer' => $w->cust1, 'admin' => $w->admin, 'a' => $w->a, 'b' => $w->b];

    return $w;
}

/** Sets what an order (from scOrder) is worth: its one line, and the order's total. Assembly is part of the line's value, not of the price. */
function nbPrice(array $o, string $amount, string $assembly = '0.00'): array
{
    $line = number_format((float) $amount - (float) $assembly, 2, '.', '');
    DB::table('orders')->where('id', $o['order'])->update(['subtotal' => $amount, 'grand_total' => $amount]);
    DB::table('order_items')->where('order_id', $o['order'])->update(['unit_price' => $line, 'unit_final_price' => $line, 'line_subtotal' => $line, 'line_total' => $line, 'assembly_total' => $assembly]);

    return $o;
}

/**
 * A delivery that reached the customer: placed, accepted $accept minutes later, ready $prepare minutes after that, delivered at a Qatar moment, promised for a
 * day (or none), given to a driver (with a trip of $trip minutes) and priced. $order overrides columns of the order.
 *
 * @param  array<string, mixed>  $order
 * @return array{order: string, part: string, number: string}
 */
function nbDelivered(Branch $branch, string $placed, string $deliveredAt, ?string $slot, ?User $driver = null, string $amount = '100.00', array $order = [], int $accept = 5, int $prepare = 10, int $trip = 30): array
{
    $o = scOrder($branch, $placed, $accept, $prepare, ['status' => 'delivered', 'type' => 'delivery', 'delivered_at' => scAt($deliveredAt), 'slot_date' => $slot], $order);
    nbPrice($o, $amount);
    if ($driver !== null) {
        scTrip($o, $branch, $driver, 'delivered', ['delivered_at' => $deliveredAt, 'out_for_delivery_at' => CarbonImmutable::parse($deliveredAt, 'Asia/Qatar')->subMinutes($trip)->format('Y-m-d H:i:s')]);
    }

    return $o;
}

/** A refund processed at a Qatar moment (the payment it comes from is a plain cash-on-delivery row). */
function nbRefund(array $o, string $amount, string $at, string $reason = 'return'): void
{
    static $n = 0;
    $n++;
    DB::table('refunds')->insert(['id' => (string) Str::uuid(), 'number' => 'NBRF'.str_pad((string) $n, 5, '0', STR_PAD_LEFT), 'payment_id' => opsPayment($o['order']), 'order_id' => $o['order'],
        'amount' => $amount, 'reason_code' => $reason, 'method' => 'manual_cash', 'status' => 'processed', 'processed_at' => scAt($at), 'created_at' => scAt($at), 'updated_at' => scAt($at)]);
}

/** A return claim opened at a Qatar moment, and (when the goods came back) received at another. */
function nbClaim(array $o, Branch $branch, User $customer, string $opened, ?string $received = null, string $reason = 'damaged'): void
{
    static $n = 0;
    $n++;
    DB::table('return_requests')->insert(['id' => (string) Str::uuid(), 'number' => 'NBRR'.str_pad((string) $n, 5, '0', STR_PAD_LEFT), 'order_id' => $o['order'], 'user_id' => $customer->id,
        'branch_id' => $branch->id, 'status' => $received !== null ? 'received' : 'requested', 'reason_code' => $reason, 'items' => '[]', 'received_at' => $received !== null ? scAt($received) : null,
        'created_at' => scAt($opened), 'updated_at' => scAt($opened)]);
}

/** Gives a hand-in's open difference back as explained. */
function nbResolve(string $handInId): void
{
    DB::table('cash_handovers')->where('id', $handInId)->update(['variance_status' => 'resolved', 'variance_resolved_at' => now(), 'variance_resolution' => 'Counted again']);
}

/** An order of several branches (parts: [branch, subtotal, delivery fee]), every part accepted and ready on the day it was placed, and delivered on the dates given (null: still being prepared). */
function nbSplit(array $parts, string $placed, array $deliveredAt, array $slots, ?User $customer = null, array $drivers = []): array
{
    $order = onOrder($parts, ['user_id' => $customer?->id, 'placed_at' => qatar($placed), 'confirmed_at' => qatar($placed)]);
    foreach ($order->fulfillments as $i => $f) {
        $at = qatar($placed);
        $update = ['created_at' => scAt($placed), 'accepted_at' => $at->addMinutes(10)->utc()->format('Y-m-d H:i:s'), 'status' => 'preparing'];
        if (($deliveredAt[$i] ?? null) !== null) {
            $update = ['status' => 'delivered', 'ready_at' => $at->addMinutes(40)->utc()->format('Y-m-d H:i:s'), 'delivered_at' => scAt($deliveredAt[$i]), 'slot_date' => $slots[$i] ?? null] + $update;
            if (($drivers[$i] ?? null) !== null) {
                scTrip(['order' => $order->id, 'part' => $f->id], Branch::find($f->branch_id), $drivers[$i], 'delivered', ['delivered_at' => $deliveredAt[$i],
                    'out_for_delivery_at' => CarbonImmutable::parse($deliveredAt[$i], 'Asia/Qatar')->subMinutes(30)->format('Y-m-d H:i:s')]);
            }
        }
        DB::table('order_fulfillments')->where('id', $f->id)->update($update);
    }

    return ['order' => $order->id, 'number' => $order->number, 'parts' => $order->fulfillments->pluck('id')->all()];
}

/**
 * Writes the whole fixture. Returns the things a test wants to point at.
 *
 * @return array<string, mixed>
 */
function nbFill(object $w): array
{
    [$a, $b, $c] = [$w->a, $w->b, $w->c];
    $x = [];

    // ── The week before (2 to 8 Nov, ISO week 45) ──────────────────────────────────────────────────────────────────────────────────────────
    nbDelivered($a, '2026-11-02 10:00', '2026-11-02 14:00', '2026-11-02', $w->d1, '200.00');                                   // on time
    nbDelivered($a, '2026-11-03 10:00', '2026-11-04 11:00', '2026-11-03', $w->d1, '150.00');                                   // a day late
    scFailed($a, '2026-11-04 10:00', '2026-11-05 12:00', 'customer_absent', $w->d1);
    scHandIn($a, $w->d1, '2026-11-06 18:00', '3.00');                                                                              // 3.00 over, still open
    scOrder($a, '2026-11-06 10:00', 10, 20, ['type' => 'pickup', 'status' => 'pickup_ready'], ['payment_method' => 'pay_at_pickup', 'fulfillment_type' => 'pickup']);  // never collected: its pass ran out on Mon 9
    nbDelivered($b, '2026-11-02 09:00', '2026-11-02 12:00', '2026-11-02', $w->d2, '80.00');
    scCancelled($b, '2026-11-03 09:00', '2026-11-03 10:00', 'changed_mind');

    // ── Doha, week 46 ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
    $x['a1'] = nbDelivered($a, '2026-11-09 10:00', '2026-11-09 15:00', '2026-11-09', $w->d1, '100.00', [], 5, 10, 60);          // on time, 60 min on the road
    scCash($x['a1'], $a, $w->d1, '100.00', '2026-11-09 15:00');                                                                   // cash taken on delivery, never handed in
    $x['a2'] = nbDelivered($a, '2026-11-10 11:00', '2026-11-11 09:00', '2026-11-10', $w->d1);                                   // a day late
    scCash($x['a2'], $a, $w->d1, '100.00', '2026-11-11 09:00', handedIn: true);
    $x['h1'] = scHandIn($a, $w->d1, '2026-11-11 18:00', '-5.00');                                                                  // short, open
    $x['a3'] = nbDelivered($a, '2026-11-10 23:59', '2026-11-11 00:05', '2026-11-10', $w->d1, '100.00', [], 10, 20, 5);          // placed 23:59, delivered 00:05 the next day: late by the Qatar day
    $x['a3b'] = nbDelivered($a, '2026-11-11 00:30', '2026-11-11 14:00', '2026-11-11', $w->d1);                                  // placed at 21:30 UTC of the 10th, but the 11th in Qatar
    $x['a4'] = scFailed($a, '2026-11-12 09:00', '2026-11-12 13:00', 'customer_absent', $w->d1);
    $x['a5'] = scFailed($a, '2026-11-13 10:00', '2026-11-13 16:00', 'damaged', $w->d2);
    $x['a6'] = scCancelled($a, '2026-11-11 10:00', '2026-11-11 11:00', 'changed_mind');                                              // a real order, cancelled
    $x['a7'] = scOrder($a, '2026-11-11 12:00', null, null, ['status' => 'cancelled', 'cancelled_at' => scAt('2026-11-11 12:30')], ['status' => 'cancelled', 'payment_method' => 'sadad', 'confirmed_at' => null]);   // an online order that expired unpaid: not an order
    $x['a8'] = scOrder($a, '2026-11-11 12:30', null, null, [], ['status' => 'pending_payment', 'payment_method' => 'sadad', 'confirmed_at' => null]);                                                          // waiting for online payment: not an order
    $x['a9'] = scOrder($a, '2026-11-09 12:00', 10, 20, ['type' => 'pickup', 'status' => 'picked_up', 'picked_up_at' => scAt('2026-11-09 18:00')], ['payment_method' => 'pay_at_pickup', 'fulfillment_type' => 'pickup']);   // collected in time
    $x['a11'] = nbDelivered($a, '2026-11-13 10:00', '2026-11-13 16:00', '2026-11-13', $w->d1, '100.00', ['user_id' => $w->cust1->id]);
    nbRefund($x['a11'], '30.00', '2026-11-14 10:00');                                                                              // refunded the next day, same week
    nbClaim($x['a11'], $a, $w->cust1, '2026-11-14 10:00', '2026-11-16 10:00');                                                     // opened in week 46, the goods came back in week 47
    scCash($x['a11'], $a, $w->d1, '100.00', '2026-11-13 16:00');
    $x['a12'] = nbDelivered($a, '2026-11-15 14:00', '2026-11-15 18:00', '2026-11-15', $w->d2, '100.00', ['user_id' => $w->cust1->id]);
    nbRefund($x['a12'], '40.00', '2026-11-16 10:00');                                                                              // refunded the day after the week ended
    $x['a14'] = nbPrice(nbDelivered($a, '2026-11-12 15:00', '2026-11-14 12:00', '2026-11-14', $w->d1, '850.00', ['user_id' => $w->cust1->id]), '850.00', '50.00');   // 800 of goods and 50 of assembly
    $x['h3'] = scHandIn($a, $w->d2, '2026-11-13 18:00', '0.00');                                                                   // counted exactly
    $x['h4'] = scHandIn($a, $w->d1, '2026-11-14 18:00', '2.00');
    nbResolve($x['h4']);                                                                                                           // over by 2.00, explained
    $card = scCash(nbDelivered($a, '2026-11-12 08:00', '2026-11-12 11:00', '2026-11-12'), $a, $w->d1, '60.00', '2026-11-12 11:00');
    DB::table('cash_collections')->where('id', $card)->update(['tender' => 'card_terminal']);                                      // a card terminal is not cash

    // ── Wakra, week 46 ────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
    $x['b1'] = nbDelivered($b, '2026-11-09 09:00', '2026-11-09 12:00', '2026-11-09', $w->d2, '100.00', [], 15, 25);
    scCash($x['b1'], $b, $w->d2, '100.00', '2026-11-09 12:00', handedIn: true);
    $x['h2'] = scHandIn($b, $w->d2, '2026-11-10 11:00', '4.50');                                                                   // over, open
    $x['b2'] = nbDelivered($b, '2026-11-10 16:00', '2026-11-10 18:00', null, $w->d2);                                           // no promised day
    $x['b3'] = nbDelivered($b, '2026-11-11 10:00', '2026-11-12 17:00', '2026-11-12', $w->d2);                                   // first promised for the 11th, moved to the 12th
    $trip = DB::table('deliveries')->where('order_fulfillment_id', $x['b3']['part'])->value('id');
    DB::table('delivery_status_history')->insert(['delivery_id' => $trip, 'from_status' => 'failed', 'to_status' => 'rescheduled', 'source' => 'staff', 'created_at' => scAt('2026-11-11 16:00')]);
    $x['b4'] = nbDelivered($b, '2026-11-13 10:00', '2026-11-14 10:00', '2026-11-14', $w->d2, '100.00', ['payment_method' => 'sadad', 'payment_status' => 'paid']);
    $x['b5'] = scCancelled($b, '2026-11-14 09:00', '2026-11-14 10:00', 'out_of_stock');
    $x['b7'] = scCancelled($b, '2026-11-11 15:00', '2026-11-11 16:00', 'changed_mind', ['payment_method' => 'sadad', 'payment_status' => 'refunded']);                    // paid online, cancelled, paid back in full
    nbRefund($x['b7'], '100.00', '2026-11-11 16:30', 'order_cancelled');
    $x['b6'] = scOrder($b, '2026-11-15 09:00', 5, 25, ['type' => 'pickup', 'status' => 'picked_up', 'picked_up_at' => scAt('2026-11-15 11:00')], ['payment_method' => 'pay_at_pickup', 'fulfillment_type' => 'pickup']);
    nbPrice($x['b6'], '50.00');

    // ── The branch with opening hours: a night when it was shut is not slowness ─────────────────────────────────────────────────────────────
    $x['c1'] = scOrder($c, '2026-11-09 21:50', 0, null, ['accepted_at' => scAt('2026-11-10 10:10'), 'ready_at' => scAt('2026-11-10 10:40'), 'status' => 'delivered', 'type' => 'delivery',
        'delivered_at' => scAt('2026-11-10 16:00'), 'slot_date' => '2026-11-10']);
    $x['c2'] = nbDelivered($c, '2026-11-11 12:00', '2026-11-11 15:00', '2026-11-11', null, '100.00', [], 10, 20);

    // ── An order of two branches (A carries 1015.00 of it, B 275.00) ───────────────────────────────────────────────────────────────────────
    $x['s1'] = nbSplit([[$a, '1000.00', '15.00'], [$b, '250.00', '25.00']], '2026-11-11 13:00', ['2026-11-12 11:00', '2026-11-13 15:00'], ['2026-11-12', '2026-11-12'], $w->cust1, [$w->d1, $w->d2]);
    $promotion = Promotion::create(['name_en' => 'Ten off', 'name_ar' => 'عشرة', 'type' => 'percentage', 'value' => '10', 'scope' => 'order', 'requires_coupon' => true, 'is_active' => true]);
    $coupon = Coupon::create(['promotion_id' => $promotion->id, 'code' => 'NUMBERS10', 'is_active' => true]);
    CouponUsage::create(['coupon_id' => $coupon->id, 'promotion_id' => $promotion->id, 'user_id' => $w->cust1->id, 'order_id' => $x['a14']['order'], 'discount_amount' => '85.00', 'status' => 'applied']);
    CouponUsage::create(['coupon_id' => $coupon->id, 'promotion_id' => $promotion->id, 'user_id' => $w->cust1->id, 'order_id' => $x['a6']['order'], 'discount_amount' => '10.00', 'status' => 'applied']);   // on an order that was cancelled: no sale, no return

    // ── Orders from months ago (a customer who has gone quiet) ─────────────────────────────────────────────────────────────────────────────
    onOrder([[$a, '500.00', '0.00', 'delivered']], ['user_id' => User::factory()->create(['name' => 'Nbtest Quiet Customer'])->id, 'placed_at' => qatar('2026-08-01 10:00')]);

    // ── Today, Thursday 19 Nov ─────────────────────────────────────────────────────────────────────────────────────────────────────────────
    $x['t1'] = scOrder($a, '2026-11-19 09:00');                                                                                    // nobody has accepted it
    $x['t2'] = scOrder($a, '2026-11-19 10:00', 5, null, [], ['payment_method' => 'sadad', 'payment_status' => 'paid']);          // paid online, being prepared
    nbPrice($x['t2'], '250.00');
    DB::table('payments')->insert(['id' => (string) Str::uuid(), 'payable_type' => 'order', 'payable_id' => $x['t2']['order'], 'order_id' => $x['t2']['order'], 'method' => 'sadad', 'provider' => 'sadad', 'status' => 'paid',
        'amount' => '250.00', 'captured_amount' => '250.00', 'paid_at' => scAt('2026-11-19 10:05'), 'created_at' => scAt('2026-11-19 10:05'), 'updated_at' => scAt('2026-11-19 10:05')]);
    $x['t3'] = scOrder($b, '2026-11-19 11:00', 5, 10, ['type' => 'pickup', 'status' => 'pickup_ready'], ['payment_method' => 'pay_at_pickup', 'fulfillment_type' => 'pickup']);
    nbPrice($x['t3'], '60.00');
    $x['t4'] = scOrder($a, '2026-11-19 08:30', 5, 10, ['status' => 'out_for_delivery', 'dispatched_at' => scAt('2026-11-19 09:00')]);
    opsDelivery($x['t4']['part'], $a, 'out_for_delivery', ['driver_id' => $w->d1->id, 'out_for_delivery_at' => scAt('2026-11-19 09:00')]);
    $x['t5'] = nbSplit([[$a, '300.00', '0.00'], [$b, '100.00', '0.00']], '2026-11-19 09:30', [null, null], [], null);
    $x['t6'] = scOrder($a, '2026-11-18 09:00');                                                                                    // yesterday's order that nobody accepted: late
    scCash(scOrder($a, '2026-11-19 09:15', 5, 10, ['status' => 'delivered', 'delivered_at' => scAt('2026-11-19 09:30'), 'slot_date' => '2026-11-19']), $a, $w->d1, '80.00', '2026-11-19 09:30');
    $cardToday = scCash(scOrder($b, '2026-11-19 09:45', 5, 10, ['status' => 'delivered', 'delivered_at' => scAt('2026-11-19 10:00'), 'slot_date' => '2026-11-19']), $b, $w->d2, '120.00', '2026-11-19 10:00');
    DB::table('cash_collections')->where('id', $cardToday)->update(['tender' => 'card_terminal']);                                  // "Collected today" counts a card, the daily close's cash does not

    // ── Stock as it stands: Doha has one item out and one running low ──────────────────────────────────────────────────────────────────────
    [$v1, $v2, $v3] = onVariants(3);
    $ledger = app(InventoryLedger::class);
    foreach ([[$v1, 3], [$v2, 2], [$v3, 50]] as [$variant, $received]) {
        $ledger->apply($a->id, $variant->id, MovementType::OpeningBalance, $received);
    }
    $ledger->apply($a->id, $v1->id, MovementType::SaleDeduction, -3);
    DB::table('branch_inventory')->where('branch_id', $a->id)->where('product_variant_id', $v2->id)->update(['minimum_stock' => 5]);

    // The order builders number their lines from a counter that runs on across a whole test run, and the pack's "top five products" breaks ties by that number.
    // Naming every line by its place in the fixture (its day, branch and amount) makes the top five the same on every run.
    $lines = DB::table('order_items as oi')->join('orders as o', 'o.id', '=', 'oi.order_id')->join('branches as b', 'b.id', '=', 'oi.branch_id')
        ->orderBy('o.placed_at')->orderBy('b.code')->orderBy('oi.line_total')->orderBy('o.status')->orderBy('oi.assembly_total')->pluck('oi.id');
    foreach ($lines as $i => $id) {
        DB::table('order_items')->where('id', $id)->update(['sku' => sprintf('NB-%04d', $i + 1), 'name_en' => 'Fixture item '.($i + 1), 'name_ar' => 'صنف '.($i + 1)]);
    }

    return $x;
}

/** Every figure of every screen that shows these numbers, for the fixture, with ids replaced by codes and names, so a recording made on one run compares on any other. @return array<string, mixed> */
function nbSnapshot(object $w): array
{
    $names = [$w->a->id => 'DOH', $w->b->id => 'WKR', $w->c->id => 'HRS', $w->d->id => 'EMP', $w->d1->id => 'DRIVER-DINA', $w->d2->id => 'DRIVER-OMAR'];
    $close = app(DailyCloseNumbers::class);
    $days = ['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-14', '2026-11-15', '2026-11-16', '2026-11-18', '2026-11-19'];
    $daily = [];
    foreach ([$w->a, $w->b, $w->c, $w->d] as $branch) {
        foreach ($days as $day) {
            $daily[$branch->code][$day] = $close->for($branch->id, $day);
        }
    }
    $pack = [];
    foreach (['2026-W45', '2026-W46', '2026-W47'] as $week) {
        $pack[$week] = app(OwnerPackData::class)->for($week);
    }
    $period = ScorecardPeriod::between('2026-11-09', '2026-11-15');
    $card = [];
    foreach ([1, 3] as $min) {
        app(ScorecardSettings::class)->setMinSample($min);
        Cache::flush();
        $card['min'.$min] = ['branches' => $w->svc->branches($w->admin, $period), 'drivers' => $w->svc->drivers($w->admin, $period)];
    }
    app(ScorecardSettings::class)->setMinSample(1);
    Cache::flush();
    $metrics = app(DashboardMetrics::class)->for($w->admin);
    $dashboard = array_intersect_key($metrics, array_flip(['sales_today', 'collected_today', 'collected_online', 'collected_cash', 'orders_today', 'pending_fulfillments', 'ready_for_pickup', 'out_for_delivery',
        'low_stock', 'out_of_stock', 'pending_refunds']));
    $dashboard['chart'] = $metrics['chart'];
    $board = app(LiveBoard::class)->for($w->admin, null, LiveBoard::LANES);
    $lanes = ['open' => $board->open];
    foreach ($board->lanes as $key => $lane) {
        $lanes[$key] = ['total' => $lane['total'], 'red' => $lane['red'], 'amber' => $lane['amber'], 'from' => $lane['cards']->countBy(fn ($card) => $card->from)->sortKeys()->all()];
    }
    $value = app(CustomerValue::class);
    $value->refresh();
    $customers = ['summary' => $value->summary($w->admin), 'lifetime_cust1' => $value->lifetimeOf($w->admin, $w->cust1->id)];
    $customers['lifetime_cust1'] = ['orders' => $customers['lifetime_cust1']['orders'], 'spend' => $customers['lifetime_cust1']['spend']];
    $offers = app(OfferReturn::class)->report('2026-11-09', '2026-11-15');
    $offer = ['overall' => $offers['overall'], 'offers' => array_map(fn (array $o) => array_diff_key($o, ['key' => 1]), $offers['offers'])];

    $reports = [];
    foreach (['all' => null, 'DOH' => $w->a->id, 'WKR' => $w->b->id] as $label => $branch) {
        $rows = app(ReportService::class)->run($w->admin, 'sales_summary', '2026-11-09', '2026-11-15', $branch)['rows'];
        $reports[$label] = collect($rows)->whereIn('metric', ['orders', 'booked_sales', 'gross_sales', 'discounts', 'cancelled', 'refunds', 'net_sales', 'average_order_value'])->pluck('value', 'metric')->all();
    }

    $all = ['daily_close' => $daily, 'reports' => $reports, 'owner_pack' => $pack, 'scorecard' => $card, 'dashboard' => $dashboard, 'live_board' => $lanes, 'customer_value' => $customers, 'offer_return' => $offer];

    return nbScrub($all, $names);
}

/** Turns ids into names and drops what changes from run to run (the moment a figure was made). @param array<string, mixed> $data @param array<string, string> $names @return array<string, mixed> */
function nbScrub(array $data, array $names): array
{
    $strip = function (array $node) use (&$strip): array {
        unset($node['generated_at']);
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $strip($value);
            }
        }

        return $node;
    };
    $json = json_encode($strip($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

    return nbSorted(json_decode(str_replace(array_keys($names), array_values($names), $json), true, 512, JSON_THROW_ON_ERROR));
}

/** Puts the keys of every keyed list in order (lists stay as they are), so two snapshots compare on what they hold and not on how it was assembled. @param array<mixed> $data @return array<mixed> */
function nbSorted(array $data): array
{
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $data[$key] = nbSorted($value);
        }
    }
    if (! array_is_list($data)) {
        ksort($data);
    }

    return $data;
}
