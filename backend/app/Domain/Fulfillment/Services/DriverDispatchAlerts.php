<?php

namespace App\Domain\Fulfillment\Services;

use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Identity\Models\User;
use App\Domain\Operations\Services\StaffAlerts;
use Illuminate\Support\Collection;

/**
 * Tells the branch managers (and whoever may assign drivers there) when the driver side of dispatch needs a person: a driver handed a job back,
 * nobody took a job, or a driver went quiet while carrying jobs. Pushes go through the existing staff alert (event `staff.order_reminder`: the same
 * switches in Content > Notification controls, the same push and inbox), and carry the order number and a first name only, never customer data.
 * Every method does nothing and never throws when nobody is there to tell.
 */
final class DriverDispatchAlerts
{
    public function __construct(private readonly StaffAlerts $alerts) {}

    /**
     * A driver handed a job back. $next is the driver the system gave it to at once (null: nobody); $auto says the branch gives jobs out by itself,
     * so "nobody" means no other driver is on duty, and otherwise a person has to choose.
     */
    public function declined(Delivery $delivery, User $driver, string $reason, ?User $next, bool $auto): int
    {
        $number = $this->number($delivery);

        return $this->send($delivery->branch_id, $delivery, 'declined', fn (string $locale) => [
            'title' => __('driver_day.push.declined_title', ['number' => $number], $locale),
            'body' => __('driver_day.push.declined_body', ['driver' => DriverPosition::firstName($driver->name), 'reason' => __('driver_day.reason.'.$reason, [], $locale),
                'next' => match (true) {
                    $next !== null => __('driver_day.push.next_given', ['driver' => DriverPosition::firstName($next->name)], $locale),
                    $auto => __('driver_day.push.next_waiting', [], $locale),
                    default => __('driver_day.push.next_manual', [], $locale),
                }], $locale),
        ]);
    }

    /** The system tried $attempts drivers and none took the job: it waits for a person. */
    public function nobodyTook(Delivery $delivery, int $attempts): int
    {
        $number = $this->number($delivery);

        return $this->send($delivery->branch_id, $delivery, 'silent', fn (string $locale) => [
            'title' => __('driver_day.push.silent_title', ['number' => $number], $locale),
            'body' => trans_choice('driver_day.push.silent_body', $attempts, ['n' => $attempts], $locale),
        ]);
    }

    /**
     * A driver on duty has not been heard from for $minutes minutes, so he was set off duty. Jobs he already accepted stay his (flagged here, never
     * taken away): $carrying of them are with him now.
     *
     * @param  list<string>  $branchIds
     */
    public function wentQuiet(array $branchIds, User $driver, int $minutes, int $carrying): int
    {
        $reached = 0;
        foreach ($branchIds as $branchId) {
            $reached += $this->send($branchId, null, 'quiet', fn (string $locale) => [
                'title' => __('driver_day.push.quiet_title', ['driver' => DriverPosition::firstName($driver->name)], $locale),
                'body' => trans_choice('driver_day.push.quiet_body', $carrying, ['minutes' => $minutes, 'count' => $carrying], $locale),
            ], ['driver_id' => $driver->id]);
        }

        return $reached;
    }

    /**
     * @param  \Closure(string): array{title: string, body: string}  $text
     * @param  array<string, string|null>  $extra
     */
    private function send(string $branchId, ?Delivery $delivery, string $kind, \Closure $text, array $extra = []): int
    {
        /** @var Collection<int, User> $people */
        $people = $this->alerts->recipients($branchId, ['deliveries.assign', 'orders.approve']);
        if ($people->isEmpty()) {
            return 0;
        }
        $order = $delivery?->fulfillment?->order;
        $data = ['branch_id' => $branchId, 'kind' => 'driver_'.$kind] + $extra;
        if ($delivery !== null && $order !== null) {
            $data += ['order_id' => $order->id, 'number' => $order->number, 'fulfillment_id' => $delivery->order_fulfillment_id, 'delivery_id' => $delivery->id];
        }

        return $this->alerts->send($people, 'staff.order_reminder', $text, $data, $order !== null ? route('admin.orders.index', ['view' => $order->id], false) : null);
    }

    private function number(Delivery $delivery): string
    {
        return (string) ($delivery->fulfillment?->order?->number ?? '');
    }
}
