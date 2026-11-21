<?php

namespace App\Domain\Orders\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\ReturnRequest;
use App\Domain\Orders\Models\ReturnRequestEvent;
use App\Support\DocumentNumber;
use App\Support\DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The customer's side of a return or damage claim: send it (with photos), add photos or a note while it waits for the team, cancel it before it is
 * decided. The server decides everything that matters: which pieces can be claimed (handed over, inside the claim window, not already in another
 * claim), whether a photo is needed, which branch handles it. Every call is safe to repeat: a double tap, or a retry after a lost answer, never
 * makes a second claim, a second photo or a second message.
 */
class ReturnClaims
{
    /** A claim with no submit key that repeats an identical one made this recently (a double tap) is answered with the first. */
    public const REPLAY_SECONDS = 120;

    public function __construct(
        private readonly ClaimPhotoStore $photos,
        private readonly OrderService $orders,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array{items: list<array{order_item_id: string, quantity: int}>, reason_code: string, reason?: string|null}  $data
     * @param  list<UploadedFile>  $files
     * @return array{claim: ReturnRequest, replayed: bool}
     */
    public function submit(Order $order, User $customer, array $data, array $files, ?string $key = null): array
    {
        $note = $this->note($data['reason'] ?? null);
        $this->checkFiles($data['reason_code'], $files, 0);
        $key = $key !== null && $key !== '' ? Str::limit($key, 64, '') : null;
        $lines = $this->normalise($data['items']);

        $stored = [];
        try {
            $result = DB::transaction(function () use ($order, $customer, $data, $files, $key, $note, $lines, &$stored) {
                Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if (($replay = $this->replayOf($order, $customer, $key, $data['reason_code'], $lines, $note)) !== null) {
                    return ['claim' => $replay, 'replayed' => true];
                }
                $branchId = $this->assertClaimable($order, $lines);
                $claim = ReturnRequest::create(['number' => DocumentNumber::make('RT'), 'order_id' => $order->id, 'user_id' => $customer->id, 'branch_id' => $branchId,
                    'reason_code' => $data['reason_code'], 'reason' => $note, 'items' => $lines, 'idempotency_key' => $key]);
                foreach ($files as $file) {
                    $photo = $this->photos->store($claim, $file, $customer);
                    $stored[] = $photo->path;
                }
                $this->event($claim, 'submitted', 'customer', $customer, $note, ['photos' => count($files)]);
                $this->orders->history($order, null, 'return', null, 'requested', $customer, $note, ['return' => $claim->number]);
                $this->audit->log('return.requested', $claim, [], ['type' => $claim->reason_code, 'photos' => count($files), 'lines' => count($lines)], branchId: $branchId, actor: $customer);
                $this->tell($claim, $order, $customer, 'return.received');

                return ['claim' => $claim, 'replayed' => false];
            });
        } catch (Throwable $e) {
            // The claim was rolled back: the photos it had already written must not stay behind.
            Storage::disk($this->photos->disk())->delete($stored);
            throw $e;
        }

        return $result;
    }

    /**
     * More photos and/or a note while the claim is still waiting for the team. The same photo twice, or the same note again, changes nothing.
     *
     * @param  list<UploadedFile>  $files
     */
    public function addEvidence(ReturnRequest $claim, User $customer, array $files, ?string $note): ReturnRequest
    {
        $note = $this->note($note);
        if ($files === [] && $note === null) {
            throw ValidationException::withMessages(['photos' => __('returns.evidence_required')]);
        }

        return DB::transaction(function () use ($claim, $customer, $files, $note) {
            $c = ReturnRequest::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            if (! $c->isEditable()) {
                throw DomainException::make('claim_not_editable', 409);
            }
            $had = $c->photos()->pluck('id')->all();
            $fresh = [];
            try {
                foreach ($files as $file) {
                    $photo = $this->photos->store($c, $file, $customer);
                    if (! in_array($photo->id, $had, true)) {
                        $fresh[$photo->id] = $photo->path;
                    }
                }
                // Counted after storing: a photo sent again is kept once, so a retry of an upload that already worked never trips the limit.
                $this->checkFiles($c->reason_code, [], $c->photos()->count(), requireOne: false);
            } catch (Throwable $e) {
                Storage::disk($this->photos->disk())->delete(array_values($fresh));
                throw $e;
            }
            $added = count($fresh);
            $last = $c->events()->where('kind', 'evidence_added')->get()->last();
            $newNote = $note !== null && trim((string) $last?->note) !== $note ? $note : null;
            if ($added > 0 || $newNote !== null) {
                $this->event($c, 'evidence_added', 'customer', $customer, $newNote, ['photos' => $added]);
                $this->audit->log('return.evidence_added', $c, [], ['photos' => $added, 'note' => $newNote !== null], branchId: $c->branch_id, actor: $customer);
            }

            return $c;
        });
    }

    /** The customer withdraws the claim before the team has decided. Cancelling twice is the same as once. */
    public function cancel(ReturnRequest $claim, User $customer): ReturnRequest
    {
        return DB::transaction(function () use ($claim, $customer) {
            $c = ReturnRequest::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            if ($c->status === 'cancelled') {
                return $c;
            }
            if (! $c->isEditable()) {
                throw DomainException::make('claim_not_editable', 409);
            }
            $from = $c->status;
            $c->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            $this->event($c, 'cancelled', 'customer', $customer);
            $this->orders->history(Order::findOrFail($c->order_id), null, 'return', $from, 'cancelled', $customer, null, ['return' => $c->number]);
            $this->audit->log('return.cancelled', $c, ['status' => $from], ['status' => 'cancelled'], branchId: $c->branch_id, actor: $customer);

            return $c;
        });
    }

    // ── rules ────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Every line must be one of the order's, in a delivered or collected parcel that is still inside the claim window, with no more pieces than were
     * handed over and are not already in another claim. All lines come from one branch (the branch that decides the claim).
     *
     * @param  list<array{order_item_id: string, quantity: int}>  $lines
     * @return string the branch of the lines
     */
    private function assertClaimable(Order $order, array $lines): string
    {
        $order->load('items.fulfillment');
        $claimed = [];
        foreach (ReturnRequest::where('order_id', $order->id)->holding()->get() as $open) {
            foreach ((array) $open->items as $line) {
                $claimed[$line['order_item_id'] ?? ''] = ($claimed[$line['order_item_id'] ?? ''] ?? 0) + (int) ($line['quantity'] ?? 0);
            }
        }
        $branches = [];
        foreach ($lines as $line) {
            $item = $order->items->firstWhere('id', $line['order_item_id']);
            abort_unless($item && $item->fulfillment && OrderPresenter::returnWindowOpen($item->fulfillment)
                && $line['quantity'] <= $item->quantity_handed_over - $item->quantity_returned - ($claimed[$item->id] ?? 0), 422, __('checkout.return_not_allowed'));
            $branches[$item->branch_id] = true;
        }
        if (count($branches) > 1) {
            throw ValidationException::withMessages(['items' => __('returns.one_branch')]);
        }

        return (string) array_key_first($branches);
    }

    /**
     * A damaged or wrong item cannot be judged without a picture, so it needs at least one; no claim carries more than the maximum, and a file
     * that is not a picture is refused before anything is written.
     *
     * @param  list<UploadedFile>  $files
     */
    private function checkFiles(string $type, array $files, int $already, bool $requireOne = true): void
    {
        if ($requireOne && in_array($type, ReturnRequest::EVIDENCE_REQUIRED, true) && $files === []) {
            throw ValidationException::withMessages(['photos' => __('returns.photo_required')]);
        }
        if ($already + count($files) > ReturnRequest::MAX_PHOTOS) {
            throw ValidationException::withMessages(['photos' => __('returns.photos_too_many', ['max' => ReturnRequest::MAX_PHOTOS])]);
        }
    }

    /**
     * The claim this request repeats: the same submit key (on another order the key is refused), or, with no key, an identical claim sent a moment ago
     * that nobody has looked at yet (a double tap).
     *
     * @param  list<array{order_item_id: string, quantity: int}>  $lines
     */
    private function replayOf(Order $order, User $customer, ?string $key, string $type, array $lines, ?string $note): ?ReturnRequest
    {
        $mine = ReturnRequest::where('user_id', $customer->id);
        if ($key !== null) {
            $found = (clone $mine)->where('idempotency_key', $key)->first();
            if ($found !== null && $found->order_id !== $order->id) {
                throw DomainException::make('idempotency_key_reused', 409);
            }

            return $found;
        }

        return (clone $mine)->where('order_id', $order->id)->where('reason_code', $type)->where('created_at', '>=', now()->subSeconds(self::REPLAY_SECONDS))
            ->whereIn('status', ReturnRequest::EDITABLE)->get()
            ->first(fn (ReturnRequest $c) => $this->normalise((array) $c->items) === $lines && ($c->reason === null ? null : trim($c->reason)) === $note);
    }

    /**
     * @param  list<array{order_item_id: string, quantity: int}>  $items
     * @return list<array{order_item_id: string, quantity: int}> one entry per line, sorted, so the same request always reads the same
     */
    private function normalise(array $items): array
    {
        $out = [];
        foreach ($items as $line) {
            $id = strtolower((string) $line['order_item_id']);
            $out[$id] = ['order_item_id' => $id, 'quantity' => (int) $line['quantity']];
        }
        ksort($out);

        return array_values($out);
    }

    private function note(?string $note): ?string
    {
        $note = $note !== null ? trim($note) : null;
        if ($note === null || $note === '') {
            return null;
        }
        if (mb_strlen($note) > 500) {
            throw ValidationException::withMessages(['reason' => __('returns.note_too_long', ['max' => 500])]);
        }

        return $note;
    }

    /** @param array<string, mixed> $meta */
    private function event(ReturnRequest $claim, string $kind, string $actorType, ?User $actor, ?string $note = null, array $meta = []): void
    {
        ReturnRequestEvent::create(['return_request_id' => $claim->id, 'kind' => $kind, 'actor_type' => $actorType, 'actor_id' => $actor?->id, 'note' => $note, 'meta' => $meta ?: null]);
    }

    private function tell(ReturnRequest $claim, Order $order, User $customer, string $event): void
    {
        rescue(fn () => $this->notifications->notify($customer, $event, ['order_number' => $order->number, 'return_number' => $claim->number,
            'deep_link' => 'albahar://orders/'.$order->id, 'reference' => $order->id]), report: true);
    }
}
