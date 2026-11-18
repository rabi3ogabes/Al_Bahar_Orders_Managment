<?php

namespace App\Domain\Marketing\Services;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Checkout\Support\CartVisibility;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Identity\Support\BranchScope;
use App\Domain\Marketing\Models\Journey;
use App\Domain\Marketing\Models\JourneyDelivery;
use App\Domain\Marketing\Models\JourneyRun;
use App\Domain\Marketing\Models\JourneyStep;
use App\Domain\Marketing\Models\JourneyTemplate;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Marketing\Support\QuietHours;
use App\Domain\Marketing\Support\ReminderText;
use App\Domain\Notifications\Services\N8nClient;
use App\Domain\Notifications\Services\NotificationChannelStatus;
use App\Domain\Notifications\Services\NotificationRules;
use App\Domain\Notifications\Services\WhatsAppClient;
use App\Domain\Orders\Models\Cart;
use App\Support\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Automatic abandoned-cart reminders, as the owner sees them: ONE switch, a few plain settings and the words of each reminder.
 *
 * It is a friendly front for the `abandoned_cart` customer journey, which is the one engine that sends them (JourneyService: runs, steps, deliveries,
 * quiet hours, expiry, consent, attribution of orders). Nothing here sends a message. This class
 *  - reads and saves the settings (the journey, its first three steps and their message templates, all in one transaction),
 *  - answers "is it allowed right now" for the engine (the `cart.abandoned` and campaign switches of the notification controls),
 *  - reports what it did (reminders sent, carts won back, revenue) and what happened to one cart.
 *
 * The three slots are the first three steps of the journey, in order. Steps beyond the third (added in Marketing > Customer journeys) are left alone.
 */
class AbandonedCartReminders
{
    public const KEY = 'abandoned_cart';

    public const SLOTS = 3;

    /** Minutes after the customer last touched the cart. The first reminder: 30 min, 1 h, 3 h, 6 h or 24 h. */
    public const FIRST_DELAYS = [30, 60, 180, 360, 1440];

    /** The second and third reminder: 3 h, 6 h, 12 h, 1 day, 2 days, 3 days (never earlier than MIN_GAP_MINUTES after the one before). */
    public const LATER_DELAYS = [180, 360, 720, 1440, 2880, 4320];

    /** Two reminders about the same cart are at least this far apart (3 hours). */
    public const MIN_GAP_MINUTES = 180;

    /** A reminder that is more than this many hours late (quiet hours, the scheduler or a switch was off) is skipped, never sent late. */
    public const GRACE_HOURS = 12;

    public const DEFAULT_MAX_PER_CART = 2;

    /** The three ready-made schedules (delays in minutes; `max` = most reminders per cart). */
    public const PRESETS = [
        'gentle' => ['delays' => [180], 'max' => 1],
        'balanced' => ['delays' => [60, 1440], 'max' => 2],
        'persistent' => ['delays' => [60, 1440, 4320], 'max' => 3],
    ];

    /** The default words, per reminder: [English title, English message, Arabic title, Arabic message]. Short, warm, no pressure. */
    private const TEXTS = [
        ['Your cart is waiting', 'Hi {{name}}, your cart has {{items_text}} worth {{total}}. Come back whenever you are ready.',
            'سلتك بانتظارك', 'هلا {{name}}، في سلتك {{items_text}} بقيمة {{total}}. ارجع لها وقت ما تحب.'],
        ['Still thinking it over?', 'Hi {{name}}, we saved your cart with {{items_text}}. It is one tap away when you are ready.',
            'سلتك محفوظة لك', 'هلا {{name}}، حفظنا لك سلتك وفيها {{items_text}}. تكمل طلبك بضغطة وحدة وقت ما تبي.'],
        ['Your cart is still saved', 'Hi {{name}}, a friendly note: your cart with {{items_text}} is still here if you would like it.',
            'سلتك للحين محفوظة', 'هلا {{name}}، تذكير بسيط: سلتك وفيها {{items_text}} للحين محفوظة إذا حاب تكمل.'],
    ];

    /** What the starter journey of the first version said, to recognise a journey nobody ever changed or switched on. */
    private const LEGACY_FIRST_BODY = 'Hi {{name}}, you left {{items}} item(s) in your cart. Complete your order before they sell out.';

    public function __construct(
        private readonly NotificationRules $rules,
        private readonly NotificationChannelStatus $channels,
        private readonly N8nClient $n8n,
        private readonly WhatsAppClient $whatsapp,
        private readonly AuditLogger $audit,
        private readonly AccessControl $access,
    ) {}

    // ── The switch and the rules around it ───────────────────────────────────────────────────────────────────────────

    /** Is the owner's switch on? (Does not create anything.) */
    public function isEnabled(): bool
    {
        return (bool) Journey::where('key', self::KEY)->value('is_enabled');
    }

