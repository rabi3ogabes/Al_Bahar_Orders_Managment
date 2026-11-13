<?php

namespace App\Domain\Orders\Services;

use App\Domain\Cms\Services\ThemeService;
use App\Domain\Fulfillment\Enums\DeliveryStatus as DS;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DriverProfile;
use App\Domain\Fulfillment\Services\DriverPosition;
use App\Domain\Fulfillment\Services\Eta\EtaService;
use App\Domain\Fulfillment\Services\Eta\StraightLineEta;
use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Enums\OrderStatus as OS;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Settings\Services\Settings;
use App\Support\BusinessTime;
use App\Support\Geo;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The customer's live order card: where the order is on a four-stage bar, the arrival window, who is bringing it and the
 * texts in the customer's language. One builder for every surface — the FCM push, GET /orders/active-progress and the
 * "progress" block of the order detail — so they always agree.
 *
 * Stages. Delivery: placed → preparing → on the way → delivered. Pickup: placed → preparing → ready → collected.
 * An order with several branch parts shows its LEAST advanced part and the LATEST arrival window.
 *
 * Arrival window, first that applies: the booked slot (while it is still ahead); a live estimate from the driver's
 * position (road-based when a routing server answers, else straight line — the estimators of the tracking feature);
 * otherwise the settings orders.default_prep_minutes + orders.default_delivery_minutes (a pickup uses its branch's
 * pickup preparation time). Estimates are ± a few minutes, rounded to 5, and cached for 30 s per order.
 *
 * The result is a snapshot: `seq` is its time in milliseconds, so a client keeps the larger `seq` when updates arrive
 * out of order.
 */
class OrderProgress
{
    public const TOTAL = 4;

    private const ACTIVE_DELIVERY = [DS::Assigned, DS::Accepted, DS::PickedUp, DS::OutForDelivery];

    private const DONE_PHASES = ['delivered', 'collected', 'cancelled', 'failed'];

    public function __construct(
        private readonly EtaService $eta,
        private readonly StraightLineEta $straight,
        private readonly ThemeService $theme,
        private readonly Settings $settings,
    ) {}

    /**
     * The payload in one locale, or null when the order has no progress to show (no live parts, or returned).
     *
     * @param  bool  $remote  false = never wait on the routing server (page loads); the estimate then uses straight lines only
     * @return array<string, mixed>|null
     */
    public function forOrder(Order $order, ?string $locale = null, bool $remote = true): ?array
    {
        $locale = $this->locale($locale);

        return $this->build($order, [$locale], $remote)['payloads'][$locale] ?? null;
    }

    /**
     * Payloads for several locales from one calculation. `expired` means the order finished longer ago than
     * platform.live_order.keep_after_done_minutes: it is no longer updated or listed.
     *
     * @param  list<string>  $locales
     * @return array{payloads: array<string, array<string, mixed>>, done: bool, finished_at: ?CarbonImmutable, expired: bool}|null
     */
    public function build(Order $order, array $locales, bool $remote = true): ?array
    {
        $state = $this->state($order, $remote);
        if ($state === null) {
            return null;
        }
        $payloads = [];
        foreach (array_unique(array_map(fn (string $l) => $this->locale($l), $locales)) as $locale) {
            $previous = app()->getLocale();
            app()->setLocale($locale); // __() and the branch's localized name follow it
            try {
                $payloads[$locale] = $this->present($order, $state, $locale);
            } finally {
                app()->setLocale($previous);
            }
        }
        $keep = (int) config('platform.live_order.keep_after_done_minutes', 30);

        return ['payloads' => $payloads, 'done' => $state['done'], 'finished_at' => $state['finished_at'],
            'expired' => $state['done'] && $state['finished_at'] !== null && $state['finished_at']->lt(CarbonImmutable::now()->subMinutes($keep))];
    }

