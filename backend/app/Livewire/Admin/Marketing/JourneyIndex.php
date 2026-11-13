<?php

namespace App\Livewire\Admin\Marketing;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Marketing\Models\Journey;
use App\Domain\Marketing\Models\JourneyDelivery;
use App\Domain\Marketing\Models\JourneyStep;
use App\Domain\Marketing\Models\JourneyTemplate;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Marketing\Services\JourneyService;
use App\Domain\Notifications\Services\N8nClient;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Customer journeys: automatic follow-ups for abandoned carts and birthdays. The admin edits the timeline of steps
 * (when, which channels, which template), the reusable AR/EN templates (with an optional personal coupon), and watches
 * what was sent and what it earned.
 */
class JourneyIndex extends Component
{
    use InteractsWithAdmin, WithPagination;

    #[Url(except: 'abandoned_cart')]
    public string $tab = 'abandoned_cart';

    #[Url(except: '')]
    public string $filterStatus = '';

    #[Url(except: 30)]
    public int $days = 30;

    #[Url(except: '')]
    public string $filterJourney = '';

    #[Url(except: '')]
    public string $filterChannel = '';

    public bool $showStep = false;

    #[Locked]
    public ?string $stepId = null;

    public array $step = [];

    public bool $showTemplate = false;

    #[Locked]
    public ?string $templateId = null;

    public array $tpl = [];

    public array $rules = ['quiet_start' => '', 'quiet_end' => '', 'max_per_day' => 3, 'attribution_days' => 7];

    public string $previewLocale = 'en';

    public array $testChannels = ['push', 'in_app'];