    /** The most reminders one cart gets (reminders sent by hand count too). */
    public function maxPerCart(): int
    {
        $max = (int) (Journey::where('key', self::KEY)->first()?->setting('max_per_cart', self::DEFAULT_MAX_PER_CART) ?? self::DEFAULT_MAX_PER_CART);

        return max(1, $max);
    }

    /**
     * Why nothing is sent although the switch may be on: `cart_rule` (the Cart reminder notification is switched off in the notification controls)
     * or `campaign_rule` (campaign messages are switched off). Null when nothing blocks it.
     */
    public function blockedBy(): ?string
    {
        return match (true) {
            ! $this->rules->enabled('cart.abandoned') => 'cart_rule',
            ! $this->rules->enabled('campaign') => 'campaign_rule',
            default => null,
        };
    }

    /** May the engine start and send reminders at all right now? */
    public function allowed(): bool
    {
        return $this->blockedBy() === null;
    }

    /** May a reminder go out on this channel? (The channel switches of the Cart reminder in the notification controls.) */
    public function channelAllowed(string $channel): bool
    {
        return $this->rules->allows('cart.abandoned', $channel);
    }

    /** @return array<string, bool> channel => can send on this server */
    public function channelStatus(): array
    {
        return ['push' => $this->channels->isReady('push'), 'in_app' => true, 'whatsapp' => $this->n8n->configured() || $this->whatsapp->configured()];
    }

    /**
     * Switch the automatic reminders on or off. Turning on needs at least one reminder and one way to reach customers that works on this server.
     * Audited like the switch on the journeys page.
     *
     * @return bool the state before the change
     *
     * @throws DomainException when it cannot be turned on yet
     */
    public function setEnabled(bool $on, ?User $actor = null): bool
    {
        $journey = $this->journey();
        $before = (bool) $journey->is_enabled;
        if ($on === $before) {
            return $before;
        }
        if ($on) {
            $form = $this->form();
            if (! collect($form['reminders'])->contains(fn (array $r) => $r['enabled'])) {
                throw new DomainException('cart_reminders_no_reminder', __('cart_reminders.err_no_reminder'), 422);
            }
            $ready = $this->channelStatus();
            if (! collect(self::channelKeys($form['channels']))->contains(fn (string $c) => $ready[$c] ?? false)) {
                throw new DomainException('cart_reminders_no_channel', __('cart_reminders.err_no_channel'), 422);
            }
        }
        $journey->update(['is_enabled' => $on]);
        if ($on) {
            app(JourneyService::class)->wake($journey);
        }
        $this->audit->log('marketing.journey_'.($on ? 'enabled' : 'disabled'), $journey, [], ['key' => self::KEY], ['via' => 'abandoned_orders'], actor: $actor);
        $this->forgetResults();

        return $before;
    }

    // ── The settings ─────────────────────────────────────────────────────────────────────────────────────────────────

    /** The journey, created with the default settings (switched off) the first time it is needed. */
    public function journey(): Journey
    {
        $journey = Journey::where('key', self::KEY)->first() ?? $this->seed();
        $this->upgradeUnusedStarter($journey);

        return $journey->load(['steps.template']);
    }

    /**
     * What the settings window shows: the saved settings in one flat array.
     *
     * @return array<string, mixed>
     */
    public function form(): array
    {
        $journey = $this->journey();
        $steps = $journey->steps->values();
        $defaults = $this->defaults();
        $reminders = [];
        for ($i = 0; $i < self::SLOTS; $i++) {
            $step = $steps[$i] ?? null;
            $t = $step?->template;
            $d = $defaults['reminders'][$i];
            $reminders[] = ['enabled' => $step ? (bool) $step->is_enabled : false, 'delay' => (int) ($step?->delay_minutes ?? $d['delay']),
                'title_en' => (string) ($t->title_en ?? $d['title_en']), 'body_en' => (string) ($t->body_en ?? $d['body_en']),
                'title_ar' => (string) ($t->title_ar ?? $d['title_ar']), 'body_ar' => (string) ($t->body_ar ?? $d['body_ar'])];
        }
        $lead = $steps->first(fn (JourneyStep $s) => $s->is_enabled) ?? $steps->first();
        $on = array_map('strval', (array) ($lead?->channels ?? ['push', 'in_app']));
        $offer = ['promotion_id' => '', 'reminder' => 2, 'valid_hours' => 48];
        foreach ($steps->take(self::SLOTS) as $i => $s) {
            if ($s->template?->promotion_id) {
                $offer = ['promotion_id' => (string) $s->template->promotion_id, 'reminder' => $i + 1, 'valid_hours' => (int) $s->template->coupon_valid_hours];
                break;
            }
        }
        $from = (string) $journey->setting('quiet_start', '');
        $to = (string) $journey->setting('quiet_end', '');

        return [
            'reminders' => $reminders,
            'max_per_cart' => (int) $journey->setting('max_per_cart', self::DEFAULT_MAX_PER_CART),
            'quiet' => $from !== '' && $to !== '' && $from !== $to,
            'quiet_start' => $from !== '' ? $from : $defaults['quiet_start'],
            'quiet_end' => $to !== '' ? $to : $defaults['quiet_end'],
            'channels' => ['push' => in_array('push', $on, true), 'in_app' => in_array('in_app', $on, true), 'whatsapp' => in_array('whatsapp', $on, true)],
            'offer' => $offer,
        ];
    }

