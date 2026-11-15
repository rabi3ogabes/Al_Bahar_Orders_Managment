<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Messaging\Data\AudienceSelection;
use App\Domain\Messaging\Data\SendPlan;
use App\Domain\Messaging\Data\SendRequest;
use App\Domain\Messaging\Jobs\SendCustomerMessageJob;
use App\Domain\Messaging\Models\CustomerMessage;
use App\Domain\Messaging\Models\MessageBatch;
use App\Domain\Notifications\Services\FcmClient;
use App\Domain\Notifications\Services\N8nClient;
use App\Domain\Notifications\Services\WhatsAppClient;
use App\Domain\Settings\Services\Settings;
use App\Support\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Messages that staff send to customers by hand: one customer, several ticked ones, or everyone matching the customers list filters.
 * This class is the one door: it checks who may send and to whom, validates and cleans the texts, shows what a send would do (plan),
 * saves the history (a batch + one row per customer and channel, skipped ones too), and hands the work to the queue. The actual
 * delivery is MessageDelivery; WhatsApp goes through the n8n automation.
 */
class CustomerMessaging
{
    public function __construct(
        private readonly Eligibility $eligibility,
        private readonly MessageContent $content,
        private readonly CustomerFilter $filters,
        private readonly Settings $settings,
        private readonly AuditLogger $audit,
        private readonly AccessControl $access,
        private readonly N8nClient $n8n,
        private readonly WhatsAppClient $whatsapp,
        private readonly FcmClient $fcm,
    ) {}

    /** @return array{cooldown_hours: int, whatsapp_per_minute: int, bulk_max: int} */
    public function limits(): array
    {
        return [
            'cooldown_hours' => max(0, (int) $this->settings->get('messaging.cooldown_hours', config('platform.messaging.cooldown_hours'))),
            'whatsapp_per_minute' => max(1, (int) $this->settings->get('messaging.whatsapp_per_minute', config('platform.messaging.whatsapp_per_minute'))),
            'bulk_max' => max(1, (int) $this->settings->get('messaging.bulk_max', config('platform.messaging.bulk_max'))),
        ];
    }

    /**
     * What can be used right now. WhatsApp needs the n8n automation (Settings → Integrations) or the WhatsApp Business account.
     *
     * @return array{notification: array{available: bool, push: bool}, whatsapp: array{available: bool, via: ?string}}
     */
    public function channelsAvailable(): array
    {
        return [
            'notification' => ['available' => true, 'push' => $this->fcm->configured()],
            'whatsapp' => ['available' => $this->n8n->configured() || $this->whatsapp->configured(), 'via' => $this->n8n->configured() ? 'n8n' : ($this->whatsapp->configured() ? 'meta' : null)],
        ];
    }

    public function canSend(?User $staff): bool
    {
        return $this->access->allows($staff, 'notifications.create');
    }

    /** Attaching a coupon creates money-off codes, so it needs marketing rights as well. */
    public function canAttachCoupon(?User $staff): bool
    {
        return $this->canSend($staff) && $this->access->allows($staff, 'marketing.create');
    }

    /**
     * The customers this staff member may message, for the given selection.
     *
     * @return Builder<User>
     */
    public function audienceQuery(User $staff, AudienceSelection $sel): Builder
    {
        $q = CustomerScope::for($staff);
        if ($sel->all) {
            $q = $this->filters->apply($q, $sel->filters);
            if ($sel->ids !== []) {
                $q->whereNotIn('users.id', $sel->ids);
            }

            return $q;
        }

        return $q->whereIn('users.id', $sel->ids === [] ? ['00000000-0000-0000-0000-000000000000'] : $sel->ids);
    }

