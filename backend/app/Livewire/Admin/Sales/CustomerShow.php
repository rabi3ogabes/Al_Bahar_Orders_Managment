<?php

namespace App\Livewire\Admin\Sales;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Identity\Services\TokenService;
use App\Domain\Marketing\Models\LoyaltyTransaction;
use App\Domain\Marketing\Services\LoyaltyService;
use App\Domain\Marketing\Services\ReferralService;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Customer profile: orders (branch-scoped), addresses, loyalty balance/ledger with reasoned adjustments, status and consents. */
class CustomerShow extends Component
{
    use InteractsWithAdmin, WithPagination;

    #[Locked]
    public string $customerId;

    #[Url(except: 'orders')]
    public string $tab = 'orders';

    public array $adjust = ['direction' => 'credit', 'points' => '', 'reason' => ''];

    public string $statusReason = '';

    public bool $showStatus = false;

    public string $newStatus = '';

    public function mount(User $customer): void
    {
        abort_unless($this->can('customers.view') && $customer->isCustomer(), 403);
        abort_unless(CustomerIndex::visibleTo(auth()->user())->whereKey($customer->id)->exists(), 403);
        $this->customerId = $customer->id;
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    private function customer(): User
    {
        return User::where('type', 'customer')->findOrFail($this->customerId);
    }

    public function adjustPoints(LoyaltyService $loyalty): void
    {
        abort_unless($this->canGlobally('loyalty.edit'), 403);
        $data = $this->validate([
            'adjust.direction' => 'required|in:credit,debit',
            'adjust.points' => 'required|integer|min:1|max:1000000',
            'adjust.reason' => 'required|string|min:5|max:500',
        ], [], ['adjust.points' => __('sales_admin.points'), 'adjust.reason' => __('admin.reason')])['adjust'];
        $points = (int) $data['points'] * ($data['direction'] === 'debit' ? -1 : 1);
        if ($this->attempt(fn () => $loyalty->adjust($this->customer(), $points, trim($data['reason']), auth()->user()), __('sales_admin.points_adjusted'))) {
            $this->adjust = ['direction' => 'credit', 'points' => '', 'reason' => ''];
        }
    }

    public function askStatus(string $status): void
    {
        abort_unless($this->canGlobally('customers.edit') && in_array($status, ['active', 'suspended'], true), 403);
        $this->newStatus = $status;
        $this->statusReason = '';
        $this->resetValidation();
        $this->showStatus = true;
    }

    public function changeStatus(AuditLogger $audit, TokenService $tokens): void
    {
        abort_unless($this->canGlobally('customers.edit') && in_array($this->newStatus, ['active', 'suspended'], true), 403);
        $this->validate(['statusReason' => 'required|string|min:5|max:500'], [], ['statusReason' => __('admin.reason')]);
        $c = $this->customer();
        $old = $c->status->value;
        $c->update(['status' => UserStatus::from($this->newStatus)]);
        if ($this->newStatus === 'suspended') {
            $tokens->revokeAll($c, 'suspended_by_staff');
        }
        $audit->log('customer.status_changed', $c, ['status' => $old], ['status' => $this->newStatus], ['reason' => trim($this->statusReason)]);
        $this->showStatus = false;
        $this->toast(__('sales_admin.status_updated'));
    }

    public function render(LoyaltyService $loyalty, NotificationService $notifications, ReferralService $referrals)
    {
        $c = $this->customer()->load(['addresses' => fn ($q) => $q->orderByDesc('is_default')]);
        $scope = app(AccessControl::class)->scope(auth()->user(), 'orders.view');
        $orders = Order::where('user_id', $c->id)->whereIn('id', $scope->apply(OrderFulfillment::query()->select('order_id')->getQuery(), 'branch_id'))
            ->with('fulfillments.branch:id,code')->orderByDesc('placed_at');
        $account = $loyalty->account($c);
        $stats = Order::where('user_id', $c->id)->whereNotIn('status', ['pending_payment', 'cancelled'])->selectRaw('count(*) as n, coalesce(sum(grand_total),0) as spend, max(placed_at) as last_at')->first();
        $reveal = $this->can('customers.edit');

        return view('livewire.admin.sales.customer-show', [
            'c' => $c,
            'reveal' => $reveal,
            'orders' => $this->tab === 'orders' && $this->can('orders.view') ? $orders->paginate(10, pageName: 'op') : null,
            'account' => $account,
            'ledger' => $this->tab === 'loyalty' ? LoyaltyTransaction::where('loyalty_account_id', $account->id)->orderByDesc('id')->paginate(15, pageName: 'lp') : null,
            'stats' => $stats,
            'prefs' => collect($notifications->preferences($c))->groupBy('category'),
            'referral' => $referrals->summary($c),
            'canAdjust' => $this->canGlobally('loyalty.edit'),
            'canEdit' => $this->canGlobally('customers.edit'),
            'pointValue' => $loyalty->pointValue(),
        ])->title($c->name);
    }
}
