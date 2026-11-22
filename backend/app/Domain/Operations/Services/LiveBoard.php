<?php

namespace App\Domain\Operations\Services;

use App\Domain\Branches\Models\Branch;
use App\Domain\Fulfillment\Enums\DeliveryStatus;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Services\SlotOffers;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Identity\Support\PermissionRegistry;
use App\Domain\Operations\Support\Board;
use App\Domain\Operations\Support\BoardCard;
use App\Domain\Operations\Support\OpsAge;
use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Enums\OrderStatus;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderCancellation;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\SubstitutionRequest;
use App\Domain\Orders\Services\ParcelShares;
use App\Domain\Orders\Services\SubstitutionClock;
use App\Domain\Payments\Services\CodSplit;
use App\Support\BusinessTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;

/**
 * The live operations board: every OPEN branch part of an order, in the lane that says where it stands, so "what is happening right now and what
 * is stuck?" is answered at a glance. One part is one card (an order from two branches is two cards).
 *
 * Which lane a part is in (exactly one; the full table is in docs/guides/SEARCH_AND_LIVE_BOARD.md):
 *   new         the branch has not accepted it (part `pending`; an unpaid online order is not the branch's work yet and is not on the board)
 *   preparing   accepted or being prepared (part `accepted`, `preparing`)
 *   ready       ready and waiting: a delivery for a driver (none named, named but not accepted, or accepted and coming to collect), or for the customer
 *   out         on the road (part `dispatched`, `out_for_delivery`)
 *   pickup      ready for the customer to collect (part `pickup_ready`)
 *   attention   broken or late, whichever step it is in: the customer asked to cancel (a manager must decide), the delivery failed, its clock is RED
 *               (the same limits as Settings > Operations clock), or the customer's answer to a replacement is half used up or over
 * A part that is waiting for the customer's answer to a replacement carries a "waiting for the customer" badge in whatever lane it is in.
 *
 * Reads: ONE statement for the open parts of the branches the person may see: one short lookup per part state (index branch_id, status, created_at), each
 * cut at PER_STATE oldest first, so a flood of one state (a backlog of new orders, say) never hides the others and the longest-stuck are the ones kept.
 * Then a handful of eager loads by id, one grouped query for the units and one each for waiting replacements and cancellation requests.
 * The lanes and their counts are worked out in memory from those rows; only the cards that are shown get their slot text and cash amount. No writes.
 * Branch scope is the person's `orders.view` scope, narrowed (never widened) by the branch filter; every quick action on a card re-checks its own permission.
 */
final class LiveBoard
{
    public const LANES = ['new', 'preparing', 'ready', 'out', 'pickup', 'attention'];

    /** Part states that are still work for somebody (everything that is not finished, cancelled or returned). */
    public const OPEN = ['pending', 'accepted', 'preparing', 'ready', 'dispatched', 'out_for_delivery', 'pickup_ready', 'failed'];

    /** Cards a lane shows, and the most it shows when opened ("N more"). Beyond that the Orders page is the place. */
    public const SHOWN = 10;

    public const EXPANDED = 60;

    /** Parts of one state read at once (so at most eight times this many in all). */
    public const PER_STATE = 150;

    public function __construct(
        private readonly OpsClock $clock,
        private readonly AccessControl $acl,
        private readonly SubstitutionClock $substitutions,
    ) {}

    /**
     * The branches the person may see orders of, in menu order, by id. A wall display account has no right to orders at all: the branches its board right was
     * given for are the ones its screen shows (and the cards carry no customer data, so nothing more is opened up).
     *
     * @return Collection<string, Branch>
     */
    public function branches(User $user): Collection
    {
        $scope = $this->acl->scope($user, $this->acl->isWallDisplay($user) ? PermissionRegistry::WALL_DISPLAY_KEY : 'orders.view');
        if ($scope->isEmpty()) {
            return new Collection;
        }

        return Branch::query()->when(! $scope->all, fn ($q) => $q->whereIn('id', $scope->branchIds))->orderBy('sort_order')->orderBy('code')->get(['id', 'code', 'name_en', 'name_ar'])->keyBy('id');
    }

