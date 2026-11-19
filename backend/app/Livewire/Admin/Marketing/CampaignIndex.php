<?php

namespace App\Livewire\Admin\Marketing;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
use App\Domain\Marketing\Models\CustomerGroup;
use App\Domain\Marketing\Services\CampaignService;
use App\Domain\Notifications\Models\MarketingCampaign;
use App\Domain\Notifications\Services\NotificationRules;
use App\Livewire\Admin\Concerns\WithAdminTable;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Consent-aware campaigns: audience segment with live reach estimate, channels, EN/AR copy, preview, test send, schedule/send. */
class CampaignIndex extends Component
{
    use WithAdminTable;

    public const CHANNELS = ['push', 'in_app', 'sms', 'whatsapp', 'email'];

    #[Url(except: '')]
    public string $filterStatus = '';

    public bool $showEditor = false;

    #[Locked]
    public ?string $editingId = null;

    public array $form = [];

    public string $previewLocale = 'en';

    public function mount(): void
    {
        abort_unless($this->can('marketing.view'), 403);
    }

    private function blank(): array
    {
        return ['name' => '', 'channels' => ['push', 'in_app'], 'title_en' => '', 'title_ar' => '', 'body_en' => '', 'body_ar' => '', 'deep_link' => '',
            'branch_ids' => [], 'customer_group_ids' => [], 'purchased_since' => '', 'min_orders' => '', 'auction_participants' => false, 'when' => 'now', 'scheduled_at' => ''];
    }

    public function create(): void
    {
        abort_unless($this->can('marketing.create'), 403);
        $this->editingId = null;
        $this->form = $this->blank();
        $this->resetValidation();
        $this->showEditor = true;
    }

    public function edit(string $id): void
    {
        abort_unless($this->can('marketing.edit'), 403);
        $c = MarketingCampaign::findOrFail($id);
        abort_unless(in_array($c->status, ['draft', 'scheduled'], true), 409);
        $seg = $c->segment ?? [];
        $this->editingId = $c->id;
        $this->form = array_merge($this->blank(), $c->only(['name', 'title_en', 'title_ar', 'body_en', 'body_ar']), [
            'channels' => $c->channels ?? [], 'deep_link' => $c->deep_link ?? '', 'branch_ids' => $seg['branch_ids'] ?? [], 'customer_group_ids' => $seg['customer_group_ids'] ?? [],
            'purchased_since' => $seg['purchased_since'] ?? '', 'min_orders' => $seg['min_orders'] ?? '', 'auction_participants' => (bool) ($seg['auction_participants'] ?? false),
            'when' => $c->status === 'scheduled' ? 'later' : 'draft', 'scheduled_at' => $c->scheduled_at ? BusinessTime::local($c->scheduled_at)->format('Y-m-d\TH:i') : '',
        ]);
        $this->resetValidation();
        $this->showEditor = true;
    }

    private function segment(): array
    {
        $f = $this->form;

        return array_filter([
            'branch_ids' => array_values($f['branch_ids'] ?? []) ?: null, 'customer_group_ids' => array_values($f['customer_group_ids'] ?? []) ?: null,
            'purchased_since' => ($f['purchased_since'] ?? '') ?: null, 'min_orders' => ($f['min_orders'] ?? '') !== '' ? (int) $f['min_orders'] : null,
            'auction_participants' => ! empty($f['auction_participants']) ? true : null,
        ], fn ($v) => $v !== null);
    }

    public function save(CampaignService $svc, AuditLogger $audit): void
    {
        abort_unless($this->can($this->editingId ? 'marketing.edit' : 'marketing.create'), 403);
        $data = $this->validate([
            'form.name' => 'required|string|max:255', 'form.channels' => 'required|array|min:1', 'form.channels.*' => 'in:'.implode(',', self::CHANNELS),
            'form.title_en' => 'required|string|max:120', 'form.title_ar' => 'required|string|max:120',
            'form.body_en' => 'required|string|max:1000', 'form.body_ar' => 'required|string|max:1000',
            'form.deep_link' => ['nullable', 'string', 'max:255', 'regex:/^(albahar:\/\/|https:\/\/)/'],
            'form.branch_ids' => 'array', 'form.branch_ids.*' => 'uuid|exists:branches,id', 'form.customer_group_ids' => 'array', 'form.customer_group_ids.*' => 'uuid|exists:customer_groups,id',
            'form.purchased_since' => 'nullable|date', 'form.min_orders' => 'nullable|integer|min:1', 'form.auction_participants' => 'boolean',
            'form.when' => 'required|in:draft,now,later', 'form.scheduled_at' => 'required_if:form.when,later|nullable|date|after:now',
        ], ['form.deep_link.regex' => __('marketing_admin.deep_link_format')], ['form.name' => __('marketing_admin.campaign_name'), 'form.channels' => __('marketing_admin.channels'),
            'form.title_en' => __('marketing_admin.title_en'), 'form.title_ar' => __('marketing_admin.title_ar'), 'form.body_en' => __('marketing_admin.body_en'), 'form.body_ar' => __('marketing_admin.body_ar'),
            'form.scheduled_at' => __('marketing_admin.send_at')])['form'];
        if ($data['when'] === 'now') {
            abort_unless($this->can('marketing.approve'), 403);
        }
        // The row is locked while it is edited: a campaign that was sent (or is being sent) since the editor opened is never overwritten back to a draft.
        $conflict = false;
        $c = DB::transaction(function () use ($data, $audit, &$conflict) {
            if ($this->editingId) {
                $c = MarketingCampaign::whereKey($this->editingId)->lockForUpdate()->firstOrFail();
                if (! in_array($c->status, ['draft', 'scheduled'], true)) {
                    $conflict = true;

                    return $c;
                }
            } else {
                $c = new MarketingCampaign(['created_by' => auth()->id(), 'status' => 'draft']);
            }
            $old = $c->exists ? $c->only(['name', 'status', 'scheduled_at']) : [];
            $c->fill(['name' => $data['name'], 'channels' => array_values($data['channels']), 'title_en' => $data['title_en'], 'title_ar' => $data['title_ar'],
                'body_en' => $data['body_en'], 'body_ar' => $data['body_ar'], 'deep_link' => $data['deep_link'] ?: null, 'segment' => $this->segment(),
                'status' => $data['when'] === 'later' ? 'scheduled' : 'draft', 'status_note' => null,
                'scheduled_at' => $data['when'] === 'later' ? CarbonImmutable::parse($data['scheduled_at'], BusinessTime::zone())->utc() : null])->save();
            $audit->log($old ? 'marketing.campaign_updated' : 'marketing.campaign_created', $c, $old, $c->only(['name', 'status', 'scheduled_at', 'channels', 'segment']));

            return $c;
        });
        $this->showEditor = false;
        if ($conflict) {
            $this->toast($c->status === 'sending' ? __('owner_numbers.campaign_already_sending') : __('marketing_admin.campaign_not_sendable'), 'error');

            return;
        }
        if ($data['when'] === 'now') {
            $this->sendNow($c->id, $svc);
        } else {
            $this->toast($c->status === 'scheduled' ? __('marketing_admin.campaign_scheduled') : __('admin.saved'));
        }
    }

