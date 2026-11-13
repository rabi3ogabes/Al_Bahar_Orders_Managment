<?php

namespace App\Livewire\Admin\Auctions;

use App\Domain\Auctions\Models\Auction;
use App\Domain\Auctions\Models\AuctionLot;
use App\Domain\Auctions\Services\AuctionService;
use App\Livewire\Admin\Concerns\WithAdminTable;
use App\Livewire\Admin\Sales\Concerns\HasRecordWindow;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Auction catalogue: drafts, scheduled, live and past events with lot counts and quick links to the live console.
 * A row opens a quick view in a side window (`?view=<auction id>`): status, window, rules, lots and the publish step.
 * The editor itself (EN/AR content, schedule, rules, lots) is a large multi-section form and stays a page.
 */
class AuctionIndex extends Component
{
    use HasRecordWindow, WithAdminTable;

    #[Locked]
    #[Url(as: 'view', except: '')]
    public string $viewId = '';

    #[Url(except: '')]
    public string $filterStatus = '';

    #[Url(except: '')]
    public string $filterType = '';

    public function mount(): void
    {
        abort_unless($this->can('auctions.view'), 403);
        if ($this->viewId !== '') {
            $this->openView($this->viewId, quiet: true);
        }
    }

    /** Same gate as the editor page: auctions.view (checked in mount). */
    protected function findViewable(string $id): ?Model
    {
        return Auction::with(['translations', 'branches:id,code,name_en,name_ar', 'lots.winners'])->find($id);
    }

    /** Publish from the quick view; same permission and service as the editor. */
    public function publish(string $id, AuctionService $svc): void
    {
        abort_unless($this->can('auctions.approve') || $this->can('auctions.edit'), 403);
        $this->attempt(fn () => $svc->publish(auth()->user(), Auction::findOrFail($id)), __('auction_admin.published'));
    }

    public function render()
    {
        $s = trim($this->search);
        $q = Auction::query()->with(['translations'])->withCount('lots')
            ->withCount(['lots as open_lots_count' => fn ($l) => $l->whereIn('status', ['open', 'paused'])])
            ->withSum('lots as bids_total', 'bid_count')
            ->when($this->filterStatus, fn ($q, $st) => $q->where('status', $st))
            ->when($this->filterType, fn ($q, $t) => $q->where('type', $t))
            ->when($s, fn ($q) => $q->where(fn ($w) => $w->where('reference', 'ilike', "%{$s}%")->orWhereHas('translations', fn ($t) => $t->where('title', 'ilike', "%{$s}%"))))
            ->orderByRaw("CASE status WHEN 'live' THEN 0 WHEN 'paused' THEN 1 WHEN 'scheduled' THEN 2 WHEN 'draft' THEN 3 ELSE 4 END")->latest();

        return view('livewire.admin.auctions.index', [
            'auctions' => $q->paginate($this->perPage),
            'liveLots' => AuctionLot::with('auction.translations')->whereIn('status', ['open', 'paused'])->orderBy('ends_at')->limit(6)->get(),
            'canCreate' => $this->can('auctions.create'),
            'canPublish' => $this->can('auctions.approve') || $this->can('auctions.edit'),
        ])->title(__('nav.auctions'));
    }
}
