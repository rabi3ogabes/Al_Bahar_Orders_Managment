<?php

namespace App\Domain\Orders\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Services\NotificationRules;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\OrderRevision;
use App\Domain\Orders\Models\SubstitutionRequest;
use App\Domain\Orders\Realtime\OrderRealtime;
use App\Domain\Settings\Services\Settings;
use App\Support\BusinessTime;
use App\Support\DomainException;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Substitution consent: the branch replaces a line with another product (or one that costs more), and the customer is asked.
 *
 * The rules (docs/guides/RETURNS_AND_SUBSTITUTIONS.md):
 *  - The replacement is put in the order at once, reserved for it, and never costs more than what it replaces (the adjustment already guarantees
 *    that). So "the customer is never charged more than they agreed to" holds while the question is open, and an answer can only take things away.
 *  - Accept keeps it. Decline, or no answer in time (with the admin's fallback `remove`), takes the replacement out again through the normal
 *    adjustment, which refunds what was paid. The fallback `keep` keeps a replacement that is not dearer than the original, which is always the case.
 *  - Only the order's owner can answer, repeating the same answer changes nothing, and a different answer once settled is refused.
 *  - While a question is open the branch cannot mark that part ready (OrderService), and the deadline counts the branch's opening hours.
 * The consent switch is Settings → Checkout & fees; a plain quantity reduction or removal never asks.
 */
class SubstitutionService
{
    public const DEFAULT_MINUTES = 30;

    public function __construct(
        private readonly Settings $settings,
        private readonly SubstitutionClock $clock,
        private readonly AuditLogger $audit,
        private readonly OrderService $orders,
        private readonly NotificationService $notifications,
        private readonly NotificationRules $rules,
    ) {}

    // ─── Settings ───────────────────────────────────────────────────────────────────────────────────────────────────

    public function enabled(): bool
    {
        return (bool) $this->settings->get('substitutions.consent_enabled', true);
    }

    /** Opening-hours minutes the customer has to answer (5 to 1440, default 30). */
    public function responseMinutes(): int
    {
        $m = $this->settings->get('substitutions.response_minutes', self::DEFAULT_MINUTES);

        return is_numeric($m) && (int) $m >= 5 ? min(1440, (int) $m) : self::DEFAULT_MINUTES;
    }

    /** What happens when time runs out: `remove` (the line is taken out and refunded) or `keep` (the replacement stays, it is never dearer). */
    public function fallback(): string
    {
        return $this->settings->get('substitutions.fallback', 'remove') === 'keep' ? 'keep' : 'remove';
    }

    /**
     * Does this replacement need the customer's say? A different product, or one whose own price is above what the customer ordered (they would still
     * pay no more than the original, but it is not what they chose). The same product at the same or a lower price (another size or colour) does not.
     *
     * @param  array<string, mixed>  $change  one resolved change of the adjustment plan
     */
    public function needsConsent(array $change): bool
    {
        if (! $this->enabled() || ($change['sub'] ?? null) === null) {
            return false;
        }
        /** @var OrderItem $item */
        $item = $change['item'];

        return $change['sub']['variant']->product_id !== $item->product_id
            || $change['sub']['price']->final()->greaterThan(Money::of($item->unit_final_price));
    }

    // ─── Asking ─────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Opens a question for every replacement of an adjustment that needs one. Runs inside the adjustment's transaction, once the replacement lines and the
     * revision exist.
     *
     * @param  list<array<string, mixed>>  $changes  the plan's resolved changes
     * @param  array<string, OrderItem>  $newItems  the replacement line, by the id of the line it replaced
     * @return list<SubstitutionRequest>
     */
    public function open(Order $order, OrderFulfillment $f, OrderRevision $revision, array $changes, array $newItems, ?User $actor): array
    {
        $out = [];
        foreach ($changes as $c) {
            if (! $this->needsConsent($c) || ! isset($newItems[$c['item']->id])) {
                continue;
            }
            $original = $c['old']['total'];
            $replacement = $c['sub']['line']['total']->min($original);
            $minutes = $this->responseMinutes();
            $now = now();
            $r = SubstitutionRequest::create([
                'order_id' => $order->id, 'order_fulfillment_id' => $f->id, 'branch_id' => $f->branch_id, 'order_revision_id' => $revision->id,
                'original_order_item_id' => $c['item']->id, 'replacement_order_item_id' => $newItems[$c['item']->id]->id, 'requested_by' => $actor?->id,
                'status' => SubstitutionRequest::WAITING, 'fallback' => $this->fallback(), 'response_minutes' => $minutes, 'asked_at' => $now,
                'deadline_at' => $this->clock->deadline($f->branch_id, $now, $minutes), 'original_total' => $original->toDecimal(), 'replacement_total' => $replacement->toDecimal(),
            ]);
            $this->orders->history($order, $f, 'substitution', null, 'substitution_asked', $actor, null, ['substitution' => $r->id], visible: true);
            $this->audit->log('order.substitution_asked', $order, [], ['request' => $r->id, 'deadline' => $r->deadline_at->toIso8601String()], ['fulfillment' => $f->number], branchId: $f->branch_id, actor: $actor);
            $out[] = $r;
        }

        return $out;
    }

    /**
     * Tells the customer what they are asked (push and inbox, in their language). False when the offer message is switched off in the notification
     * controls or nobody can be told, so the caller sends the plain "your order was updated" instead.
     *
     * @param  list<SubstitutionRequest>  $requests
     */
    public function notifyOffers(Order $order, array $requests): bool
    {
        $user = $order->user;
        if ($requests === [] || $user === null || ! $user->isActive() || ! $this->rules->enabled('order.substitution_offer')) {
            return false;
        }
        $first = $requests[0];
        $for = function (string $locale) use ($order, $requests, $first) {
            $diff = Money::of($first->original_total)->minus(Money::of($first->replacement_total));

            return [
                'order_number' => $order->number, 'item' => $this->label($first->original()->first(), $locale), 'replacement' => $this->label($first->replacement()->first(), $locale),
                'price' => $diff->isPositive() ? __('substitutions.price_less', ['amount' => $diff->format($locale)], $locale) : __('substitutions.price_same', [], $locale),
                'minutes' => $first->response_minutes,
                'more' => count($requests) > 1 ? __('substitutions.more', ['n' => count($requests) - 1], $locale) : '',
                'fallback' => __('substitutions.fallback_'.$first->fallback, [], $locale),
            ];
        };
        $by = collect((array) config('platform.supported_locales'))->mapWithKeys(fn ($l) => [$l => $for($l)])->all();

        // A message that cannot be stored never undoes the adjustment (the savepoint keeps the surrounding transaction alive): the caller sends the plain one.
        try {
            return DB::transaction(fn () => $this->notifications->notify($user, 'order.substitution_offer', $for($user->preferredLocale()) + [
                'deep_link' => 'albahar://orders/'.$order->id, 'reference' => $order->id, 'by_locale' => $by,
            ]) !== []);
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    // ─── Closing a question because the line changed again ──────────────────────────────────────────────────────────

    /** A branch changes a replacement line again (reduces, removes or replaces it): the open question about it no longer describes the order. @param list<string> $itemIds */
    public function closeForChanged(array $itemIds): void
    {
        if ($itemIds === []) {
            return;
        }
        SubstitutionRequest::where('status', SubstitutionRequest::WAITING)->whereIn('replacement_order_item_id', $itemIds)
            ->update(['status' => 'cancelled', 'answered_at' => now(), 'answered_source' => 'system']);
    }

    // ─── Answering ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The customer's answer: `accept` or `decline`. The caller has checked the order is the customer's own. Repeating the recorded answer changes
     * nothing; a different one once it is settled is refused with the state it is in.
     *
     * @return array{request: SubstitutionRequest, replayed: bool}
     */
    public function answer(Order $order, User $customer, string $requestId, string $answer): array
    {
        if (! in_array($answer, ['accept', 'decline'], true)) {
            throw DomainException::make('validation_failed', 422);
        }

        $result = DB::transaction(function () use ($order, $customer, $requestId, $answer) {
            $r = SubstitutionRequest::whereKey($requestId)->where('order_id', $order->id)->lockForUpdate()->first();
            abort_if($r === null, 404);
            if (! $r->isWaiting()) {
                $recorded = ['accepted' => 'accept', 'declined' => 'decline'][$r->status] ?? null;
                if ($recorded === $answer && $r->answered_source === 'customer') {
                    return ['request' => $r, 'replayed' => true];
                }

                return ['error' => in_array($r->status, ['accepted', 'declined'], true) ? 'substitution_already_answered' : 'substitution_closed', 'status' => $r->status];
            }
            // The question is settled and committed first, and only then refused: an exception inside the transaction would undo the settling.
            if ($r->deadline_at->lte(now())) {
                $this->settleLocked($r->id);

                return ['error' => 'substitution_expired', 'status' => $r->fresh()?->status];
            }
            $replacement = OrderItem::whereKey($r->replacement_order_item_id)->lockForUpdate()->first();
            // The customer agreed to a price: if the line costs more than that now, nothing is charged and the question is closed.
            if ($this->blocked($r) || $replacement === null || $replacement->quantity < 1 || Money::of($replacement->line_total)->greaterThan(Money::of($r->replacement_total))) {
                $r->update(['status' => 'cancelled', 'answered_at' => now(), 'answered_source' => 'system']);

                return ['error' => 'substitution_closed', 'status' => 'cancelled'];
            }
            $f = OrderFulfillment::findOrFail($r->order_fulfillment_id);
            if ($answer === 'accept') {
                $r->update(['status' => 'accepted', 'answered_at' => now(), 'answered_source' => 'customer']);
                $this->orders->history($order, $f, 'substitution', 'substitution_asked', 'substitution_accepted', $customer, null, ['substitution' => $r->id], visible: true);
                $this->audit->log('order.substitution_accepted', $order, [], ['request' => $r->id], ['fulfillment' => $f->number], branchId: $f->branch_id, actor: $customer);
                DB::afterCommit(fn () => rescue(fn () => app(OrderRealtime::class)->fulfillmentChanged($f->fresh()), report: true));
            } else {
                $revision = $this->takeOut($r, 'substitute_declined', 'The customer did not accept the replacement.', $customer);
                $r->update(['status' => 'declined', 'answered_at' => now(), 'answered_source' => 'customer', 'resolution_revision_id' => $revision]);
                $this->orders->history($order, $f, 'substitution', 'substitution_asked', 'substitution_declined', $customer, null, ['substitution' => $r->id], visible: true);
                $this->audit->log('order.substitution_declined', $order, [], ['request' => $r->id, 'revision' => $revision], ['fulfillment' => $f->number], branchId: $f->branch_id, actor: $customer);
            }

            return ['request' => $r->fresh(), 'replayed' => false];
        }, 3);
        if (isset($result['error'])) {
            throw DomainException::make($result['error'], 409, ['status' => $result['status']]);
        }

        return $result;
    }

    // ─── Running out of time ────────────────────────────────────────────────────────────────────────────────────────

    /** Settles every question whose time is up, by the fallback it was asked with. Safe to run twice and concurrently. @return int questions settled */
    public function expireDue(?CarbonInterface $now = null): int
    {
        $now ??= now();
        $done = 0;
        foreach (SubstitutionRequest::where('status', SubstitutionRequest::WAITING)->where('deadline_at', '<=', $now)->orderBy('deadline_at')->limit(200)->pluck('id') as $id) {
            try {
                $done += DB::transaction(fn () => $this->settleLocked((string) $id), 3) ? 1 : 0;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $done;
    }

    private function settleLocked(string $id): bool
    {
        $r = SubstitutionRequest::whereKey($id)->lockForUpdate()->first();
        if ($r === null || ! $r->isWaiting()) {
            return false;
        }
        $order = Order::findOrFail($r->order_id);
        $f = OrderFulfillment::findOrFail($r->order_fulfillment_id);
        $replacement = OrderItem::whereKey($r->replacement_order_item_id)->first();
        if ($this->blocked($r) || $replacement === null || $replacement->quantity < 1) {
            $r->update(['status' => 'cancelled', 'answered_at' => now(), 'answered_source' => 'system']);

            return true;
        }
        $keep = $r->fallback === 'keep' && ! Money::of($r->replacement_total)->greaterThan(Money::of($r->original_total))
            && ! Money::of($replacement->line_total)->greaterThan(Money::of($r->replacement_total));
        if ($keep) {
            $r->update(['status' => 'expired_kept', 'answered_at' => now(), 'answered_source' => 'system']);
            $this->orders->history($order, $f, 'substitution', 'substitution_asked', 'substitution_expired', null, null, ['substitution' => $r->id, 'kept' => true], visible: true);
            $this->audit->log('order.substitution_expired', $order, [], ['request' => $r->id, 'kept' => true], ['fulfillment' => $f->number], branchId: $f->branch_id);
            $this->tellKept($order, $r);
            DB::afterCommit(fn () => rescue(fn () => app(OrderRealtime::class)->fulfillmentChanged($f->fresh()), report: true));

            return true;
        }
        $revision = $this->takeOut($r, 'substitute_expired', 'No answer about the replacement in time: it was taken out.', null);
        $r->update(['status' => 'expired_removed', 'answered_at' => now(), 'answered_source' => 'system', 'resolution_revision_id' => $revision]);
        $this->orders->history($order, $f, 'substitution', 'substitution_asked', 'substitution_expired', null, null, ['substitution' => $r->id, 'kept' => false], visible: true);
        $this->audit->log('order.substitution_expired', $order, [], ['request' => $r->id, 'kept' => false, 'revision' => $revision], ['fulfillment' => $f->number], branchId: $f->branch_id);

        return true;
    }

    /** The replacement comes out of the order again, through the normal adjustment (stock released, a refund asked for what was paid, the customer told). @return string|null the revision it made */
    private function takeOut(SubstitutionRequest $r, string $reason, string $note, ?User $actor): ?string
    {
        $result = app(OrderAdjustmentService::class)->removeSubstitute(OrderItem::findOrFail($r->replacement_order_item_id), $reason, $note, $actor);

        return $result['revision']['id'] ?? null;
    }

    /** The question can no longer be acted on: the parcel is not being prepared any more, or the customer asked to cancel. */
    private function blocked(SubstitutionRequest $r): bool
    {
        $f = OrderFulfillment::find($r->order_fulfillment_id);
        $order = Order::find($r->order_id);

        return $f === null || $order === null || app(OrderAdjustmentService::class)->blockedReason($f, $order) !== null;
    }

    private function tellKept(Order $order, SubstitutionRequest $r): void
    {
        $user = $order->user;
        if ($user === null || ! $user->isActive()) {
            return;
        }
        $for = fn (string $locale) => ['order_number' => $order->number, 'item' => $this->label($r->original()->first(), $locale), 'replacement' => $this->label($r->replacement()->first(), $locale)];
        $by = collect((array) config('platform.supported_locales'))->mapWithKeys(fn ($l) => [$l => $for($l)])->all();
        rescue(fn () => $this->notifications->notify($user, 'order.substitution_kept', $for($user->preferredLocale()) + ['deep_link' => 'albahar://orders/'.$order->id, 'reference' => $order->id, 'by_locale' => $by]), report: true);
    }

    // ─── Reading ────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The order's questions as the customer reads them, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function present(Order $o): array
    {
        $rows = SubstitutionRequest::where('order_id', $o->id)->orderByDesc('asked_at')->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $items = OrderItem::whereIn('id', $rows->pluck('original_order_item_id')->merge($rows->pluck('replacement_order_item_id')))->get()->keyBy('id');
        $presenter = app(OrderPresenter::class);
        $line = fn (?OrderItem $i, int $quantity, string $total) => $i === null ? null : ['name' => $i->name(), 'variant_name' => $i->variantName(), 'quantity' => $quantity,
            'image_url' => $presenter->imageUrl($i->image_path), 'total' => Money::of($total)->jsonSerialize()];

        return $rows->map(function (SubstitutionRequest $r) use ($items, $line, $o) {
            $orig = $items->get($r->original_order_item_id);
            $repl = $items->get($r->replacement_order_item_id);

            return [
                'id' => $r->id, 'status' => $r->status,
                'original' => $line($orig, (int) ($orig?->original_quantity ?? $orig?->quantity ?? 0), $r->original_total), 'replacement' => $line($repl, (int) ($repl?->quantity ?? 0), $r->replacement_total),
                // What the customer pays more (or, as a negative amount, less) than for the line they ordered: never above zero.
                'price_difference' => Money::of($r->replacement_total)->minus(Money::of($r->original_total))->jsonSerialize(),
                'fallback' => $r->fallback, 'asked_at' => BusinessTime::iso($r->asked_at), 'expires_at' => BusinessTime::iso($r->deadline_at), 'answered_at' => BusinessTime::iso($r->answered_at),
                'can_answer' => $r->isWaiting() && $r->deadline_at->gt(now()) && ! $o->status->isTerminal(),
            ];
        })->values()->all();
    }

    /**
     * What the branch sees per parcel while customers are asked: how many questions are open, since when, and when the first runs out.
     *
     * @param  list<string>  $fulfillmentIds
     * @return array<string, array{count: int, since: string|null, deadline_at: string|null}>
     */
    public function waitingFor(array $fulfillmentIds): array
    {
        if ($fulfillmentIds === []) {
            return [];
        }
        $out = [];
        foreach (SubstitutionRequest::where('status', SubstitutionRequest::WAITING)->whereIn('order_fulfillment_id', $fulfillmentIds)
            ->selectRaw('order_fulfillment_id, count(*) as n, min(asked_at) as since, min(deadline_at) as deadline')->groupBy('order_fulfillment_id')->get() as $row) {
            $out[$row->order_fulfillment_id] = ['count' => (int) $row->n, 'since' => BusinessTime::iso($row->since), 'deadline_at' => BusinessTime::iso($row->deadline)];
        }

        return $out;
    }

    /** True while a question about this parcel waits for the customer. */
    public function isWaiting(string $fulfillmentId): bool
    {
        return SubstitutionRequest::where('order_fulfillment_id', $fulfillmentId)->where('status', SubstitutionRequest::WAITING)->exists();
    }

    private function label(?OrderItem $i, string $locale): string
    {
        if ($i === null) {
            return '';
        }
        $name = $locale === 'ar' ? ($i->name_ar ?: $i->name_en) : $i->name_en;
        $variant = $locale === 'ar' ? ($i->variant_name_ar ?: $i->variant_name_en) : $i->variant_name_en;

        return $variant ? $name.' ('.$variant.')' : $name;
    }
}
