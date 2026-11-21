<?php

namespace App\Domain\Operations\Support;

/**
 * One card of the live operations board: one BRANCH PART of an order (an order that comes from two branches is two cards, one in each branch's lanes).
 * Plain facts for the screen, already worked out and already checked against the viewer's permissions: nothing here is a customer's name, phone or address.
 *
 *  - `lane`    where it stands (new, preparing, ready, out, pickup) or `attention` when it is broken or late; `from` is the lane it would stand in
 *  - `reason`  only in the attention lane: cancellation (a manager must decide), failed (the delivery failed), late (the clock is red), customer (waiting for the customer's answer)
 *  - `stage`   what it is waiting for, a key of ops_board.stage.* ("Waiting to be accepted", "Driver named, not accepted yet" ...)
 *  - `age`     the order clock of that wait (OpsClock), or a plain "out for 25 min" for a delivery on the road, or null when nobody has to act
 *  - `due`     what has to be collected for this part in cash (cash on delivery or pay at pickup), already formatted, or null
 *  - `assign`  where to name or change the driver (the Deliveries page filtered to this order), or null when the viewer may not
 */
final class BoardCard
{
    public function __construct(
        public readonly string $id,
        public readonly string $orderId,
        public readonly string $number,
        public readonly ?string $part,
        public readonly string $branchId,
        public readonly ?string $branchCode,
        public readonly string $lane,
        public readonly string $from,
        public readonly ?string $reason,
        public readonly string $stage,
        public readonly ?OpsAge $age,
        public readonly int $units,
        public readonly string $type,
        public readonly bool $express,
        public readonly ?string $when,
        public readonly string $payment,
        public readonly ?string $due,
        public readonly ?string $driver,
        public readonly bool $waitingCustomer,
        public readonly ?string $note,
        public readonly bool $canAccept,
        public readonly ?string $assign,
    ) {}

    /** The urgency used to sort a lane and to colour the card: ok, amber or red. */
    public function state(): string
    {
        return $this->age->state ?? OpsAge::OK;
    }

    public function rank(): int
    {
        return OpsAge::rankOf($this->state());
    }
}
