<?php

namespace App\Domain\Operations\Services;

use App\Domain\Branches\Models\Branch;
use App\Domain\Fulfillment\Enums\DeliveryStatus;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Services\AssemblyFacts;
use App\Domain\Fulfillment\Services\DispatchSettings;
use App\Domain\Fulfillment\Services\SlotOffers;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Identity\Support\BranchScope;
use App\Domain\Operations\Support\AttentionItem;
use App\Domain\Operations\Support\AttentionList;
use App\Domain\Operations\Support\OpsAge;
use App\Domain\Orders\Enums\FulfillmentStatus;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderCancellation;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\ReturnRequest;
use App\Domain\Orders\Models\SubstitutionRequest;
use App\Domain\Orders\Services\SubstitutionClock;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\Services\CashShiftSettings;
use App\Domain\Reports\Services\Metrics\Cash;
use App\Support\BusinessTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The "Needs attention" inbox: everything that waits for a human, for the person who is allowed to see it.
 *
 *  - Each kind of item is gated by its own permission AND the branches that permission covers (the same rule as the page that handles it),
 *    so a person never learns about an order, a driver or a till they could not open.
 *  - One query per kind plus one eager load per relation: the number of queries does not grow with the number of rows (a test seeds 200).
 *  - Things that are only worth a line once they are late (a part being prepared, a driver who has not answered, a pickup, cash held) are
 *    listed when amber or red; things that always need someone (a new order, a ready part with no driver, a cancellation request, a refund
 *    to approve, a failed delivery) are listed from the first minute, so nothing waits silently.
 *  - The result is sorted worst first, then oldest first.
 */
final class AttentionFeed
{
    /** Longest list of one kind shown at once (the screen says when more exist). */
    public const CAP = 100;

    /** The permissions that open the inbox: any one of them, on at least one branch. */
    public const GATE = ['orders.view', 'deliveries.view', 'payments.view', 'pickup.view'];

    /** Kinds that are listed only once amber or red. */
    private const LATE_ONLY = ['stuck', 'driver_silent', 'pickup_waiting', 'cash', 'claim'];

    /** Seconds the count behind the sidebar badge is remembered. */
    private const BADGE_TTL = 15;

    /** Set while a list is built: the part query hit its row limit, so even the longest-waiting lines may not all be here. */
    private bool $cutOff = false;

    /** @var array<string, int> deliveries the system tried drivers for without an answer (delivery id => drivers tried), set while a list is built */
    private array $silent = [];

    /** @var array<string, array{state: string, text: string}> what became of the new times offered after a failed delivery (delivery id => the line), set while a list is built */
    private array $offers = [];

    public function __construct(private readonly OpsClock $clock, private readonly AccessControl $acl) {}

