<?php

namespace App\Domain\Reports\Services;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Every scheduled task leaves a heartbeat in `scheduler_heartbeats` (when it last started, when it last finished, whether it worked), written by the
 * scheduler itself. The dashboard's System health card reads them, so a dead scheduler container or a task that keeps failing is seen in minutes
 * instead of when customers complain that expired orders still hold stock or a payment never confirmed.
 *
 * routes/console.php calls {@see self::attach()} once, after it has registered the tasks: nothing has to be remembered per task, and a task added
 * to the schedule later gets its heartbeat (and its place on the card) without touching this class. How often a task is expected is read from its
 * cron expression and stored with the heartbeat; TASKS lists the known ones so that a task that has NEVER run is shown too, not only the ones that did.
 * Writing a heartbeat can never break the task it describes. Only the exception-free facts are stored (times and an ok/failed flag), never output.
 */
final class SchedulerHeartbeat
{
    /** Scheduled task => how many minutes apart its runs are expected (a sub-minute task counts as 1). */
    public const TASKS = [
        'orders:expire-unpaid' => 1,
        'inventory:expire-reservations' => 1,
        'deals:refresh-feed' => 1,
        'payments:reconcile-pending' => 5,
        'marketing:send-scheduled' => 1,
        'auctions:tick' => 1,
        'journeys:tick' => 1,
        'messaging:dispatch-due' => 1,
        'alerts:process' => 5,
        'alerts:prune' => 1440,
        'rooms:ensure-home' => 60,
        'loyalty:expire-points' => 1440,
        'drivers:prune-locations' => 1440,
        'push:prune-devices' => 1440,
        'banners:purge-deleted' => 1440,
        'reports:send-owner-pack' => 5,
        'customers:refresh-value' => 30,
    ];

    /** Tasks that run every few seconds write at most once per this many seconds (a heartbeat a minute is plenty to see them alive). */
    private const FREQUENT = ['auctions:tick'];

    private const MIN_SECONDS_BETWEEN_WRITES = 30;

    /** @var array<string, float> when each frequent task last wrote */
    private static array $lastWrite = [];

    /** @var array<string, bool> whether the run in progress is being recorded */
    private static array $recording = [];

    /** Hooks every event registered so far; call it after the last Schedule::command(...) of the file. */
    public static function attach(Schedule $schedule): void
    {
        foreach ($schedule->events() as $event) {
            $task = self::taskOf($event);
            if ($task === null) {
                continue;
            }
            $every = self::everyMinutes($event);
            $event->before(fn () => self::started($task, $every))
                ->onSuccess(fn () => self::finished($task, true, $every))
                ->onFailure(fn () => self::finished($task, false, $every));
        }
    }

    public static function started(string $task, ?int $every = null): void
    {
        self::$recording[$task] = ! self::throttled($task);
        if (! self::$recording[$task]) {
            return;
        }
        self::write(fn () => DB::statement(
            "insert into scheduler_heartbeats (task, last_started_at, last_status, every_minutes, run_count, created_at, updated_at) values (?, now(), 'running', ?, 1, now(), now())
             on conflict (task) do update set last_started_at = now(), last_status = 'running', last_error = null, every_minutes = coalesce(excluded.every_minutes, scheduler_heartbeats.every_minutes),
               run_count = scheduler_heartbeats.run_count + 1, updated_at = now()",
            [$task, $every]
        ));
    }

    public static function finished(string $task, bool $ok, ?int $every = null): void
    {
        if ($ok && (self::$recording[$task] ?? true) === false) {
            return; // this run was one of the frequent task's skipped beats
        }
        self::write(fn () => DB::statement(
            'insert into scheduler_heartbeats (task, last_started_at, last_finished_at, last_status, last_error, every_minutes, run_count, created_at, updated_at)
             values (?, now(), now(), ?, ?, ?, 1, now(), now())
             on conflict (task) do update set last_finished_at = now(), last_status = excluded.last_status, last_error = excluded.last_error,
               every_minutes = coalesce(excluded.every_minutes, scheduler_heartbeats.every_minutes),
               last_duration_ms = least(2147483647, greatest(0, (extract(epoch from (now() - scheduler_heartbeats.last_started_at)) * 1000)::bigint)), updated_at = now()',
            [$task, $ok ? 'ok' : 'failed', $ok ? null : 'command_failed', $every]
        ));
    }

    /** Minutes without a finished run after which the task is shown as overdue (red). */
    public static function overdueAfterMinutes(int $expectedEvery): int
    {
        return match (true) {
            $expectedEvery <= 1 => 5,
            $expectedEvery <= 5 => 15,
            default => (int) ($expectedEvery * 1.5),
        };
    }

    /** Minutes between two runs of a scheduled event, from its cron expression (a task that runs every few seconds counts as every minute). */
    public static function everyMinutes(Event $event): int
    {
        try {
            $cron = new CronExpression($event->expression);
            $first = CarbonImmutable::instance($cron->getNextRunDate('2026-01-05 00:00:00'));
            $second = CarbonImmutable::instance($cron->getNextRunDate($first));

            return max(1, min(10080, (int) $first->diffInMinutes($second, true)));
        } catch (Throwable) {
            return 5;
        }
    }

    /** The artisan name of a scheduled command ("orders:expire-unpaid"), or null for a task that is not an artisan command. */
    private static function taskOf(Event $event): ?string
    {
        return preg_match('/artisan[\'"]?\s+([A-Za-z0-9:_\-]+)/', (string) $event->command, $m) === 1 ? $m[1] : null;
    }

    private static function throttled(string $task): bool
    {
        if (! in_array($task, self::FREQUENT, true)) {
            return false;
        }
        $now = microtime(true);
        if (isset(self::$lastWrite[$task]) && $now - self::$lastWrite[$task] < self::MIN_SECONDS_BETWEEN_WRITES) {
            return true;
        }
        self::$lastWrite[$task] = $now;

        return false;
    }

    private static function write(callable $write): void
    {
        try {
            $write();
        } catch (Throwable $e) {
            report($e); // the heartbeat is a witness, never a reason for the task to fail
        }
    }
}
