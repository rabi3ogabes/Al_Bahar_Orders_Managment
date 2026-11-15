<?php

namespace App\Livewire\Admin\Messaging;

use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Models\JourneyTemplate;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Messaging\Data\AudienceSelection;
use App\Domain\Messaging\Data\SendRequest;
use App\Domain\Messaging\Models\CustomerMessage;
use App\Domain\Messaging\Models\MessageBatch;
use App\Domain\Messaging\Services\CustomerMessaging;
use App\Domain\Messaging\Services\CustomerScope;
use App\Domain\Messaging\Services\Eligibility;
use App\Domain\Messaging\Services\MessageContent;
use App\Domain\Messaging\Services\MessageVariables;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Support\BusinessTime;
use App\Support\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "Send message": one window, opened from the customers list or a customer's page, in which a staff member picks a ready-made message
 * (or writes their own), chooses notification and/or WhatsApp, edits the texts in both languages with a live preview, sees exactly who would
 * receive it and who would be skipped (and why), and sends it now or at a chosen time.
 *
 * The audience arrives as an event (`compose-message`) but is never trusted: it is cleaned, then re-resolved against what the signed-in staff
 * member may see (CustomerMessaging::audienceQuery) every time it is used. All rules (consent, cooldown, cart, limits, coupon, schedule)
 * live in App\Domain\Messaging; this class only gathers the input, shows the answers and keeps what was typed when something is refused.
 *
 * @phpstan-type Audience array{mode: string, ids: list<string>, filters: array<string, string>, except: list<string>}
 */
class MessageComposer extends Component
{
    use InteractsWithAdmin;

    /** The places in the apps a message can open (albahar://<key>). */
    public const LINKS = ['home', 'cart', 'orders', 'auctions', 'categories', 'search', 'wishlist', 'scan'];

    /** Most customer ids one event may carry (a tampered or runaway list is cut here; the send itself is capped by the messaging limits). */
    private const MAX_IDS = 20000;

    public bool $open = false;

    /** The "send to N customers?" decision shown over the window. */
    public bool $confirming = false;

    /**
     * Who the send is for, as cleaned from the opening event.
     *
     * @var Audience
     */
    #[Locked]
    public array $audience = ['mode' => 'ids', 'ids' => [], 'filters' => [], 'except' => []];

    /** How many customers the staff member may reach with this audience. */
    #[Locked]
    public int $count = 0;

    #[Locked]
    public ?string $singleId = null;

    /** Where the window was opened from (selection | row | customer), handed back with `messages-sent` so the page can tidy up after itself. */
    #[Locked]
    public string $source = '';

    #[Locked]
    public string $singleName = '';

    /** Language the window opens its editor in (a single customer's own language). */
    #[Locked]
    public string $defaultLang = 'en';

    /**
     * The values the preview fills the placeholders with, per language.
     *
     * @var array<string, array<string, string>>
     */
    #[Locked]
    public array $vars = [];

    /** '' = nothing chosen yet, 'custom' = write your own, otherwise a journey template id. */
    #[Locked]
    public string $templateId = '';

    #[Locked]
    public string $kind = 'custom';

    /** @var list<string> */
    public array $channels = ['notification'];

    /** @var array<string, mixed> */
    public array $content = [];

    public bool $ignoreCooldown = false;

    public bool $scheduling = false;

    /** Local business time as the browser's datetime-local value (Y-m-dTH:i), or ''. */
    public string $scheduleAt = '';

    /** compose | sent */
    #[Locked]
    public string $step = 'compose';

    /** What a send would do right now (see refreshPlan). @var array<string, mixed> */
    #[Locked]
    public array $plan = [];

    /** What happened, once sent. @var array<string, mixed> */
    #[Locked]
    public array $result = [];

    /** A refusal that is not about one field (too many customers, rate limit ...). */
    #[Locked]
    public string $error = '';

    public function mount(): void
    {
        $this->guard();
        $this->content = $this->blankContent();
    }