    public static function canSee(User $user): bool
    {
        $acl = app(AccessControl::class);
        foreach (self::GATE as $permission) {
            if ($acl->allows($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  string|null  $branchId  show one branch only (it can only narrow what the person may see)
     * @param  bool  $mine  only what is assigned to the person (their parts, their deliveries, the cash they hold, their requests)
     */
    public function for(User $user, ?string $branchId = null, bool $mine = false): AttentionList
    {
        $this->cutOff = false;
        $branches = $this->branches($user);
        $items = collect()
            ->concat($this->fulfillmentItems($user, $branches))
            ->concat($this->cancellationItems($user, $branches))
            ->concat($this->refundItems($user, $branches))
            ->concat($this->claimItems($user, $branches))
            ->concat($this->substitutionItems($user, $branches))
            ->concat($this->assemblyItems($user, $branches))
            ->concat($this->cashItems($user, $branches))
            ->concat($this->cashShiftItems($user, $branches))
            ->concat($this->systemItems($user));

        if ($branchId !== null && $branchId !== '' && $branches->has($branchId)) {
            $items = $items->filter(fn (AttentionItem $i) => $i->branchId === $branchId);
        }
        if ($mine) {
            $items = $items->filter(fn (AttentionItem $i) => $i->assignedTo === $user->id);
        }

        $truncated = $this->cutOff;
        $capped = $items->groupBy('category')->flatMap(function (Collection $group) use (&$truncated) {
            $truncated = $truncated || $group->count() > self::CAP;

            return $group->sort(self::worstFirst(...))->take(self::CAP);
        })->sort(self::worstFirst(...))->values();

        $chips = $branches->map(fn (Branch $b) => ['id' => $b->id, 'code' => $b->code, 'name' => (string) $b->localized('name')])->values()->all();

        return new AttentionList($capped, $truncated, $chips);
    }

    /** Worst state first, then the longest wait, then by id so the order never flickers between two refreshes. */
    private static function worstFirst(AttentionItem $a, AttentionItem $b): int
    {
        return [$b->rank(), $b->age?->ageMinutes ?? 0, $a->id] <=> [$a->rank(), $a->age?->ageMinutes ?? 0, $b->id];
    }

    /**
     * The numbers behind the sidebar badge. Remembered for a few seconds per person, so a page full of staff does not run the whole
     * inbox on every click; the inbox itself is always read fresh.
     *
     * @return array{total: int, red: int, amber: int}
     */
    public function summary(User $user): array
    {
        /** @var array{total: int, red: int, amber: int} $summary */
        $summary = Cache::remember('ops:attention:summary:'.$user->id, self::BADGE_TTL, function () use ($user): array {
            $c = $this->for($user)->counts();

            return ['total' => $c['total'], 'red' => $c['red'], 'amber' => $c['amber']];
        });

        return $summary;
    }

    // ── Branch parts of orders: new, stuck, ready, driver silent, failed, pickup waiting ─────────────────────────────

    /**
     * @param  Collection<string, Branch>  $branches
     * @return list<AttentionItem>
     */
    private function fulfillmentItems(User $user, Collection $branches): array
    {
        $scope = $this->union($user, ['orders.view', 'deliveries.view', 'pickup.view']);
        if ($scope->isEmpty()) {
            return [];
        }
        // A wait can only be as long as the real time that passed, so a part that has not waited the amber time in real minutes cannot be late.
        $cut = fn (string ...$kinds) => CarbonImmutable::now()->subMinutes(min(array_map(fn (string $k) => $this->clock->limits($k)['amber'], $kinds)));
        $preparing = $cut(OpsClock::PREPARING);
        $ready = $cut(OpsClock::READY, OpsClock::DRIVER);
        $pickup = $cut(OpsClock::PICKUP);

        $query = OrderFulfillment::query()->where(function ($w) use ($preparing, $ready, $pickup) {
            $w->where(fn ($x) => $x->where('order_fulfillments.status', 'pending')->whereHas('order', fn ($o) => $o->whereNotIn('status', ['pending_payment', 'cancellation_requested'])))
                ->orWhere(fn ($x) => $x->whereIn('order_fulfillments.status', ['accepted', 'preparing'])->whereRaw('coalesce(order_fulfillments.accepted_at, order_fulfillments.updated_at) <= ?', [$preparing]))
                ->orWhere(fn ($x) => $x->where('order_fulfillments.status', 'ready')->where('order_fulfillments.type', 'delivery')->whereRaw('coalesce(order_fulfillments.ready_at, order_fulfillments.updated_at) <= ?', [$ready]))
                ->orWhere(fn ($x) => $x->where('order_fulfillments.status', 'pickup_ready')->whereRaw('coalesce(order_fulfillments.ready_at, order_fulfillments.updated_at) <= ?', [$pickup]))
                ->orWhere(fn ($x) => $x->where('order_fulfillments.status', 'failed')->where('order_fulfillments.type', 'delivery'));
        });
        $scope->apply($query->getQuery(), 'order_fulfillments.branch_id');

        $rows = $query
            ->with([
                'order:id,number,status,confirmed_at,customer_name,customer_phone',
                'deliveries:id,order_fulfillment_id,status,created_at,assigned_at,failed_at,failure_reason,driver_id',
                'deliveries.driver:id,name,phone',
                'pickup:id,order_fulfillment_id,token_expires_at,status',
            ])
            ->withSum('items as units', 'quantity')
            ->orderBy('order_fulfillments.created_at')
            ->limit(self::CAP * 5 + 1)
            ->get();
        if ($rows->count() > self::CAP * 5) {
            $this->cutOff = true;
            $rows = $rows->take(self::CAP * 5);
        }

        $this->clock->preload($rows->pluck('branch_id')->all());
        $this->silent = $this->silentDeliveries($rows);
        $this->offers = app(SlotOffers::class)->staffNotes($rows->where('status', FulfillmentStatus::Failed)->map(fn (OrderFulfillment $f) => OpsClock::currentDelivery($f->deliveries))->filter()->values());
        $items = [];
        foreach ($rows as $f) {
            $age = $this->clock->forFulfillment($f);
            $order = $f->order;
            if ($age === null || $order === null) {
                continue;
            }
            $category = match (true) {
                $f->status === FulfillmentStatus::Pending => 'unaccepted',
                in_array($f->status, [FulfillmentStatus::Accepted, FulfillmentStatus::Preparing], true) => 'stuck',
                $f->status === FulfillmentStatus::Ready && $age->kind === OpsClock::DRIVER => 'driver_silent',
                $f->status === FulfillmentStatus::Ready => 'undispatched',
                $f->status === FulfillmentStatus::PickupReady => 'pickup_waiting',
                default => 'delivery_failed',
            };
            $permission = match ($category) {
                'unaccepted', 'stuck' => 'orders.view',
                'pickup_waiting' => 'pickup.view',
                default => 'deliveries.view',
            };
            if (! $this->acl->allows($user, $permission, $f->branch_id) || (in_array($category, self::LATE_ONLY, true) && ! $age->isLate())) {
                continue;
            }
            $items[] = $this->fulfillmentItem($user, $f, $order, $age, $category, $branches->get($f->branch_id));
        }

        return $items;
    }

    /**
     * Deliveries the system handed to drivers one after the other and none of them answered in time (dispatch:watchdog gave up after its
     * automatic tries): delivery id => how many drivers were tried. One grouped query for the whole page.
     *
     * @param  Collection<int, OrderFulfillment>  $rows  with their deliveries loaded
     * @return array<string, int>
     */
    private function silentDeliveries(Collection $rows): array
    {
        $ids = $rows->flatMap(fn (OrderFulfillment $f) => $f->deliveries->where('status', DeliveryStatus::Pending)->pluck('id'))->values()->all();
        if ($ids === []) {
            return [];
        }
        $tries = app(DispatchSettings::class)->maxAutoAttempts();

        return DB::table('delivery_declines')->whereIn('delivery_id', $ids)->where('reason', 'timeout')->groupBy('delivery_id')->havingRaw('count(*) >= ?', [$tries])
            ->selectRaw('delivery_id, count(*) as n')->pluck('n', 'delivery_id')->map(fn (mixed $n) => (int) $n)->all();
    }

    private function fulfillmentItem(User $user, OrderFulfillment $f, Order $order, OpsAge $age, string $category, ?Branch $branch): AttentionItem
    {
        $delivery = OpsClock::currentDelivery($f->deliveries);
        $units = (int) ($f->getAttribute('units') ?? 0);
        $who = $order->customer_name.($units > 0 ? ' · '.trans_choice('ops_live.items', $units, ['count' => $units]) : '');
        $number = $order->number;
        $search = fn (string $lane) => ['type' => 'link', 'url' => route('admin.deliveries.index', ['lane' => $lane, 'search' => $number])];
        $note = null;
        $call = $order->customer_phone ? ['label' => __('ops_live.call_customer'), 'phone' => $order->customer_phone] : null;
        $assigned = $f->assigned_to;
        $label = __('ops_live.cat.'.$category.'.label');

        switch ($category) {
            case 'driver_silent':
                $driver = $delivery?->driver;
                $who = ($driver ? __('ops_live.detail.driver', ['name' => $driver->name]).' · ' : '').$order->customer_name;
                $action = $search('scheduled') + ['label' => __('ops_live.action.reassign')];
                $call = $driver?->phone ? ['label' => __('ops_live.call_driver'), 'phone' => $driver->phone] : $call;
                $assigned = $delivery?->driver_id;
                break;
            case 'undispatched':
                $action = $search('to_dispatch') + ['label' => __('ops_live.action.assign_driver')];
                // The system already tried drivers who did not answer (dispatch:watchdog): the line says so, so the manager knows why it waits.
                if ($delivery !== null && isset($this->silent[$delivery->id])) {
                    $label = __('ops_live.cat.driver_silent.label');
                    $note = __('ops_live.note.drivers_silent', ['n' => $this->silent[$delivery->id]]);
                }
                break;
            case 'delivery_failed':
                $reason = $delivery?->failure_reason;
                $note = $reason !== null && Lang::has('sales_admin.fail_'.$reason) ? __('sales_admin.fail_'.$reason) : null;
                // What became of the new times offered to the customer: still choosing, did not answer in time, or nothing free to offer.
                $note = implode(' · ', array_filter([$note, $delivery !== null ? ($this->offers[$delivery->id]['text'] ?? null) : null])) ?: null;
                $action = $search('exceptions') + ['label' => __('ops_live.action.reschedule')];
                break;
            case 'pickup_waiting':
                $expires = $f->pickup?->token_expires_at;
                $expired = $expires !== null && $expires->isPast();
                $note = $expired ? __('ops_live.note.pass_expired') : null;
                $action = in_array($f->pickup?->status, ['ready', 'partially_collected'], true) && $this->acl->allows($user, 'pickup.edit', $f->branch_id)
                    ? ['type' => 'reissue', 'label' => __('ops_live.action.reissue'), 'fulfillment' => $f->id]
                    : ['type' => 'peek', 'label' => __('ops_live.action.open_order'), 'order' => $order->id];
                break;
            default: // unaccepted, stuck
                $action = ['type' => 'peek', 'label' => __('ops_live.action.open_order'), 'order' => $order->id];
        }

        return new AttentionItem(
            id: $category.':'.$f->id, category: $category, icon: self::ICONS[$category], subject: $number, label: $label, detail: $who,
            state: $age->state, age: $age, branchId: $f->branch_id, branchCode: $branch?->code, action: $action, call: $call, assignedTo: $assigned, note: $note,
        );
    }

    private const ICONS = [
        'assembly' => 'package', 'unaccepted' => 'bag', 'stuck' => 'package', 'undispatched' => 'truck', 'driver_silent' => 'phone', 'delivery_failed' => 'alert', 'pickup_waiting' => 'qr',
        'cancellation' => 'x', 'refund' => 'refund', 'claim' => 'package', 'substitution' => 'bag', 'cash' => 'cash', 'cash_shift' => 'scale', 'queue' => 'settings', 'campaign' => 'megaphone',
    ];

    // ── Cancellation requests awaiting a manager ─────────────────────────────────────────────────────────────────────

    /**
     * @param  Collection<string, Branch>  $branches
     * @return list<AttentionItem>
     */
    private function cancellationItems(User $user, Collection $branches): array
    {
        $scope = $this->acl->scope($user, 'orders.view');
        if ($scope->isEmpty()) {
            return [];
        }
        $query = OrderCancellation::query()->where('status', 'requested');
        if (! $scope->all) {
            $query->whereIn('order_id', $scope->apply(OrderFulfillment::query()->select('order_id')->getQuery(), 'branch_id'));
        }
        $rows = $query->with(['order:id,number,customer_name,customer_phone', 'order.fulfillments:id,order_id,branch_id'])
            ->orderBy('created_at')->limit(self::CAP + 1)->get(['id', 'order_id', 'order_fulfillment_id', 'requested_by', 'reason', 'created_at']);

        $this->clock->preload($rows->flatMap(fn (OrderCancellation $c) => $c->order?->fulfillments->pluck('branch_id') ?? collect())->all());
        $items = [];
        foreach ($rows as $c) {
            $order = $c->order;
            if ($order === null) {
                continue;
            }
            $branchIds = $order->fulfillments->pluck('branch_id');
            $own = $c->order_fulfillment_id !== null ? $order->fulfillments->firstWhere('id', $c->order_fulfillment_id)?->branch_id : null;
            $branchId = $own ?? $branchIds->first(fn (string $b) => $scope->contains($b)) ?? $branchIds->first();
            $age = $this->clock->evaluate(OpsClock::PENDING, $c->created_at ?? CarbonImmutable::now(), $branchId);
            $items[] = new AttentionItem(
                id: 'cancellation:'.$c->id, category: 'cancellation', icon: self::ICONS['cancellation'], subject: $order->number, label: __('ops_live.cat.cancellation.label'),
                detail: $order->customer_name, state: $age->state, age: $age, branchId: $branchId, branchCode: $branchId !== null ? $branches->get($branchId)?->code : null,
                action: ['type' => 'peek', 'label' => __('ops_live.action.review_request'), 'order' => $order->id],
                call: $order->customer_phone ? ['label' => __('ops_live.call_customer'), 'phone' => $order->customer_phone] : null,
                assignedTo: $c->requested_by, note: $c->reason ? Str::limit($c->reason, 140) : null,
            );
        }

        return $items;
    }

    // ── Refunds awaiting approval ────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  Collection<string, Branch>  $branches
     * @return list<AttentionItem>
     */
    private function refundItems(User $user, Collection $branches): array
    {
        $scope = $this->acl->scope($user, 'refunds.view');
        if ($scope->isEmpty()) {
            return [];
        }
        $query = Refund::query()->where('status', 'requested');
        if (! $scope->all) {
            $query->whereIn('order_id', $scope->apply(OrderFulfillment::query()->select('order_id')->getQuery(), 'branch_id'));
        }
        $rows = $query->with(['order:id,number,customer_name', 'order.fulfillments:id,order_id,branch_id'])
            ->orderBy('created_at')->limit(self::CAP + 1)->get(['id', 'number', 'order_id', 'amount', 'currency', 'requested_by', 'created_at']);

        $items = [];
        foreach ($rows as $r) {
            $order = $r->order;
            $branchId = $order?->fulfillments->pluck('branch_id')->first(fn (string $b) => $scope->contains($b)) ?? $order?->fulfillments->pluck('branch_id')->first();
            $age = $this->clock->evaluate(OpsClock::CASH, $r->created_at ?? CarbonImmutable::now(), $branchId);
            $amount = Money::of($r->amount, $r->currency)->format();
            $items[] = new AttentionItem(
                id: 'refund:'.$r->id, category: 'refund', icon: self::ICONS['refund'], subject: $order?->number ?? $r->number, label: __('ops_live.cat.refund.label'),
                detail: $amount.($order ? ' · '.$order->customer_name : ''), state: $age->state, age: $age, branchId: $branchId, branchCode: $branchId !== null ? $branches->get($branchId)?->code : null,
                action: ['type' => 'link', 'label' => __('ops_live.action.review_refund'), 'url' => route('admin.refunds.index')], assignedTo: $r->requested_by,
            );
        }

        return $items;
    }

    // ── Return and damage claims nobody has decided ───────────────────────────────────────────────────────────────────

    /**
     * A claim about the condition of the goods (damaged, wrong, missing, quality) that no one has decided: listed once it has waited longer than the
     * Operations clock says (opening hours only). A plain "changed my mind" return is not urgent and stays on the Returns page.
     *
     * @param  Collection<string, Branch>  $branches
     * @return list<AttentionItem>
     */
    private function claimItems(User $user, Collection $branches): array
    {
        $scope = $this->acl->scope($user, 'orders.view');
        if ($scope->isEmpty()) {
            return [];
        }
        $cut = CarbonImmutable::now()->subMinutes($this->clock->limits(OpsClock::CLAIM)['amber']);
        $query = ReturnRequest::query()->whereIn('status', ReturnRequest::UNDECIDED)->whereIn('reason_code', ReturnRequest::DEFECT_TYPES)->where('created_at', '<=', $cut);
        $scope->apply($query->getQuery(), 'branch_id');
        $rows = $query->with(['order:id,number,customer_name,customer_phone'])->withCount('photos')->orderBy('created_at')->limit(self::CAP + 1)
            ->get(['id', 'number', 'order_id', 'branch_id', 'reason_code', 'status', 'review_started_by', 'created_at']);

        $this->clock->preload($rows->pluck('branch_id')->all());
        $items = [];
        foreach ($rows as $c) {
            $order = $c->order;
            $age = $this->clock->evaluate(OpsClock::CLAIM, $c->created_at ?? CarbonImmutable::now(), $c->branch_id);
            if ($order === null || ! $age->isLate()) {
                continue;
            }
            $type = __('returns.type.'.$c->reason_code);
            $items[] = new AttentionItem(
                id: 'claim:'.$c->id, category: 'claim', icon: self::ICONS['claim'], subject: $order->number, label: __('ops_live.cat.claim.label'),
                detail: $order->customer_name.' · '.$type, state: $age->state, age: $age, branchId: $c->branch_id, branchCode: $branches->get($c->branch_id)?->code,
                action: ['type' => 'link', 'label' => __('ops_live.action.review_claim'), 'url' => route('admin.returns.index', ['view' => $c->id])],
                call: $order->customer_phone ? ['label' => __('ops_live.call_customer'), 'phone' => $order->customer_phone] : null,
                assignedTo: $c->review_started_by, note: trans_choice('ops_live.claim_photos', (int) $c->photos_count, ['count' => (int) $c->photos_count]),
            );
        }

        return $items;
    }

    // ── Replacement questions the customer has not answered ─────────────────────────────────────────────────────────────

    /**
     * A question to the customer about a replacement that has used half of its time (amber) or all of it (red, waiting for the minute-by-minute job to
     * settle it by the shop's fallback): the branch may want to call the customer. Time counts opening hours, like the question's own deadline.
     *
     * @param  Collection<string, Branch>  $branches
     * @return list<AttentionItem>
     */
    private function substitutionItems(User $user, Collection $branches): array
    {
        $scope = $this->acl->scope($user, 'orders.view');
        if ($scope->isEmpty()) {
            return [];
        }
        $query = SubstitutionRequest::query()->where('status', SubstitutionRequest::WAITING)->where('asked_at', '<=', CarbonImmutable::now()->subMinutes(2));
        $scope->apply($query->getQuery(), 'branch_id');
        $rows = $query->with(['order:id,number,customer_name,customer_phone', 'original', 'replacement'])->orderBy('deadline_at')->limit(self::CAP + 1)->get();

        $clock = app(SubstitutionClock::class);
        $items = [];
        foreach ($rows as $r) {
            $order = $r->order;
            if ($order === null) {
                continue;
            }
            ['counted' => $counted, 'state' => $state] = $clock->wait($r);
            if ($state === OpsAge::OK) {
                continue;
            }
            $wall = max(0, intdiv(CarbonImmutable::now()->getTimestamp() - $r->asked_at->getTimestamp(), 60));
            $name = fn (?OrderItem $i) => $i?->name() ?? '';
            $items[] = new AttentionItem(
                id: 'substitution:'.$r->id, category: 'substitution', icon: self::ICONS['substitution'], subject: $order->number, label: __('ops_live.cat.substitution.label'),
                detail: __('substitutions.detail', ['customer' => $order->customer_name, 'item' => $name($r->original), 'replacement' => $name($r->replacement)]),
                state: $state, age: new OpsAge('substitution', $state, $counted, $wall, OpsClock::label($counted), $r->asked_at), branchId: $r->branch_id, branchCode: $branches->get($r->branch_id)?->code,
                action: ['type' => 'peek', 'label' => __('ops_live.action.open_order'), 'order' => $order->id],
                call: $order->customer_phone ? ['label' => __('ops_live.call_customer'), 'phone' => $order->customer_phone] : null,
                assignedTo: $r->requested_by, note: __('substitutions.attention_note', ['fallback' => __('substitutions.fallback_'.$r->fallback.'_short')]),
            );
        }

        return $items;
    }

    // ── Assembly the driver could not do ─────────────────────────────────────────────────────────────────────────────

    /**
     * The customer paid for assembly and the driver reported that it was not done: the branch refunds the fee (the usual refund approval) or books it again,
     * then marks it handled on the order. Listed from the first minute (the customer is waiting for an answer) and measured like a failed delivery.
     *
     * @param  Collection<string, Branch>  $branches
     * @return list<AttentionItem>
     */
    private function assemblyItems(User $user, Collection $branches): array
    {
        $scope = $this->acl->scope($user, 'deliveries.view');
        if ($scope->isEmpty()) {
            return [];
        }
        $query = OrderFulfillment::query()->where('assembly_status', AssemblyFacts::NOT_DONE)->whereNull('assembly_handled_at');
        $scope->apply($query->getQuery(), 'order_fulfillments.branch_id');
        $rows = $query->with(['order:id,number,customer_name,customer_phone'])->orderBy('assembly_answered_at')->orderBy('id')->limit(self::CAP + 1)
            ->get(['id', 'order_id', 'branch_id', 'number', 'assembly_reason', 'assembly_note', 'assembly_answered_at', 'assembly_answered_by']);

        $this->clock->preload($rows->pluck('branch_id')->all());
        $items = [];
        foreach ($rows as $f) {
            $order = $f->order;
            if ($order === null) {
                continue;
            }
            $age = $this->clock->evaluate(OpsClock::READY, $f->assembly_answered_at ?? CarbonImmutable::now(), $f->branch_id);
            $reason = __('delivery_followup.assembly_reason.'.$f->assembly_reason);
            $items[] = new AttentionItem(
                id: 'assembly:'.$f->id, category: 'assembly', icon: self::ICONS['assembly'], subject: $order->number, label: __('delivery_followup.attention.label'),
                detail: $order->customer_name.' · '.$reason, state: $age->state, age: $age, branchId: $f->branch_id, branchCode: $branches->get($f->branch_id)?->code,
                action: ['type' => 'peek', 'label' => __('ops_live.action.open_order'), 'order' => $order->id],
                call: $order->customer_phone ? ['label' => __('ops_live.call_customer'), 'phone' => $order->customer_phone] : null,
                assignedTo: $f->assembly_answered_by, note: $f->assembly_note !== null ? Str::limit($f->assembly_note, 140) : __('delivery_followup.attention.note'),
            );
        }

        return $items;
    }

    // ── Cash a driver or cashier still holds ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  Collection<string, Branch>  $branches
     * @return list<AttentionItem>
     */
    private function cashItems(User $user, Collection $branches): array
    {
        $scope = $this->acl->scope($user, 'payments.view');
        if ($scope->isEmpty()) {
            return [];
        }
        $cut = CarbonImmutable::now()->subMinutes($this->clock->limits(OpsClock::CASH)['amber']);
        $query = Cash::heldQuery();
        $scope->apply($query, 'branch_id');
        $rows = $query->groupBy('collected_by', 'branch_id')->havingRaw('min(collected_at) <= ?', [$cut])
            ->selectRaw('collected_by, branch_id, count(*) as n, sum(amount_collected) as total, min(collected_at) as oldest')
            ->orderByRaw('min(collected_at)')->limit(self::CAP + 1)->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $names = User::query()->whereIn('id', $rows->pluck('collected_by'))->get(['id', 'name'])->keyBy('id');

        $items = [];
        foreach ($rows as $r) {
            $age = $this->clock->evaluate(OpsClock::CASH, CarbonImmutable::parse((string) $r->oldest), (string) $r->branch_id);
            if (! $age->isLate()) {
                continue;
            }
            $branch = $branches->get((string) $r->branch_id);
            $items[] = new AttentionItem(
                id: 'cash:'.$r->collected_by.':'.$r->branch_id, category: 'cash', icon: self::ICONS['cash'], subject: $names->get($r->collected_by)?->name ?? '—',
                label: __('ops_live.cat.cash.label'), detail: Money::of((string) $r->total)->format().' · '.trans_choice('ops_live.collections', (int) $r->n, ['count' => (int) $r->n]),
                state: $age->state, age: $age, branchId: (string) $r->branch_id, branchCode: $branch?->code,
                action: ['type' => 'link', 'label' => __('ops_live.action.receive_cash'), 'url' => route('admin.cash.index', ['tab' => 'outstanding'])],
                assignedTo: (string) $r->collected_by, subjectIsNumber: false,
            );
        }

        return $items;
    }

    // ── Cash shifts (payments.view): a drawer left open for too long, a day with cash and no shift ───────────────────

    /**
     * Two kinds of line, both for a branch that uses cash shifts:
     *  - a shift that has been open longer than the limit in Settings > Cash (amber, and red from one and a half times that);
     *  - cash received from hand-ins while no shift was open, on a finished day (one of the last three). A branch that never opened a shift (or opened its first
     *    one after that day) is not nagged: it simply does not use shifts yet.
     * Aggregates only: the line names the cashier or the day, never a customer.
     *
     * @param  Collection<string, Branch>  $branches
     * @return list<AttentionItem>
     */
    private function cashShiftItems(User $user, Collection $branches): array
    {
        $scope = $this->acl->scope($user, 'payments.view');
        if ($scope->isEmpty()) {
            return [];
        }
        $now = CarbonImmutable::now();
        $limit = app(CashShiftSettings::class)->maxOpenHours() * 60;
        $link = ['type' => 'link', 'label' => __('ops_live.action.close_shift'), 'url' => route('admin.cash.index', ['tab' => 'shifts'])];
        $items = [];

        $open = DB::table('cash_shifts as s')->join('users as u', 'u.id', '=', 's.opened_by')->where('s.status', 'open')->where('s.opened_at', '<=', $now->subMinutes($limit));
        $scope->apply($open, 's.branch_id');
        foreach ($open->orderBy('s.opened_at')->orderBy('s.id')->limit(self::CAP)->get(['s.id', 's.branch_id', 's.opened_by', 's.opened_at', 'u.name']) as $r) {
            $since = CarbonImmutable::parse((string) $r->opened_at);
            $minutes = max(0, intdiv($now->getTimestamp() - $since->getTimestamp(), 60));
            $state = $minutes >= intdiv($limit * 3, 2) ? OpsAge::RED : OpsAge::AMBER;
            $items[] = new AttentionItem(
                id: 'cash_shift:'.$r->id, category: 'cash_shift', icon: self::ICONS['cash_shift'], subject: (string) $r->name, label: __('ops_live.cat.cash_shift.label'),
                detail: __('ops_live.detail.shift_open', ['time' => OpsClock::label($minutes)]), state: $state, age: new OpsAge('cash_shift', $state, $minutes, $minutes, OpsClock::label($minutes), $since),
                branchId: (string) $r->branch_id, branchCode: $branches->get((string) $r->branch_id)?->code, action: $link, assignedTo: (string) $r->opened_by, subjectIsNumber: false,
            );
        }

        // Cash received on a finished day while no shift was open at that moment, per branch and day (one query; the shift check is per hand-in).
        $today = BusinessTime::now()->startOfDay();
        $zone = BusinessTime::zone();
        $taken = DB::table('cash_handovers as h')->where('h.created_at', '>=', $today->subDays(3)->utc())->where('h.created_at', '<', $today->utc())
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('cash_shifts as s')->whereColumn('s.branch_id', 'h.branch_id')->whereColumn('s.opened_at', '<=', 'h.created_at')
                ->where(fn ($w) => $w->whereNull('s.closed_at')->orWhereColumn('s.closed_at', '>=', 'h.created_at')));
        $scope->apply($taken, 'h.branch_id');
        $days = $taken->groupByRaw("1, timezone('{$zone}', h.created_at)::date")->selectRaw("h.branch_id, timezone('{$zone}', h.created_at)::date as day, count(*) as n, coalesce(sum(h.counted_amount), 0) as total")
            ->orderBy('day')->orderBy('h.branch_id')->limit(self::CAP)->get();
        if ($days->isEmpty()) {
            return $items;
        }
        // A branch that never opened a shift (or opened its first one after that day) simply does not use shifts yet: no line for it.
        $first = DB::table('cash_shifts')->whereIn('branch_id', $days->pluck('branch_id')->unique()->all())->groupBy('branch_id')->selectRaw('branch_id, min(opened_at) as first_opened')->pluck('first_opened', 'branch_id');
        foreach ($days as $d) {
            $start = CarbonImmutable::parse((string) $d->day, $zone)->startOfDay();
            $end = $start->addDay();
            if (! $first->has($d->branch_id) || ! CarbonImmutable::parse((string) $first[$d->branch_id])->lt($end)) {
                continue;
            }
            $minutes = max(0, intdiv($now->getTimestamp() - $end->getTimestamp(), 60));
            $state = $start->lt($today->subDay()) ? OpsAge::RED : OpsAge::AMBER;
            $items[] = new AttentionItem(
                id: 'cash_shift_day:'.$d->branch_id.':'.$d->day, category: 'cash_shift', icon: self::ICONS['cash_shift'], subject: BusinessTime::display($start, 'd M') ?? (string) $d->day,
                label: __('ops_live.cat.cash_shift.missing'), detail: trans_choice('ops_live.detail.shift_missing', (int) $d->n, ['amount' => Money::of((string) $d->total)->format(), 'count' => (int) $d->n]),
                state: $state, age: new OpsAge('cash_shift', $state, $minutes, $minutes, OpsClock::label($minutes), $end), branchId: (string) $d->branch_id,
                branchCode: $branches->get((string) $d->branch_id)?->code, action: $link, subjectIsNumber: false,
            );
        }

        return $items;
    }

    // ── The system itself (settings.edit only): failed background jobs and a campaign that stopped moving ───────────

    /** @return list<AttentionItem> */
    private function systemItems(User $user): array
    {
        if (! $this->acl->allowsGlobally($user, 'settings.edit')) {
            return [];
        }
        $items = [];
        if ($this->exists('failed_jobs', 'failed_at')) {
            $row = DB::table('failed_jobs')->selectRaw('count(*) as n, min(failed_at) as oldest')->first();
            $n = (int) ($row->n ?? 0);
            if ($n > 0) {
                $since = CarbonImmutable::parse((string) $row->oldest);
                $minutes = max(0, intdiv(CarbonImmutable::now()->getTimestamp() - $since->getTimestamp(), 60));
                $state = $n >= 10 ? OpsAge::RED : OpsAge::AMBER;
                $items[] = new AttentionItem(
                    id: 'queue:failed_jobs', category: 'queue', icon: self::ICONS['queue'], subject: __('ops_live.cat.queue.subject'), label: __('ops_live.cat.queue.label'),
                    detail: trans_choice('ops_live.failed_jobs', $n, ['count' => $n]), state: $state, age: new OpsAge('queue', $state, $minutes, $minutes, OpsClock::label($minutes), $since),
                    branchId: null, branchCode: null, action: ['type' => 'link', 'label' => __('ops_live.action.open_dashboard'), 'url' => route('admin.dashboard')],
                    subjectIsNumber: false, note: __('ops_live.note.queue_fix'),
                );
            }
        }
        if ($this->exists('marketing_campaigns', 'status')) {
            $stuck = DB::table('marketing_campaigns')->where('status', 'sending')->where('updated_at', '<=', CarbonImmutable::now()->subMinutes(30))->orderBy('updated_at')->limit(self::CAP)->get(['id', 'name', 'updated_at']);
            foreach ($stuck as $c) {
                $since = CarbonImmutable::parse((string) $c->updated_at);
                $minutes = max(0, intdiv(CarbonImmutable::now()->getTimestamp() - $since->getTimestamp(), 60));
                $state = $minutes >= 120 ? OpsAge::RED : OpsAge::AMBER;
                $items[] = new AttentionItem(
                    id: 'campaign:'.$c->id, category: 'campaign', icon: self::ICONS['campaign'], subject: (string) $c->name, label: __('ops_live.cat.campaign.label'),
                    detail: __('ops_live.detail.campaign_stuck', ['time' => OpsClock::label($minutes)]), state: $state, age: new OpsAge('campaign', $state, $minutes, $minutes, OpsClock::label($minutes), $since),
                    branchId: null, branchCode: null, action: ['type' => 'link', 'label' => __('ops_live.action.open_campaigns'), 'url' => route('admin.campaigns.index')], subjectIsNumber: false,
                );
            }
        }

        return $items;
    }

    /** Does this table (and column) exist yet? Another release may add it; a missing one just means that card is skipped. Remembered for an hour. */
    private function exists(string $table, string $column): bool
    {
        return Cache::remember('ops:schema:'.$table.'.'.$column, 3600, fn () => Schema::hasTable($table) && Schema::hasColumn($table, $column));
    }

    // ── Scope helpers ────────────────────────────────────────────────────────────────────────────────────────────────

    /** The branches covered by ANY of these permissions. @param  list<string>  $permissions */
    private function union(User $user, array $permissions): BranchScope
    {
        $ids = [];
        foreach ($permissions as $permission) {
            $scope = $this->acl->scope($user, $permission);
            if ($scope->all) {
                return BranchScope::all();
            }
            $ids = array_merge($ids, $scope->branchIds);
        }

        return BranchScope::of($ids);
    }

    /** The branches the person can see anything of, by id (for the codes on the rows and the filter chips). @return Collection<string, Branch> */
    private function branches(User $user): Collection
    {
        $scope = $this->union($user, ['orders.view', 'deliveries.view', 'pickup.view', 'payments.view', 'refunds.view']);
        if ($scope->isEmpty()) {
            return new Collection;
        }

        return Branch::query()->when(! $scope->all, fn ($q) => $q->whereIn('id', $scope->branchIds))->orderBy('sort_order')->get(['id', 'code', 'name_en', 'name_ar'])->keyBy('id');
    }
}
