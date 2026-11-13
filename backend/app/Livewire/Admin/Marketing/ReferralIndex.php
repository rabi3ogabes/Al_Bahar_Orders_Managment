<?php

namespace App\Livewire\Admin\Marketing;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Marketing\Models\Referral;
use App\Domain\Marketing\Services\LoyaltyService;
use App\Domain\Marketing\Services\ReferralService;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Concerns\WithAdminTable;
use App\Support\Money;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Referral programme: on/off switch, reward rules, share link and message, stats and the list of referrals. */
class ReferralIndex extends Component
{
    use WithAdminTable;

    private const CFG = ['referrer_type', 'referrer_amount', 'referrer_promotion_id', 'referrer_prefix', 'referrer_valid_hours',
        'friend_type', 'friend_amount', 'friend_promotion_id', 'friend_prefix', 'friend_valid_hours', 'min_order_amount', 'max_rewards_per_referrer', 'validity_days'];

    private const SHARE = ['share_link', 'share_message_en', 'share_message_ar'];

    #[Url(except: 'program')]
    public string $tab = 'program';

    #[Url(except: '')]
    public string $filterStatus = '';

    public array $cfg = [];

    public array $share = [];

    #[Locked]
    public array $baseCfg = [];

    #[Locked]
    public array $baseShare = [];

    public bool $showReject = false;

    #[Locked]
    public ?string $rejectId = null;

    public string $rejectReason = '';

    public function mount(ReferralService $svc): void
    {
        abort_unless($this->can('marketing.view'), 403);
        $this->load($svc);
    }

    private function load(ReferralService $svc): void
    {
        $this->cfg = collect(self::CFG)->mapWithKeys(fn ($k) => [$k => (string) ($svc->setting($k) ?? '')])->all();
        $this->share = collect(self::SHARE)->mapWithKeys(fn ($k) => [$k => (string) ($svc->setting($k) ?? '')])->all();
        $this->baseCfg = $this->cfg;
        $this->baseShare = $this->share;
    }

    public function toggle(Settings $settings, AuditLogger $audit): void
    {
        abort_unless($this->can('marketing.edit'), 403);
        $on = ! (bool) $settings->get('referrals.enabled', false);
        $settings->set('referrals.enabled', $on);
        $audit->log('marketing.referrals_'.($on ? 'enabled' : 'disabled'), 'referrals');
        $this->toast($on ? __('referrals_admin.turned_on') : __('referrals_admin.turned_off'));
    }

    public function saveRewards(Settings $settings, AuditLogger $audit, ReferralService $svc): void
    {
        abort_unless($this->can('marketing.edit'), 403);
        $promo = fn ($side) => ['nullable', 'required_if:cfg.'.$side.'_type,coupon', 'exists:promotions,id'];
        $d = $this->validate([
            'cfg.referrer_type' => 'required|in:balance,coupon', 'cfg.friend_type' => 'required|in:none,balance,coupon',
            'cfg.referrer_amount' => ['nullable', 'required_if:cfg.referrer_type,balance', 'numeric', 'min:0.5', 'max:100000'],
            'cfg.friend_amount' => ['nullable', 'required_if:cfg.friend_type,balance', 'numeric', 'min:0.5', 'max:100000'],
            'cfg.referrer_promotion_id' => $promo('referrer'), 'cfg.friend_promotion_id' => $promo('friend'),
            'cfg.referrer_prefix' => 'nullable|alpha_num|max:12', 'cfg.friend_prefix' => 'nullable|alpha_num|max:12',
            'cfg.referrer_valid_hours' => 'required|integer|min:1|max:8760', 'cfg.friend_valid_hours' => 'required|integer|min:1|max:8760',
            'cfg.min_order_amount' => 'required|numeric|min:0|max:1000000', 'cfg.max_rewards_per_referrer' => 'required|integer|min:0|max:100000',
            'cfg.validity_days' => 'required|integer|min:1|max:365',
        ], [], collect(self::CFG)->mapWithKeys(fn ($k) => ['cfg.'.$k => __('referrals_admin.f_'.$k)])->all())['cfg'];
        foreach ([['referrer', 'balance'], ['friend', 'balance']] as [$s]) {
            if ($d[$s.'_type'] === 'coupon' && ! Promotion::whereKey($d[$s.'_promotion_id'])->where('requires_coupon', true)->where('is_active', true)->exists()) {
                $this->addError('cfg.'.$s.'_promotion_id', __('referrals_admin.promotion_invalid'));

                return;
            }
        }
        foreach (self::CFG as $k) {
            $v = $d[$k] ?? '';
            $settings->set('referrals.'.$k, match (true) {
                in_array($k, ['referrer_amount', 'friend_amount', 'min_order_amount'], true) => number_format((float) $v, 2, '.', ''),
                in_array($k, ['referrer_valid_hours', 'friend_valid_hours', 'max_rewards_per_referrer', 'validity_days'], true) => (int) $v,
                str_ends_with($k, '_promotion_id') => $v !== '' ? $v : null,
                default => $v,
            });
        }
        $audit->log('marketing.referrals_rewards_updated', 'referrals', array_intersect_key($this->baseCfg, array_flip(self::CFG)), $d);
        $this->load($svc);
        $this->toast(__('admin.saved'));
    }