    /**
     * The customer's orders that are still in progress, or finished within the last 30 minutes, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function activeFor(string $userId, ?string $locale = null): array
    {
        $locale = $this->locale($locale);
        $keep = (int) config('platform.live_order.keep_after_done_minutes', 30);
        $max = (int) config('platform.live_order.max_active', 10);
        $terminal = array_map(fn (OS $s) => $s->value, array_filter(OS::cases(), fn (OS $s) => $s->isTerminal()));

        $orders = Order::where('user_id', $userId)
            ->where('placed_at', '>=', now()->subDays((int) config('platform.live_order.max_age_days', 7)))
            ->where(fn ($q) => $q->whereNotIn('status', $terminal)->orWhere('updated_at', '>=', now()->subMinutes($keep)))
            ->with(['fulfillments.branch', 'fulfillments.slot', 'fulfillments.deliveries.driver'])
            ->orderByDesc('placed_at')->orderByDesc('id')->limit($max * 2)->get();

        $out = [];
        foreach ($orders as $order) {
            $built = $this->build($order, [$locale], remote: false);
            if ($built !== null && ! $built['expired']) {
                $out[] = $built['payloads'][$locale];
            }
            if (count($out) === $max) {
                break;
            }
        }

        return $out;
    }

    /** "ar" or "en" from whatever a device or request reports ("ar_QA", "AR", null → the app default). */
    public function locale(?string $locale): string
    {
        $short = strtolower(substr((string) $locale, 0, 2));

        return in_array($short, (array) config('platform.supported_locales'), true) ? $short : (string) config('app.locale', 'en');
    }

    // ── What is true about the order (no wording) ────────────────────────────────────────────────────────────────────

    /**
     * @return array{type: string, step: int, phase: string, detail: string, done: bool, finished_at: ?CarbonImmutable,
     *     fulfillment: ?OrderFulfillment, delivery: ?Delivery, window: ?array{from: CarbonImmutable, to: CarbonImmutable}, sub: ?float}|null
     */
    private function state(Order $order, bool $remote): ?array
    {
        $order->loadMissing(['fulfillments.branch', 'fulfillments.slot', 'fulfillments.deliveries.driver']);
        $type = $order->fulfillment_type === 'pickup' ? 'pickup' : 'delivery';

        if ($order->status === OS::Cancelled) {
            return ['type' => $type, 'step' => 0, 'phase' => 'cancelled', 'detail' => 'cancelled', 'done' => true,
                'finished_at' => $order->cancelled_at ?? CarbonImmutable::instance($order->updated_at),
                'fulfillment' => null, 'delivery' => null, 'window' => null, 'sub' => null];
        }
        $parts = $order->fulfillments->reject(fn (OrderFulfillment $f) => $f->status === FS::Cancelled)->map(fn (OrderFulfillment $f) => $this->stage($order, $f));
        if ($order->status === OS::Returned || $parts->isEmpty()) {
            return null;
        }

        // The least advanced part decides the stage; a failed delivery wins a tie because it needs the customer's attention.
        $chosen = $parts->sortBy(fn (array $p) => $p['rank'] * 2 + ($p['phase'] === 'failed' ? 0 : 1))->first();
        $f = $chosen['fulfillment'];
        $done = in_array($chosen['phase'], self::DONE_PHASES, true);
        $window = null;
        $sub = null;
        if (! $done) {
            // Latest arrival over every part still to come; the progress share comes from the part that decides the stage.
            foreach ($parts->reject(fn (array $p) => in_array($p['phase'], [...self::DONE_PHASES, 'ready'], true) || $p['detail'] === 'pending_payment') as $p) {
                $w = $this->window($order, $p, $remote);
                if ($w !== null && ($window === null || $w['to']->gt($window['to']))) {
                    $window = ['from' => $w['from'], 'to' => $w['to']];
                }
                if ($p['fulfillment']->is($f)) {
                    $sub = $w['sub'] ?? null;
                }
            }
        }

        return ['type' => $type, 'step' => $chosen['rank'], 'phase' => $chosen['phase'], 'detail' => $chosen['detail'], 'done' => $done,
            'finished_at' => $done ? $this->finishedAt($order, $chosen) : null,
            'fulfillment' => $f, 'delivery' => $chosen['delivery'], 'window' => $window, 'sub' => $sub];
    }

