<?php

namespace App\Domain\Reports\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Services\WhatsAppClient;
use App\Domain\Reports\Mail\OwnerPackMail;
use App\Domain\Reports\Models\OwnerPackDelivery;
use App\Domain\Reports\Models\OwnerPackSend;
use App\Support\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends the weekly owner pack and keeps the log of what happened (docs/guides/OWNER_PACK_AND_CUSTOMER_VALUE.md).
 *
 *  - WHEN: the scheduler calls {@see self::sendDue()} every few minutes. A pack is due from the chosen weekday and time AFTER its week ended, and is
 *    still sent for up to {@see self::CATCH_UP_HOURS} hours after that (a server that was down at 08:00 sends at 08:05 when it is back), never later:
 *    a pack that is days old is not worth a surprise, and next week's comes on its own.
 *  - ONCE PER WEEK: the scheduled send of a week and channel is one row, unique in the database. A second run (two servers, a retry, a re-deploy) finds the
 *    row and does nothing, or, if the first run failed or died, sends only to the recipients that have not got it yet. Nobody is sent the same pack twice.
 *  - NEVER SILENT: every outcome is a row in the log, with how many were reached and why the rest were not. The WhatsApp channel says "not connected" in
 *    the log while no provider is connected; it never pretends to have sent anything.
 *  - Aggregates only. A WhatsApp or e-mail message holds figures and product names, never a customer. Quiet hours do not apply: the owner chose the time.
 */
final class OwnerPackSender
{
    /** How long after its due time a scheduled pack is still sent. */
    public const CATCH_UP_HOURS = 72;

    /** A failed or half-sent pack is tried again at most every so many minutes, up to this many attempts. */
    public const RETRY_MINUTES = 10;

    public const MAX_ATTEMPTS = 12;

    /** A send still "sending" after this long was left behind by a run that died; it is picked up again. */
    public const STALE_MINUTES = 15;

    /** "Send test to me": at most this many in this many minutes per person. */
    public const TEST_LIMIT = 3;

    public const TEST_WINDOW_MINUTES = 10;

