<?php

namespace App\Domain\Orders\Realtime;

use App\Domain\Fulfillment\Events\DispatchUpdated;
use App\Domain\Fulfillment\Events\DriverJobUpdated;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Orders\Events\OrderUpdated;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use Illuminate\Support\Facades\DB;

/**
 * The single place where "something about an order moved" becomes real-time messages for the customer, the branch staff and the driver.
 *
 * It is driven by the models themselves (see OrderRealtimeObservers), so every way an order can change is covered the same way:
 * the customer placing it, branch staff in the apps or the dashboard, the driver, a courier webhook, a payment, a cancellation.
 *
 *  1. A model that changed tells us ("touch"). Several touches of one order inside one request or transaction are one change.
 *  2. After the database commit (a rolled-back change never leaves the server) the flush is queued with defer(): it runs once the
 *     HTTP response has been sent (or the command / job has finished), so a slow or absent socket server never holds up a user.
 *  3. The flush reads the committed state, raises the order's version (orders.progress_seq) once and sends the messages: one to the
 *     customer, one per branch part (and delivery) to its branch, one per delivery to its driver (and a "taken away" message to the
 *     driver who just lost it). Messages are built from the state read at that moment, so they carry the final state, not a step.
 *
 * Nothing here can fail a request: every message is sent inside rescue() and reported, and the apps re-read from the API whenever the
 * socket was down, so a lost message is repaired by the next reconnect or poll.
 */
class OrderRealtime
{
    /** @var array<string, array<string, array{kind: string, fulfillment: ?string, delivery: ?string, previous_driver: ?string}>> changes by order id */
    private array $byOrder = [];

    /** @var array<string, array<string, array{kind: string, fulfillment: ?string, delivery: ?string, previous_driver: ?string}>> changes by fulfillment id (their order is not known yet) */
    private array $byFulfillment = [];

    /** The order itself changed (placed, status, payment): the customer is told, and the branches get a refresh ping. */
    public function orderChanged(Order $order): void
    {
        $this->add($this->byOrder, $order->id, 'o:'.$order->id, ['kind' => 'order', 'fulfillment' => null, 'delivery' => null, 'previous_driver' => null]);
    }

    /** One branch part of an order was created or moved. */
    public function fulfillmentChanged(OrderFulfillment $f): void
    {
        $this->add($this->byOrder, $f->order_id, 'f:'.$f->id, ['kind' => 'order', 'fulfillment' => $f->id, 'delivery' => null, 'previous_driver' => null]);
    }

    /** A delivery was created, moved or handed to another driver ($previousDriver is the driver it was taken from). */
    public function deliveryChanged(Delivery $d, ?string $previousDriver = null): void
    {
        $this->add($this->byFulfillment, $d->order_fulfillment_id, 'd:'.$d->id, ['kind' => 'delivery', 'fulfillment' => $d->order_fulfillment_id, 'delivery' => $d->id, 'previous_driver' => $previousDriver]);
    }

    /**
     * @param  array<string, array<string, array{kind: string, fulfillment: ?string, delivery: ?string, previous_driver: ?string}>>  $bucket
     * @param  array{kind: string, fulfillment: ?string, delivery: ?string, previous_driver: ?string}  $change
     */
    private function add(array &$bucket, string $key, string $changeKey, array $change): void
    {
        // A delivery handed over twice before the commit keeps the driver it was first taken from.
        $change['previous_driver'] = ($bucket[$key][$changeKey]['previous_driver'] ?? null) ?? $change['previous_driver'];
        $bucket[$key][$changeKey] = $change;
        // Inside a transaction this waits for its commit (and never runs when it rolls back); outside one it runs now.
        DB::afterCommit(fn () => defer(fn () => $this->flush(), 'order-realtime', always: true));
    }

    /** Sends everything collected so far. Safe to call twice; the second call finds nothing. */
    public function flush(): void
    {
        $byFulfillment = $this->byFulfillment;
        $byOrder = $this->byOrder;
        $this->byFulfillment = $this->byOrder = [];
        if ($byFulfillment !== []) {
            foreach (OrderFulfillment::whereIn('id', array_keys($byFulfillment))->get(['id', 'order_id']) as $f) {
                $byOrder[$f->order_id] = ($byOrder[$f->order_id] ?? []) + $byFulfillment[$f->id];
            }
        }
        foreach ($byOrder as $orderId => $changes) {
            rescue(fn () => $this->publish((string) $orderId, array_values($changes)), report: true);
        }
    }

    /** @param list<array{kind: string, fulfillment: ?string, delivery: ?string, previous_driver: ?string}> $changes */
    private function publish(string $orderId, array $changes): void
    {
        $order = Order::with(['fulfillments.deliveries.driver:id,name'])->find($orderId);
        if ($order === null) {
            return;
        }
        $seq = $this->nextVersion($orderId);
        $parts = $order->fulfillments->keyBy('id');
        $touched = collect($changes)->pluck('fulfillment')->filter()->map(fn (string $id) => $parts->get($id))->filter()->last();

        if ($order->user_id) {
            $customer = OrderRealtimePayload::customer($order, $touched, $seq);
            rescue(fn () => event(new OrderUpdated($order->user_id, $order->id, $order->number, $order->status->value, $customer['delivery_status'], $customer)), report: true);
        }

        // Branch staff and drivers: one message per changed part or delivery; an order-level change (cancellation asked, payment) pings every part.
        $targets = [];
        foreach ($changes as $c) {
            if ($c['fulfillment'] === null) {
                foreach ($parts as $part) {
                    $targets['order:'.$part->id] ??= ['kind' => 'order', 'f' => $part, 'd' => $part->activeDelivery(), 'previous' => null, 'level' => true];
                }

                continue;
            }
            $f = $parts->get($c['fulfillment']);
            if ($f === null) {
                continue;
            }
            $delivery = $c['delivery'] ? $f->deliveries->firstWhere('id', $c['delivery']) : $f->activeDelivery();
            $targets[$c['kind'].':'.($c['delivery'] ?? $f->id)] = ['kind' => $c['kind'], 'f' => $f, 'd' => $delivery, 'previous' => $c['previous_driver'], 'level' => false];
        }
        foreach ($targets as $t) {
            /** @var OrderFulfillment $f */
            $f = $t['f'];
            /** @var Delivery|null $d */
            $d = $t['d'];
            $branch = OrderRealtimePayload::branch($order, $f, $d, $t['kind'], $seq, $t['level']);
            rescue(fn () => event(new DispatchUpdated($f->branch_id, $order->number, $t['kind'], $branch['status'], $d?->driver_id, $f->id, $branch)), report: true);
            if ($t['kind'] === 'delivery' && $d !== null) {
                if ($d->driver_id) {
                    rescue(fn () => event(new DriverJobUpdated($d->driver_id, OrderRealtimePayload::driver($order, $f, $d, true, $seq))), report: true);
                }
                if ($t['previous'] !== null && $t['previous'] !== $d->driver_id) {
                    rescue(fn () => event(new DriverJobUpdated($t['previous'], OrderRealtimePayload::driver($order, $f, $d, false, $seq))), report: true);
                }
            }
        }
    }

    /** Raises the order's version by one and returns it (one statement: two flushes at the same moment never get the same number). */
    private function nextVersion(string $orderId): int
    {
        $row = DB::selectOne('update orders set progress_seq = progress_seq + 1 where id = ? returning progress_seq', [$orderId]);

        return (int) ($row->progress_seq ?? 0);
    }
}
