<?php

namespace App\Livewire\Admin\Operations;

use App\Broadcasting\BranchChannel;
use App\Domain\Operations\Services\LiveBoard;
use App\Domain\Orders\Enums\FulfillmentStatus;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Services\OrderService;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Livewire\Admin\Sales\Concerns\PeeksAtOrder;
use App\Support\BusinessTime;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Operations > Live board: every open branch part of an order in the lane that says where it stands, refreshed by itself
 * (see {@see LiveBoard} for what each lane means and what it reads). A card opens the order in the side window of this page (`?view=`);
 * "Accept" does what the order window's own Accept does, with the same permission and audit trail, and "Assign driver" goes to the Deliveries page.
 *
 * `?wall=1` is the wall display: the same board without the menu and the top bar, in large type, for a screen in the branch. It is a display, so it has no
 * action buttons. The layout reads the same address to leave its chrome out (resources/views/layouts/admin.blade.php).
 * The page refreshes on the live order events the layout already listens to, on the branch channels (see `channels`) and on a timer that
 * the browser makes faster when the socket is down (resources/js/ops-board.js).
 */
class LiveBoardPage extends Component
{
    use InteractsWithAdmin;
    use PeeksAtOrder {
        peekOrder as private openPeek;
    }

    /** One branch only; it can only narrow what the person may see. */
    #[Url(except: '')]
    public string $branch = '';

    /** The wall display (large type, no menu, no buttons). */
    #[Locked]
    #[Url(except: false)]
    public bool $wall = false;

    /** The order shown in the side window; mirrors the window state so reload, sharing and Back work. */
    #[Locked]
    #[Url(as: 'view', except: '')]
    public string $viewId = '';

    /** @var list<string> lanes opened to show more cards */
    public array $open = [];

    /**
     * The branch channels this person may listen to for part and delivery changes (the staff apps' rule: they work at the branch and see its orders).
     * Worked out once when the page opens; the browser joins them and refreshes the board when one speaks.
     *
     * @var list<string>
     */
    #[Locked]
    public array $channels = [];

    public function mount(): void
    {
        abort_unless($this->can('orders.view'), 403);
        $gate = app(BranchChannel::class);
        $this->channels = app(LiveBoard::class)->branches(auth()->user())->keys()->filter(fn (string $id) => $gate->join(auth()->user(), $id))->map(fn (string $id) => 'branches.'.$id)->values()->all();
        if ($this->viewId !== '' && ! $this->wall) {
            $this->peekOrder($this->viewId, quiet: true);
        }
    }

    public function setBranch(string $branch): void
    {
        $this->branch = $this->branch === $branch ? '' : $branch;
    }

    /** Opens a lane to show more of its cards, or folds it again. */
    public function toggleLane(string $lane): void
    {
        if (! in_array($lane, LiveBoard::LANES, true)) {
            return;
        }
        $this->open = in_array($lane, $this->open, true) ? array_values(array_diff($this->open, [$lane])) : [...$this->open, $lane];
    }

    public function peekOrder(string $id, bool $quiet = false): void
    {
        if ($this->wall) {
            return;
        }
        $this->openPeek($id, $quiet);
        $this->viewId = $this->showOrder ? $this->orderPeekId : '';
    }

    public function closeOrderPeek(): void
    {
        $this->showOrder = false;
        $this->viewId = '';
    }

    /** The window was closed with Esc, the scrim or Back: the address forgets it too (on the next request, which is at most a refresh away). */
    public function updatedShowOrder(bool $open): void
    {
        if (! $open) {
            $this->viewId = '';
        }
    }

    /**
     * The card's Accept: the same step as "Accept" in the order window (the order desk, orders.edit, at the part's branch), by the same service, so the
     * stock, the audit trail, the alerts and the customer's message are exactly the same. A part that is not waiting to be accepted any more
     * (someone else was quicker) is simply refused with a plain message.
     */
    public function acceptPart(string $partId, OrderService $orders): void
    {
        abort_if($this->wall, 403);
        $f = Str::isUuid($partId) ? OrderFulfillment::query()->where('status', FulfillmentStatus::Pending->value)->with('order:id,number')->find($partId) : null;
        if ($f === null) {
            $this->toast(__('drawer_sales.not_available'), 'error');

            return;
        }
        $this->authorizeBranch('orders.view', $f->branch_id);
        $this->authorizeBranch('orders.edit', $f->branch_id);
        $this->attempt(fn () => $orders->transition($f, FulfillmentStatus::Accepted, auth()->user()), __('ops_board.accepted_done', ['number' => (string) $f->order?->number]));
    }

    public function render(LiveBoard $board)
    {
        $data = $board->for(auth()->user(), $this->branch !== '' ? $this->branch : null, $this->open);

        return view('livewire.admin.operations.live-board', [
            'board' => $data,
            'counts' => $data->counts(),
            'branchKnown' => collect($data->branches)->contains('id', $this->branch),
            'renderedAt' => BusinessTime::now(),
        ])->title(__('ops_board.page.title'));
    }
}
