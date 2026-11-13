<?php

namespace App\Domain\Marketing\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Marketing\Models\Journey;
use App\Domain\Marketing\Models\JourneyDelivery;
use App\Domain\Marketing\Models\JourneyRun;
use App\Domain\Marketing\Models\JourneyStep;
use App\Domain\Marketing\Models\JourneyTemplate;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Services\FcmClient;
use App\Domain\Notifications\Services\N8nClient;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Notifications\Services\WhatsAppClient;
use App\Domain\Orders\Models\Cart;
use App\Domain\Orders\Models\Order;
use App\Support\BusinessTime;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Customer journeys: a run follows one customer through timed steps (abandoned cart: minutes after the last cart activity;
 * birthday: days around the birthday at a set time). A step sends its template on push / in-app / WhatsApp (via n8n), in the
 * customer's app language, optionally with a personal one-time coupon. Idempotent: (run, step, channel) is unique, so a
 * repeated tick can never double-send. Marketing consent and per-channel preferences are always honoured.
 */
class JourneyService
{
    public const CHANNELS = ['push', 'in_app', 'whatsapp'];

    /** Messages are skipped, never sent late: abandoned-cart steps expire after this many hours, birthday steps after the hour count below. */
    private const ABANDONED_GRACE_HOURS = 48;

    private const BIRTHDAY_GRACE_HOURS = 18;

    public function __construct(
        private readonly NotificationService $notify,
        private readonly FcmClient $fcm,
        private readonly N8nClient $n8n,
        private readonly WhatsAppClient $whatsapp,
    ) {}

    /** One scheduler pass. @return array{started: int, sent: int, converted: int, stopped: int} */
    public function tick(): array
    {
        $out = ['started' => 0, 'sent' => 0, 'converted' => 0, 'stopped' => 0];
        $this->ensureDefaults();
        foreach (Journey::all() as $any) {
            $out['converted'] += $this->attributeLateOrders($any); // a paused journey still gets credit for earlier messages
        }
        foreach (Journey::where('is_enabled', true)->with(['steps' => fn ($q) => $q->where('is_enabled', true)->with('template')])->get() as $journey) {
            if ($journey->steps->isEmpty()) {
                continue;
            }
            if ($journey->key === 'abandoned_cart') {
                [$c, $s] = $this->settleAbandonedRuns($journey);
                $out['converted'] += $c;
                $out['stopped'] += $s;
                $out['started'] += $this->startAbandonedRuns($journey);
            } elseif ($journey->key === 'birthday') {
                $out['started'] += $this->startBirthdayRuns($journey);
            }
            $out['sent'] += $this->dispatchDue($journey);
        }

        return $out;
    }

    /* ───────────────────────── runs ───────────────────────── */

    private function startAbandonedRuns(Journey $journey): int
    {
        $first = (int) $journey->steps->min('delay_minutes');
        $n = 0;
        Cart::query()->where('status', 'active')->whereNotNull('user_id')->has('items')
            ->where('last_activity_at', '<=', now()->subMinutes($first))->where('last_activity_at', '>=', now()->subDays(7))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('journey_runs')->whereColumn('journey_runs.user_id', 'carts.user_id')
                ->where('journey_runs.journey_id', $journey->id)->where('journey_runs.status', 'active'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('journey_runs')->whereColumn('journey_runs.cart_id', 'carts.id')
                ->where('journey_runs.journey_id', $journey->id)->whereColumn('journey_runs.anchor_at', 'carts.last_activity_at'))
            ->orderBy('last_activity_at')->limit(300)->get(['id', 'user_id', 'last_activity_at'])
            ->each(function (Cart $cart) use ($journey, &$n) {
                $run = JourneyRun::firstOrCreate(['journey_id' => $journey->id, 'user_id' => $cart->user_id, 'anchor_at' => $cart->last_activity_at], ['cart_id' => $cart->id]);
                $n += $run->wasRecentlyCreated ? 1 : 0;
            });

        return $n;
    }