    public function mount(JourneyService $svc): void
    {
        abort_unless($this->can('marketing.view'), 403);
        $svc->ensureDefaults();
        $this->loadRules();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['days', 'filterJourney', 'filterChannel', 'filterStatus'], true)) {
            $this->resetPage();
        }
    }

    public function updatedTab(): void
    {
        $this->resetPage();
        $this->loadRules();
    }

    private function journey(): ?Journey
    {
        return in_array($this->tab, ['abandoned_cart', 'birthday'], true) ? Journey::where('key', $this->tab)->first() : null;
    }

    private function loadRules(): void
    {
        $j = $this->journey();
        $this->rules = ['quiet_start' => (string) $j?->setting('quiet_start', ''), 'quiet_end' => (string) $j?->setting('quiet_end', ''), 'max_per_day' => (int) $j?->setting('max_per_day', 3), 'attribution_days' => (int) $j?->setting('attribution_days', 7)];
    }

    /* ─────────── journey on/off + rules ─────────── */

    public function toggle(AuditLogger $audit): void
    {
        abort_unless($this->can('marketing.edit'), 403);
        $j = $this->journey() ?? abort(404);
        if (! $j->is_enabled && ! $j->steps()->where('is_enabled', true)->whereNotNull('template_id')->exists()) {
            $this->toast(__('journeys_admin.needs_step'), 'error');

            return;
        }
        $j->update(['is_enabled' => ! $j->is_enabled]);
        $audit->log('marketing.journey_'.($j->is_enabled ? 'enabled' : 'disabled'), $j, [], ['key' => $j->key]);
        $this->toast($j->is_enabled ? __('journeys_admin.turned_on') : __('journeys_admin.turned_off'));
    }

    public function saveRules(AuditLogger $audit): void
    {
        abort_unless($this->can('marketing.edit'), 403);
        $j = $this->journey() ?? abort(404);
        $d = $this->validate([
            'rules.quiet_start' => ['nullable', 'date_format:H:i'], 'rules.quiet_end' => ['nullable', 'date_format:H:i', 'required_with:rules.quiet_start'], 'rules.max_per_day' => ['required', 'integer', 'min:0', 'max:20'], 'rules.attribution_days' => ['required', 'integer', 'min:1', 'max:30'],
        ], [], ['rules.quiet_start' => __('journeys_admin.quiet_from'), 'rules.quiet_end' => __('journeys_admin.quiet_to'), 'rules.max_per_day' => __('journeys_admin.max_per_day'), 'rules.attribution_days' => __('journeys_admin.attribution_days')])['rules'];
        $j->update(['settings' => ['quiet_start' => $d['quiet_start'] ?: null, 'quiet_end' => $d['quiet_end'] ?: null, 'max_per_day' => (int) $d['max_per_day'], 'attribution_days' => (int) $d['attribution_days']]]);
        $audit->log('marketing.journey_rules_updated', $j, [], $j->settings);
        $this->toast(__('admin.saved'));
    }

    /* ─────────── steps ─────────── */

    public function editStep(?string $id = null): void
    {
        abort_unless($this->can('marketing.edit'), 403);
        $j = $this->journey() ?? abort(404);
        $s = $id ? $j->steps()->findOrFail($id) : null;
        $minutes = (int) ($s?->delay_minutes ?? 60);
        [$val, $unit] = $minutes % 1440 === 0 ? [intdiv($minutes, 1440), 'days'] : ($minutes % 60 === 0 ? [intdiv($minutes, 60), 'hours'] : [$minutes, 'minutes']);
        $this->stepId = $s?->id;
        $this->step = ['label' => $s?->label ?? '', 'delay_value' => $val, 'delay_unit' => $unit, 'offset_days' => (int) ($s?->offset_days ?? 0), 'send_time' => $s?->send_time ?? '10:00',
            'channels' => $s?->channels ?? ['push'], 'template_id' => $s?->template_id ?? '', 'is_enabled' => $s?->is_enabled ?? true];
        $this->resetValidation();
        $this->showStep = true;
    }

    public function saveStep(AuditLogger $audit): void
    {
        abort_unless($this->can('marketing.edit'), 403);
        $j = $this->journey() ?? abort(404);
        $birthday = $j->key === 'birthday';
        $d = $this->validate([
            'step.label' => ['nullable', 'string', 'max:80'], 'step.channels' => ['required', 'array', 'min:1'], 'step.channels.*' => ['in:'.implode(',', JourneyService::CHANNELS)],
            'step.template_id' => ['required', 'uuid', 'exists:journey_templates,id'], 'step.is_enabled' => ['boolean'],
            'step.delay_value' => [$birthday ? 'nullable' : 'required', 'integer', 'min:1', 'max:10080'], 'step.delay_unit' => ['in:minutes,hours,days'],
            'step.offset_days' => [$birthday ? 'required' : 'nullable', 'integer', 'min:-30', 'max:30'], 'step.send_time' => [$birthday ? 'required' : 'nullable', 'date_format:H:i'],
        ], [], ['step.channels' => __('journeys_admin.channels'), 'step.template_id' => __('journeys_admin.template'), 'step.delay_value' => __('journeys_admin.after'),
            'step.offset_days' => __('journeys_admin.day'), 'step.send_time' => __('journeys_admin.at_time')])['step'];
        $minutes = null;
        if (! $birthday) {
            $minutes = (int) $d['delay_value'] * ['minutes' => 1, 'hours' => 60, 'days' => 1440][$d['delay_unit']];
            if ($minutes > 10080 || $minutes < 1) {
                $this->addError('step.delay_value', __('journeys_admin.delay_range'));

                return;
            }
        }
        $s = $this->stepId ? $j->steps()->findOrFail($this->stepId) : new JourneyStep(['journey_id' => $j->id]);
        $s->fill(['label' => $d['label'] ?: null, 'channels' => array_values($d['channels']), 'template_id' => $d['template_id'], 'is_enabled' => (bool) $d['is_enabled'],
            'delay_minutes' => $minutes, 'offset_days' => $birthday ? (int) $d['offset_days'] : null, 'send_time' => $birthday ? $d['send_time'] : null])->save();
        $this->renumber($j);
        $audit->log('marketing.journey_step_saved', $j, [], $s->only(['label', 'channels', 'delay_minutes', 'offset_days', 'send_time', 'template_id']));
        $this->showStep = false;
        $this->toast(__('admin.saved'));
    }

    public function deleteStep(string $id, AuditLogger $audit): void
    {
        abort_unless($this->can('marketing.edit'), 403);
        $j = $this->journey() ?? abort(404);
        $j->steps()->findOrFail($id)->delete();
        $this->renumber($j);
        if ($j->is_enabled && ! $j->steps()->where('is_enabled', true)->exists()) {
            $j->update(['is_enabled' => false]);
        }
        $audit->log('marketing.journey_step_deleted', $j, [], ['step' => $id]);
        $this->toast(__('journeys_admin.step_deleted'));
    }

    /** Steps are numbered in the order they happen. */
    private function renumber(Journey $j): void
    {
        $order = $j->key === 'birthday' ? ['offset_days', 'send_time'] : ['delay_minutes'];
        $q = $j->steps()->getQuery();
        foreach ($order as $col) {
            $q->orderBy($col);
        }
        $q->get()->each(fn ($s, $i) => $s->position === $i + 1 ?: $s->update(['position' => $i + 1]));
    }

    /* ─────────── templates ─────────── */

    public function editTemplate(?string $id = null): void
    {
        abort_unless($this->can('marketing.edit'), 403);
        $t = $id ? JourneyTemplate::findOrFail($id) : null;
        $this->templateId = $t?->id;
        $this->tpl = $t ? $t->only(['name', 'kind', 'title_en', 'title_ar', 'body_en', 'body_ar', 'whatsapp_en', 'whatsapp_ar', 'deep_link', 'promotion_id', 'coupon_prefix', 'coupon_valid_hours', 'is_active'])
            : ['name' => '', 'kind' => 'reminder', 'title_en' => '', 'title_ar' => '', 'body_en' => '', 'body_ar' => '', 'whatsapp_en' => '', 'whatsapp_ar' => '', 'deep_link' => 'albahar://cart',
                'promotion_id' => '', 'coupon_prefix' => 'BAHAR', 'coupon_valid_hours' => 72, 'is_active' => true];
        $this->tpl['promotion_id'] = $this->tpl['promotion_id'] ?? '';
        $this->testChannels = ['push', 'in_app'];
        $this->resetValidation();
        $this->showTemplate = true;
    }

    public function saveTemplate(AuditLogger $audit): void
    {
        abort_unless($this->can('marketing.edit'), 403);
        $d = $this->validate([
            'tpl.name' => ['required', 'string', 'max:120'], 'tpl.kind' => ['required', 'in:reminder,offer'],
            'tpl.title_en' => ['required', 'string', 'max:120'], 'tpl.title_ar' => ['required', 'string', 'max:120'],
            'tpl.body_en' => ['required', 'string', 'max:1000'], 'tpl.body_ar' => ['required', 'string', 'max:1000'],
            'tpl.whatsapp_en' => ['nullable', 'string', 'max:1500'], 'tpl.whatsapp_ar' => ['nullable', 'string', 'max:1500'],
            'tpl.deep_link' => ['nullable', 'string', 'max:191', 'regex:/^(albahar:\/\/|https:\/\/)/'],
            'tpl.promotion_id' => ['nullable', 'uuid', 'exists:promotions,id'], 'tpl.coupon_prefix' => ['required', 'alpha_num', 'max:8'],
            'tpl.coupon_valid_hours' => ['required', 'integer', 'min:1', 'max:2160'], 'tpl.is_active' => ['boolean'],
        ], ['tpl.deep_link.regex' => __('marketing_admin.deep_link_format')], ['tpl.name' => __('journeys_admin.template_name'), 'tpl.title_en' => __('marketing_admin.title_en'), 'tpl.title_ar' => __('marketing_admin.title_ar'),
            'tpl.body_en' => __('marketing_admin.body_en'), 'tpl.body_ar' => __('marketing_admin.body_ar'), 'tpl.coupon_prefix' => __('journeys_admin.coupon_prefix'), 'tpl.coupon_valid_hours' => __('journeys_admin.coupon_valid')])['tpl'];
        $t = $this->templateId ? JourneyTemplate::findOrFail($this->templateId) : new JourneyTemplate;
        $t->fill(array_merge($d, ['promotion_id' => $d['promotion_id'] ?: null, 'deep_link' => $d['deep_link'] ?: null, 'coupon_prefix' => strtoupper($d['coupon_prefix']),
            'whatsapp_en' => $d['whatsapp_en'] ?: null, 'whatsapp_ar' => $d['whatsapp_ar'] ?: null]))->save();
        $audit->log('marketing.journey_template_saved', $t, [], $t->only(['name', 'kind', 'promotion_id']));
        $this->templateId = $t->id;
        $this->showTemplate = false;
        $this->toast(__('admin.saved'));
    }

    public function deleteTemplate(string $id, AuditLogger $audit): void
    {
        abort_unless($this->can('marketing.edit'), 403);
        $t = JourneyTemplate::findOrFail($id);
        if (JourneyStep::where('template_id', $t->id)->exists()) {
            $this->toast(__('journeys_admin.template_in_use'), 'error');

            return;
        }
        $t->delete();
        $audit->log('marketing.journey_template_deleted', $t, [], ['id' => $id]);
        $this->toast(__('journeys_admin.template_deleted'));
    }

    public function sendTest(JourneyService $svc): void
    {
        abort_unless($this->can('marketing.edit'), 403);
        $d = $this->validate(['testChannels' => ['required', 'array', 'min:1'], 'testChannels.*' => ['in:'.implode(',', JourneyService::CHANNELS)]])['testChannels'];
        $t = JourneyTemplate::findOrFail($this->templateId ?? abort(404));
        $res = $svc->sendTest($t, auth()->user(), $d, $this->previewLocale);
        $bad = collect($res)->reject(fn ($r) => $r === 'sent');
        $this->toast($bad->isEmpty() ? __('journeys_admin.test_sent') : __('journeys_admin.test_partial', ['detail' => $bad->map(fn ($r, $ch) => __('content_admin.ch_'.$ch).': '.__('journeys_admin.reason.'.$r))->implode(' · ')]), $bad->isEmpty() ? 'success' : 'warning');
    }

    public function render(JourneyService $svc, N8nClient $n8n)
    {
        $j = $this->journey();
        $steps = $j ? $j->steps()->with('template:id,name,kind')->get() : collect();
        $recovered = $this->tab === 'recovered'
            ? $svc->recovered(in_array($this->days, [7, 30, 90], true) ? $this->days : 30, $this->filterJourney ?: null, $this->filterChannel ?: null)
                ->with(['user:id,name', 'order:id,number,grand_total,placed_at,payment_method', 'journey:id,key', 'attributedDelivery.step:id,label,position'])->latest('converted_at')->paginate(20) : null;
        $deliveries = $this->tab === 'activity'
            ? JourneyDelivery::with(['user:id,name', 'run.journey:id,key,name', 'step:id,position,label'])->when($this->filterStatus, fn ($q, $s) => $q->where('status', $s))->latest()->paginate(20)
            : null;

        return view('livewire.admin.marketing.journeys', [
            'journeyRow' => $j,
            'steps' => $steps,
            'stats' => $j ? $svc->stats($j) : null,
            'templates' => in_array($this->tab, ['templates', 'abandoned_cart', 'birthday'], true) || $this->showStep ? JourneyTemplate::orderBy('name')->get() : collect(),
            'promotions' => $this->showTemplate ? Promotion::where('is_active', true)->where('requires_coupon', true)->orderBy('name_en')->get(['id', 'name_en', 'name_ar', 'type', 'value']) : collect(),
            'deliveries' => $deliveries,
            'recovered' => $recovered,
            'recovery' => $this->tab === 'recovered' ? $svc->recoveryStats(in_array($this->days, [7, 30, 90], true) ? $this->days : 30, $this->filterJourney ?: null) : null,
            'canEdit' => $this->can('marketing.edit'),
            'n8nReady' => $n8n->configured(),
            'channels' => JourneyService::CHANNELS,
        ])->title(__('journeys_admin.title'));
    }
}