    /** What the send would do right now: who is reachable on each channel and why the others are skipped. Nothing is written. */
    public function plan(User $staff, SendRequest $r): SendPlan
    {
        $this->authorize($staff);
        $limits = $this->limits();
        $q = $this->audienceQuery($staff, $r->audience);
        $total = (clone $q)->count();
        $users = $q->orderBy('users.id')->limit($limits['bulk_max'])->get();
        $channels = $this->channels($r->channels);
        $eval = $this->eligibility->evaluate($users, $channels, $r->kind, $limits['cooldown_hours'], null, $this->override($r, $total));

        $perChannel = array_fill_keys($channels, ['ok' => 0, 'skipped' => 0]);
        $reasons = [];
        $examples = [];
        $reachable = 0;
        foreach ($users as $user) {
            $rows = $eval[(string) $user->id] ?? [];
            $anyOk = false;
            $firstReason = null;
            foreach ($rows as $channel => $reason) {
                if ($reason === null) {
                    $perChannel[$channel]['ok']++;
                    $anyOk = true;
                } else {
                    $perChannel[$channel]['skipped']++;
                    $firstReason ??= $reason;
                }
            }
            if ($anyOk) {
                $reachable++;
            } elseif ($firstReason) {
                $reasons[$firstReason] = ($reasons[$firstReason] ?? 0) + 1;
                if (count($examples) < 5) {
                    $examples[] = ['id' => (string) $user->id, 'name' => (string) $user->name, 'reason' => $firstReason];
                }
            }
        }

        return new SendPlan($total, $limits['bulk_max'], $reachable, $perChannel, $reasons, $examples);
    }

    /**
     * Saves the send and queues it (or schedules it). Everyone chosen gets a row per channel: the ones who may not be messaged right now are
     * saved as skipped with the reason, so the history shows exactly what happened and why.
     *
     * @throws DomainException
     * @throws ValidationException when the texts are not valid
     */
    public function send(User $staff, SendRequest $r): MessageBatch
    {
        $this->authorize($staff);
        $channels = $this->channels($r->channels);
        if ($channels === []) {
            throw new DomainException('messaging_no_channel', __('messaging_admin.err.no_channel'));
        }
        $this->assertChannelsUsable($channels);
        if (! in_array($r->kind, MessageBatch::KINDS, true)) {
            throw new DomainException('messaging_bad_kind', __('messaging_admin.err.bad_kind'));
        }
        $content = $this->content->clean($r->content, $channels, $r->kind);
        $this->assertCoupon($staff, $content);
        $scheduleAt = $this->schedule($r->scheduleAt);

        $limits = $this->limits();
        $q = $this->audienceQuery($staff, $r->audience);
        $total = (clone $q)->count();
        if ($total === 0) {
            throw new DomainException('messaging_no_recipients', __('messaging_admin.err.no_recipients'));
        }
        if ($total > $limits['bulk_max']) {
            throw new DomainException('messaging_too_many', __('messaging_admin.err.too_many', ['max' => number_format($limits['bulk_max']), 'count' => number_format($total)]));
        }
        $this->throttle($staff, $total);

        $users = $q->orderBy('users.id')->get();
        $ignoreCooldown = $this->override($r, $total);
        // A scheduled send is checked again when it goes out (consent or the cooldown may change by then); an immediate one is decided now.
        $eval = $scheduleAt ? [] : $this->eligibility->evaluate($users, $channels, $r->kind, $limits['cooldown_hours'], null, $ignoreCooldown);

        $batch = DB::transaction(function () use ($staff, $r, $channels, $content, $scheduleAt, $total, $users, $eval, $ignoreCooldown) {
            $batch = MessageBatch::create([
                'created_by' => $staff->id, 'template_id' => $r->template?->id, 'template_name' => $r->template?->name ?? __('messaging_admin.custom_template'),
                'kind' => $r->kind, 'source' => $total === 1 ? 'single' : 'bulk', 'channels' => $channels, 'content' => $content,
                'audience' => $r->audience->describe($total) + ['override_cooldown' => $ignoreCooldown],
                'recipients_count' => $total, 'status' => $scheduleAt ? 'scheduled' : 'sending',
                'scheduled_at' => $scheduleAt, 'started_at' => $scheduleAt ? null : now(),
            ]);
            $now = now();
            $rows = [];
            foreach ($users as $user) {
                foreach ($channels as $channel) {
                    $reason = $eval[(string) $user->id][$channel] ?? null;
                    $rows[] = ['id' => (string) Str::uuid7(), 'batch_id' => $batch->id, 'user_id' => $user->id, 'channel' => $channel, 'locale' => $user->preferredLocale(),
                        'status' => $reason ? 'skipped' : 'queued', 'reason' => $reason, 'created_at' => $now, 'updated_at' => $now];
                }
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                CustomerMessage::insert($chunk);
            }
            $this->audit->log($scheduleAt ? 'customer_messages.scheduled' : 'customer_messages.sent', $batch, [], [
                'kind' => $r->kind, 'channels' => $channels, 'recipients' => $total, 'template' => $batch->template_name, 'scheduled_at' => $scheduleAt?->toIso8601String(),
            ], actor: $staff);

            return $batch;
        });

        if (! $scheduleAt) {
            $this->dispatchBatch($batch);
        }

        return $batch->fresh();
    }