    /** @return array{rank: int, phase: string, detail: string, fulfillment: OrderFulfillment, delivery: ?Delivery} */
    private function stage(Order $order, OrderFulfillment $f): array
    {
        $d = $f->activeDelivery();
        $ds = $d?->status;
        // The delivery moves a moment before its fulfilment follows, so the stage is read from both.
        $road = in_array($f->status, [FS::Pending, FS::Accepted, FS::Preparing, FS::Ready, FS::Dispatched, FS::OutForDelivery], true) && in_array($ds, [DS::PickedUp, DS::OutForDelivery], true);
        [$rank, $phase, $detail] = match (true) {
            $road || in_array($f->status, [FS::Dispatched, FS::OutForDelivery], true) => [2, 'on_the_way', ($ds === DS::OutForDelivery || $f->status === FS::OutForDelivery) ? 'out_for_delivery' : 'picked_up'],
            $f->status === FS::Pending => [0, 'placed', $order->status === OS::PendingPayment ? 'pending_payment' : 'placed'],
            in_array($f->status, [FS::Accepted, FS::Preparing, FS::Ready], true) => [1, 'preparing', match ($ds) {
                DS::Assigned => 'driver_assigned', DS::Accepted => 'driver_accepted', default => 'preparing',
            }],
            $f->status === FS::PickupReady => [2, 'ready', 'ready'],
            $f->status === FS::Failed => [2, 'failed', 'failed'],
            $f->status === FS::PickedUp => [3, 'collected', 'collected'],
            default => [3, $f->type === 'pickup' ? 'collected' : 'delivered', $f->type === 'pickup' ? 'collected' : 'delivered'], // delivered, returned
        };

        return ['rank' => $rank, 'phase' => $phase, 'detail' => $detail, 'fulfillment' => $f, 'delivery' => $d];
    }

    private function finishedAt(Order $order, array $chosen): CarbonImmutable
    {
        $f = $chosen['fulfillment'];
        $at = match ($chosen['phase']) {
            'failed' => $chosen['delivery']?->failed_at ?? $f->updated_at,
            default => $order->completed_at ?? $order->fulfillments->map(fn (OrderFulfillment $p) => $p->delivered_at ?? $p->picked_up_at)->filter()->max() ?? $order->updated_at,
        };

        return CarbonImmutable::instance($at ?? now());
    }

    // ── Arrival window ───────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array{rank: int, phase: string, detail: string, fulfillment: OrderFulfillment, delivery: ?Delivery}  $p
     * @return array{from: CarbonImmutable, to: CarbonImmutable, sub?: float}|null
     */
    private function window(Order $order, array $p, bool $remote): ?array
    {
        $f = $p['fulfillment'];
        $slot = $this->slotWindow($f);
        if ($slot !== null) {
            return $slot;
        }
        $key = 'order_progress:eta:'.$f->id.':'.$p['detail'];
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $this->fromCache($cached);
        }
        $w = $this->estimate($order, $p, $remote);
        if ($remote && $w !== null) {
            Cache::put($key, ['from' => $w['from']->getTimestamp(), 'to' => $w['to']->getTimestamp(), 'sub' => $w['sub'] ?? null], 30);
        }

