<?php

namespace App\Domain\Reports\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * A read-only picture of whether the background machinery is alive, for the owner's dashboard: when each scheduled task last ran (and which are
 * overdue), how many jobs wait in the queue and how many have failed. Counts and times only: nothing here can carry a secret, a customer or an
 * error message. The backup is not shown on purpose: it runs outside the application (ops/backup.sh), so the app cannot know when it last ran
 * without guessing, and a guess on a health card is worse than none.
 */
final class SystemHealth
{
    /** The queues the worker listens to (docker-compose: --queue=default,notifications,exports). */
    public const QUEUES = ['default', 'notifications', 'exports'];

    /** A queue with more waiting jobs than this is flagged (docs/operations/MONITORING.md). */
    public const QUEUE_BACKLOG = 500;

    /**
     * @return array{
     *   tasks: list<array{task: string, state: string, last_finished_at: ?CarbonImmutable, last_started_at: ?CarbonImmutable, every_minutes: int, duration_ms: ?int}>,
     *   scheduler_ok: bool,
     *   queue: array{connection: string, depth: ?array<string, int>, waiting: ?int, failed: ?int, state: string},
     *   ok: bool
     * }
     */
    public function snapshot(): array
    {
        $tasks = $this->tasks();
        $queue = $this->queue();
        $schedulerOk = collect($tasks)->every(fn (array $t) => in_array($t['state'], ['ok', 'running'], true));

        return ['tasks' => $tasks, 'scheduler_ok' => $schedulerOk, 'queue' => $queue, 'ok' => $schedulerOk && in_array($queue['state'], ['ok', 'inline'], true)];
    }

    /**
     * One entry per expected task. state: ok | running | overdue (no finished run in time) | failed (the last run failed) | never (no heartbeat yet).
     *
     * @return list<array{task: string, state: string, last_finished_at: ?CarbonImmutable, last_started_at: ?CarbonImmutable, every_minutes: int, duration_ms: ?int}>
     */
    private function tasks(): array
    {
        $rows = DB::table('scheduler_heartbeats')->get()->keyBy('task');
        $now = CarbonImmutable::now();
        // The known tasks (so one that has never run is listed) and any other task that has left a heartbeat, with the interval its schedule gave it.
        $expected = SchedulerHeartbeat::TASKS;
        foreach ($rows as $task => $row) {
            $expected[$task] ??= max(1, (int) ($row->every_minutes ?? 5));
        }
        $out = [];
        foreach ($expected as $task => $every) {
            $row = $rows[$task] ?? null;
            $finished = $row?->last_finished_at ? CarbonImmutable::parse($row->last_finished_at) : null;
            $started = $row?->last_started_at ? CarbonImmutable::parse($row->last_started_at) : null;
            $limit = SchedulerHeartbeat::overdueAfterMinutes($every);
            $state = match (true) {
                $row === null => 'never',
                $row->last_status === 'failed' => 'failed',
                // started but never finished for longer than the lock lives: the process died mid-run
                $row->last_status === 'running' && $started !== null && $started->diffInMinutes($now, true) > max($limit, 10) => 'overdue',
                $row->last_status === 'running' => 'running',
                $finished === null || $finished->diffInMinutes($now, true) > $limit => 'overdue',
                default => 'ok',
            };
            $out[] = ['task' => $task, 'state' => $state, 'last_finished_at' => $finished, 'last_started_at' => $started, 'every_minutes' => $every,
                'duration_ms' => $row?->last_duration_ms !== null ? (int) $row->last_duration_ms : null];
        }

        return $out;
    }

    /** @return array{connection: string, depth: ?array<string, int>, waiting: ?int, failed: ?int, state: string} state: ok | backlog | failed_jobs | inline | unavailable */
    private function queue(): array
    {
        $connection = (string) config('queue.default');
        $failed = null;
        try {
            if (config('queue.failed.driver') !== null && config('queue.failed.driver') !== 'null') {
                $failed = (int) DB::table((string) config('queue.failed.table', 'failed_jobs'))->count();
            }
        } catch (Throwable $e) {
            report($e);
        }
        if ($connection === 'sync') {
            return ['connection' => $connection, 'depth' => null, 'waiting' => null, 'failed' => $failed, 'state' => 'inline'];
        }
        try {
            $depth = [];
            foreach (self::QUEUES as $queue) {
                $depth[$queue] = (int) Queue::size($queue);
            }
        } catch (Throwable $e) {
            report($e);

            return ['connection' => $connection, 'depth' => null, 'waiting' => null, 'failed' => $failed, 'state' => 'unavailable'];
        }
        $waiting = array_sum($depth);
        $state = match (true) {
            max($depth) > self::QUEUE_BACKLOG => 'backlog',
            ($failed ?? 0) > 0 => 'failed_jobs',
            default => 'ok',
        };

        return ['connection' => $connection, 'depth' => $depth, 'waiting' => $waiting, 'failed' => $failed, 'state' => $state];
    }
}
