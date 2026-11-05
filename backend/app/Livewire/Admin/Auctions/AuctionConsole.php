<?php

namespace App\Livewire\Admin\Auctions;

use App\Domain\Auctions\Models\AuctionBid;
use App\Domain\Auctions\Models\AuctionBidder;
use App\Domain\Auctions\Models\AuctionLot;
use App\Domain\Auctions\Models\AuctionWinner;
use App\Domain\Auctions\Services\LotService;
use App\Domain\Audit\Services\AuditLogger;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Support\Money;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Auctioneer console for one lot: live bid feed (Reverb channel auction-lot.{id}, always resynced from the server),
 * server clock, open/pause/resume/hammer/cancel per LotService, bid retraction, bidder blocking and winner follow-up.
 */
class AuctionConsole extends Component
{
    use InteractsWithAdmin;

    #[Locked]
    public string $lotId;

    public bool $showDialog = false;

    /** open | pause | cancel | retract | block */
    public ?string $dialog = null;

    #[Locked]
    public ?string $target = null;

    public string $reason = '';

    public string $duration = '300';

    public function mount(AuctionLot $lot): void
    {
        abort_unless($this->can('auctions.view'), 403);
        $this->lotId = $lot->id;
    }

    private function lot(): AuctionLot
    {
        return AuctionLot::findOrFail($this->lotId);
    }

    /** Event hook for the realtime listener: just re-render from the server's truth. */
    public function sync(): void {}

    public function ask(string $dialog, ?string $target = null): void
    {
        abort_unless(in_array($dialog, ['open', 'pause', 'cancel', 'retract', 'block'], true), 422);
        abort_unless($this->can(in_array($dialog, ['retract', 'block'], true) ? 'auctions.override' : 'auctions.edit'), 403);
        $this->dialog = $dialog;
        $this->target = $target;
        $this->reason = '';
        $this->duration = '300';
        $this->resetValidation();
        $this->showDialog = true;
    }

    public function confirmDialog(LotService $lots, AuditLogger $audit): void
    {
        $u = auth()->user();
        $lot = $this->lot();
        if ($this->dialog === 'open') {
            abort_unless($this->can('auctions.edit'), 403);
            $this->validate(['duration' => 'nullable|integer|min:30|max:604800'], [], ['duration' => __('auction_admin.duration')]);
            $ok = $this->attempt(fn () => $lots->open($lot, $u, (int) $this->duration ?: null), __('auction_admin.lot_opened'));
        } else {
            $this->validate(['reason' => 'required|string|min:3|max:255'], [], ['reason' => __('admin.reason')]);
            $reason = trim($this->reason);
            $ok = match ($this->dialog) {
                'pause' => $this->attempt(fn () => $lots->pause($lot, $u, $reason), __('auction_admin.lot_paused')),
                'cancel' => $this->attempt(fn () => $lots->cancel($lot, $u, $reason), __('auction_admin.lot_cancelled')),
                'retract' => $this->attempt(fn () => $lots->retractBid(AuctionBid::where('auction_lot_id', $lot->id)->findOrFail((string) $this->target), $u, $reason), __('auction_admin.bid_retracted')),
                'block' => $this->blockBidder($lot, $reason, $audit),
                default => abort(422),
            };
        }
        if ($ok) {
            $this->showDialog = false;
        }
    }

    private function blockBidder(AuctionLot $lot, string $reason, AuditLogger $audit): bool
    {
        abort_unless($this->can('auctions.override'), 403);
        $bidder = AuctionBidder::where('auction_id', $lot->auction_id)->where('user_id', (string) $this->target)->firstOrFail();
        $bidder->update(['status' => 'blocked', 'blocked_reason' => $reason, 'blocked_by' => auth()->id()]);
        $audit->log('auction.bidder_blocked', $bidder, [], ['reason' => $reason]);
        $this->toast(__('auction_admin.bidder_blocked'));

        return true;
    }

    public function resume(LotService $lots): void
    {
        abort_unless($this->can('auctions.edit'), 403);
        $this->attempt(fn () => $lots->resume($this->lot(), auth()->user()), __('auction_admin.lot_resumed'));
    }

    public function hammer(LotService $lots): void
    {
        abort_unless($this->can('auctions.edit'), 403);
        $this->attempt(fn () => $lots->close($this->lot(), 'hammer', actor: auth()->user()), __('auction_admin.lot_closed'));
    }

    /** Apply the published unpaid-winner policy to every overdue winner now (same job the scheduler runs). */
    public function processOverdue(LotService $lots): void
    {
        abort_unless($this->can('auctions.override'), 403);
        $n = $lots->defaultUnpaidWinners();
        $this->toast(trans_choice('auction_admin.overdue_processed', $n, ['count' => $n]), $n ? 'success' : 'warning');
    }

    public function render()
    {
        $lot = AuctionLot::with(['auction.translations', 'products.variant.product.translations', 'products.branch:id,code'])->findOrFail($this->lotId);
        $lot->makeVisible('reserve_price');
        $bids = $lot->bids()->with('user:id,name,phone')->orderByDesc('bid_number')->limit(100)->get();
        $paddles = AuctionBidder::where('auction_id', $lot->auction_id)->get()->keyBy('user_id');
        $winners = AuctionWinner::where('auction_lot_id', $lot->id)->with('user:id,name,phone')->orderBy('rank')->get();

        return view('livewire.admin.auctions.console', [
            'lot' => $lot,
            'bids' => $bids,
            'paddles' => $paddles,
            'winners' => $winners,
            'autoBids' => $lot->autoBids()->with('user:id,name')->where('status', 'active')->orderByDesc('max_amount')->get(),
            'events' => $lot->events()->reorder()->orderByDesc('sequence')->limit(12)->get(),
            'serverNow' => now(),
            'reserveMet' => $lot->reserve_price === null ? null : ($lot->current_price !== null && Money::of($lot->current_price)->greaterThanOrEqual(Money::of($lot->reserve_price))),
            'canEdit' => $this->can('auctions.edit'),
            'canOverride' => $this->can('auctions.override'),
            'overdue' => $winners->contains(fn ($w) => in_array($w->status, ['pending_payment', 'pending_confirmation'], true) && $w->payment_due_at?->isPast()),
        ])->title(__('auction_admin.console').' · '.$lot->localized('title'));
    }
}
