<?php

namespace App\Domain\Orders\Services;

use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderStatusHistory;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The small, PII-light order event shown as a live toast: order number, status change, branch, customer FIRST name,
 * total, payment method and fulfilment type. Used by the staff broadcast (private-live-orders) and by the partner
 * page's incremental poll, so both show exactly the same facts.
 */
class LiveOrderFeed
{
    /** The poll re-reads this many milliseconds before the cursor so a row committed late is never missed (the client de-duplicates by id). */
    public const OVERLAP_MS = 3000;

    public const PAGE = 20;

    /** @return array<string, string|null> */
    public function payload(Order $order, string $type, ?string $from, string $to, string $eventId, CarbonInterface $at): array
    {
        $order->loadMissing('fulfillments.branch:id,name_en,name_ar');
        $branches = $order->fulfillments->pluck('branch')->filter()->unique('id')->values();

        return [
            'id' => $eventId,
            'type' => $type, // placed | status
            'order_id' => $order->id,
            'number' => $order->number,
            'status' => $to,
            'previous' => $from,
            'branch' => $branches->pluck('name_en')->filter()->join(', ') ?: null,
            'branch_ar' => $branches->map(fn ($b) => $b->name_ar ?: $b->name_en)->filter()->join('، ') ?: null,
            'customer' => $this->firstName($order->customer_name),
            'total' => (string) $order->grand_total,
            'currency' => $order->currency ?: 'QAR',
            'payment_method' => $order->payment_method,
            'fulfilment' => $order->fulfillment_type,
            'at' => $at->toIso8601String(),
        ];
    }

    /** @return list<string> the branch ids that own a part of the order (they hear it on their own channel) */
    public function branchIds(Order $order): array
    {
        $order->loadMissing('fulfillments:id,order_id,branch_id');

        return $order->fulfillments->pluck('branch_id')->filter()->unique()->values()->all();
    }

    /**
     * Events newer than $sinceMs (Unix milliseconds), oldest first, at most PAGE. A null cursor only returns a fresh
     * cursor, so a page that has just opened never replays the past.
     *
     * @return array{events: list<array<string, string|null>>, cursor: int}
     */
    public function since(?int $sinceMs): array
    {
        $nowMs = (int) (microtime(true) * 1000);
        if ($sinceMs === null) {
            return ['events' => [], 'cursor' => $nowMs];
        }
        $from = BusinessTime::toUtc(CarbonImmutable::createFromTimestampMsUTC(max(0, $sinceMs - self::OVERLAP_MS)));

        $placed = Order::query()->where('placed_at', '>', $from)->orderBy('placed_at')->limit(self::PAGE)->get()
            ->map(fn (Order $o) => $this->payload($o, 'placed', null, $o->status->value, 'placed:'.$o->id, $o->placed_at));

        $changes = OrderStatusHistory::query()->where('kind', 'order')->whereNotNull('from_status')->where('created_at', '>', $from)
            ->orderBy('id')->limit(self::PAGE)->get();
        $orders = Order::query()->whereIn('id', $changes->pluck('order_id'))->get()->keyBy('id');
        $status = $changes->filter(fn ($h) => $orders->has($h->order_id))
            ->map(fn (OrderStatusHistory $h) => $this->payload($orders[$h->order_id], 'status', $h->from_status, $h->to_status, 'status:'.$h->id, $h->created_at));

        /** @var Collection<int, array<string, string|null>> $merged */
        $merged = $placed->concat($status)->sortBy('at')->values()->take(self::PAGE);

        return ['events' => $merged->all(), 'cursor' => $nowMs];
    }

    private function firstName(?string $name): ?string
    {
        $first = preg_split('/\s+/u', trim((string) $name))[0] ?? '';

        return $first === '' ? null : mb_substr($first, 0, 40);
    }
}
