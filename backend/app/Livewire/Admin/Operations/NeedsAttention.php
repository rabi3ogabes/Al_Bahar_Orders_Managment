<?php

namespace App\Livewire\Admin\Operations;

use App\Domain\Fulfillment\Services\PickupService;
use App\Domain\Operations\Services\AttentionFeed;
use App\Domain\Operations\Support\OpsAge;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Livewire\Admin\Sales\Concerns\PeeksAtOrder;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Operations > Needs attention: everything that waits for a person, worst and oldest first, each line with the one thing to tap.
 * What a person sees is decided by {@see AttentionFeed}: every kind of line by its own permission and branches. The page refreshes itself
 * every 20 seconds and at once when the live order event arrives (the layout announces it as "ops-order-live").
 * An order opens in the side window of this page (`?view=`), so nobody leaves the list to deal with it.
 */
class NeedsAttention extends Component
{
    use InteractsWithAdmin;
    use PeeksAtOrder {
        peekOrder as private openPeek;
    }

    public const SCOPES = ['all', 'mine'];

    #[Url(except: 'all')]
    public string $scope = 'all';

    /** One branch only; it can only narrow what the person may see. */
    #[Url(except: '')]
    public string $branch = '';

    /** The order shown in the side window; mirrors the window state so reload, sharing and Back work. */
    #[Locked]
    #[Url(as: 'view', except: '')]
    public string $viewId = '';

    public function mount(): void
    {
        abort_unless(AttentionFeed::canSee(auth()->user()), 403);
        if (! in_array($this->scope, self::SCOPES, true)) {
            $this->scope = 'all';
        }
        if ($this->viewId !== '') {
            $this->peekOrder($this->viewId, quiet: true);
        }
    }

    public function setScope(string $scope): void
    {
        $this->scope = in_array($scope, self::SCOPES, true) ? $scope : 'all';
    }

    public function setBranch(string $branch): void
    {
        $this->branch = $this->branch === $branch ? '' : $branch;
    }

    public function peekOrder(string $id, bool $quiet = false): void
    {
        $this->openPeek($id, $quiet);
        $this->viewId = $this->showOrder ? $this->orderPeekId : '';
    }

    public function closeOrderPeek(): void
    {
        $this->showOrder = false;
        $this->viewId = '';
    }

    /**
     * Makes a fresh pickup pass in place (the customer's old one stops working and the customer is told), for a part that waits for pickup. The pickup
     * service refuses a part that is not waiting any more, and never takes one that was handed over in pieces back to "ready".
     */
    public function reissuePass(string $fulfillmentId, PickupService $pickup): void
    {
        $f = OrderFulfillment::query()->where('type', 'pickup')->where('status', 'pickup_ready')->findOrFail($fulfillmentId);
        $this->authorizeBranch('pickup.edit', $f->branch_id);
        $this->attempt(fn () => $pickup->reissue($f, auth()->user(), 'needs_attention'), __('ops_live.reissued'));
    }

    public function render(AttentionFeed $feed)
    {
        $list = $feed->for(auth()->user(), $this->branch !== '' ? $this->branch : null, $this->scope === 'mine');
        $groups = [];
        foreach ([OpsAge::RED, OpsAge::AMBER, OpsAge::OK] as $state) {
            $groups[$state] = $list->items->where('state', $state)->values();
        }

        return view('livewire.admin.operations.needs-attention', [
            'list' => $list,
            'groups' => $groups,
            'counts' => $list->counts(),
            'branchKnown' => collect($list->branches)->contains('id', $this->branch),
        ])->title(__('ops_live.page.title'));
    }
}
