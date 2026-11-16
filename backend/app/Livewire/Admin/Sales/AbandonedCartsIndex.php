<?php

namespace App\Livewire\Admin\Sales;

use App\Domain\Branches\Models\Branch;
use App\Domain\Checkout\Services\AbandonedCartService;
use App\Domain\Identity\Services\AccessControl;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Support\DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Abandoned orders: customer carts idle for 30 minutes or more. KPI strip, filters kept in the URL (idle time, search, coupon, branch),
 * newest idle first with keyset paging (25 per page), a row action menu (Open, Send reminder, Discard) and the cart in a side window
 * (see AbandonedCartActions) where staff fix the cart and remind the customer or place the order for them.
 *
 * `orders.view` opens the page; every change needs `orders.create` and is checked again by AbandonedCartService.
 */
class AbandonedCartsIndex extends Component
{
    use AbandonedCartActions;
    use InteractsWithAdmin;

    /** Idle for at least: 30m | 1h | 24h | 7d. */
    #[Url(except: '30m')]
    public string $idle = '30m';

    #[Url(except: '')]
    public string $search = '';

    #[Url(as: 'coupon', except: false)]
    public bool $withCoupon = false;

    #[Url(except: '')]
    public string $branch = '';

    /** Keyset cursor of the page being shown ('' = first page); an invalid one simply means the first page. */
    #[Url(as: 'after', except: '')]
    public string $after = '';

    /** @var list<string> cursors of the pages before this one, so "Previous" goes back without OFFSET */
    public array $trail = [];

    public function mount(): void
    {
        abort_unless($this->svc()->canView(auth()->user()), 403);
        if (! isset(AbandonedCartService::IDLE[$this->idle])) {
            $this->idle = '30m';
        }
        if ($this->branch !== '' && ! Str::isUuid($this->branch)) {
            $this->branch = '';
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['idle', 'search', 'withCoupon', 'branch'], true)) {
            $this->after = '';
            $this->trail = [];
        }
        if ($property === 'idle' && ! isset(AbandonedCartService::IDLE[$this->idle])) {
            $this->idle = '30m';
        }
    }

    public function setIdle(string $idle): void
    {
        $this->idle = isset(AbandonedCartService::IDLE[$idle]) ? $idle : '30m';
        $this->after = '';
        $this->trail = [];
    }

    public function toggleCoupon(): void
    {
        $this->withCoupon = ! $this->withCoupon;
        $this->after = '';
        $this->trail = [];
    }

    public function clearFilters(): void
    {
        $this->reset('idle', 'search', 'withCoupon', 'branch', 'after', 'trail');
    }

    public function nextPage(string $cursor): void
    {
        $this->trail[] = $this->after;
        $this->after = $cursor; // a made-up cursor only moves the position (the scope is applied to every page) and falls back to page one when invalid
    }

    public function previousPage(): void
    {
        $this->after = (string) array_pop($this->trail);
    }

    /** The window's data, or the plain reason it cannot be shown any more (the customer is shopping again, it was ordered or discarded). */
    private function windowData(): ?array
    {
        if (! $this->showCart || $this->cartId === '') {
            return null;
        }
        try {
            $data = $this->svc()->present(auth()->user(), $this->cartId);
        } catch (DomainException $e) {
            return ['gone' => $e->getMessage()];
        } catch (AuthorizationException) {
            return ['gone' => __('abandoned_admin.err_cart_unavailable')];
        }
        $this->stamp = $data['stamp'];
        $data['results'] = [];
        if ($this->addOpen && $this->svc()->canChange(auth()->user())) {
            try {
                $data['results'] = $this->svc()->searchProducts(auth()->user(), $this->cartId, $this->addSearch);
            } catch (DomainException) {
                $data['results'] = []; // rate limited: the list stays as it was
            }
        }

        return $data;
    }

    public function render()
    {
        $user = auth()->user();
        $svc = $this->svc();
        $filters = ['idle' => $this->idle, 'search' => $this->search, 'coupon' => $this->withCoupon, 'branch' => $this->branch];
        $page = $svc->page($user, $filters, $this->after);
        $scope = app(AccessControl::class)->scope($user, 'orders.view');

        return view('livewire.admin.sales.abandoned', [
            'rows' => $page['rows'],
            'next' => $page['next'],
            'total' => $svc->filtered($user, $filters)->count(),
            'stats' => $svc->stats($user, $this->branch),
            'branches' => Branch::query()->when(! $scope->all, fn ($q) => $q->whereIn('id', $scope->branchIds))->orderBy('sort_order')->get(['id', 'code', 'name_en', 'name_ar']),
            'window' => $this->windowData(),
            'canChange' => $svc->canChange($user),
            'filtered' => $this->idle !== '30m' || $this->search !== '' || $this->withCoupon || $this->branch !== '',
        ])->title(__('abandoned_admin.title'));
    }
}