    /* ───────────────────────── opening ───────────────────────── */

    /**
     * @param  list<mixed>  $ids  ticked customers (mode ids)
     * @param  array<string, mixed>  $filters  the list's filters (mode all)
     * @param  list<mixed>  $except  customers un-ticked since "select all matching" (mode all)
     */
    #[On('compose-message')]
    public function openComposer(string $mode = 'ids', array $ids = [], array $filters = [], array $except = [], string $source = ''): void
    {
        $this->guard();
        $audience = $mode === 'all'
            ? ['mode' => 'all', 'ids' => [], 'filters' => $this->cleanFilters($filters), 'except' => $this->cleanIds($except)]
            : ['mode' => 'ids', 'ids' => $this->cleanIds($ids), 'filters' => [], 'except' => []];
        $query = $this->svc()->audienceQuery(auth()->user(), $this->selectionOf($audience));
        $count = (clone $query)->count();
        if ($count === 0) {
            $this->toast(__('messaging_admin.err.no_recipients'), 'error');

            return;
        }
        $single = $count === 1 ? $query->first() : null;

        $this->audience = $audience;
        $this->count = $count;
        $this->singleId = $single ? (string) $single->id : null;
        $this->source = in_array($source, ['selection', 'row', 'customer'], true) ? $source : '';
        $this->singleName = $single ? (string) $single->name : '';
        $this->defaultLang = $single && $single->preferredLocale() === 'ar' ? 'ar' : 'en';
        $this->vars = $this->previewVars($single);
        $this->templateId = '';
        $this->kind = 'custom';
        $this->channels = ['notification'];
        $this->content = $this->blankContent();
        $this->ignoreCooldown = false;
        $this->scheduling = false;
        $this->scheduleAt = '';
        $this->step = 'compose';
        $this->result = [];
        $this->error = '';
        $this->confirming = false;
        $this->plan = [];
        $this->resetValidation();
        $this->open = true;
    }

    /** Called by every way of closing (✕, Esc, scrim, Back): nothing is kept that could leak into the next send. */
    public function updatedOpen(bool $open): void
    {
        if (! $open) {
            $this->confirming = false;
        }
    }

    /* ───────────────────────── what to send ───────────────────────── */

    /** A template card, or 'custom'. Fills the editor with that message. */
    public function choose(string $id): void
    {
        $this->guard();
        if ($this->step !== 'compose') {
            return;
        }
        if ($id === 'custom') {
            $this->templateId = 'custom';
            $this->kind = 'custom';
            $this->content = $this->blankContent();
        } else {
            $template = Str::isUuid($id) ? JourneyTemplate::where('is_active', true)->find($id) : null;
            if (! $template || ! in_array($template->kind, ['reminder', 'offer'], true)) {
                $this->toast(__('messaging_composer.template_gone'), 'error');

                return;
            }
            $content = $this->contentFromTemplate($template);
            if ($content['coupon']['enabled'] && ! $this->canCoupon()) {
                $this->toast(__('messaging_admin.err.coupon_forbidden'), 'error');

                return;
            }
            $this->templateId = (string) $template->id;
            $this->kind = (string) $template->kind;
            $this->content = $content;
        }
        $this->error = '';
        $this->resetValidation();
        $this->refreshPlan();
    }

    /** WhatsApp can only be ticked when it is connected; the audience's answer is refreshed. */
    public function updatedChannels(): void
    {
        $this->guard();
        $this->channels = array_values(array_intersect(MessageBatch::CHANNELS, array_map('strval', $this->channels)));
        if (! $this->svc()->channelsAvailable()['whatsapp']['available']) {
            $this->channels = array_values(array_diff($this->channels, ['whatsapp']));
        }
        $this->resetErrorBag('channels');
        if ($this->templateId !== '') {
            $this->refreshPlan();
        }
    }

    /** Whatever the browser put into the texts is brought back to the expected shape at once, so nothing downstream (the view included) can trip over it. */
    public function updatedContent(): void
    {
        $this->content = $this->normalise($this->content);
    }