    public function __construct(
        private readonly OwnerPackSettings $settings,
        private readonly OwnerPackData $data,
        private readonly WhatsAppClient $whatsapp,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The scheduler's entry point: sends the pack of the week that just ended on every channel that is switched on, when it is due. Safe to run any
     * number of times.
     *
     * @return array{state: string, week: string, sends: list<string>}
     */
    public function sendDue(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $week = OwnerPackData::lastCompletedWeek($now);
        if (! $this->settings->enabled()) {
            return ['state' => 'off', 'week' => $week, 'sends' => []];
        }
        $due = $this->settings->dueAt($week);
        if ($now < $due || $now >= $due->addHours(self::CATCH_UP_HOURS)) {
            return ['state' => 'not_due', 'week' => $week, 'sends' => []];
        }
        $sends = [];
        foreach (array_filter(['email' => $this->settings->emailOn(), 'whatsapp' => $this->settings->whatsappOn()]) as $channel => $on) {
            $result = $this->deliverScheduled($week, $channel, $now);
            if ($result !== null) {
                $sends[] = $channel.':'.$result->status;
            }
        }

        return ['state' => $sends === [] ? 'nothing_to_do' : 'sent', 'week' => $week, 'sends' => $sends];
    }

    /**
     * "Send test to me": the pack of a week to the person who asked, on e-mail only, so the owner can see exactly what will arrive. It never counts
     * for the week and never reaches the recipients.
     *
     * @throws DomainException
     */
    public function sendTest(User $user, string $week): OwnerPackSend
    {
        OwnerPackData::bounds($week);
        $key = 'owner-pack-test:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, self::TEST_LIMIT)) {
            throw new DomainException('owner_pack_too_many', __('owner_pack.errors.too_many', ['minutes' => self::TEST_WINDOW_MINUTES]), 429);
        }
        if (! filter_var((string) $user->email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('owner_pack_no_email', __('owner_pack.errors.no_email'), 422);
        }
        if (! $this->settings->mailReady()) {
            throw new DomainException('owner_pack_mail_not_ready', __('owner_pack.errors.mail_not_ready'), 422);
        }
        RateLimiter::hit($key, self::TEST_WINDOW_MINUTES * 60);

        $lang = $this->settings->language();
        $send = OwnerPackSend::create(['iso_week' => $week, 'channel' => 'email', 'kind' => 'test', 'status' => 'sending', 'language' => $lang, 'recipients_count' => 1,
            'triggered_by' => $user->id]);
        $this->run($send, [strtolower((string) $user->email)]);
        $this->audit->log('owner_pack.test_sent', $send, [], ['week' => $week, 'status' => $send->status], actor: $user);

        return $send->fresh() ?? $send;
    }

    /** The prepared pack of a week in a language: the dashboard preview and the e-mail are both drawn from this. @return array<string, mixed> */
    public function pack(string $week, string $lang): array
    {
        return OwnerPackPresenter::present($this->data->for($week), $lang);
    }

    private function deliverScheduled(string $week, string $channel, CarbonImmutable $now): ?OwnerPackSend
    {
        $recipients = $channel === 'email' ? $this->settings->emails() : $this->settings->numbers();
        $send = $this->claim($week, $channel, $now);
        if ($send === null) {
            return null; // already done, or another run holds it
        }
        $send->forceFill(['recipients_count' => count($recipients), 'language' => $this->settings->language()])->save();
        $send = $this->run($send, $recipients);
        $this->audit->log('owner_pack.sent', $send, [], ['week' => $week, 'channel' => $channel, 'status' => $send->status, 'sent' => $send->sent_count, 'failed' => $send->failed_count]);

        return $send;
    }

    /**
     * Takes the scheduled send of this week and channel: creates it, or takes back one that failed, was only half sent, or was left "sending" by a run that
     * died. Returns null when there is nothing to do (it was sent, or somebody else is on it). One UPDATE decides who owns a retry.
     */
    private function claim(string $week, string $channel, CarbonImmutable $now): ?OwnerPackSend
    {
        // INSERT ... ON CONFLICT DO NOTHING: the unique index decides who owns the week, with no exception to catch (a failed insert would poison a surrounding transaction).
        $id = (string) Str::uuid();
        $created = DB::table('owner_pack_sends')->insertOrIgnore(['id' => $id, 'iso_week' => $week, 'channel' => $channel, 'kind' => 'scheduled', 'status' => 'sending', 'language' => $this->settings->language(),
            'recipients_count' => 0, 'sent_count' => 0, 'failed_count' => 0, 'attempts' => 1, 'created_at' => $now, 'updated_at' => $now]);
        if ($created === 1) {
            return OwnerPackSend::query()->findOrFail($id);
        }
        // The row of this week exists: see whether it needs another go.
        $retry = fn ($q) => $q->where(fn ($w) => $w->whereIn('status', ['failed', 'partial'])->where('updated_at', '<=', $now->subMinutes(self::RETRY_MINUTES)))
            ->orWhere(fn ($w) => $w->where('status', 'sending')->where('updated_at', '<=', $now->subMinutes(self::STALE_MINUTES)));
        $taken = OwnerPackSend::query()->where('iso_week', $week)->where('channel', $channel)->where('kind', 'scheduled')->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where($retry)->update(['status' => 'sending', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => $now]);

        return $taken === 1 ? OwnerPackSend::query()->where('iso_week', $week)->where('channel', $channel)->where('kind', 'scheduled')->first() : null;
    }

    /**
     * Sends to every recipient that has not been reached yet, records each result, and closes the send with its counts.
     *
     * @param  list<string>  $recipients
     */
    private function run(OwnerPackSend $send, array $recipients): OwnerPackSend
    {
        if ($send->channel === 'whatsapp' && ! $this->whatsapp->configured()) {
            return $this->finish($send, 'not_connected', 'whatsapp_not_connected', 0, 0, count($recipients));
        }
        if ($recipients === []) {
            return $this->finish($send, 'failed', 'no_recipients', 0, 0, 0);
        }
        if ($send->channel === 'email' && ! $this->settings->mailReady()) {
            // A mailer that only writes to a log would "send" and nobody would read it: say so, and try again when the mail account is set up.
            return $this->finish($send, 'failed', 'mail_not_ready', 0, 0, count($recipients));
        }
        try {
            $present = OwnerPackPresenter::present($this->data->for($send->iso_week), $send->language);
        } catch (Throwable $e) {
            report($e);

            return $this->finish($send, 'failed', 'pack_not_built', 0, 0, count($recipients));
        }
        $text = OwnerPackPresenter::text($present);
        $already = OwnerPackDelivery::query()->where('owner_pack_send_id', $send->id)->where('status', 'sent')->pluck('recipient')->all();
        $subject = (string) __('owner_pack.subject', ['week' => (int) substr($send->iso_week, -2)], $send->language);
        foreach ($recipients as $to) {
            if (in_array($to, $already, true)) {
                continue;
            }
            try {
                if ($send->channel === 'email') {
                    Mail::to($to)->send((new OwnerPackMail($present, $text, $subject))->locale($send->language));
                } else {
                    $this->whatsapp->sendText($to, $subject."\n\n".$text);
                }
                $this->record($send, $to, 'sent', null);
            } catch (Throwable $e) {
                $this->record($send, $to, 'failed', mb_substr(class_basename($e).': '.$e->getMessage(), 0, 250));
            }
        }
        $sent = OwnerPackDelivery::query()->where('owner_pack_send_id', $send->id)->where('status', 'sent')->whereIn('recipient', $recipients)->count();
        $failed = count($recipients) - $sent;

        return $this->finish($send, $failed === 0 ? 'sent' : ($sent > 0 ? 'partial' : 'failed'), $failed === 0 ? null : 'some_failed', $sent, $failed, count($recipients));
    }

    private function record(OwnerPackSend $send, string $to, string $status, ?string $error): void
    {
        OwnerPackDelivery::query()->updateOrCreate(['owner_pack_send_id' => $send->id, 'recipient' => $to],
            ['status' => $status, 'error' => $error, 'sent_at' => $status === 'sent' ? now() : null]);
    }

    private function finish(OwnerPackSend $send, string $status, ?string $error, int $sent, int $failed, int $recipients): OwnerPackSend
    {
        $send->forceFill(['status' => $status, 'error' => $error, 'sent_count' => $sent, 'failed_count' => $failed, 'recipients_count' => $recipients, 'finished_at' => now()])->save();

        return $send;
    }

    /** The log, newest first (bounded). @return \Illuminate\Support\Collection<int, OwnerPackSend> */
    public static function log(int $limit = 30)
    {
        return OwnerPackSend::query()->orderByDesc('created_at')->orderByDesc('id')->limit(min($limit, 100))->get();
    }
}
