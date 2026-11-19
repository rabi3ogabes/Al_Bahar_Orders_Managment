<?php

namespace App\Domain\Operations\Services;

use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Operations\Support\OpsAge;
use App\Domain\Orders\Enums\FulfillmentStatus;
use App\Domain\Orders\Enums\OrderStatus;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Settings\Services\Settings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Collection;

/**
 * The order clock: for something that waits for a person (a new order, a part being prepared, a delivery nobody took, a pickup nobody
 * collected, cash a driver still holds) it says how long it has waited and whether that is fine (ok), getting late (amber) or late (red).
 *
 * The limits are settings (Settings > Operations clock), read once per request. Steps done by the branch team (accept, prepare, hand to a driver,
 * driver accepts) count BUSINESS minutes only, so a shop closed overnight does not start the morning red; pickups (days), cash and refunds (hours)
 * count real time because nobody at the branch is racing them. Everything here is a pure read: no writes, and no queries except the
 * settings and the branch hours (loaded once, see {@see BranchSchedules}).
 */
#[Scoped]
final class OpsClock
{
    public const PENDING = 'pending';

    public const PREPARING = 'preparing';

    public const READY = 'ready';

    public const DRIVER = 'driver';

    public const PICKUP = 'pickup';

    public const CASH = 'cash';

    /** @var list<string> */
    public const KINDS = [self::PENDING, self::PREPARING, self::READY, self::DRIVER, self::PICKUP, self::CASH];

    /** Kinds that count opening hours only. */
    public const BUSINESS_KINDS = [self::PENDING, self::PREPARING, self::READY, self::DRIVER];

    /** The unit the limits of a kind are typed in on the settings page. */
    public const UNITS = [self::PENDING => 'minutes', self::PREPARING => 'minutes', self::READY => 'minutes', self::DRIVER => 'minutes', self::PICKUP => 'days', self::CASH => 'hours'];

    /** Factory defaults [amber, red], in the unit of the kind. */
    public const DEFAULTS = [
        self::PENDING => [5, 15],
        self::PREPARING => [45, 90],
        self::READY => [30, 60],
        self::DRIVER => [5, 10],
        self::PICKUP => [2, 4],
        self::CASH => [24, 48],
    ];

    /** Longest wait a limit may be typed as, per unit (keeps the settings page honest). */
    public const MAX = ['minutes' => 1440, 'hours' => 720, 'days' => 90];

    /** @var array<string, array{amber: int, red: int}>|null limits in minutes, by kind, read once per request */
    private ?array $limits = null;

    public function __construct(private readonly Settings $settings, private readonly BranchSchedules $schedules) {}

    /** The settings key of a limit, e.g. ops_clock.pending_amber_minutes. */
    public static function settingKey(string $kind, string $level): string
    {
        return 'ops_clock.'.$kind.'_'.$level.'_'.self::UNITS[$kind];
    }

    /** The factory default of a limit, in the unit of its kind. */
    public static function defaultFor(string $kind, string $level): int
    {
        return self::DEFAULTS[$kind][$level === 'amber' ? 0 : 1];
    }

    /**
     * The limits of a kind in minutes. A saved value that is not a positive whole number falls back to the default, and a red limit below
     * the amber one is lifted to it, so a hand-edited setting can never turn the order around (red before amber).
     *
     * @return array{amber: int, red: int}
     */
    public function limits(string $kind): array
    {
        if ($this->limits === null) {
            $this->limits = [];
            foreach (self::KINDS as $k) {
                $factor = match (self::UNITS[$k]) {
                    'days' => 1440, 'hours' => 60, default => 1,
                };
                $amber = $this->whole($this->settings->get(self::settingKey($k, 'amber')), self::defaultFor($k, 'amber'));
                $red = $this->whole($this->settings->get(self::settingKey($k, 'red')), self::defaultFor($k, 'red'));
                $this->limits[$k] = ['amber' => $amber * $factor, 'red' => max($red, $amber) * $factor];
            }
        }

        return $this->limits[$kind] ?? ['amber' => PHP_INT_MAX, 'red' => PHP_INT_MAX];
    }

