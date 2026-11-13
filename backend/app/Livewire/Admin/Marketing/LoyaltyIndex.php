<?php

namespace App\Livewire\Admin\Marketing;

use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Models\LoyaltyAccount;
use App\Domain\Marketing\Models\LoyaltyTransaction;
use App\Domain\Marketing\Models\Referral;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Concerns\WithAdminTable;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Loyalty programme: earn/redeem rules (Settings), the points ledger across customers, and referrals. */
class LoyaltyIndex extends Component
{
    use WithAdminTable;

    public const RULES = ['enabled', 'points_per_qar', 'redeem_value_per_point', 'max_redeem_percent', 'points_expiry_days'];

    #[Url(except: 'ledger')]
    public string $tab = 'ledger';

    #[Url(except: '')]
    public string $filterType = '';

    public array $rules = [];

    public bool $editingRules = false;

    public function mount(Settings $settings): void
    {
        abort_unless($this->can('loyalty.view'), 403);
        $this->loadRules($settings);
    }

    private function loadRules(Settings $settings): void
    {
        foreach (self::RULES as $k) {
            $v = $settings->get('loyalty.'.$k);
            $this->rules[$k] = $k === 'enabled' ? (bool) $v : (string) $v;
        }
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function saveRules(Settings $settings): void
    {
        abort_unless($this->canGlobally('loyalty.override'), 403);
        $this->validate([
            'rules.enabled' => 'boolean', 'rules.points_per_qar' => 'required|integer|min:0|max:100',
            'rules.redeem_value_per_point' => 'required|numeric|min:0|max:10', 'rules.max_redeem_percent' => 'required|integer|min:0|max:100',
            'rules.points_expiry_days' => 'required|integer|min:1|max:3650',
        ], [], collect(self::RULES)->mapWithKeys(fn ($k) => ['rules.'.$k => __('marketing_admin.rule_'.$k)])->all());
        foreach (self::RULES as $k) {
            $v = $this->rules[$k];
            $settings->set('loyalty.'.$k, match ($k) {
                'enabled' => (bool) $v, 'redeem_value_per_point' => number_format((float) $v, 2, '.', ''), default => (int) $v
            });
        }
        $this->editingRules = false;
        $this->loadRules($settings);
        $this->toast(__('admin.saved'));
    }

    public function render()
    {
        $s = trim($this->search);
        $customer = fn ($q) => $q->when($s, fn ($q) => $q->whereIn('loyalty_account_id', LoyaltyAccount::whereIn('user_id', User::where('type', 'customer')->where(fn ($u) => $u->where('name', 'ilike', "%{$s}%")
            ->when(strlen(preg_replace('/\D+/', '', $s)) >= 4, fn ($x) => $x->orWhere('phone', 'like', '%'.preg_replace('/\D+/', '', $s).'%')))->select('id'))->select('id')));
        $since = now()->subDays(30);

        $ledger = $this->tab === 'ledger' ? LoyaltyTransaction::query()->tap($customer)->when($this->filterType, fn ($q, $t) => $q->where('type', $t))
            ->orderByDesc('id')->paginate($this->perPage) : null;

        return view('livewire.admin.marketing.loyalty', [
            'ledger' => $ledger,
            'accounts' => $ledger ? LoyaltyAccount::whereIn('id', $ledger->pluck('loyalty_account_id')->unique())->get()->keyBy('id') : collect(),
            'names' => $ledger ? User::whereIn('id', LoyaltyAccount::whereIn('id', $ledger->pluck('loyalty_account_id')->unique())->select('user_id'))->pluck('name', 'id') : collect(),
            'referrals' => $this->tab === 'referrals' ? Referral::query()->orderByDesc('created_at')->paginate($this->perPage) : null,
            'refUsers' => $this->tab === 'referrals' ? User::whereIn('id', Referral::query()->latest()->limit(100)->get(['referrer_id', 'referee_id'])->flatMap(fn ($r) => [$r->referrer_id, $r->referee_id]))->pluck('name', 'id') : collect(),
            'stats' => [
                'outstanding' => (int) LoyaltyAccount::sum('balance'),
                'members' => LoyaltyAccount::where('balance', '>', 0)->count(),
                'earned' => (int) LoyaltyTransaction::whereIn('type', ['earn', 'referral'])->where('created_at', '>=', $since)->sum('points'),
                'redeemed' => (int) -LoyaltyTransaction::where('type', 'redeem')->where('created_at', '>=', $since)->sum('points'),
            ],
            'tiers' => LoyaltyAccount::select('tier', DB::raw('count(*) as n'))->groupBy('tier')->pluck('n', 'tier'),
            'canEditRules' => $this->canGlobally('loyalty.override'),
        ])->title(__('nav.loyalty'));
    }
}