    /** Stops a scheduled send, or the part of a running one that has not gone out yet. */
    public function cancel(User $staff, MessageBatch $batch): MessageBatch
    {
        $this->authorizeBatch($staff, $batch);
        if ($batch->isFinished()) {
            throw new DomainException('messaging_batch_finished', __('messaging_admin.err.finished'));
        }
        DB::transaction(function () use ($batch, $staff) {
            CustomerMessage::where('batch_id', $batch->id)->where('status', 'queued')->update(['status' => 'cancelled', 'reason' => 'cancelled_by_staff', 'updated_at' => now()]);
            $batch->forceFill(['status' => 'cancelled', 'completed_at' => now()])->save();
            $batch->refreshCounts();
            $this->audit->log('customer_messages.cancelled', $batch, [], ['sent' => $batch->sent_count], actor: $staff);
        });

        return $batch->fresh();
    }

    /** Puts the failed messages of a send back in the queue (they are checked again before going out). @return int messages requeued */
    public function retryFailed(User $staff, MessageBatch $batch): int
    {
        $this->authorizeBatch($staff, $batch);
        if (! in_array($batch->status, ['done', 'sending'], true)) {
            throw new DomainException('messaging_batch_not_retryable', __('messaging_admin.err.not_retryable'));
        }
        $n = CustomerMessage::where('batch_id', $batch->id)->where('status', 'failed')->update(['status' => 'queued', 'reason' => null, 'updated_at' => now()]);
        if ($n === 0) {
            return 0;
        }
        $batch->forceFill(['status' => 'sending', 'completed_at' => null])->save();
        $this->audit->log('customer_messages.retried', $batch, [], ['messages' => $n], actor: $staff);
        $this->dispatchBatch($batch->fresh());

        return $n;
    }

    /** Scheduler pass: starts the scheduled sends that are due and re-queues sends whose jobs were lost. @return array{started: int, resumed: int} */
    public function dispatchDue(): array
    {
        $started = $resumed = 0;
        MessageBatch::where('status', 'scheduled')->where('scheduled_at', '<=', now())->orderBy('scheduled_at')->limit(20)->get()->each(function (MessageBatch $b) use (&$started) {
            $claimed = MessageBatch::whereKey($b->id)->where('status', 'scheduled')->update(['status' => 'sending', 'started_at' => now(), 'updated_at' => now()]);
            if ($claimed) {
                $this->dispatchBatch($b->fresh());
                $started++;
            }
        });
        $perMinute = $this->limits()['whatsapp_per_minute'];
        MessageBatch::where('status', 'sending')->where('queued_count', '>', 0)->limit(20)->get()->each(function (MessageBatch $b) use ($perMinute, &$resumed) {
            // Everything normally leaves within recipients / per-minute minutes; much longer than that means the jobs were lost (restart, queue flushed).
            $grace = 15 + (int) ceil($b->recipients_count / $perMinute);
            if ($b->started_at && $b->started_at->lt(now()->subMinutes($grace)) && ! Cache::has('customer-messages:resume:'.$b->id)) {
                Cache::put('customer-messages:resume:'.$b->id, 1, now()->addMinutes($grace));
                $this->dispatchBatch($b);
                $resumed++;
            }
        });

        return ['started' => $started, 'resumed' => $resumed];
    }

    /** One job per customer, so a customer's channels share one coupon and WhatsApp can be paced. */
    public function dispatchBatch(MessageBatch $batch): void
    {
        $perMinute = $this->limits()['whatsapp_per_minute'];
        $waIndex = 0;
        CustomerMessage::query()->where('batch_id', $batch->id)->where('status', 'queued')->groupBy('user_id')
            ->selectRaw("user_id, bool_or(channel = 'whatsapp') as wa")->orderBy('user_id')->get()->each(function ($row) use ($batch, $perMinute, &$waIndex) {
                $minutes = $row->getAttribute('wa') ? intdiv($waIndex++, $perMinute) : 0;
                $job = SendCustomerMessageJob::dispatch($batch->id, (string) $row->user_id);
                if ($minutes > 0) {
                    $job->delay(now()->addMinutes($minutes));
                }
            });
        $batch->refreshCounts();
    }