    public function saveShare(Settings $settings, AuditLogger $audit, ReferralService $svc): void
    {
        abort_unless($this->can('marketing.edit'), 403);
        $d = $this->validate([
            'share.share_link' => ['nullable', 'url:https', 'max:300'], 'share.share_message_en' => 'required|string|max:400', 'share.share_message_ar' => 'required|string|max:400',
        ], [], collect(self::SHARE)->mapWithKeys(fn ($k) => ['share.'.$k => __('referrals_admin.f_'.$k)])->all())['share'];
        foreach ($d as $k => $v) {
            $settings->set('referrals.'.$k, $k === 'share_link' ? ($v ?: null) : $v);
        }
        $audit->log('marketing.referrals_share_updated', 'referrals', $this->baseShare, $d);
        $this->load($svc);
        $this->toast(__('admin.saved'));
    }

    public function askReject(string $id): void
    {
        abort_unless($this->can('marketing.edit'), 403);
        $this->rejectId = Referral::where('status', 'pending')->findOrFail($id)->id;
        $this->rejectReason = '';
        $this->resetValidation();
        $this->showReject = true;
    }

    public function reject(ReferralService $svc): void
    {
        abort_unless($this->can('marketing.edit'), 403);
        $this->validate(['rejectReason' => 'required|string|min:3|max:250'], [], ['rejectReason' => __('admin.reason')]);
        $this->attempt(function () use ($svc) {
            $svc->reject(Referral::findOrFail($this->rejectId), auth()->user(), $this->rejectReason);
            $this->showReject = false;
        }, __('referrals_admin.rejected_ok'));
    }

    public function render(ReferralService $svc, Settings $settings)
    {
        $s = trim($this->search);
        $q = Referral::query()->with(['referrer:id,name', 'referee:id,name', 'qualifyingOrder:id,number'])
            ->when($this->filterStatus, fn ($q, $st) => $q->where('status', $st))
            ->when($s, fn ($q) => $q->where(fn ($w) => $w->where('code', 'ilike', "%{$s}%")
                ->orWhereIn('referrer_id', User::where('name', 'ilike', "%{$s}%")->select('id'))->orWhereIn('referee_id', User::where('name', 'ilike', "%{$s}%")->select('id'))));
        $counts = Referral::selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status');
        $value = Money::of((string) (Referral::where('status', 'rewarded')->sum('referrer_reward_value') + Referral::where('status', 'rewarded')->sum('referee_reward_value')));
        $perPoint = (float) $settings->get('loyalty.redeem_value_per_point', '0.05');

        return view('livewire.admin.marketing.referrals', [
            'enabled' => $svc->enabled(),
            'canEdit' => $this->can('marketing.edit'),
            'rewardsDirty' => $this->cfg !== $this->baseCfg,
            'shareDirty' => $this->share !== $this->baseShare,
            'rows' => $this->tab === 'referrals' ? $q->orderByDesc('created_at')->paginate($this->perPage) : null,
            'promotions' => Promotion::where('requires_coupon', true)->where('is_active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_ar']),
            'stats' => ['referrers' => Referral::distinct('referrer_id')->count('referrer_id'), 'signed_up' => (int) $counts->sum(), 'pending' => (int) ($counts['pending'] ?? 0),
                'rewarded' => (int) ($counts['rewarded'] ?? 0), 'rejected' => (int) ($counts['rejected'] ?? 0), 'value' => $value],
            'loyaltyOff' => ! app(LoyaltyService::class)->enabled(),
            'perPoint' => $perPoint,
            'example' => $this->example(),
        ])->title(__('referrals_admin.title'));
    }

    private function example(): string
    {
        $c = $this->cfg;
        $name = __('referrals_admin.example_name');
        $min = (float) ($c['min_order_amount'] ?? 0);
        $what = fn ($side) => ($c[$side.'_type'] ?? '') === 'coupon'
            ? __('referrals_admin.ex_coupon', ['name' => (($c[$side.'_promotion_id'] ?? '') !== '' ? Promotion::find($c[$side.'_promotion_id']) : null)?->localized('name') ?? __('referrals_admin.ex_a_coupon')])
            : __('referrals_admin.ex_amount', ['amount' => Money::of((string) (is_numeric($c[$side.'_amount'] ?? '') ? $c[$side.'_amount'] : 0))->format()]);
        $line = __('referrals_admin.ex_referrer', ['name' => $name, 'reward' => $what('referrer'), 'min' => $min > 0 ? Money::of((string) $min)->format() : __('referrals_admin.ex_any')]);

        return ($c['friend_type'] ?? 'none') === 'none' ? $line : $line.' '.__('referrals_admin.ex_friend', ['reward' => $what('friend')]);
    }
}