    /**
     * @param  string|null  $branchId  one branch only; it can only narrow what the person may see
     * @param  list<string>  $expanded  lanes opened to show more cards
     */
    public function for(User $user, ?string $branchId = null, array $expanded = [], ?CarbonInterface $now = null): Board
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());
        $branches = $this->branches($user);
        $chips = $branches->map(fn (Branch $b) => ['id' => $b->id, 'code' => $b->code, 'name' => (string) $b->localized('name')])->values()->all();
        $ids = $branchId !== null && $branchId !== '' && $branches->has($branchId) ? [$branchId] : $branches->keys()->all();
        if ($ids === []) {
            return new Board($this->lanes(collect(), $expanded), $chips, false, 0);
        }

        $leg = fn (string $state) => OrderFulfillment::query()->whereIn('order_fulfillments.branch_id', $ids)->where('order_fulfillments.status', $state)
            ->orderBy('order_fulfillments.created_at')->orderBy('order_fulfillments.id')->limit(self::PER_STATE + 1);
        $union = $leg(self::OPEN[0]);
        foreach (array_slice(self::OPEN, 1) as $state) {
            $union->unionAll($leg($state));
        }
        /** @var Collection<int, OrderFulfillment> $rows */
        $rows = $union->with([
            'order:id,number,status,payment_method,payment_status,grand_total,paid_total,currency,confirmed_at',
            'deliveries:id,order_fulfillment_id,status,created_at,assigned_at,failed_at,picked_up_at,failure_reason,driver_id,cod_amount',
            'deliveries.driver:id,name',
            'slot:id,starts_at,ends_at',
            'pickup:id,order_fulfillment_id,token_expires_at,status',
        ])->get();
        $truncated = false;
        $rows = $rows->groupBy(fn (OrderFulfillment $f) => $f->status->value)->flatMap(function (Collection $group) use (&$truncated) {
            $truncated = $truncated || $group->count() > self::PER_STATE;

            return $group->sortBy([['created_at', 'asc'], ['id', 'asc']])->take(self::PER_STATE);
        })->values();

        $this->clock->preload($rows->pluck('branch_id')->all());
        $cancels = $this->cancellations($rows);
        $waiting = $this->waitingAnswers($rows, $now);
        $units = $rows->isEmpty() ? collect() : OrderItem::query()->whereIn('order_fulfillment_id', $rows->pluck('id'))->groupBy('order_fulfillment_id')
            ->selectRaw('order_fulfillment_id, sum(quantity) as units')->toBase()->pluck('units', 'order_fulfillment_id');
        $partCount = $rows->isEmpty() ? collect() : OrderFulfillment::query()->whereIn('order_id', $rows->pluck('order_id')->unique()->values())
            ->groupBy('order_id')->selectRaw('order_id, count(*) as n')->toBase()->pluck('n', 'order_id');

        $drafts = collect();
        foreach ($rows as $f) {
            $draft = $this->draft($f, $cancels, $waiting, $now);
            if ($draft !== null) {
                $drafts->push($draft);
            }
        }
        // What became of the new times offered to a customer whose delivery failed (still choosing, chose one, did not answer): one line on the card.
        $offers = app(SlotOffers::class)->staffNotes($drafts->where('reason', 'failed')->pluck('delivery')->filter()->values());
        $cards = $this->lanes($drafts, $expanded, ['user' => $user, 'branches' => $branches, 'partCount' => $partCount, 'units' => $units, 'now' => $now, 'offers' => $offers]);

        return new Board($cards, $chips, $truncated, $drafts->count());
    }

    // ── Which lane ───────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Places one part: its lane, why it needs attention (if it does), what it is waiting for and how long. Null for a part that is not the branch's
     * work (an unpaid online order).
     *
     * @param  Collection<string, CarbonImmutable>  $cancels  order id => when the cancellation request was made
     * @param  Collection<string, array{state: string, counted: int, wall: int, since: CarbonImmutable|null}>  $waiting  part id => its worst waiting replacement
     * @return array{f: OrderFulfillment, order: Order, delivery: ?Delivery, lane: string, from: string, reason: ?string, stage: string, age: ?OpsAge, waiting: bool}|null
     */
    private function draft(OrderFulfillment $f, Collection $cancels, Collection $waiting, CarbonInterface $now): ?array
    {
        $order = $f->order;
        if ($order === null || ($f->status === FS::Pending && $order->status === OrderStatus::PendingPayment)) {
            return null;
        }
        $delivery = OpsClock::currentDelivery($f->deliveries);
        $clockAge = $this->clock->forFulfillment($f, $now);
        [$from, $stage] = match ($f->status) {
            FS::Pending => ['new', 'new'],
            FS::Accepted => ['preparing', 'accepted'],
            FS::Preparing => ['preparing', 'preparing'],
            FS::Ready => ['ready', $this->readyStage($f, $delivery)],
            FS::Dispatched, FS::OutForDelivery => ['out', 'out'],
            FS::PickupReady => ['pickup', 'pickup'],
            default => ['out', 'failed'],
        };

        $reply = $waiting->get($f->id);
        $reason = match (true) {
            $order->status === OrderStatus::CancellationRequested => 'cancellation',
            $f->status === FS::Failed => 'failed',
            $clockAge?->state === OpsAge::RED => 'late',
            $reply !== null && $reply['state'] !== OpsAge::OK => 'customer',
            default => null,
        };
        $age = match ($reason) {
            'cancellation' => $this->clock->evaluate(OpsClock::PENDING, $cancels->get($order->id, $f->updated_at ?? $now), $f->branch_id, $now),
            'failed' => $clockAge ?? $this->clock->evaluate(OpsClock::READY, $delivery?->failed_at ?? $f->updated_at ?? $now, $f->branch_id, $now),
            'customer' => new OpsAge('substitution', $reply['state'] ?? OpsAge::OK, $reply['counted'] ?? 0, $reply['wall'] ?? 0, OpsClock::label($reply['counted'] ?? 0), $reply['since'] ?? CarbonImmutable::instance($now)),
            default => $clockAge ?? ($from === 'out' ? $this->onTheRoad($f, $delivery, $now) : null),
        };
        if ($reason !== null && $reason !== 'late') {
            $stage = $reason;
        }

        return ['f' => $f, 'order' => $order, 'delivery' => $delivery, 'lane' => $reason !== null ? 'attention' : $from, 'from' => $from, 'reason' => $reason, 'stage' => $stage, 'age' => $age, 'waiting' => $reply !== null];
    }

    /** What a ready delivery part waits for: a driver to be named, the named driver to accept, or the accepted driver to arrive; or the customer. */
    private function readyStage(OrderFulfillment $f, ?Delivery $delivery): string
    {
        return match (true) {
            $f->type !== 'delivery' => 'ready_pickup',
            $delivery === null => 'undispatched',
            $delivery->status === DeliveryStatus::Assigned => 'driver_named',
            in_array($delivery->status, [DeliveryStatus::Accepted, DeliveryStatus::PickedUp, DeliveryStatus::OutForDelivery], true) => 'driver_accepted',
            default => 'undispatched',
        };
    }

    /** How long a delivery has been on the road: plain elapsed time, no limit (nobody at the branch is racing it). */
    private function onTheRoad(OrderFulfillment $f, ?Delivery $delivery, CarbonInterface $now): OpsAge
    {
        $since = CarbonImmutable::instance($f->dispatched_at ?? $delivery?->picked_up_at ?? $f->updated_at ?? $now);
        $minutes = max(0, intdiv($now->getTimestamp() - $since->getTimestamp(), 60));

        return new OpsAge('out', OpsAge::OK, $minutes, $minutes, OpsClock::label($minutes), $since);
    }

    /**
     * When the customer asked to cancel, for the orders on the board that are waiting for a manager's decision: order id => the oldest open request.
     *
     * @param  Collection<int, OrderFulfillment>  $rows
     * @return Collection<string, CarbonImmutable>
     */
    private function cancellations(Collection $rows): Collection
    {
        $orders = $rows->filter(fn (OrderFulfillment $f) => $f->order?->status === OrderStatus::CancellationRequested)->pluck('order_id')->unique()->values();
        if ($orders->isEmpty()) {
            return collect();
        }

        return OrderCancellation::query()->where('status', 'requested')->whereIn('order_id', $orders)->groupBy('order_id')->selectRaw('order_id, min(created_at) as at')
            ->toBase()->get()->mapWithKeys(fn (object $r) => [(string) $r->order_id => CarbonImmutable::parse((string) $r->at)]);
    }

    /**
     * The customer's answer to a replacement is awaited on these parts: part id => the question that has used most of its time (the same
     * measure as the "Needs attention" inbox: amber from half, red at the end).
     *
     * @param  Collection<int, OrderFulfillment>  $rows
     * @return Collection<string, array{state: string, counted: int, wall: int, since: CarbonImmutable|null}>
     */
    private function waitingAnswers(Collection $rows, CarbonInterface $now): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }
        $found = SubstitutionRequest::query()->where('status', SubstitutionRequest::WAITING)->whereIn('order_fulfillment_id', $rows->pluck('id'))
            ->get(['id', 'order_fulfillment_id', 'branch_id', 'asked_at', 'deadline_at', 'response_minutes']);
        $out = [];
        foreach ($found as $r) {
            $wait = $this->substitutions->wait($r, $now);
            $mine = ['state' => $wait['state'], 'counted' => $wait['counted'], 'wall' => max(0, intdiv($now->getTimestamp() - $r->asked_at->getTimestamp(), 60)), 'since' => $r->asked_at];
            $kept = $out[$r->order_fulfillment_id] ?? null;
            if ($kept === null || OpsAge::rankOf($mine['state']) > OpsAge::rankOf($kept['state'])) {
                $out[$r->order_fulfillment_id] = $mine;
            }
        }

        return collect($out);
    }

    // ── Lanes and cards ──────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  Collection<int, array{f: OrderFulfillment, order: Order, delivery: ?Delivery, lane: string, from: string, reason: ?string, stage: string, age: ?OpsAge, waiting: bool}>  $drafts
     * @param  list<string>  $expanded
     * @param  array{user?: ?User, branches?: Collection<string, Branch>, partCount?: Collection<string, mixed>, units?: Collection<string, mixed>, now?: CarbonInterface, offers?: array<string, array{state: string, text: string}>}  $ctx  what a card needs besides its part
     * @return array<string, array{key: string, cards: Collection<int, BoardCard>, total: int, more: int, red: int, amber: int}>
     */
    private function lanes(Collection $drafts, array $expanded, array $ctx = []): array
    {
        $out = [];
        $by = $drafts->groupBy('lane');
        $shares = [];
        foreach (self::LANES as $key) {
            $all = ($by->get($key) ?? collect())->sort(self::worstFirst(...))->values();
            $limit = in_array($key, $expanded, true) ? self::EXPANDED : self::SHOWN;
            $cards = new Collection;
            foreach ($all->take($limit) as $d) {
                $cards->push($this->card($d, $ctx, $shares));
            }
            $out[$key] = [
                'key' => $key, 'cards' => $cards, 'total' => $all->count(), 'more' => max(0, $all->count() - $limit),
                'red' => $all->filter(fn (array $d) => $d['age']?->state === OpsAge::RED)->count(),
                'amber' => $all->filter(fn (array $d) => $d['age']?->state === OpsAge::AMBER)->count(),
            ];
        }

        return $out;
    }

    /** Worst first, then the longest wait, then by id so a card never swaps places between two refreshes. @param array{f: OrderFulfillment, age: ?OpsAge} $a @param array{f: OrderFulfillment, age: ?OpsAge} $b */
    private static function worstFirst(array $a, array $b): int
    {
        return [OpsAge::rankOf($b['age']->state ?? OpsAge::OK), $b['age']->ageMinutes ?? 0, $a['f']->id] <=> [OpsAge::rankOf($a['age']->state ?? OpsAge::OK), $a['age']->ageMinutes ?? 0, $b['f']->id];
    }

    /**
     * @param  array{f: OrderFulfillment, order: Order, delivery: ?Delivery, lane: string, from: string, reason: ?string, stage: string, age: ?OpsAge, waiting: bool}  $d
     * @param  array{user?: ?User, branches?: Collection<string, Branch>, partCount?: Collection<string, mixed>, units?: Collection<string, mixed>, now?: CarbonInterface, offers?: array<string, array{state: string, text: string}>}  $ctx
     * @param  array<string, array<string, Money>>  $shares  memo of an order's split cash
     */
    private function card(array $d, array $ctx, array &$shares): BoardCard
    {
        $f = $d['f'];
        $order = $d['order'];
        $delivery = $d['delivery'];
        $user = $ctx['user'] ?? null;
        $now = $ctx['now'] ?? CarbonImmutable::now();
        $can = fn (string $permission) => $user !== null && $this->acl->allows($user, $permission, $f->branch_id);
        $stage = $d['stage'];
        $total = (int) (($ctx['partCount'] ?? collect())->get($f->order_id) ?? 1);
        $suffix = preg_match('/-(\d+)$/', $f->number, $m) === 1 ? (int) $m[1] : 1;

        return new BoardCard(
            id: $f->id, orderId: $order->id, number: $order->number, part: $total > 1 ? __('ops_board.part_of', ['n' => $suffix, 'total' => $total]) : null,
            branchId: $f->branch_id, branchCode: ($ctx['branches'] ?? collect())->get($f->branch_id)?->code,
            lane: $d['lane'], from: $d['from'], reason: $d['reason'], stage: $stage, age: $d['age'],
            units: (int) (($ctx['units'] ?? collect())->get($f->id) ?? 0), type: $f->type, express: (bool) $f->is_express,
            when: $this->when($f, $delivery, $now), payment: $order->payment_method, due: $this->due($f, $order, $delivery, $total, $shares),
            driver: $delivery !== null && $f->type === 'delivery' ? $delivery->driver?->name : null,
            waitingCustomer: $d['waiting'], note: $stage === 'failed' ? $this->failure($delivery, $ctx['offers'] ?? []) : null,
            canAccept: $f->status === FS::Pending && $order->status !== OrderStatus::CancellationRequested && $can('orders.edit'),
            assign: $can('deliveries.view') ? $this->assignUrl($stage, $order->number) : null,
        );
    }

    /** The Deliveries page filtered to this order, on the tab where the next step is taken (as the "Needs attention" inbox links). */
    private function assignUrl(string $stage, string $number): ?string
    {
        $lane = match ($stage) {
            'undispatched' => 'to_dispatch',
            'driver_named' => 'scheduled',
            'failed' => 'exceptions',
            default => null,
        };

        return $lane === null ? null : route('admin.deliveries.index', ['lane' => $lane, 'search' => $number]);
    }

    /**
     * Why the delivery failed, and what became of the new times offered to the customer.
     *
     * @param  array<string, array{state: string, text: string}>  $offers  by delivery id
     */
    private function failure(?Delivery $delivery, array $offers = []): ?string
    {
        $reason = $delivery?->failure_reason;
        $why = $reason !== null && Lang::has('sales_admin.fail_'.$reason) ? __('sales_admin.fail_'.$reason) : null;

        return implode(' · ', array_filter([$why, $delivery !== null ? ($offers[$delivery->id]['text'] ?? null) : null])) ?: null;
    }

    /** "Today 14:00–16:00", "Sat 22 Nov", or null when the customer chose no time. */
    private function when(OrderFulfillment $f, ?Delivery $delivery, CarbonInterface $now): ?string
    {
        $date = $f->slot_date ?? ($f->type === 'delivery' ? $delivery?->scheduled_for : null);
        $time = $f->slot !== null ? substr((string) $f->slot->starts_at, 0, 5).'–'.substr((string) $f->slot->ends_at, 0, 5) : null;
        if ($date === null && $time === null) {
            return null;
        }
        $day = null;
        if ($date !== null) {
            $local = BusinessTime::local($date);
            $today = BusinessTime::local($now);
            $day = match (true) {
                $local === null || $today === null => null,
                $local->isSameDay($today) => __('ops_board.today'),
                $local->isSameDay($today->addDay()) => __('ops_board.tomorrow'),
                default => BusinessTime::display($date, 'D d M'),
            };
            if ($time === null && $f->slot_date === null && $local !== null) {
                $time = $local->format('H:i');   // a driver's planned time of day, when there is no slot
            }
        }

        return trim(implode(' ', array_filter([$day, $time])));
    }

    /**
     * The cash to collect for this part (cash on delivery or pay at pickup), formatted, or null when nothing is due. What the part's own delivery says
     * wins (that is what the driver is asked for); before there is a delivery it is this part's share of what the order still owes.
     *
     * @param  int  $parts  how many parts the order has (one part is owed everything, so no split is looked up)
     * @param  array<string, array<string, Money>>  $shares
     */
    private function due(OrderFulfillment $f, Order $order, ?Delivery $delivery, int $parts, array &$shares): ?string
    {
        if (! in_array($order->payment_method, ['cod', 'pay_at_pickup'], true)) {
            return null;
        }
        if ($order->payment_method === 'cod' && $delivery !== null && Money::of((string) $delivery->cod_amount)->isPositive()) {
            return Money::of((string) $delivery->cod_amount, $order->currency ?: 'QAR')->format();
        }
        $currency = $order->currency ?: 'QAR';
        $balance = Money::of((string) $order->grand_total, $currency)->minus(Money::of((string) $order->paid_total, $currency))->max(Money::zero($currency));
        if (! $balance->isPositive()) {
            return null;
        }
        $share = $parts > 1 ? ($shares[$order->id] ??= $this->split($order, $f, $balance))[$f->id] ?? null : $balance;

        return $share !== null && $share->isPositive() ? $share->format() : null;
    }

    /**
     * What each still-open part of an order that comes from several branches has to collect: the order's balance shared out the way the drivers' cash
     * is (ParcelShares, and CodSplit for the cash a driver still has to ask for).
     *
     * @return array<string, Money> part id => its share
     */
    private function split(Order $order, OrderFulfillment $f, Money $balance): array
    {
        $parts = OrderFulfillment::query()->where('order_id', $order->id)->where('type', $f->type)->whereIn('status', self::OPEN)->get(['id', 'delivery_fee']);

        return ParcelShares::split($order->payment_method === 'cod' ? CodSplit::due($order) : $balance, $parts);
    }
}