    /** n8n reports what happened to a WhatsApp message it was given. false = not one of ours. */
    public function recordProviderStatus(string $messageId, string $status, ?string $externalId, ?string $reason): bool
    {
        $m = CustomerMessage::find($messageId);
        if (! $m || $m->channel !== 'whatsapp') {
            return false;
        }
        $rank = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'failed' => 2, 'skipped' => 2, 'cancelled' => 2];
        if (($rank[$status] ?? -1) < ($rank[$m->status] ?? 0) || ($m->status === 'delivered' && $status !== 'delivered')) {
            return true; // an older status arrived late: ignore
        }
        $m->update([
            'status' => $status, 'external_id' => $externalId ?: $m->external_id,
            'reason' => $status === 'failed' ? Str::limit((string) ($reason ?: 'provider_failed'), 45, '') : ($status === 'delivered' ? null : $m->reason),
            'sent_at' => $m->sent_at ?? ($status === 'queued' ? null : now()),
            'delivered_at' => $status === 'delivered' ? now() : $m->delivered_at,
        ]);
        $m->batch?->refreshCounts();

        return true;
    }

    /** Batches this staff member may see: all with company-wide notification rights, otherwise their own. @return Builder<MessageBatch> */
    public function visibleBatches(User $staff): Builder
    {
        $q = MessageBatch::query();
        if (! $this->access->allowsGlobally($staff, 'notifications.view')) {
            $q->where('created_by', $staff->id);
        }

        return $q;
    }

    /* ───────────────────────── guards ───────────────────────── */

    private function authorize(User $staff): void
    {
        if (! $this->canSend($staff)) {
            throw DomainException::make('forbidden', 403);
        }
    }

    private function authorizeBatch(User $staff, MessageBatch $batch): void
    {
        $this->authorize($staff);
        if (! $this->visibleBatches($staff)->whereKey($batch->id)->exists()) {
            throw DomainException::make('forbidden', 403);
        }
    }

    /** @param  list<string>  $channels  @return list<string> */
    private function channels(array $channels): array
    {
        return array_values(array_intersect(MessageBatch::CHANNELS, $channels));
    }

    /** @param  list<string>  $channels */
    private function assertChannelsUsable(array $channels): void
    {
        if (in_array('whatsapp', $channels, true) && ! $this->channelsAvailable()['whatsapp']['available']) {
            throw new DomainException('messaging_whatsapp_off', __('messaging_admin.err.whatsapp_off'));
        }
    }

    /** @param  array<string, mixed>  $content */
    private function assertCoupon(User $staff, array $content): void
    {
        $coupon = $content['coupon'];
        if (! $coupon['enabled']) {
            return;
        }
        if (! $this->canAttachCoupon($staff)) {
            throw new DomainException('messaging_coupon_forbidden', __('messaging_admin.err.coupon_forbidden'), 403);
        }
        $promotion = Promotion::find($coupon['promotion_id']);
        if (! $promotion || ! $promotion->is_active || ($promotion->ends_at && $promotion->ends_at->isPast())) {
            throw ValidationException::withMessages(['coupon.promotion_id' => __('messaging_admin.err.promotion_inactive')]);
        }
    }

    private function schedule(?CarbonImmutable $at): ?CarbonImmutable
    {
        if ($at === null) {
            return null;
        }
        if ($at->lt(now()->addSeconds(30))) {
            throw ValidationException::withMessages(['scheduleAt' => __('messaging_admin.err.schedule_past')]);
        }
        if ($at->gt(now()->addDays(60))) {
            throw ValidationException::withMessages(['scheduleAt' => __('messaging_admin.err.schedule_far')]);
        }

        return $at->utc();
    }

    /** "Send anyway" skips the recently-contacted guard, and only for one customer at a time: a bulk send always keeps it. */
    private function override(SendRequest $r, int $total): bool
    {
        return $r->ignoreCooldown && $total === 1;
    }

    private function throttle(User $staff, int $total): void
    {
        [$key, $max, $seconds] = $total > 1 ? ['bulk:'.$staff->id, 10, 3600] : ['single:'.$staff->id, 40, 60];
        $key = 'customer-messages:'.$key;
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new DomainException('messaging_rate_limited', __('messaging_admin.err.rate_limited', ['seconds' => RateLimiter::availableIn($key)]), 429);
        }
        RateLimiter::hit($key, $seconds);
    }
}
