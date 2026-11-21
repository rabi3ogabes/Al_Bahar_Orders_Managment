<?php

namespace App\Domain\Orders\Services;

use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\ReturnRequest;
use App\Domain\Orders\Models\ReturnRequestEvent;
use App\Domain\Payments\Models\Refund;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * A return or damage claim as the customer reads it: where it stands, what they sent (photos, note, lines), what the team answered, and a compact
 * timeline (Received → Under review → Approved or Rejected → pickup or bring it to the branch → Item received → Refunded). The words are the
 * apps' own (they translate the `key`s); only the team's free text and the amounts come from the server.
 */
class ReturnClaimPresenter
{
    public function __construct(private readonly OrderPresenter $orders) {}

    /** @return array<string, mixed> */
    public function detail(ReturnRequest $r): array
    {
        $r->loadMissing(['photos', 'branch', 'events']);
        $refund = $r->refund_id ? Refund::find($r->refund_id) : null;
        $note = $r->events->first(fn (ReturnRequestEvent $e) => $e->kind === 'submitted')?->note ?? $r->reason;
        $type = $r->reason_code === 'not_as_described' ? 'quality' : $r->reason_code;

        return [
            'id' => $r->id, 'number' => $r->number, 'order_id' => $r->order_id, 'status' => $r->status, 'status_label' => __('status.'.$r->status),
            'kind' => $type, 'reason_code' => $r->reason_code, 'reason_label' => __('returns.type.'.$r->reason_code),
            'evidence_required' => $r->needsEvidence(), 'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            'requested_at' => BusinessTime::iso($r->created_at), 'decided_at' => BusinessTime::iso($r->decided_at),
            'decision_note' => $r->decision_note !== null && trim($r->decision_note) !== '' ? trim($r->decision_note) : null,
            'items' => $this->lines($r),
            'photos' => $r->photos->map(fn ($p) => ['id' => $p->id, 'path' => 'returns/'.$r->id.'/photos/'.$p->id, 'at' => BusinessTime::iso($p->created_at)])->values()->all(),
            'max_photos' => ReturnRequest::MAX_PHOTOS,
            // What the customer wrote after sending it (extra photos or notes while it waited for the team), oldest first.
            'messages' => $r->events->where('kind', 'evidence_added')->filter(fn (ReturnRequestEvent $e) => trim((string) $e->note) !== '')
                ->map(fn (ReturnRequestEvent $e) => ['text' => (string) $e->note, 'at' => BusinessTime::iso($e->created_at)])->values()->all(),
            'can_add_evidence' => $r->isEditable(), 'can_cancel' => $r->isEditable(),
            'method' => $r->return_method, 'method_note' => $r->method_note,
            'branch' => $r->branch ? ['id' => $r->branch->id, 'name' => $r->branch->localized('name'), 'phone' => $r->branch->phone] : null,
            'refund' => $refund ? ['status' => $refund->status, 'amount' => Money::of($refund->amount, $refund->currency)->jsonSerialize(), 'method' => $refund->method,
                'processed_at' => BusinessTime::iso($refund->processed_at)] : null,
            'timeline' => $this->timeline($r, $refund),
        ];
    }

    /**
     * The claim's steps in order, each `done`, `current` (what the claim is waiting for), `upcoming`, or `stopped` (where it ended: rejected, cancelled).
     *
     * @return list<array{key: string, state: string, at: string|null, detail: string|null}>
     */
    public function timeline(ReturnRequest $r, ?Refund $refund): array
    {
        $step = fn (string $key, string $state, mixed $at = null, ?string $detail = null) => ['key' => $key, 'state' => $state, 'at' => BusinessTime::iso($at), 'detail' => $detail];
        $status = $r->status;
        $reviewed = $status !== 'requested';
        $steps = [$step('received', $status === 'requested' ? 'current' : 'done', $r->created_at)];

        if ($status === 'cancelled') {
            $steps[] = $step('cancelled', 'stopped', $r->cancelled_at);

            return $steps;
        }
        $steps[] = $step('under_review', $status === 'under_review' ? 'current' : ($reviewed ? 'done' : 'upcoming'), $r->review_started_at ?? ($reviewed ? $r->decided_at : null));
        if ($status === 'rejected') {
            $steps[] = $step('rejected', 'stopped', $r->decided_at, $this->text($r->decision_note));

            return $steps;
        }
        $decided = in_array($status, ['approved', 'received', 'refunded', 'closed'], true);
        $steps[] = $decided ? $step('approved', 'done', $r->decided_at, $this->text($r->decision_note)) : $step('decision', 'upcoming');

        $goodsBack = in_array($r->return_method, ['pickup', 'branch'], true);
        if ($goodsBack || ! $decided) {
            $handover = $r->return_method === 'branch' ? 'branch' : 'pickup';
            $steps[] = $decided ? $step($handover, 'done', $r->decided_at, $this->text($r->method_note)) : $step('handover', 'upcoming');
            $received = in_array($status, ['received', 'refunded', 'closed'], true);
            $steps[] = $step('item_received', $received ? 'done' : ($status === 'approved' ? 'current' : 'upcoming'), $r->received_at);
        }
        if ($status === 'closed' && $refund === null) {
            return $steps; // closed without any money going back: there is no refund step to show
        }
        $refundWaits = in_array($status, ['received', 'refunded', 'closed'], true) || ($status === 'approved' && $r->return_method === 'none');
        $steps[] = $step('refunded', $refund?->status === 'processed' ? 'done' : ($refundWaits ? 'current' : 'upcoming'), $refund?->processed_at);

        return $steps;
    }

    /** @return list<array<string, mixed>> */
    private function lines(ReturnRequest $r): array
    {
        $ids = collect((array) $r->items)->pluck('order_item_id')->all();
        /** @var Collection<string, OrderItem> $items */
        $items = OrderItem::whereIn('id', $ids)->get()->keyBy('id');

        return collect((array) $r->items)->map(function (array $line) use ($items) {
            $i = $items->get($line['order_item_id'] ?? '');

            return ['order_item_id' => $line['order_item_id'] ?? null, 'quantity' => (int) ($line['quantity'] ?? 0), 'name' => $i?->name() ?? '', 'variant_name' => $i?->variantName(),
                'image_url' => $this->orders->imageUrl($i?->image_path)];
        })->values()->all();
    }

    private function text(?string $s): ?string
    {
        return $s !== null && trim($s) !== '' ? trim($s) : null;
    }
}