    /** Active abandoned-cart runs end when the customer orders (converted), empties the cart or comes back and leaves again. @return array{0: int, 1: int} */
    private function settleAbandonedRuns(Journey $journey): array
    {
        $converted = $stopped = 0;
        JourneyRun::with('user')->where('journey_id', $journey->id)->where('status', 'active')->limit(500)->get()->each(function (JourneyRun $run) use (&$converted, &$stopped) {
            $cart = $run->cart_id ? Cart::withCount('items')->find($run->cart_id) : null;
            $order = Order::where('user_id', $run->user_id)->where('placed_at', '>=', $run->anchor_at)->whereNotIn('status', ['cancelled', 'pending_payment'])->orderBy('placed_at')->first();
            if ($order || $cart?->status === 'converted') {
                $order ??= Order::where('user_id', $run->user_id)->where('placed_at', '>=', $run->anchor_at)->orderBy('placed_at')->first();
                if ($order && $this->credit($run, $order)) {
                    $converted++;
                } else {
                    $this->finish($run, 'stopped', 'ordered_on_their_own'); // they ordered before any message reached them: not our win
                    $stopped++;
                }
            } elseif (! $cart || $cart->items_count === 0) {
                $this->finish($run, 'stopped', 'cart_empty');
                $stopped++;
            } elseif ($cart->last_activity_at && $cart->last_activity_at->gt($run->anchor_at)) {
                $this->finish($run, 'stopped', 'customer_returned');
                $stopped++;
            }
        });

        return [$converted, $stopped];
    }

    private function startBirthdayRuns(Journey $journey): int
    {
        $offsets = $journey->steps->pluck('offset_days')->map(fn ($v) => (int) $v);
        $today = BusinessTime::now()->startOfDay();
        $n = 0;
        // A birthday on date D is in play while today ∈ [D + minOffset, D + maxOffset].
        for ($back = (int) $offsets->min() * -1; $back >= (int) $offsets->max() * -1; $back--) {
            $d = $today->addDays($back);
            $dobDays = [$d];
            if ($d->month === 2 && $d->day === 28 && ! $d->isLeapYear()) {
                $dobDays[] = $d->setDay(29); // Feb 29 birthdays are celebrated on Feb 28 in common years
            }
            User::query()->where('type', 'customer')->where('status', 'active')->whereNotNull('marketing_consent_at')->whereNotNull('date_of_birth')
                ->where(function ($q) use ($dobDays) {
                    foreach ($dobDays as $day) {
                        $q->orWhere(fn ($w) => $w->whereRaw('EXTRACT(MONTH FROM date_of_birth) = ?', [$day->month])->whereRaw('EXTRACT(DAY FROM date_of_birth) = ?', [$day->day]));
                    }
                })->limit(1000)->get(['id'])->each(function (User $u) use ($journey, $d, &$n) {
                    $run = JourneyRun::firstOrCreate(['journey_id' => $journey->id, 'user_id' => $u->id, 'anchor_at' => $d->startOfDay()->utc()]);
                    $n += $run->wasRecentlyCreated ? 1 : 0;
                });
        }

        return $n;
    }

    /**
     * Credits an order to a run when a message of the run was SENT before the order (and within the attribution window).
     * The order is credited to the last such message, and to one run only, so revenue is never counted twice.
     */
    private function credit(JourneyRun $run, Order $order): bool
    {
        $window = max(1, (int) $run->journey->setting('attribution_days', 7));
        $touch = JourneyDelivery::where('run_id', $run->id)->whereIn('status', ['sent', 'delivered'])->where('sent_at', '<=', $order->placed_at)
            ->where('sent_at', '>=', $order->placed_at->subDays($window))->orderByDesc('sent_at')->first();
        if (! $touch || JourneyRun::where('order_id', $order->id)->exists()) {
            return false;
        }
        $run->update(['status' => 'converted', 'stop_reason' => null, 'order_id' => $order->id, 'revenue' => (float) $order->grand_total, 'converted_at' => $order->placed_at,
            'attributed_delivery_id' => $touch->id, 'finished_at' => $run->finished_at ?? now()]);

        return true;
    }