        return $w;
    }

    /** The booked slot, while its end is still ahead (a slot that has passed no longer promises anything). */
    private function slotWindow(OrderFulfillment $f): ?array
    {
        if ($f->slot === null || $f->slot_date === null) {
            return null;
        }
        $day = $f->slot_date->format('Y-m-d');
        $to = CarbonImmutable::parse($day.' '.$f->slot->ends_at, BusinessTime::zone());
        if ($to->lte(CarbonImmutable::now())) {
            return null;
        }

        return ['from' => CarbonImmutable::parse($day.' '.$f->slot->starts_at, BusinessTime::zone()), 'to' => $to];
    }

    /**
     * @param  array{rank: int, phase: string, detail: string, fulfillment: OrderFulfillment, delivery: ?Delivery}  $p
     * @return array{from: CarbonImmutable, to: CarbonImmutable, sub: ?float}
     */
    private function estimate(Order $order, array $p, bool $remote): array
    {
        $f = $p['fulfillment'];
        $d = $p['delivery'];
        $now = CarbonImmutable::now();
        $half = (int) config('platform.live_order.window_half_minutes', 7);
        $deliver = max(1, (int) $this->settings->get('orders.default_delivery_minutes'));
        $prep = $f->type === 'pickup' && $f->branch?->pickup_preparation_minutes !== null
            ? (int) $f->branch->pickup_preparation_minutes
            : max(1, (int) $this->settings->get('orders.default_prep_minutes'));
        $start = CarbonImmutable::instance($f->accepted_at ?? $order->confirmed_at ?? $order->placed_at ?? $order->created_at);
        $ready = $f->ready_at !== null ? CarbonImmutable::instance($f->ready_at) : $start->addMinutes($prep);

        if ($p['phase'] === 'on_the_way') {
            $left = $this->minutesToCustomer($order, $d, $remote) ?? max(3, $deliver - (int) $this->since($d?->out_for_delivery_at ?? $d?->picked_up_at ?? $f->dispatched_at, $now));
            $point = $now->addMinutes($left);
            $half = min($half, max(3, (int) ceil($left / 3)));
            $from = CarbonImmutable::instance($d?->out_for_delivery_at ?? $d?->picked_up_at ?? $f->dispatched_at ?? $now);
        } else {
            $point = $f->type === 'pickup' ? max($now, $ready) : max($now, $ready)->addMinutes($deliver);
            $from = $start;
        }
        $window = $this->round($point, $half, $now);

        return $window + ['sub' => $p['rank'] === 0 ? null : $this->share($from, $p['phase'] === 'on_the_way' ? $point : $ready, $now)];
    }

    /** Minutes the driver still needs to reach the destination, from a fresh GPS position; null when there is none to go by. */
    private function minutesToCustomer(Order $order, ?Delivery $d, bool $remote): ?int
    {
        $dest = (array) ($order->address_snapshot ?? []);
        $profile = $d?->driver_id ? DriverProfile::find($d->driver_id) : null;
        $pos = DriverPosition::of($profile);
        if ($pos === null || $pos['stale'] || ! Geo::validPoint($dest['latitude'] ?? null, $dest['longitude'] ?? null)) {
            return null;
        }
        $to = [(float) $dest['latitude'], (float) $dest['longitude']];
        $e = $remote ? $this->eta->between($d->id, $pos['lat'], $pos['lng'], ...$to) : $this->straight->estimate($pos['lat'], $pos['lng'], ...$to);

        return $e['minutes'];
    }

    private function since(mixed $at, CarbonImmutable $now): float
    {
        return $at === null ? 0.0 : max(0, ($now->getTimestamp() - $at->getTimestamp()) / 60);
    }

    /** Share (0..1, two decimals) of the time between $from and $to that has passed. */
    private function share(CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $now): ?float
    {
        $span = $to->getTimestamp() - $from->getTimestamp();

        return $span > 0 ? round(max(0.0, min(1.0, ($now->getTimestamp() - $from->getTimestamp()) / $span)), 2) : null;
    }

    /**
     * ± $half minutes around the point, widened to whole 5-minute marks, never starting before the current 5-minute mark
     * and at least 5 minutes wide.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable}
     */
    private function round(CarbonImmutable $point, int $half, CarbonImmutable $now): array
    {
        $mark = fn (CarbonImmutable $t, bool $up) => CarbonImmutable::createFromTimestamp(((int) ($up ? ceil($t->getTimestamp() / 300) : floor($t->getTimestamp() / 300))) * 300);
        $from = max($mark($point->subMinutes($half), false), $mark($now, false));
        $to = $mark($point->addMinutes($half), true);

        return ['from' => $from, 'to' => $to->lte($from) ? $from->addMinutes(5) : $to];
    }

    /** @param array{from: int, to: int, sub: ?float} $c */
    private function fromCache(array $c): array
    {
        return ['from' => CarbonImmutable::createFromTimestamp($c['from']), 'to' => CarbonImmutable::createFromTimestamp($c['to']), 'sub' => $c['sub']];
    }

    // ── Wording ──────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function present(Order $order, array $s, string $locale): array
    {
        $f = $s['fulfillment'];
        $d = $s['delivery'];
        $pickup = $s['type'] === 'pickup';
        $driver = $d?->driver;
        $profile = $driver !== null ? DriverProfile::find($driver->id) : null;
        $active = $d !== null && in_array($d->status, self::ACTIVE_DELIVERY, true);
        $name = $driver !== null ? DriverPosition::firstName($driver->name) : '';
        $now = CarbonImmutable::now();
        $window = $s['window'];

        $payload = [
            'kind' => 'order_progress', 'order_id' => $order->id, 'number' => $order->number, 'type' => $s['type'],
            'step' => $s['step'], 'total' => self::TOTAL, 'sub' => $s['sub'],
            'phase' => $s['phase'], 'detail' => $s['detail'],
            'step_label' => in_array($s['phase'], ['cancelled', 'failed'], true) ? __('push.progress.title.'.$s['phase'])
                : __('push.'.($pickup && $s['step'] >= 2 ? 'progress.pickup_step_' : 'step_').$s['step']),
            'title' => $this->title($s, $window, $pickup, $locale),
            'body' => $this->body($order, $s, $f, $name),
            'eta_label' => $window !== null ? $this->windowLabel($window, $locale) : null,
            'eta_from' => $window !== null ? $window['from']->utc()->toIso8601ZuluString() : null,
            'eta_to' => $window !== null ? $window['to']->utc()->toIso8601ZuluString() : null,
            'driver_name' => $name !== '' && $d !== null && $d->status !== DS::Cancelled ? $name : null,
            'driver_phone' => $active ? Phone::normalize($driver?->phone) : null,
            'vehicle' => $name !== '' && $active ? $profile?->vehicle : null,
            'seq' => (int) round($now->getPreciseTimestamp(3)),
            'updated_at' => $now->utc()->toIso8601ZuluString(),
            'deep_link' => '/orders/'.$order->id,
            'done' => $s['done'] ? '1' : '0',
            'brand' => $this->brand(),
        ];
        if ($payload['sub'] === null) {
            unset($payload['sub']); // omitted, not zero, when there is no estimate to measure against
        }

        return $payload;
    }

    private function title(array $s, ?array $window, bool $pickup, string $locale): string
    {
        if ($window === null) {
            return __('push.progress.title.'.$s['phase']);
        }
        $day = $this->day($window['from'], $locale);
        $times = ['from' => $this->time($window['from'], $locale), 'to' => $this->time($window['to'], $locale)];
        $key = $pickup ? ($day === null ? 'pickup_at' : 'pickup_on') : ($day === null ? 'arrives' : 'arrives_on');

        return __('push.progress.'.$key, $times + ['day' => $day]);
    }

    private function windowLabel(array $window, string $locale): string
    {
        $day = $this->day($window['from'], $locale);
        $label = __('push.progress.window', ['from' => $this->time($window['from'], $locale), 'to' => $this->time($window['to'], $locale)]);

        return $day === null ? $label : $day.($locale === 'ar' ? '، ' : ', ').$label;
    }

    private function body(Order $order, array $s, ?OrderFulfillment $f, string $driverName): string
    {
        if ($order->status === OS::CancellationRequested && ! $s['done']) {
            return __('push.progress.body.cancellation_requested');
        }
        $driver = $driverName !== '' ? __('push.progress.driver_named', ['name' => $driverName]) : __('push.progress.driver_unnamed');
        $detail = $s['detail'];
        if ($detail === 'out_for_delivery' && $driverName === '') {
            $detail = 'out_for_delivery_anonymous';
        }

        return __('push.progress.body.'.$detail, ['branch' => $f?->branch?->localized('name') ?? '', 'driver' => $driver]);
    }

    /** "tomorrow" or a short date for a window that does not start today; null for today. */
    private function day(CarbonImmutable $at, string $locale): ?string
    {
        $local = $at->setTimezone(BusinessTime::zone());
        $today = BusinessTime::now();
        if ($local->isSameDay($today)) {
            return null;
        }

        return $local->isSameDay($today->addDay()) ? __('push.progress.tomorrow') : $local->locale($locale)->isoFormat('ddd D MMM');
    }

    /** 12-hour Qatar time with Latin digits (as the store shows them): "9:20pm" / "9:20 م". */
    private function time(CarbonImmutable $at, string $locale): string
    {
        $local = $at->setTimezone(BusinessTime::zone())->locale($locale);

        return $locale === 'ar' ? $local->isoFormat('h:mm A') : $local->format('g:ia');
    }

    /** @return array{primary: string, accent: string} the live theme's colours, so the card matches seasonal looks */
    private function brand(): array
    {
        $colors = (array) ($this->theme->payload()['colors'] ?? []);
        $hex = fn (mixed $v, string $fallback) => is_string($v) && preg_match('/^#[0-9A-Fa-f]{6}$/', $v) === 1 ? strtoupper($v) : $fallback;

        return ['primary' => $hex($colors['primary'] ?? null, '#8A1538'), 'accent' => $hex($colors['accent'] ?? null, '#B3254F')];
    }
}