    /**
     * Starts the send and returns at once: the campaign is claimed in ONE atomic step (a second person, or the scheduler, finds it taken and nothing
     * is sent twice) and its delivery runs in the background, so a big audience never ties up the page or times out. The list shows the progress.
     * The audit record is written by the service when the delivery ends, by whichever path started it.
     */
    public function sendNow(string $id, CampaignService $svc): void
    {
        abort_unless($this->can('marketing.approve'), 403);
        $c = MarketingCampaign::findOrFail($id);
        if (! app(NotificationRules::class)->enabled('campaign')) {
            $this->toast(__('notification_rules_admin.campaign_off'), 'error'); // switched off in Content > Notification controls

            return;
        }
        $outcome = $svc->start($c, auth()->user());
        if ($outcome === CampaignService::NOT_SENDABLE) {
            $this->toast($c->fresh()?->status === 'sending' ? __('owner_numbers.campaign_already_sending') : __('marketing_admin.campaign_not_sendable'), 'error');

            return;
        }
        if ($outcome === CampaignService::SWITCHED_OFF) {
            $this->toast(__('notification_rules_admin.campaign_off'), 'error');

            return;
        }
        $c = $c->fresh();
        // Where the work already ended (a server without a queue, a tiny audience): say what really happened. Otherwise it is on its way.
        if ($c && in_array($c->status, ['sent', 'partly_sent'], true)) {
            $this->toast(trans_choice('marketing_admin.campaign_sent', $c->recipients_count, ['count' => $c->recipients_count]));
        } elseif ($c && in_array($c->status, ['draft', 'scheduled'], true)) {
            // Nobody could be reached (every channel switched off in Notification controls, nobody opted in, or an empty audience): not sent, status unchanged.
            $this->toast(__('marketing_admin.campaign_nothing_sent'), 'error');
        } else {
            $this->toast(__('owner_numbers.campaign_started'));
        }
    }

    public function sendTest(string $id, CampaignService $svc): void
    {
        abort_unless($this->can('marketing.edit') || $this->can('marketing.create'), 403);
        $c = MarketingCampaign::findOrFail($id);
        $svc->sendTest($c, auth()->user(), $this->previewLocale);
        $this->toast(__('marketing_admin.test_sent'));
    }

    public function cancel(string $id, AuditLogger $audit): void
    {
        abort_unless($this->can('marketing.edit'), 403);
        $c = MarketingCampaign::findOrFail($id);
        $before = $c->status;
        // One atomic step: a campaign that was claimed for sending a moment ago cannot be cancelled underneath its own delivery.
        $cancelled = MarketingCampaign::whereKey($id)->whereIn('status', ['draft', 'scheduled'])->update(['status' => 'cancelled', 'status_note' => null]);
        abort_unless($cancelled === 1, 409);
        $audit->log('marketing.campaign_cancelled', $c, ['status' => $before], ['status' => 'cancelled']);
        $this->toast(__('marketing_admin.campaign_cancelled'));
    }

    public function render(CampaignService $svc)
    {
        $s = trim($this->search);
        $campaigns = MarketingCampaign::query()->when($this->filterStatus, fn ($q, $st) => $q->where('status', $st))
            ->when($s, fn ($q) => $q->where('name', 'ilike', "%{$s}%"))->latest()->paginate($this->perPage);

        return view('livewire.admin.marketing.campaigns', [
            'campaigns' => $campaigns,
            'anySending' => $campaigns->contains(fn (MarketingCampaign $c) => $c->status === 'sending'), // the list refreshes itself while one is going out
            'reach' => $this->showEditor ? $svc->audience($this->segment())->count() : null,
            'branches' => $this->showEditor ? Branch::orderBy('sort_order')->get(['id', 'code', 'name_en', 'name_ar']) : collect(),
            'groups' => $this->showEditor ? CustomerGroup::orderBy('name_en')->get() : collect(),
            'canSend' => $this->can('marketing.approve'),
            'canEdit' => $this->can('marketing.edit'),
            'canCreate' => $this->can('marketing.create'),
        ])->title(__('nav.campaigns'));
    }
}