    /** Runs that already ended (all steps sent, or the customer came back) can still win the order later within the window. @return int orders credited */
    private function attributeLateOrders(Journey $journey): int
    {
        $n = 0;
        $window = max(1, (int) $journey->setting('attribution_days', 7));
        JourneyRun::with('journey')->where('journey_id', $journey->id)->whereIn('status', ['completed', 'stopped'])->whereNull('order_id')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('journey_deliveries')->whereColumn('journey_deliveries.run_id', 'journey_runs.id')->whereIn('journey_deliveries.status', ['sent', 'delivered'])
                ->where('journey_deliveries.sent_at', '>=', now()->subDays($window + 1)))
            ->limit(500)->get()->each(function (JourneyRun $run) use (&$n) {
                $first = JourneyDelivery::where('run_id', $run->id)->whereIn('status', ['sent', 'delivered'])->min('sent_at');
                $order = $first ? Order::where('user_id', $run->user_id)->where('placed_at', '>=', $first)->whereNotIn('status', ['cancelled', 'pending_payment'])->orderBy('placed_at')->first() : null;
                $n += $order && $this->credit($run, $order) ? 1 : 0;
            });

        return $n;
    }

    private function finish(JourneyRun $run, string $status, ?string $reason = null, ?string $orderId = null, float $revenue = 0): void
    {
        $run->update(['status' => $status, 'stop_reason' => $reason, 'order_id' => $orderId, 'revenue' => $revenue, 'finished_at' => now()]);
    }

    /* ───────────────────────── dispatching ───────────────────────── */

    private function dispatchDue(Journey $journey): int
    {
        $sent = 0;
        JourneyRun::with(['user', 'deliveries'])->where('journey_id', $journey->id)->where('status', 'active')->orderBy('anchor_at')->limit(300)->get()
            ->each(function (JourneyRun $run) use ($journey, &$sent) {
                $pending = 0;
                foreach ($journey->steps as $step) {
                    [$due, $expires] = $this->window($journey, $run, $step);
                    if (now()->lt($due)) {
                        $pending++;

                        continue;
                    }
                    if (now()->gt($expires) || ! $step->template || ! $step->template->is_active) {
                        continue; // too late to be useful (or nothing to send): skipped, never sent late
                    }
                    if ($this->inQuietHours($journey)) {
                        $pending++;

                        continue;
                    }
                    foreach (array_intersect(self::CHANNELS, $step->channels ?? []) as $channel) {
                        if ($run->deliveries->contains(fn ($d) => $d->step_id === $step->id && $d->channel === $channel)) {
                            continue;
                        }
                        if ($this->overDailyCap($journey, $run->user_id)) {
                            $pending++;

                            continue 2;
                        }
                        $d = $this->deliver($run, $step, $channel);
                        $sent += $d?->status === 'sent' ? 1 : 0;
                    }
                }
                if ($pending === 0) {
                    $this->finish($run->fresh(), 'completed');
                }
            });

        return $sent;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} when the step becomes due and when it stops being worth sending (UTC) */
    private function window(Journey $journey, JourneyRun $run, JourneyStep $step): array
    {
        if ($journey->key === 'birthday') {
            $day = BusinessTime::local($run->anchor_at)->startOfDay()->addDays((int) $step->offset_days);
            [$h, $m] = array_map('intval', explode(':', $step->send_time ?: '10:00'));
            $due = $day->setTime($h, $m)->utc();

            return [$due, $due->addHours(self::BIRTHDAY_GRACE_HOURS)];
        }
        $due = CarbonImmutable::instance($run->anchor_at)->addMinutes((int) $step->delay_minutes);

        return [$due, $due->addHours(self::ABANDONED_GRACE_HOURS)];
    }

    private function inQuietHours(Journey $journey): bool
    {
        [$from, $to] = [$journey->setting('quiet_start'), $journey->setting('quiet_end')];
        if (! $from || ! $to || $from === $to) {
            return false;
        }
        $now = BusinessTime::now()->format('H:i');

        return $from < $to ? ($now >= $from && $now < $to) : ($now >= $from || $now < $to);
    }

    private function overDailyCap(Journey $journey, string $userId): bool
    {
        $cap = (int) $journey->setting('max_per_day', 3);

        return $cap > 0 && JourneyDelivery::where('user_id', $userId)->where('status', 'sent')->where('sent_at', '>=', now()->subDay())->count() >= $cap;
    }

    private function deliver(JourneyRun $run, JourneyStep $step, string $channel): ?JourneyDelivery
    {
        $user = $run->user;
        $locale = $user->preferredLocale();
        $d = JourneyDelivery::firstOrCreate(['run_id' => $run->id, 'step_id' => $step->id, 'channel' => $channel], ['user_id' => $user->id, 'locale' => $locale, 'status' => 'queued']);
        if (! $d->wasRecentlyCreated) {
            return $d;
        }
        try {
            $skip = $this->skipReason($user, $channel);
            if ($skip) {
                return $this->mark($d, 'skipped', $skip);
            }
            $coupon = $this->couponFor($run, $step, $user);
            $vars = $this->variables($run, $user, $step->template, $coupon);
            $d->update(['coupon_code' => $coupon?->code]);

            return match ($channel) {
                'in_app' => $this->sendInApp($d, $step->template, $user, $locale, $vars, $run),
                'push' => $this->sendPush($d, $step->template, $user, $vars, $run),
                'whatsapp' => $this->sendWhatsApp($d, $step->template, $user, $locale, $vars, $run, $step),
            };
        } catch (Throwable $e) {
            report($e);

            return $this->mark($d, 'failed', Str::limit($e->getMessage(), 45, ''));
        }
    }

    private function skipReason(User $user, string $channel): ?string
    {
        if (! $user->isActive()) {
            return 'inactive_user';
        }
        if (! $user->marketing_consent_at) {
            return 'no_marketing_consent';
        }
        if (! $this->notify->allowed($user, $channel, 'marketing')) {
            return $channel === 'whatsapp' ? 'no_whatsapp_optin' : 'opted_out';
        }

        return null;
    }

    private function sendInApp(JourneyDelivery $d, JourneyTemplate $t, User $user, string $locale, array $vars, JourneyRun $run): JourneyDelivery
    {
        [$title, $body] = $this->text($t, $locale, $vars);
        Notification::create(['user_id' => $user->id, 'event' => 'journey.'.$run->journey->key, 'category' => 'marketing', 'title' => $title, 'body' => $body,
            'data' => array_filter(['deep_link' => $this->deepLink($t, $vars), 'coupon' => $vars['coupon'] ?: null])]);

        return $this->mark($d, 'sent');
    }

    private function sendPush(JourneyDelivery $d, JourneyTemplate $t, User $user, array $vars, JourneyRun $run): JourneyDelivery
    {
        $devices = $user->devices()->whereNull('disabled_at')->get();
        if ($devices->isEmpty() || ! $this->fcm->configured()) {
            return $this->mark($d, 'skipped', $devices->isEmpty() ? 'no_devices' : 'push_not_configured');
        }
        $ok = 0;
        foreach ($devices as $device) {
            [$title, $body] = $this->text($t, $device->locale ?: $user->preferredLocale(), $vars); // the app language on that phone
            $res = $this->fcm->send($device->push_token, $title, $body, ['event' => 'journey.'.$run->journey->key, 'deep_link' => $this->deepLink($t, $vars),
                'coupon' => $vars['coupon'], 'delivery_id' => $d->id], $device);
            if ($res['unregistered'] ?? false) {
                $device->update(['disabled_at' => now()]);
            }
            $ok += ($res['ok'] ?? false) ? 1 : 0;
        }

        return $ok > 0 ? $this->mark($d, 'sent') : $this->mark($d, 'failed', 'push_rejected');
    }

    private function sendWhatsApp(JourneyDelivery $d, JourneyTemplate $t, User $user, string $locale, array $vars, JourneyRun $run, JourneyStep $step): JourneyDelivery
    {
        if (! $user->phone) {
            return $this->mark($d, 'skipped', 'no_phone');
        }
        $text = $this->whatsAppText($t, $locale, $vars);
        if ($this->n8n->configured()) {
            $res = $this->n8n->send($this->n8nPayload($d, $user, $locale, $text, $run, $step, $vars));

            return $res['ok'] ? $this->mark($d, 'sent', null, $res['id'] ?? null) : $this->mark($d, 'failed', 'n8n_http_'.($res['status'] ?? 0));
        }
        if ($this->whatsapp->configured()) {
            $r = $this->whatsapp->sendText($user->phone, $text);

            return $this->mark($d, 'sent', null, $r['id'] ?? null);
        }

        return $this->mark($d, 'skipped', 'whatsapp_not_configured');
    }

    private function n8nPayload(JourneyDelivery $d, User $user, string $locale, string $text, JourneyRun $run, JourneyStep $step, array $vars): array
    {
        return ['event' => 'journey.whatsapp', 'delivery_id' => $d->id, 'journey' => $run->journey->key, 'step' => $step->position, 'template' => $step->template?->name,
            'locale' => $locale, 'to' => $user->phone, 'customer' => ['id' => $user->id, 'name' => $vars['name']], 'message' => $text,
            'coupon' => $vars['coupon'] ? ['code' => $vars['coupon'], 'expires' => $vars['expires']] : null,
            'callback_url' => url('/api/v1/integrations/n8n/delivery'), 'sent_at' => now()->toIso8601String()];
    }

    private function mark(JourneyDelivery $d, string $status, ?string $reason = null, ?string $externalId = null): JourneyDelivery
    {
        $d->update(['status' => $status, 'reason' => $reason, 'external_id' => $externalId, 'sent_at' => $status === 'sent' ? now() : null]);

        return $d;
    }

    /* ───────────────────────── content ───────────────────────── */

    /** @return array{0: string, 1: string} localized title + body with placeholders filled */
    public function text(JourneyTemplate $t, string $locale, array $vars): array
    {
        $ar = $locale === 'ar';

        return [$this->fill($ar ? $t->title_ar : $t->title_en, $vars), $this->fill($ar ? $t->body_ar : $t->body_en, $vars)];
    }

    public function whatsAppText(JourneyTemplate $t, string $locale, array $vars): string
    {
        $ar = $locale === 'ar';
        $own = $ar ? $t->whatsapp_ar : $t->whatsapp_en;

        return $this->fill($own ?: (($ar ? $t->title_ar : $t->title_en)."\n".($ar ? $t->body_ar : $t->body_en)), $vars);
    }

    private function fill(string $s, array $vars): string
    {
        return trim(preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', fn ($m) => (string) ($vars[$m[1]] ?? ''), $s));
    }

    /** Placeholders available to every template. */
    public function variables(?JourneyRun $run, User $user, JourneyTemplate $t, ?Coupon $coupon): array
    {
        $ar = $user->preferredLocale() === 'ar';
        $cart = $run?->cart_id ? Cart::with('items.variant.product')->find($run->cart_id) : null;
        $first = $cart?->items->first()?->variant?->product;

        return [
            'name' => Str::before(trim($user->name), ' '),
            'coupon' => $coupon?->code ?? '',
            'expires' => $coupon?->ends_at ? BusinessTime::local($coupon->ends_at)->locale($ar ? 'ar' : 'en')->isoFormat('D MMM, HH:mm') : '',
            'items' => (string) ($cart?->items->sum('quantity') ?? ''),
            'first_item' => $first ? (string) $first->trans('name', $ar ? 'ar' : 'en') : '',
            'app_link' => rtrim((string) config('app.url'), '/'),
        ];
    }

    private function deepLink(JourneyTemplate $t, array $vars): string
    {
        return $t->deep_link ?: 'albahar://cart';
    }

    /** A personal, single-use coupon under the template's promotion — one per run+step, shared by all of the step's channels. */
    private function couponFor(JourneyRun $run, JourneyStep $step, User $user): ?Coupon
    {
        $t = $step->template;
        if (! $t?->promotion_id) {
            return null;
        }
        $existing = JourneyDelivery::where('run_id', $run->id)->where('step_id', $step->id)->whereNotNull('coupon_code')->value('coupon_code');
        if ($existing) {
            return Coupon::where('code', $existing)->first();
        }

        return $this->issueCoupon($t, $user);
    }

    public function issueCoupon(JourneyTemplate $t, User $user): Coupon
    {
        do {
            $code = strtoupper($t->coupon_prefix ?: 'BAHAR').'-'.strtoupper(Str::random(6));
        } while (Coupon::where('code', $code)->exists());

        return Coupon::create(['promotion_id' => $t->promotion_id, 'code' => $code, 'usage_limit' => 1, 'per_customer_limit' => 1, 'assigned_user_id' => $user->id,
            'is_active' => true, 'starts_at' => now(), 'ends_at' => now()->addHours(max(1, (int) $t->coupon_valid_hours))]);
    }

    /* ───────────────────────── staff tools ───────────────────────── */

    /** Sends a template to one staff member on the chosen channels (consent bypassed: internal recipient, no coupon issued). @return array<string, string> channel => result */
    public function sendTest(JourneyTemplate $t, User $to, array $channels, string $locale): array
    {
        $vars = ['name' => Str::before(trim($to->name), ' '), 'coupon' => 'TEST-0000', 'expires' => '', 'items' => '2', 'first_item' => $locale === 'ar' ? 'منتج تجريبي' : 'Sample product', 'app_link' => rtrim((string) config('app.url'), '/')];
        [$title, $body] = $this->text($t, $locale, $vars);
        $out = [];
        foreach (array_intersect(self::CHANNELS, $channels) as $ch) {
            try {
                $out[$ch] = match ($ch) {
                    'in_app' => (Notification::create(['user_id' => $to->id, 'event' => 'journey.test', 'category' => 'marketing', 'title' => '[TEST] '.$title, 'body' => $body, 'data' => ['deep_link' => $this->deepLink($t, $vars)]]) ? 'sent' : 'failed'),
                    'push' => $this->testPush($to, '[TEST] '.$title, $body, $this->deepLink($t, $vars)),
                    'whatsapp' => $this->testWhatsApp($to, $this->whatsAppText($t, $locale, $vars), $locale, $t),
                };
            } catch (Throwable $e) {
                report($e);
                $out[$ch] = 'failed';
            }
        }

        return $out;
    }

    private function testPush(User $to, string $title, string $body, string $link): string
    {
        $devices = $to->devices()->whereNull('disabled_at')->get();
        if ($devices->isEmpty() || ! $this->fcm->configured()) {
            return $devices->isEmpty() ? 'no_devices' : 'push_not_configured';
        }
        $ok = $devices->filter(fn ($dv) => $this->fcm->send($dv->push_token, $title, $body, ['event' => 'journey.test', 'deep_link' => $link], $dv)['ok'] ?? false)->count();

        return $ok ? 'sent' : 'failed';
    }

    private function testWhatsApp(User $to, string $text, string $locale, JourneyTemplate $t): string
    {
        if (! $to->phone) {
            return 'no_phone';
        }
        if ($this->n8n->configured()) {
            return $this->n8n->send(['event' => 'journey.whatsapp', 'test' => true, 'delivery_id' => null, 'journey' => 'test', 'template' => $t->name, 'locale' => $locale, 'to' => $to->phone,
                'customer' => ['id' => $to->id, 'name' => Str::before(trim($to->name), ' ')], 'message' => '[TEST] '.$text, 'coupon' => null, 'sent_at' => now()->toIso8601String()])['ok'] ? 'sent' : 'failed';
        }

        return $this->whatsapp->configured() ? ($this->whatsapp->sendText($to->phone, '[TEST] '.$text) ? 'sent' : 'failed') : 'whatsapp_not_configured';
    }

    /** n8n reports what happened to a WhatsApp message. */
    public function recordProviderStatus(string $deliveryId, string $status, ?string $externalId, ?string $reason): bool
    {
        $d = JourneyDelivery::find($deliveryId);
        if (! $d || $d->channel !== 'whatsapp') {
            return false;
        }
        $rank = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'failed' => 2, 'skipped' => 2];
        if (($rank[$status] ?? -1) < ($rank[$d->status] ?? 0)) {
            return true; // an older status arrived late: ignore
        }
        $d->update(['status' => $status, 'reason' => $status === 'failed' ? Str::limit((string) $reason, 45, '') : $d->reason, 'external_id' => $externalId ?: $d->external_id]);

        return true;
    }

    /** @return array{started: int, sent: int, failed: int, skipped: int, converted: int, revenue: float, rate: float} */
    public function stats(Journey $journey, int $days = 30): array
    {
        $since = now()->subDays($days);
        $runs = JourneyRun::where('journey_id', $journey->id)->where('created_at', '>=', $since);
        $started = (clone $runs)->count();
        $converted = (clone $runs)->where('status', 'converted')->count();
        $del = JourneyDelivery::whereIn('run_id', JourneyRun::where('journey_id', $journey->id)->where('created_at', '>=', $since)->select('id'))->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

        return ['started' => $started, 'sent' => (int) ($del['sent'] ?? 0) + (int) ($del['delivered'] ?? 0), 'failed' => (int) ($del['failed'] ?? 0), 'skipped' => (int) ($del['skipped'] ?? 0),
            'converted' => $converted, 'revenue' => (float) (clone $runs)->where('status', 'converted')->sum('revenue'), 'rate' => $started ? round($converted * 100 / $started, 1) : 0.0];
    }

    /** Orders won back by messages: converted runs with the message that is credited. @return \Illuminate\Database\Eloquent\Builder<JourneyRun> */
    public function recovered(int $days = 30, ?string $journeyKey = null, ?string $channel = null): Builder
    {
        return JourneyRun::query()->where('status', 'converted')->whereNotNull('order_id')->where('converted_at', '>=', now()->subDays($days))
            ->when($journeyKey, fn ($q, $k) => $q->whereHas('journey', fn ($j) => $j->where('key', $k)))
            ->when($channel, fn ($q, $c) => $q->whereHas('attributedDelivery', fn ($d) => $d->where('channel', $c)));
    }

    /** @return array{orders: int, revenue: float, avg_minutes: int, by_channel: array<string, array{orders: int, revenue: float}>, coupon_orders: int} */
    public function recoveryStats(int $days = 30, ?string $journeyKey = null): array
    {
        $rows = $this->recovered($days, $journeyKey)->with('attributedDelivery:id,channel,sent_at,coupon_code')->get(['id', 'order_id', 'revenue', 'converted_at', 'attributed_delivery_id']);
        $by = [];
        foreach ($rows as $r) {
            $ch = $r->attributedDelivery?->channel ?? 'push';
            $by[$ch] = ['orders' => ($by[$ch]['orders'] ?? 0) + 1, 'revenue' => ($by[$ch]['revenue'] ?? 0) + (float) $r->revenue];
        }
        $mins = $rows->map(fn ($r) => $r->attributedDelivery?->sent_at ? $r->attributedDelivery->sent_at->diffInMinutes($r->converted_at) : null)->filter(fn ($m) => $m !== null);
        $codes = $rows->pluck('attributedDelivery.coupon_code')->filter();
        $used = $codes->isEmpty() ? 0 : DB::table('order_coupons')->whereIn('code', $codes)->whereIn('order_id', $rows->pluck('order_id'))->count();

        return ['orders' => $rows->count(), 'revenue' => (float) $rows->sum('revenue'), 'avg_minutes' => (int) round($mins->avg() ?? 0), 'by_channel' => $by, 'coupon_orders' => $used];
    }

    /** What reached this customer, newest first (customer page, order page). @return \Illuminate\Support\Collection<int, JourneyDelivery> */
    public function touchesFor(string $userId, int $limit = 12): Collection
    {
        return JourneyDelivery::with(['run.journey:id,key', 'step:id,label,position'])->where('user_id', $userId)->whereIn('status', ['sent', 'delivered'])->latest('sent_at')->limit($limit)->get();
    }

    /** First visit: both journeys exist (switched off) with sensible steps and bilingual starter templates the admin can edit. */
    public function ensureDefaults(): void
    {
        if (Journey::exists()) {
            return;
        }
        DB::transaction(function () {
            $t = fn (string $name, string $kind, array $en, array $ar, ?string $wa_en = null, ?string $wa_ar = null) => JourneyTemplate::create(['name' => $name, 'kind' => $kind,
                'title_en' => $en[0], 'body_en' => $en[1], 'title_ar' => $ar[0], 'body_ar' => $ar[1], 'whatsapp_en' => $wa_en, 'whatsapp_ar' => $wa_ar, 'deep_link' => $kind === 'offer' ? 'albahar://cart' : 'albahar://cart']);
            $remind = $t('Cart reminder', 'reminder', ['Your cart is waiting', 'Hi {{name}}, you left {{items}} item(s) in your cart. Complete your order before they sell out.'],
                ['سلتك بانتظارك', 'مرحباً {{name}}، لديك {{items}} منتج في السلة. أكمل طلبك قبل نفاد الكمية.']);
            $offer = $t('Cart offer', 'offer', ['A little something for you', 'Hi {{name}}, use code {{coupon}} on your cart before {{expires}}.'],
                ['هدية صغيرة لك', 'مرحباً {{name}}، استخدم الرمز {{coupon}} على سلتك قبل {{expires}}.'],
                "Hi {{name}} 👋\nYour cart at Al Bahar is still waiting. Use code {{coupon}} before {{expires}}.\n{{app_link}}", "مرحباً {{name}} 👋\nسلتك في البحار بانتظارك. استخدم الرمز {{coupon}} قبل {{expires}}.\n{{app_link}}");
            $bday = $t('Birthday wish', 'offer', ['Happy birthday, {{name}}! 🎂', 'A gift from Al Bahar: use code {{coupon}} before {{expires}}.'],
                ['كل عام وأنت بخير يا {{name}}! 🎂', 'هدية من البحار: استخدم الرمز {{coupon}} قبل {{expires}}.'],
                "Happy birthday {{name}}! 🎂\nA gift from Al Bahar: use code {{coupon}} before {{expires}}.", "كل عام وأنت بخير {{name}}! 🎂\nهدية من البحار: استخدم الرمز {{coupon}} قبل {{expires}}.");
            $cart = Journey::create(['key' => 'abandoned_cart', 'name' => 'Abandoned cart', 'is_enabled' => false, 'settings' => ['quiet_start' => '22:00', 'quiet_end' => '08:00', 'max_per_day' => 3]]);
            foreach ([[30, ['push', 'in_app'], $remind, 'Reminder'], [240, ['push', 'whatsapp'], $offer, 'Offer'], [4320, ['push', 'whatsapp'], $offer, 'Last chance']] as $i => [$min, $ch, $tpl, $label]) {
                JourneyStep::create(['journey_id' => $cart->id, 'position' => $i + 1, 'label' => $label, 'delay_minutes' => $min, 'channels' => $ch, 'template_id' => $tpl->id]);
            }
            $b = Journey::create(['key' => 'birthday', 'name' => 'Birthday', 'is_enabled' => false, 'settings' => ['quiet_start' => '21:00', 'quiet_end' => '08:00', 'max_per_day' => 3]]);
            JourneyStep::create(['journey_id' => $b->id, 'position' => 1, 'label' => 'Birthday wish', 'offset_days' => 0, 'send_time' => '10:00', 'channels' => ['push', 'in_app', 'whatsapp'], 'template_id' => $bday->id]);
        });
    }
}
