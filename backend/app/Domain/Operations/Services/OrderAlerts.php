<?php

namespace App\Domain\Operations\Services;

use App\Domain\Operations\Support\OpsAge;
use App\Domain\Orders\Enums\FulfillmentStatus;
use App\Domain\Orders\Enums\OrderStatus;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Models\OrderItem;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Alerts about orders that wait for the branch team:
 *
 *  - {@see announceNew}: "a new order for your branch" (staff.order_new), once per branch part, the moment the order is real: at once for cash
 *    on delivery and pay at pickup, and for an online order only after its payment succeeded (an unpaid one never alerts anybody).
 *  - {@see remindUnaccepted}: "this order is still waiting" (staff.order_reminder), once when a new order turns amber and once when it turns
 *    red, to the branch managers (orders.approve) and the staff who can accept (orders.edit); quiet while the branch is closed.
 *
 * Every alert is claimed first in `ops_reminders` (unique per part and level), so a replayed event, a retried queue job or two overlapping
 * scheduler runs send it once. If sending fails the claim is taken back, so the retry sends it.
 */
final class OrderAlerts
{
    public const NEW = 'new';

    public function __construct(private readonly OpsClock $clock, private readonly StaffAlerts $alerts) {}

    /** @return int how many people were alerted (over all the branch parts of the order) */
    public function announceNew(string $orderId): int
    {
        $order = Order::query()->with('fulfillments:id,order_id,branch_id,status,type')->find($orderId, ['id', 'number', 'status']);
        if ($order === null || $order->status === OrderStatus::PendingPayment) {
            return 0;
        }
        $parts = $order->fulfillments->filter(fn (OrderFulfillment $f) => $f->status === FulfillmentStatus::Pending);
        if ($parts->isEmpty()) {
            return 0;
        }
        $items = OrderItem::query()->whereIn('order_fulfillment_id', $parts->pluck('id'))->groupBy('order_fulfillment_id')
            ->selectRaw('order_fulfillment_id, sum(quantity - quantity_cancelled) as units')->pluck('units', 'order_fulfillment_id');

        $reached = 0;
        foreach ($parts as $f) {
            $units = max(1, (int) ($items[$f->id] ?? 1));
            $reached += $this->once($f->id, self::NEW, fn () => $this->alerts->send(
                $this->alerts->recipients($f->branch_id, ['orders.edit']), 'staff.order_new',
                fn (string $locale) => ['title' => __('ops_live.push.new_title', ['number' => $order->number], $locale), 'body' => trans_choice('ops_live.push.new_body', $units, ['count' => $units], $locale)],
                self::data($order, $f), self::deepLink($order),
            )) ?? 0;
        }

        return $reached;
    }

    /**
     * One reminder per part per threshold crossed since the last run. Returns what it did, for the command's report.
     *
     * @return array{checked: int, reminded: int, people: int}
     */
    public function remindUnaccepted(?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());
        $amber = $this->clock->limits(OpsClock::PENDING)['amber'];
        $parts = OrderFulfillment::query()->where('status', 'pending')->where('created_at', '<=', $now->subMinutes($amber))
            ->whereHas('order', fn ($o) => $o->whereNotIn('status', ['pending_payment', 'cancellation_requested']))
            ->with('order:id,number,status,confirmed_at')->orderBy('created_at')->limit(500)->get();
        $this->clock->preload($parts->pluck('branch_id')->all());

        $out = ['checked' => $parts->count(), 'reminded' => 0, 'people' => 0];
        foreach ($parts as $f) {
            $order = $f->order;
            $age = $this->clock->forFulfillment($f, $now);
            if ($order === null || $age === null || ! $age->isLate() || ! $this->clock->branchOpen($f->branch_id, $now)) {
                continue; // not late yet, or the branch is closed: the team is not woken for it
            }
            $level = $age->state === OpsAge::RED ? 'red' : 'amber';
            if ($level === 'red') {
                $this->claim($f->id, 'amber'); // a part that jumped to red is not reminded about amber afterwards
            }
            try {
                $people = $this->once($f->id, $level, fn () => $this->alerts->send(
                    $this->alerts->recipients($f->branch_id, ['orders.approve', 'orders.edit']), 'staff.order_reminder',
                    fn (string $locale) => [
                        'title' => __('ops_live.push.'.$level.'_title', ['number' => $order->number], $locale),
                        'body' => __('ops_live.push.'.$level.'_body', ['time' => $this->waited($age, $locale)], $locale),
                    ],
                    self::data($order, $f) + ['level' => $level], self::deepLink($order),
                ));
            } catch (Throwable $e) {
                report($e); // the claim was given back: the next run tries this part again, and the other parts of this run are not held up

                continue;
            }
            if ($people !== null) {
                $out['reminded']++;
                $out['people'] += $people;
            }
        }

        return $out;
    }

    /**
     * Runs $send only if this (part, level) was not claimed yet; gives the claim back when sending throws, so a retry can send it.
     *
     * @param  callable(): int  $send
     */
    private function once(string $fulfillmentId, string $level, callable $send): ?int
    {
        if (! $this->claim($fulfillmentId, $level)) {
            return null;
        }
        try {
            return $send();
        } catch (Throwable $e) {
            DB::table('ops_reminders')->where('fulfillment_id', $fulfillmentId)->where('level', $level)->delete();
            throw $e;
        }
    }

    /** @return bool true when this call is the one that recorded the alert */
    private function claim(string $fulfillmentId, string $level): bool
    {
        return DB::table('ops_reminders')->insertOrIgnore(['fulfillment_id' => $fulfillmentId, 'level' => $level, 'sent_at' => now()]) === 1;
    }

    /** "12 min", "1 h 05 min" in the language of the alert. */
    private function waited(OpsAge $age, string $locale): string
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);
        try {
            return OpsClock::label($age->ageMinutes);
        } finally {
            app()->setLocale($previous);
        }
    }

    /** @return array<string, string|null> what the staff app receives: ids and the order number, no customer data */
    private static function data(Order $order, OrderFulfillment $f): array
    {
        return ['order_id' => $order->id, 'number' => $order->number, 'fulfillment_id' => $f->id, 'branch_id' => $f->branch_id];
    }

    private static function deepLink(Order $order): string
    {
        return route('admin.orders.index', ['view' => $order->id], false);
    }
}