    /**
     * The settings a new install starts with (the "Balanced" schedule): a reminder after 1 hour and another after 1 day, at most 2 per cart, no
     * messages between 22:00 and 08:00, push and the in-app inbox, no offer.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        $reminders = [];
        foreach (self::TEXTS as $i => [$titleEn, $bodyEn, $titleAr, $bodyAr]) {
            $reminders[] = ['enabled' => $i < 2, 'delay' => [60, 1440, 4320][$i], 'title_en' => $titleEn, 'body_en' => $bodyEn, 'title_ar' => $titleAr, 'body_ar' => $bodyAr];
        }

        return ['reminders' => $reminders, 'max_per_cart' => self::DEFAULT_MAX_PER_CART, 'quiet' => true, 'quiet_start' => '22:00', 'quiet_end' => '08:00',
            'channels' => ['push' => true, 'in_app' => true, 'whatsapp' => false], 'offer' => ['promotion_id' => '', 'reminder' => 2, 'valid_hours' => 48]];
    }

    /**
     * The delay choices the window offers for a reminder: the first from its own list, the others only later than the reminder before.
     *
     * @return list<int>
     */
    public static function delayChoices(int $slot, int $previousDelay, int $current): array
    {
        $list = $slot === 0 ? self::FIRST_DELAYS : array_values(array_filter(self::LATER_DELAYS, fn (int $m) => $m >= $previousDelay + self::MIN_GAP_MINUTES));
        if (! in_array($current, $list, true)) {
            $list[] = $current; // a value set on the journeys page stays visible and valid
            sort($list);
        }

        return $list;
    }

