<?php

namespace App\Livewire\Admin\Auctions;

use App\Domain\Auctions\Models\Auction;
use App\Domain\Auctions\Models\AuctionLot;
use App\Livewire\Admin\Concerns\WithAdminTable;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Auction catalogue: drafts, scheduled, live and past events with lot counts and quick links to the live console. */
class AuctionIndex extends Component
{
    use WithAdminTable;

    #[Url(except: '')]
    public string $filterStatus = '';

    #[Url(except: '')]
    public string $filterType = '';

    public function mount(): void
    {
        abort_unless($this->can('auctions.view'), 403);
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
        ])->title(__('nav.auctions'));
    }
}