    public function updatedIgnoreCooldown(): void
    {
        $this->guard();
        $this->ignoreCooldown = $this->ignoreCooldown && $this->singleId !== null;
        $this->refreshPlan();
    }

    /* ───────────────────────── when to send ───────────────────────── */

    /** Quick picks, worked out on the server so the clock and the Qatar time zone can never disagree with the browser's. */
    public function pickSchedule(string $key): void
    {
        $this->guard();
        $now = BusinessTime::now();
        $at = match ($key) {
            'hour' => $now->addHour(),
            'tonight' => $now->setTime(20, 0),
            'tomorrow' => $now->addDay()->setTime(10, 0),
            default => null,
        };
        if (! $at || $at->lt($now->addMinute())) {
            return;
        }
        $this->scheduling = true;
        $this->scheduleAt = $at->format('Y-m-d\TH:i');
        $this->resetErrorBag('scheduleAt');
    }

    public function startSchedule(): void
    {
        $this->guard();
        $this->scheduling = true;
        $this->resetErrorBag('scheduleAt');
    }

    public function cancelSchedule(): void
    {
        $this->guard();
        $this->scheduling = false;
        $this->scheduleAt = '';
        $this->resetErrorBag('scheduleAt');
    }

    public function updatedScheduleAt(): void
    {
        $this->guard();
        $this->resetErrorBag('scheduleAt');
        if ($this->scheduleAt !== '') {
            $this->parseSchedule();
        }
    }

    /* ───────────────────────── sending ───────────────────────── */

    /** The primary button: checks everything, then asks first when more than one customer is involved. */
    public function requestSend(): void
    {
        $this->guard();
        if ($this->step !== 'compose') {
            return;
        }
        $this->error = '';
        $this->resetErrorBag();
        if (! $this->buildRequest()) {
            return;
        }
        $this->refreshPlan();
        if (($this->plan['overCap'] ?? false) || (! $this->scheduling && ($this->plan['reachable'] ?? 0) === 0)) {
            $this->error = ($this->plan['overCap'] ?? false)
                ? __('messaging_admin.err.too_many', ['max' => number_format((int) $this->plan['max']), 'count' => number_format((int) $this->plan['total'])])
                : __('messaging_composer.nobody');

            return;
        }
        if ((int) $this->plan['total'] > 1) {
            $this->confirming = true;

            return;
        }
        $this->send();
    }

    public function send(): void
    {
        $this->guard();
        if ($this->step !== 'compose') {
            return; // a second click on a send that already went out
        }
        $this->error = '';
        $this->resetErrorBag();
        $request = $this->buildRequest();
        if (! $request) {
            $this->confirming = false;

            return;
        }
        try {
            $batch = $this->svc()->send(auth()->user(), $request);
        } catch (DomainException $e) {
            if ($e->status === 403) {
                abort(403);
            }
            $this->confirming = false;
            $this->error = $e->getMessage();
            $this->toast($e->getMessage(), 'error');

            return;
        } catch (ValidationException $e) {
            $this->confirming = false;
            $this->rekey($e);
            $this->toast(__('messaging_composer.fix_fields'), 'error');

            return;
        }
        $this->result = $this->summarise($batch);
        $this->step = 'sent';
        $this->confirming = false;
        $this->scheduling = false;
        $this->toast($this->result['scheduled'] ? __('messaging_composer.toast_scheduled') : __('messaging_composer.toast_sent'));
        $this->dispatch('messages-sent', batch: (string) $batch->id, source: $this->source);
    }

    /* ───────────────────────── plan ───────────────────────── */