    /**
     * Puts the delays in order: each reminder at least MIN_GAP_MINUTES after the one before, moving a later one forward to the next choice that fits.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public static function normalised(array $form): array
    {
        $previous = null;
        foreach ($form['reminders'] as $i => $r) {
            $delay = (int) $r['delay'];
            if ($previous !== null && $delay < $previous + self::MIN_GAP_MINUTES) {
                $delay = collect(self::LATER_DELAYS)->first(fn (int $m) => $m >= $previous + self::MIN_GAP_MINUTES) ?? ($previous + self::MIN_GAP_MINUTES);
            }
            $form['reminders'][$i]['delay'] = $delay;
            $previous = $delay;
        }

        return $form;
    }

    /**
     * A ready-made schedule laid over the form: the delays, which reminders are on and the limit per cart (texts, quiet hours and channels stay).
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public static function withPreset(array $form, string $preset): array
    {
        $p = self::PRESETS[$preset] ?? null;
        if ($p === null) {
            return $form;
        }
        foreach ($form['reminders'] as $i => $r) {
            $form['reminders'][$i]['enabled'] = isset($p['delays'][$i]);
            $form['reminders'][$i]['delay'] = $p['delays'][$i] ?? max((int) $r['delay'], ($p['delays'][$i - 1] ?? 0) + self::MIN_GAP_MINUTES);
        }
        $form['max_per_cart'] = $p['max'];
        if ((int) ($form['offer']['reminder'] ?? 0) > count($p['delays'])) {
            $form['offer']['reminder'] = count($p['delays']);
        }

        return self::normalised($form);
    }

    /** Which ready-made schedule the form matches, or null for a custom one. @param  array<string, mixed>  $form */
    public static function matchingPreset(array $form): ?string
    {
        $delays = collect($form['reminders'])->filter(fn (array $r) => $r['enabled'])->pluck('delay')->map(fn ($d) => (int) $d)->values()->all();
        foreach (self::PRESETS as $key => $p) {
            if ($delays === $p['delays'] && (int) $form['max_per_cart'] === $p['max']) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Checks a settings form from the window and returns it cleaned. Anything wrong comes back as one ValidationException with plain messages,
     * keyed like the form (`reminders.1.delay`).
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(array $form): array
    {
        $form = $this->completed($form);
        $v = Validator::make($form, [
            'reminders' => ['required', 'array', 'size:'.self::SLOTS],
            'reminders.*.enabled' => ['required', 'boolean'],
            'reminders.*.delay' => ['required', 'integer', 'min:30', 'max:10079'],
            'reminders.*.title_en' => ['required', 'string', 'max:80'],
            'reminders.*.body_en' => ['required', 'string', 'max:300'],
            'reminders.*.title_ar' => ['required', 'string', 'max:80'],
            'reminders.*.body_ar' => ['required', 'string', 'max:300'],
            'max_per_cart' => ['required', 'integer', 'min:1', 'max:5'],
            'quiet' => ['required', 'boolean'],
            'quiet_start' => [$form['quiet'] ? 'required' : 'nullable', 'date_format:H:i'],
            'quiet_end' => [$form['quiet'] ? 'required' : 'nullable', 'date_format:H:i'],
            'channels' => ['required', 'array'],
            'channels.push' => ['boolean'],
            'channels.in_app' => ['boolean'],
            'channels.whatsapp' => ['boolean'],
            'offer' => ['required', 'array'],
            'offer.promotion_id' => ['nullable', 'uuid'],
            'offer.reminder' => ['required', 'integer', 'between:1,'.self::SLOTS],
            'offer.valid_hours' => ['required', 'integer', 'between:1,720'],
        ], [], $this->attributeNames());
        $v->after(function ($v) use ($form) {
            $this->checkDelays($v, $form);
            $this->checkChannels($v, $form);
            $this->checkOffer($v, $form);
            $this->checkTexts($v, $form);
            if ($form['quiet'] && ($form['quiet_start'] ?? '') === ($form['quiet_end'] ?? '')) {
                $v->errors()->add('quiet_end', __('cart_reminders.err_quiet_same'));
            }
        });
        $v->validate();

        return $form;
    }

    /**
     * Saves the settings: the journey's rules, its first three steps and their message templates, in one transaction. Audited.
     *
     * @param  array<string, mixed>  $form
     *
     * @throws ValidationException
     */
    public function save(array $form, ?User $actor = null): void
    {
        $form = $this->validate($form);
        $journey = $this->journey();
        $before = $this->form();
        DB::transaction(fn () => $this->persist($journey, $form, false));
        $this->audit->log('marketing.cart_reminders_saved', $journey, $this->auditable($before), $this->auditable($form), ['key' => self::KEY], actor: $actor);
        app(JourneyService::class)->wake($journey);
        $this->forgetResults();
    }

    /**
     * What the settings do, in one plain sentence (the owner reads it before turning the switch on).
     *
     * @param  array<string, mixed>  $form
     */
    public function summary(array $form, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $on = collect($form['reminders'])->filter(fn (array $r) => $r['enabled'])->values();
        if ($on->isEmpty()) {
            return (string) trans('cart_reminders.summary_none', [], $locale);
        }
        $label = fn (int $m) => (string) trans(self::delayKey($m), ['n' => self::delayNumber($m)], $locale);
        $later = $on->slice(1)->map(fn (array $r) => $label((int) $r['delay']))->values()->all();
        $max = min((int) $form['max_per_cart'], 99);

        return (string) trans('cart_reminders.summary', [
            'first' => $label((int) $on->first()['delay']),
            'later' => $later === [] ? '' : trans('cart_reminders.summary_later', ['list' => self::joinList($later, $locale)], $locale),
            'window' => $form['quiet'] && ($form['quiet_start'] ?? '') !== '' && ($form['quiet_end'] ?? '') !== ''
                ? trans('cart_reminders.summary_window', ['from' => $form['quiet_end'], 'to' => $form['quiet_start']], $locale)
                : trans('cart_reminders.summary_any_hour', [], $locale),
            'max' => trans_choice('cart_reminders.max_times', $max, ['count' => $max], $locale),
        ], $locale);
    }

    /** "1 hour", "1 day", "30 minutes" in the given language. */
    public static function delayLabel(int $minutes, ?string $locale = null): string
    {
        return (string) trans_choice(self::delayKey($minutes), self::delayNumber($minutes), ['n' => self::delayNumber($minutes)], $locale);
    }

    // ── What it did ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Results of the last $days days for the customers' carts the viewer may see: reminders sent (one per step, however many channels carried it),
     * reminders that could not be sent because of an error, carts won back (the customer ordered after a reminder) and their revenue.
     * Cached for a minute (the page re-renders on every keystroke of its search box).
     *
     * @return array{sent: int, failed: int, recovered: int, revenue: string}
     */
    public function results(User $viewer, int $days = 7): array
    {
        $days = in_array($days, [7, 30], true) ? $days : 7;
        $scope = $this->access->scope($viewer, 'orders.view');
        $key = 'cart-reminders:results:'.($scope->all ? 'all' : md5(implode(',', collect($scope->branchIds)->sort()->all()))).':'.$days;

        /** @var array{sent: int, failed: int, recovered: int, revenue: string} */
        return Cache::remember($key, 60, fn () => $this->computeResults($scope, $days));
    }

    public function forgetResults(): void
    {
        foreach ([7, 30] as $days) {
            Cache::forget('cart-reminders:results:all:'.$days);
        }
    }

    /** @return array{sent: int, failed: int, recovered: int, revenue: string} */
    private function computeResults(BranchScope $scope, int $days): array
    {
        $none = ['sent' => 0, 'failed' => 0, 'recovered' => 0, 'revenue' => '0.00'];
        $journeyId = Journey::where('key', self::KEY)->value('id');
        if ($journeyId === null || $scope->isEmpty()) {
            return $none;
        }
        $since = now()->subDays($days);
        $visible = fn ($q, string $column) => $scope->all ? $q : $q->whereIn($column, CartVisibility::apply(Cart::query(), $scope)->select('carts.id'));
        $step = DB::raw('(d.run_id::text || coalesce(d.step_id::text, d.id::text))');
        $deliveries = fn (array $statuses, string $time) => $visible(DB::table('journey_deliveries as d')->join('journey_runs as r', 'r.id', '=', 'd.run_id')
            ->where('r.journey_id', $journeyId)->whereIn('d.status', $statuses)->where('d.'.$time, '>=', $since)->distinct(), 'r.cart_id');
        $won = $visible(DB::table('journey_runs as r')->where('r.journey_id', $journeyId)->where('r.status', 'converted')->where('r.converted_at', '>=', $since), 'r.cart_id');

        return [
            'sent' => (int) $deliveries(['sent', 'delivered'], 'sent_at')->count($step),
            'failed' => (int) $deliveries(['failed'], 'created_at')->count($step),
            'recovered' => (int) (clone $won)->count(),
            'revenue' => number_format((float) (clone $won)->sum('r.revenue'), 2, '.', ''),
        ];
    }

    /**
     * What happened to one cart: every reminder (automatic ones with the reason for any channel that did not go out, and the ones staff sent by hand),
     * oldest first, and the next automatic reminder when there is one.
     *
     * @return array{items: list<array<string, mixed>>, next: ?array{number: int, at: CarbonImmutable}}
     */
    public function history(Cart $cart): array
    {
        $items = [];
        $anchor = $cart->last_activity_at;
        $runIds = $anchor === null ? [] : JourneyRun::query()->where('cart_id', $cart->id)->where('anchor_at', $anchor)
            ->whereIn('journey_id', Journey::where('key', self::KEY)->select('id'))->pluck('id')->all();
        if ($runIds !== []) {
            JourneyDelivery::query()->whereIn('run_id', $runIds)->with('step:id,position')->orderBy('created_at')->get()
                ->groupBy(fn (JourneyDelivery $d) => $d->step_id ?? $d->id)->each(function ($rows) use (&$items) {
                    /** @var \Illuminate\Support\Collection<int, JourneyDelivery> $rows */
                    $sent = $rows->filter(fn (JourneyDelivery $d) => in_array($d->status, ['sent', 'delivered'], true));
                    $issues = $rows->reject(fn (JourneyDelivery $d) => in_array($d->status, ['sent', 'delivered'], true))
                        ->map(fn (JourneyDelivery $d) => ['channel' => $d->channel, 'failed' => $d->status === 'failed', 'reason' => self::reasonText($d->reason)])->values()->all();
                    $items[] = ['kind' => 'automatic', 'number' => (int) ($rows->first()?->step?->position ?? 0), 'at' => $sent->max('sent_at') ?? $rows->first()?->created_at,
                        'channels' => $sent->pluck('channel')->unique()->values()->all(), 'issues' => $issues, 'by' => null];
                });
        }
        $anchorAt = $anchor ?? $cart->created_at;
        AuditLog::query()->where('auditable_type', 'Cart')->where('auditable_id', $cart->id)->where('action', 'cart.reminder_sent')->where('created_at', '>=', $anchorAt)
            ->where(fn ($q) => $q->whereNull('metadata->source')->orWhere('metadata->source', 'staff'))->with('actor:id,name')->orderBy('created_at')->get()
            ->each(function (AuditLog $a) use (&$items) {
                $items[] = ['kind' => 'staff', 'number' => 0, 'at' => $a->created_at, 'channels' => array_values((array) ($a->new_values['channels'] ?? [])), 'issues' => [], 'by' => $a->actor?->name];
            });
        usort($items, fn (array $a, array $b) => ($a['at']?->getTimestamp() ?? 0) <=> ($b['at']?->getTimestamp() ?? 0));

        return ['items' => $items, 'next' => $this->nextFor($cart)];
    }

    /** The plain reason a reminder did not go out on a channel. */
    public static function reasonText(?string $reason): string
    {
        $reason = (string) $reason;
        $key = str_starts_with($reason, 'n8n_http_') ? 'n8n_http' : $reason;

        return trans()->has('cart_reminders.reason.'.$key) ? (string) __('cart_reminders.reason.'.$key) : (string) __('cart_reminders.reason.unknown');
    }

    /**
     * The next automatic reminder for a cart that is waiting: which one and when (pushed past quiet hours), or null when none is coming
     * (switched off, blocked by a notification rule, the limit per cart is reached or every reminder has been handled).
     *
     * @return array{number: int, at: CarbonImmutable}|null
     */
    public function nextFor(Cart $cart): ?array
    {
        $journey = Journey::where('key', self::KEY)->first();
        if (! $journey || ! $journey->is_enabled || ! $this->allowed() || ! $cart->last_activity_at || (int) $cart->abandoned_notifications >= $this->maxPerCart()) {
            return null;
        }
        $handled = JourneyDelivery::query()->whereIn('run_id', JourneyRun::where('journey_id', $journey->id)->where('cart_id', $cart->id)->where('anchor_at', $cart->last_activity_at)->select('id'))
            ->pluck('step_id')->filter()->all();
        $quiet = self::quietHours($journey);
        foreach ($journey->steps()->where('is_enabled', true)->orderBy('position')->get() as $step) {
            $due = $cart->last_activity_at->addMinutes((int) $step->delay_minutes);
            if (in_array($step->id, $handled, true) || $due->addHours(self::GRACE_HOURS)->isPast()) {
                continue;
            }
            $at = $due->isPast() ? CarbonImmutable::now() : $due;

            return ['number' => (int) $step->position, 'at' => $quiet->endAfter($at)];
        }

        return null;
    }

    public static function quietHours(Journey $journey): QuietHours
    {
        return new QuietHours(($journey->setting('quiet_start') ?: null), ($journey->setting('quiet_end') ?: null));
    }

    // ── Starting point ───────────────────────────────────────────────────────────────────────────────────────────────

    private function seed(): Journey
    {
        try {
            return DB::transaction(function () {
                $journey = Journey::create(['key' => self::KEY, 'name' => 'Abandoned cart', 'is_enabled' => false, 'settings' => ['max_per_day' => 3, 'attribution_days' => 7]]);
                $this->persist($journey, $this->defaults(), true);

                return $journey;
            });
        } catch (UniqueConstraintViolationException) {
            return Journey::where('key', self::KEY)->firstOrFail(); // the scheduler and a page created it at the same moment
        }
    }

    /**
     * A journey from the first version that was never switched on, never ran and still has its original starter steps gets the new default schedule and
     * texts. Anything the owner changed or used is left exactly as it is. Looked at once (`defaults_version`).
     */
    private function upgradeUnusedStarter(Journey $journey): void
    {
        if ((int) $journey->setting('defaults_version', 0) >= 2) {
            return;
        }
        $steps = $journey->steps()->with('template')->get();
        $original = ! $journey->is_enabled && $steps->count() === 3 && $steps->pluck('delay_minutes')->all() === [30, 240, 4320]
            && $steps[0]->template?->body_en === self::LEGACY_FIRST_BODY && $steps->every(fn (JourneyStep $s) => $s->is_enabled)
            && ! JourneyRun::where('journey_id', $journey->id)->exists();
        DB::transaction(function () use ($journey, $original) {
            if ($original) {
                $this->persist($journey, $this->defaults(), true);
            } else {
                $journey->update(['settings' => array_merge($journey->settings ?? [], ['defaults_version' => 2])]);
            }
        });
        $journey->refresh();
    }

    /**
     * Writes a validated form into the journey. Reminder N is step N; a template that another step also uses is copied first, so editing one
     * reminder never changes another. Reminders after the third, and the WhatsApp wording of a template, are not touched.
     *
     * @param  array<string, mixed>  $form
     */
    private function persist(Journey $journey, array $form, bool $seeding): void
    {
        $settings = array_merge($journey->settings ?? [], ['quiet_start' => $form['quiet'] ? $form['quiet_start'] : null, 'quiet_end' => $form['quiet'] ? $form['quiet_end'] : null,
            'max_per_cart' => (int) $form['max_per_cart'], 'defaults_version' => 2]);
        $settings += ['max_per_day' => 3, 'attribution_days' => 7];
        $journey->update(['settings' => $settings]);
        $channels = self::channelKeys($form['channels']);
        $steps = $journey->steps()->with('template')->get()->values();
        $offerSlot = ($form['offer']['promotion_id'] ?? '') !== '' ? (int) $form['offer']['reminder'] - 1 : -1;
        foreach ($form['reminders'] as $i => $r) {
            $step = $steps[$i] ?? null;
            if ($step === null && ! $r['enabled']) {
                continue;
            }
            $template = $step?->template;
            if ($template === null || ($step && JourneyStep::where('template_id', $template->id)->where('id', '!=', $step->id)->exists())) {
                $template = new JourneyTemplate(['name' => 'Abandoned cart · reminder '.($i + 1), 'whatsapp_en' => null, 'whatsapp_ar' => null]);
            } elseif ($seeding) {
                $template->name = 'Abandoned cart · reminder '.($i + 1);
            }
            $withOffer = $i === $offerSlot;
            $template->fill(['kind' => $withOffer ? 'offer' : 'reminder', 'title_en' => $r['title_en'], 'body_en' => $r['body_en'], 'title_ar' => $r['title_ar'], 'body_ar' => $r['body_ar'],
                'deep_link' => $template->deep_link ?: 'albahar://cart', 'is_active' => true, 'promotion_id' => $withOffer ? $form['offer']['promotion_id'] : null,
                'coupon_valid_hours' => $withOffer ? (int) $form['offer']['valid_hours'] : (int) ($template->coupon_valid_hours ?: 72), 'coupon_prefix' => $template->coupon_prefix ?: 'BAHAR'])->save();
            $step ??= new JourneyStep(['journey_id' => $journey->id]);
            $step->fill(['position' => $i + 1, 'label' => $step->label ?: __('cart_reminders.step_label', ['n' => $i + 1], 'en'), 'delay_minutes' => (int) $r['delay'], 'offset_days' => null, 'send_time' => null,
                'channels' => $channels, 'template_id' => $template->id, 'is_enabled' => (bool) $r['enabled']])->save();
        }
        $journey->steps()->where('position', '>', self::SLOTS)->get()->each(fn (JourneyStep $s, int $n) => $s->update(['position' => max(self::SLOTS, $steps->count() ? self::SLOTS : 0) + 1 + $n]));
    }

    // ── Validation helpers ───────────────────────────────────────────────────────────────────────────────────────────

    /**
     * A reminder that is switched off may have empty text (it is stored with the defaults), and a form from a stale or forged request is brought to shape.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    private function completed(array $form): array
    {
        $defaults = $this->defaults();
        $form['quiet'] = filter_var($form['quiet'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $form['channels'] = ['push' => (bool) ($form['channels']['push'] ?? false), 'in_app' => (bool) ($form['channels']['in_app'] ?? false), 'whatsapp' => (bool) ($form['channels']['whatsapp'] ?? false)];
        $form['offer'] = (array) ($form['offer'] ?? $defaults['offer']);
        $form['offer']['promotion_id'] = (string) ($form['offer']['promotion_id'] ?? '');
        $form['offer']['reminder'] ??= 2;
        $form['offer']['valid_hours'] ??= 48;
        $reminders = array_values((array) ($form['reminders'] ?? []));
        foreach ($reminders as $i => $r) {
            $r = (array) $r;
            $r['enabled'] = filter_var($r['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
            foreach (['title_en', 'body_en', 'title_ar', 'body_ar'] as $field) {
                $r[$field] = trim((string) ($r[$field] ?? ''));
                if (! $r['enabled'] && $r[$field] === '' && isset($defaults['reminders'][$i])) {
                    $r[$field] = $defaults['reminders'][$i][$field];
                }
            }
            $reminders[$i] = $r;
        }
        $form['reminders'] = $reminders;

        return $form;
    }

    /** @param  array<string, mixed>  $form */
    private function checkDelays($v, array $form): void
    {
        $previous = null;
        foreach ($form['reminders'] as $i => $r) {
            $delay = (int) ($r['delay'] ?? 0);
            if ($previous !== null && $delay < $previous + self::MIN_GAP_MINUTES) {
                $v->errors()->add("reminders.$i.delay", __('cart_reminders.err_delay_order', ['gap' => self::delayLabel(self::MIN_GAP_MINUTES)]));
            }
            $previous = $delay;
        }
        if (! collect($form['reminders'])->contains(fn (array $r) => $r['enabled'])) {
            $v->errors()->add('reminders.0.enabled', __('cart_reminders.err_no_reminder'));
        }
    }

    /** @param  array<string, mixed>  $form */
    private function checkChannels($v, array $form): void
    {
        if (self::channelKeys($form['channels']) === []) {
            $v->errors()->add('channels', __('cart_reminders.err_no_channel_chosen'));
        }
    }

    /** @param  array<string, mixed>  $form */
    private function checkOffer($v, array $form): void
    {
        $id = (string) ($form['offer']['promotion_id'] ?? '');
        if ($id === '') {
            return;
        }
        $promotion = Promotion::find($id);
        if (! $promotion || ! $promotion->is_active || ! $promotion->requires_coupon || ($promotion->ends_at && $promotion->ends_at->isPast())) {
            $v->errors()->add('offer.promotion_id', __('cart_reminders.err_offer_promotion'));
        }
        $slot = (int) $form['offer']['reminder'] - 1;
        if (! ($form['reminders'][$slot]['enabled'] ?? false)) {
            $v->errors()->add('offer.reminder', __('cart_reminders.err_offer_reminder_off'));
        }
    }

    /** @param  array<string, mixed>  $form */
    private function checkTexts($v, array $form): void
    {
        $offerSlot = ($form['offer']['promotion_id'] ?? '') !== '' ? (int) $form['offer']['reminder'] - 1 : -1;
        foreach ($form['reminders'] as $i => $r) {
            foreach (['title_en', 'body_en', 'title_ar', 'body_ar'] as $field) {
                $used = ReminderText::used((string) ($r[$field] ?? ''));
                $unknown = array_values(array_diff($used, ReminderText::PLACEHOLDERS));
                if ($unknown !== []) {
                    $v->errors()->add("reminders.$i.$field", __('cart_reminders.err_placeholder', ['name' => '{{'.$unknown[0].'}}']));
                } elseif ($i !== $offerSlot && array_intersect($used, ReminderText::OFFER_ONLY) !== []) {
                    $v->errors()->add("reminders.$i.$field", __('cart_reminders.err_offer_placeholder', ['name' => '{{'.array_values(array_intersect($used, ReminderText::OFFER_ONLY))[0].'}}']));
                }
            }
        }
    }

    /** @return array<string, string> */
    private function attributeNames(): array
    {
        $names = ['max_per_cart' => __('cart_reminders.max_per_cart'), 'quiet_start' => __('cart_reminders.quiet_from'), 'quiet_end' => __('cart_reminders.quiet_to'), 'channels' => __('cart_reminders.channels'),
            'offer.promotion_id' => __('cart_reminders.offer'), 'offer.reminder' => __('cart_reminders.offer_with'), 'offer.valid_hours' => __('cart_reminders.offer_valid')];
        foreach (range(0, self::SLOTS - 1) as $i) {
            $n = $i + 1;
            $names += ["reminders.$i.delay" => __('cart_reminders.reminder_n_after', ['n' => $n]), "reminders.$i.title_en" => __('cart_reminders.title_en_n', ['n' => $n]), "reminders.$i.body_en" => __('cart_reminders.body_en_n', ['n' => $n]),
                "reminders.$i.title_ar" => __('cart_reminders.title_ar_n', ['n' => $n]), "reminders.$i.body_ar" => __('cart_reminders.body_ar_n', ['n' => $n])];
        }

        return $names;
    }

    /**
     * The channels a form turned on, in sending order.
     *
     * @param  array<string, mixed>  $channels
     * @return list<string>
     */
    public static function channelKeys(array $channels): array
    {
        return array_values(array_filter(JourneyService::CHANNELS, fn (string $c) => ! empty($channels[$c])));
    }

    /**
     * What goes into the audit record: the settings without the long texts.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    private function auditable(array $form): array
    {
        return ['reminders' => collect($form['reminders'])->map(fn (array $r) => ['on' => (bool) $r['enabled'], 'delay' => (int) $r['delay'], 'text' => substr(sha1($r['title_en'].$r['body_en'].$r['title_ar'].$r['body_ar']), 0, 10)])->all(),
            'max_per_cart' => (int) $form['max_per_cart'], 'quiet' => $form['quiet'] ? $form['quiet_start'].'-'.$form['quiet_end'] : null, 'channels' => self::channelKeys($form['channels']),
            'offer' => ($form['offer']['promotion_id'] ?? '') !== '' ? ['promotion' => $form['offer']['promotion_id'], 'reminder' => (int) $form['offer']['reminder'], 'hours' => (int) $form['offer']['valid_hours']] : null];
    }

    private static function delayKey(int $minutes): string
    {
        return $minutes >= 1440 && $minutes % 1440 === 0 ? 'journeys_admin.n_days' : ($minutes >= 60 && $minutes % 60 === 0 ? 'journeys_admin.n_hours' : 'journeys_admin.n_minutes');
    }

    private static function delayNumber(int $minutes): int
    {
        return $minutes >= 1440 && $minutes % 1440 === 0 ? intdiv($minutes, 1440) : ($minutes >= 60 && $minutes % 60 === 0 ? intdiv($minutes, 60) : $minutes);
    }

    /** @param  list<string>  $items */
    private static function joinList(array $items, string $locale): string
    {
        if (count($items) < 2) {
            return (string) ($items[0] ?? '');
        }
        $last = array_pop($items);

        return implode((string) trans('cart_reminders.list_comma', [], $locale), $items).(string) trans('cart_reminders.list_and', [], $locale).$last;
    }
}