    /** Measures one wait. $branchId matters only for the kinds that count opening hours. */
    public function evaluate(string $kind, CarbonInterface $since, ?string $branchId = null, ?CarbonInterface $now = null): OpsAge
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());
        $since = CarbonImmutable::instance($since);
        if ($since > $now) {
            $since = $now;
        }
        if (in_array($kind, self::BUSINESS_KINDS, true)) {
            $m = $this->schedules->minutes($branchId, $since, $now);
        } else {
            $wall = intdiv($now->getTimestamp() - $since->getTimestamp(), 60);
            $m = ['counted' => $wall, 'wall' => $wall, 'paused' => false];
        }
        $limits = $this->limits($kind);
        $state = match (true) {
            $m['counted'] >= $limits['red'] => OpsAge::RED,
            $m['counted'] >= $limits['amber'] => OpsAge::AMBER,
            default => OpsAge::OK,
        };

        return new OpsAge($kind, $state, $m['counted'], $m['wall'], self::label($m['counted']), $since, $m['paused']);
    }

    /**
     * The wait of a branch part of an order, by what it is waiting for; null when nobody has to act (finished, cancelled, a driver has it,
     * or the order is still unpaid). Load `order` and `deliveries` on the fulfillment first (a part without them is measured as if there were
     * no unpaid order and no delivery), and `branchId` hours are read from {@see BranchSchedules}.
     */
    public function forFulfillment(OrderFulfillment $f, ?CarbonInterface $now = null): ?OpsAge
    {
        $order = $f->relationLoaded('order') ? $f->order : null;
        $delivery = $f->relationLoaded('deliveries') ? self::currentDelivery($f->deliveries) : null;
        $createdAt = $f->created_at ?? CarbonImmutable::now();
        $updatedAt = $f->updated_at ?? $createdAt;

        return match ($f->status) {
            // An unpaid online order waits for the customer, and one the customer asked to cancel waits for a manager's decision (its own line in the inbox).
            FulfillmentStatus::Pending => in_array($order?->status, [OrderStatus::PendingPayment, OrderStatus::CancellationRequested], true) ? null
                : $this->evaluate(self::PENDING, $this->latest($createdAt, $order?->confirmed_at), $f->branch_id, $now),
            FulfillmentStatus::Accepted, FulfillmentStatus::Preparing => $this->evaluate(self::PREPARING, $f->accepted_at ?? $updatedAt, $f->branch_id, $now),
            FulfillmentStatus::Ready => $this->waitingToLeave($f, $delivery, $updatedAt, $now),
            FulfillmentStatus::PickupReady => $this->evaluate(self::PICKUP, $f->ready_at ?? $updatedAt, $f->branch_id, $now),
            FulfillmentStatus::Failed => $delivery !== null && $delivery->status->value === 'failed'
                ? $this->evaluate(self::READY, $delivery->failed_at ?? $updatedAt, $f->branch_id, $now) : null,
            default => null,
        };
    }

    /** A ready delivery part: nobody holds it yet (needs a driver), or a driver was named and has not answered. A driver who accepted is moving: no clock. */
    private function waitingToLeave(OrderFulfillment $f, ?Delivery $delivery, CarbonInterface $updatedAt, ?CarbonInterface $now): ?OpsAge
    {
        if ($f->type !== 'delivery') {
            return null;
        }
        if ($delivery !== null && $delivery->status->value === 'assigned') {
            return $this->evaluate(self::DRIVER, $delivery->assigned_at ?? $delivery->created_at ?? $updatedAt, $f->branch_id, $now);
        }
        if ($delivery !== null && in_array($delivery->status->value, ['accepted', 'picked_up', 'out_for_delivery', 'delivered'], true)) {
            return null;
        }

        return $this->evaluate(self::READY, $f->ready_at ?? $updatedAt, $f->branch_id, $now);
    }

    /** The delivery that counts for a part: the newest one that is not cancelled or returned (the same choice as OrderFulfillment::activeDelivery). @param  Collection<int, Delivery>  $deliveries */
    public static function currentDelivery(Collection $deliveries): ?Delivery
    {
        return $deliveries->sortByDesc('created_at')->first(fn (Delivery $d) => ! in_array($d->status->value, ['cancelled', 'returned'], true));
    }

    /** True when the branch is open now (a branch without hours counts as open): reminders stay quiet while it is closed. */
    public function branchOpen(?string $branchId, ?CarbonInterface $now = null): bool
    {
        return $this->schedules->isOpen($branchId, $now ?? CarbonImmutable::now());
    }

    /** Loads the opening hours of these branches in one go; call it before measuring a page of rows. @param  list<string|null>  $branchIds */
    public function preload(array $branchIds): void
    {
        $this->schedules->preload($branchIds);
    }

    /** "Just now", "12 min", "2 h 05 min", "3 d 4 h": the counted age as short plain text in the dashboard language. */
    public static function label(int $minutes): string
    {
        $minutes = max(0, $minutes);

        return match (true) {
            $minutes < 1 => __('ops_live.age.just_now'),
            $minutes < 60 => __('ops_live.age.minutes', ['n' => $minutes]),
            $minutes < 1440 => $minutes % 60 === 0
                ? __('ops_live.age.hours', ['h' => intdiv($minutes, 60)])
                : __('ops_live.age.hours_minutes', ['h' => intdiv($minutes, 60), 'm' => str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT)]),
            default => intdiv($minutes % 1440, 60) === 0
                ? __('ops_live.age.days', ['d' => intdiv($minutes, 1440)])
                : __('ops_live.age.days_hours', ['d' => intdiv($minutes, 1440), 'h' => intdiv($minutes % 1440, 60)]),
        };
    }

    private function latest(CarbonInterface $a, ?CarbonInterface $b): CarbonInterface
    {
        return $b !== null && $b > $a ? $b : $a;
    }

    private function whole(mixed $value, int $default): int
    {
        return is_numeric($value) && (int) $value >= 1 ? (int) $value : $default;
    }
}