    /** Asks the messaging service what a send would do right now and keeps the answer for the summary. Nothing is written. */
    public function refreshPlan(): void
    {
        $this->guard();
        $channels = $this->planChannels();
        $plan = $this->svc()->plan(auth()->user(), new SendRequest($this->selection(), $channels, $this->kind, [], null, null, $this->ignoreCooldown && $this->singleId !== null));
        $this->plan = [
            'total' => $plan->total, 'max' => $plan->max, 'reachable' => $plan->reachable, 'skipped' => $channels === [] ? 0 : $plan->skipped(), 'overCap' => $plan->overCap(),
            'channels' => $plan->channels, 'reasons' => $plan->reasons, 'examples' => $plan->examples, 'single' => $this->singlePlan($channels),
        ];
    }

    /** One customer: the reason on each channel, so the staff member sees exactly what will and will not happen. @param list<string> $channels @return array<string, ?string>|null */
    private function singlePlan(array $channels): ?array
    {
        if ($this->singleId === null || $channels === []) {
            return null;
        }
        $user = CustomerScope::for(auth()->user())->find($this->singleId);
        if (! $user) {
            return null;
        }
        $rows = app(Eligibility::class)->evaluate(collect([$user]), $channels, $this->kind, $this->svc()->limits()['cooldown_hours'], null, $this->ignoreCooldown);

        return $rows[(string) $user->id] ?? null;
    }

    /** @return list<string> */
    private function planChannels(): array
    {
        $channels = array_values(array_intersect(MessageBatch::CHANNELS, array_map('strval', $this->channels)));
        if (! $this->svc()->channelsAvailable()['whatsapp']['available']) {
            $channels = array_values(array_diff($channels, ['whatsapp']));
        }

        return $channels;
    }

    /* ───────────────────────── building the request ───────────────────────── */

    /** The checked request, or null with the problems shown next to the fields. */
    private function buildRequest(): ?SendRequest
    {
        $channels = $this->planChannels();
        if ($channels === []) {
            $this->addError('channels', __('messaging_admin.err.no_channel'));

            return null;
        }
        if ($this->templateId === '') {
            $this->error = __('messaging_composer.pick_first');

            return null;
        }
        $this->content = $this->normalise($this->content);
        try {
            app(MessageContent::class)->clean($this->content, $channels, $this->kind);
        } catch (ValidationException $e) {
            $this->rekey($e);

            return null;
        }
        $at = null;
        if ($this->scheduling) {
            $at = $this->parseSchedule();
            if (! $at) {
                return null;
            }
        }
        $template = Str::isUuid($this->templateId) ? JourneyTemplate::find($this->templateId) : null;

        return new SendRequest($this->selection(), $channels, $this->kind, $this->content, $template, $at, $this->ignoreCooldown && $this->singleId !== null);
    }

