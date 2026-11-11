<?php

namespace App\Livewire\Admin\Sales;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Livewire\Admin\Concerns\WithAdminTable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Customer directory. Branch-scoped staff only see customers who ordered from their branches; personal data
 * (phone, email) is masked unless the viewer holds customers.edit.
 */
class CustomerIndex extends Component
{
    use WithAdminTable;

    #[Url(except: '')]
    public string $filterStatus = '';

    #[Url(except: '')]
    public string $filterConsent = '';

    public function mount(): void
    {
        abort_unless($this->can('customers.view'), 403);
    }

    /** Customers visible to the viewer (global: all; branch-scoped: ordered from one of their branches). */
    public static function visibleTo(User $viewer): Builder
    {
        $scope = app(AccessControl::class)->scope($viewer, 'customers.view');
        $q = User::query()->where('type', 'customer');
        if (! $scope->all) {
            $q->whereIn('id', Order::query()->select('user_id')->whereIn('id', $scope->apply(OrderFulfillment::query()->select('order_id')->getQuery(), 'branch_id')));
        }

        return $q;
    }

    public static function maskEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return $email;
        }
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).str_repeat('•', max(2, min(6, mb_strlen($local) - 1))).'@'.$domain;
    }

    public function render()
    {
        $s = trim($this->search);
        $q = self::visibleTo(auth()->user())
            ->when($s, function ($q) use ($s) {
                $digits = preg_replace('/\D+/', '', $s);
                $q->where(fn ($w) => $w->where('name', 'ilike', "%{$s}%")->orWhere('email', 'ilike', "%{$s}%")
                    ->when(strlen($digits) >= 4, fn ($x) => $x->orWhere('phone', 'like', "%{$digits}%")));
            })
            ->when($this->filterStatus, fn ($q, $st) => $q->where('status', $st))
            ->when($this->filterConsent === 'yes', fn ($q) => $q->whereNotNull('marketing_consent_at'))
            ->when($this->filterConsent === 'no', fn ($q) => $q->whereNull('marketing_consent_at'))
            ->withCount(['orders' => fn ($o) => $o->whereNotIn('status', ['pending_payment', 'cancelled'])])
            ->withSum(['orders as spend' => fn ($o) => $o->whereNotIn('status', ['pending_payment', 'cancelled'])], 'grand_total')
            ->with('loyaltyAccount:id,user_id,balance,tier')
            ->latest();

        return view('livewire.admin.sales.customers', [
            'customers' => $q->paginate($this->perPage),
            'reveal' => $this->can('customers.edit'),
        ])->title(__('nav.customers'));
    }
}
