<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Messaging\Models\CustomerMessage;
use App\Domain\Notifications\Services\DevicePushReach;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "When did we last contact this customer, and how?" across both ways a marketing message can reach them: the ones staff send by hand
 * (customer_messages) and the automatic journeys (journey_deliveries). Used by the recently-contacted guard, the customers list and the timeline.
 */
class ContactHistory
{
    /** SQL for the customers list: the time of the newest message that really went out to users.id (null when never). */
    public const LAST_CONTACT_SQL = "GREATEST(
        (SELECT max(cm.sent_at) FROM customer_messages cm WHERE cm.user_id = users.id AND cm.sent_at IS NOT NULL),
        (SELECT max(jd.sent_at) FROM journey_deliveries jd WHERE jd.user_id = users.id AND jd.status = 'sent' AND jd.sent_at IS NOT NULL))";

    /**
     * Customers (of those given) who got a message within the last $hours hours, or have one on its way right now.
     * Messages waiting for a later schedule do not count, and neither do the rows of $exceptBatchId (the send being worked on).
     *
     * @param  list<string>  $userIds
     * @return array<string, true> user id => true
     */
    public function recentlyContacted(array $userIds, int $hours, ?string $exceptBatchId = null): array
    {
        if ($hours <= 0 || $userIds === []) {
            return [];
        }
        $since = now()->subHours($hours);
        $manual = CustomerMessage::query()->whereIn('user_id', $userIds)
            ->when($exceptBatchId, fn ($q) => $q->where('batch_id', '!=', $exceptBatchId))
            ->where(fn ($q) => $q->where('sent_at', '>=', $since)
                ->orWhere(fn ($w) => $w->where('status', 'queued')->where('created_at', '>=', $since)
                    ->whereHas('batch', fn ($b) => $b->where('status', 'sending'))))
            ->distinct()->pluck('user_id');
        $auto = DB::table('journey_deliveries')->whereIn('user_id', $userIds)->where('status', 'sent')->where('sent_at', '>=', $since)->distinct()->pluck('user_id');

        return array_fill_keys($manual->merge($auto)->all(), true);
    }

    /** Whether staff messaged this customer by hand within the last $hours hours (the automatic journeys step aside for them). */
    public function recentManualMessage(string $userId, int $hours): bool
    {
        return $hours > 0 && CustomerMessage::query()->where('user_id', $userId)
            ->where(fn ($q) => $q->where('sent_at', '>=', now()->subHours($hours))
                ->orWhere(fn ($w) => $w->where('status', 'queued')->whereHas('batch', fn ($b) => $b->where('status', 'sending'))->where('created_at', '>=', now()->subHours($hours))))
            ->exists();
    }

    /**
     * The customer's contact history, newest first, manual and automatic together.
     *
     * @return Collection<int, array{key: string, at: CarbonImmutable, source: string, channel: string, kind: ?string, template: ?string, title: ?string, body: ?string, status: string, reason: ?string, coupon: ?string, staff: ?string, batch_id: ?string, journey: ?string, phones: array{kind: 'no_phone'|'silent', reached: int, silent: int}|null}>
     */
    public function timeline(string $userId, int $limit = 30, ?CarbonImmutable $before = null): Collection
    {
        $manual = CustomerMessage::query()->with(['batch:id,created_by,template_name,kind', 'batch.creator:id,name'])->where('user_id', $userId)
            ->when($before, fn ($q) => $q->where('created_at', '<', $before))->orderByDesc('created_at')->limit($limit)->get()
            ->map(fn (CustomerMessage $m) => [
                'key' => 'm-'.$m->id, 'at' => CarbonImmutable::instance($m->sent_at ?? $m->created_at), 'source' => 'manual', 'channel' => $m->channel,
                'kind' => $m->batch?->kind, 'template' => $m->batch?->template_name, 'title' => $m->title, 'body' => $m->body, 'status' => $m->status,
                'reason' => $m->reason, 'coupon' => $m->coupon_code, 'staff' => $m->batch?->creator?->name, 'batch_id' => $m->batch_id, 'journey' => null,
                // How many phones a notification reached and where notifications are off (only said once it went out; a skipped row already has its reason).
                'phones' => $m->channel === 'notification' && in_array($m->status, ['sent', 'delivered'], true) ? DevicePushReach::deliveryNote($m->meta) : null,
            ]);
        $auto = DB::table('journey_deliveries as d')
            ->leftJoin('journey_runs as r', 'r.id', '=', 'd.run_id')->leftJoin('journeys as j', 'j.id', '=', 'r.journey_id')
            ->leftJoin('journey_steps as s', 's.id', '=', 'd.step_id')->leftJoin('journey_templates as t', 't.id', '=', 's.template_id')
            ->where('d.user_id', $userId)->when($before, fn ($q) => $q->where('d.created_at', '<', $before))
            ->orderByDesc('d.created_at')->limit($limit)
            ->get(['d.id', 'd.channel', 'd.status', 'd.reason', 'd.coupon_code', 'd.created_at', 'd.sent_at', 'j.key as journey', 't.name as template', 't.kind as kind'])
            ->map(fn ($d) => [
                'key' => 'j-'.$d->id, 'at' => CarbonImmutable::parse($d->sent_at ?? $d->created_at), 'source' => 'journey', 'channel' => in_array($d->channel, ['push', 'in_app'], true) ? 'notification' : (string) $d->channel,
                'kind' => $d->kind !== null ? (string) $d->kind : null, 'template' => $d->template !== null ? (string) $d->template : null, 'title' => null, 'body' => null,
                'status' => (string) $d->status, 'reason' => $d->reason !== null ? (string) $d->reason : null, 'coupon' => $d->coupon_code !== null ? (string) $d->coupon_code : null, 'staff' => null,
                'batch_id' => null, 'journey' => $d->journey !== null ? (string) $d->journey : null, 'phones' => null,
            ]);

        return $manual->concat($auto)->sortByDesc(fn ($r) => $r['at']->getTimestamp())->values()->take($limit);
    }
}