    private function parseSchedule(): ?CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $this->scheduleAt)) {
            $this->addError('scheduleAt', __('messaging_composer.pick_time'));

            return null;
        }
        try {
            $at = BusinessTime::toUtc($this->scheduleAt);
        } catch (\Throwable) {
            $this->addError('scheduleAt', __('messaging_composer.pick_time'));

            return null;
        }
        if ($at->lt(now()->addMinute())) {
            $this->addError('scheduleAt', __('messaging_admin.err.schedule_past'));

            return null;
        }
        if ($at->gt(now()->addDays(60))) {
            $this->addError('scheduleAt', __('messaging_admin.err.schedule_far'));

            return null;
        }

        return $at;
    }

    /** Errors of the messaging service are keyed by its own field names; here the texts live under `content`. */
    private function rekey(ValidationException $e): void
    {
        foreach ($e->errors() as $key => $messages) {
            $this->addError($key === 'scheduleAt' ? $key : 'content.'.$key, (string) ($messages[0] ?? ''));
        }
    }

    /* ───────────────────────── the audience ───────────────────────── */

    /** @param Audience $audience */
    private function selectionOf(array $audience): AudienceSelection
    {
        return $audience['mode'] === 'all' ? AudienceSelection::matching($audience['filters'], $audience['except']) : AudienceSelection::ofIds($audience['ids']);
    }

    private function selection(): AudienceSelection
    {
        return $this->selectionOf($this->audience);
    }

    /**
     * @param  array<mixed>  $ids
     * @return list<string>
     */
    private function cleanIds(array $ids): array
    {
        $out = [];
        foreach (array_slice($ids, 0, self::MAX_IDS) as $id) {
            if (is_string($id) && Str::isUuid($id)) {
                $out[$id] = $id;
            }
        }

        return array_values($out);
    }

    /**
     * @param  array<mixed>  $filters
     * @return array<string, string>
     */
    private function cleanFilters(array $filters): array
    {
        $out = [];
        foreach (['search', 'status', 'consent', 'cart', 'contact'] as $key) {
            $value = $filters[$key] ?? '';
            if (is_string($value) && $value !== '') {
                $out[$key] = Str::limit($value, 120, '');
            }
        }

        return $out;
    }

    /** @return array<string, array<string, string>> */
    private function previewVars(?User $single): array
    {
        $variables = app(MessageVariables::class);
        $out = [];
        foreach (['en', 'ar'] as $locale) {
            $vars = $variables->sample($locale, $single ? Str::before(trim((string) $single->name), ' ') : '');
            if ($single) {
                $real = $variables->for($single, $locale);
                foreach (['items', 'first_item'] as $key) {
                    $vars[$key] = $real[$key] !== '' ? $real[$key] : $vars[$key];
                }
            }
            $out[$locale] = $vars;
        }

        return $out;
    }

    /* ───────────────────────── content ───────────────────────── */

    /** @return array<string, mixed> */
    private function blankContent(): array
    {
        return ['title_en' => '', 'title_ar' => '', 'body_en' => '', 'body_ar' => '', 'whatsapp_en' => '', 'whatsapp_ar' => '', 'deep_link' => 'albahar://home',
            'coupon' => ['enabled' => false, 'promotion_id' => '', 'prefix' => 'BAHAR', 'valid_hours' => 72]];
    }

    /** @return array<string, mixed> */
    private function contentFromTemplate(JourneyTemplate $t): array
    {
        $texts = [
            'title_en' => (string) $t->title_en, 'title_ar' => (string) $t->title_ar, 'body_en' => (string) $t->body_en, 'body_ar' => (string) $t->body_ar,
            'whatsapp_en' => (string) $t->whatsapp_en, 'whatsapp_ar' => (string) $t->whatsapp_ar,
        ];
        $variables = app(MessageVariables::class);
        $usesCoupon = collect($texts)->contains(fn (string $text) => $variables->uses($text, 'coupon'));
        $promotionOk = $t->promotion_id && $this->promotions()->contains('id', $t->promotion_id);

        return $texts + [
            'deep_link' => (string) $t->deep_link,
            'coupon' => [
                'enabled' => $t->promotion_id !== null || $usesCoupon,
                'promotion_id' => $promotionOk ? (string) $t->promotion_id : '',
                'prefix' => strtoupper((string) ($t->coupon_prefix ?: 'BAHAR')),
                'valid_hours' => max(1, min(720, (int) ($t->coupon_valid_hours ?: 72))),
            ],
        ];
    }

    /**
     * Whatever the browser sent, as the shape the messaging service expects (a tampered request must not break it).
     *
     * @param  array<mixed>  $in
     * @return array<string, mixed>
     */
    private function normalise(array $in): array
    {
        $out = $this->blankContent();
        foreach (['title_en', 'title_ar', 'body_en', 'body_ar', 'whatsapp_en', 'whatsapp_ar', 'deep_link'] as $key) {
            $out[$key] = is_scalar($in[$key] ?? null) ? (string) $in[$key] : '';
        }
        $coupon = is_array($in['coupon'] ?? null) ? $in['coupon'] : [];
        $out['coupon'] = [
            'enabled' => (bool) ($coupon['enabled'] ?? false),
            'promotion_id' => is_scalar($coupon['promotion_id'] ?? null) ? (string) $coupon['promotion_id'] : '',
            'prefix' => is_scalar($coupon['prefix'] ?? null) ? (string) $coupon['prefix'] : '',
            'valid_hours' => is_scalar($coupon['valid_hours'] ?? null) ? (string) $coupon['valid_hours'] : '',
        ];

        return $out;
    }

    /* ───────────────────────── result ───────────────────────── */

    /** @return array<string, mixed> */
    private function summarise(MessageBatch $batch): array
    {
        $reached = $skipped = $failed = 0;
        $reasons = [];
        CustomerMessage::query()->where('batch_id', $batch->id)->get(['user_id', 'status', 'reason'])->groupBy('user_id')->each(function ($rows) use (&$reached, &$skipped, &$failed, &$reasons) {
            $status = $rows->pluck('status');
            if ($status->contains(fn ($s) => in_array($s, ['queued', 'sent', 'delivered'], true))) {
                $reached++;
            } elseif ($status->contains('failed')) {
                $failed++;
            } else {
                $skipped++;
                $reason = (string) $rows->first()->reason;
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            }
        });
        $scheduled = $batch->status === 'scheduled' && $batch->scheduled_at !== null;

        return [
            'id' => (string) $batch->id, 'scheduled' => $scheduled, 'at' => $scheduled ? BusinessTime::display($batch->scheduled_at, 'D j M, H:i') : '',
            'customers' => (int) $batch->recipients_count, 'reached' => $reached, 'skipped' => $skipped, 'failed' => $failed, 'reasons' => $reasons,
            'pending' => (int) $batch->queued_count > 0,
        ];
    }

    /* ───────────────────────── helpers ───────────────────────── */

    private function svc(): CustomerMessaging
    {
        return app(CustomerMessaging::class);
    }

    private function guard(): void
    {
        abort_unless($this->svc()->canSend(auth()->user()), 403);
    }

    private function canCoupon(): bool
    {
        return $this->svc()->canAttachCoupon(auth()->user());
    }

    /** Active offers a personal coupon can be taken from. @return \Illuminate\Support\Collection<int, Promotion> */
    private function promotions(): Collection
    {
        return Promotion::query()->where('is_active', true)->where('requires_coupon', true)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderBy('name_en')->get(['id', 'name_en', 'name_ar', 'type', 'value']);
    }

    /** The human reason for a skip, with a fallback so an unknown code never shows as a raw key. */
    public static function reasonText(?string $reason): string
    {
        $key = 'messaging_admin.reason.'.$reason;

        return $reason && Lang::has($key) ? __($key, ['code' => '']) : __('messaging_admin.reason.send_error');
    }

    public function render()
    {
        $svc = $this->svc();
        $open = $this->open;
        $minAt = BusinessTime::now()->addMinutes(2);

        return view('livewire.admin.messaging.message-composer', [
            'templates' => $open && $this->step === 'compose' ? JourneyTemplate::where('is_active', true)->whereIn('kind', ['reminder', 'offer'])->orderBy('kind')->orderBy('name')->get() : collect(),
            'promotions' => $open && $this->canCoupon() ? $this->promotions() : collect(),
            'canCoupon' => $this->canCoupon(),
            'available' => $svc->channelsAvailable(),
            'limits' => $svc->limits(),
            'minAt' => $minAt->format('Y-m-d\TH:i'),
            'maxAt' => BusinessTime::now()->addDays(60)->format('Y-m-d\TH:i'),
            'tonightOk' => BusinessTime::now()->setTime(20, 0)->gte($minAt),
            'scheduleHuman' => $this->scheduleHuman(),
            'historyUrl' => $this->step === 'sent' && Route::has('admin.messages.index') && $this->can('notifications.view') ? route('admin.messages.index', ['view' => $this->result['id']]) : null,
        ]);
    }

    private function scheduleHuman(): string
    {
        if (! $this->scheduling || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $this->scheduleAt)) {
            return '';
        }
        try {
            return (string) BusinessTime::display(BusinessTime::toUtc($this->scheduleAt), 'D j M, H:i');
        } catch (\Throwable) {
            return '';
        }
    }
}
