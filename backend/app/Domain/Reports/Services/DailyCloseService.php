<?php

namespace App\Domain\Reports\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Services\BranchHoursService;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Identity\Support\BranchScope;
use App\Domain\Reports\Models\DailyClose;
use App\Domain\Settings\Services\Settings;
use App\Support\BusinessTime;
use App\Support\DomainException;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The daily close of a branch: its day is looked at by a manager (the figures of {@see DailyCloseNumbers}), and signed off with a note that is written
 * to the audit log. The owner then sees which branches closed their day and which did not.
 *
 *  - WHEN a day can be closed: once the branch is shut for that day. That is the end of the branch's last opening window of the day (Branches > Hours)
 *    plus a few minutes (setting daily_close.grace_minutes, 30 by default), or, for a branch with no hours that day, a fixed time (setting
 *    daily_close.fallback_time, 22:00 by default). A day in the past can always be closed, up to {@see self::MAX_DAYS_BACK} days later.
 *  - WHO: someone who may see the branch's reports AND approve its orders (a branch manager), at that branch. Seeing the day needs only reports.view.
 *  - THE SECOND LOOK: a day with open points (an order nobody accepted, cash still with the people who took it, a cash difference nobody has explained)
 *    cannot be closed without a note saying what is being done about them.
 *  - A closed day keeps the figures it had when it was closed. It can be re-opened by someone who manages the company's settings (both are audited).
 */
final class DailyCloseService
{
    public const MAX_DAYS_BACK = 31;

    public const DEFAULT_GRACE_MINUTES = 30;

    public const DEFAULT_FALLBACK_TIME = '22:00';

    public function __construct(
        private readonly AccessControl $access,
        private readonly AuditLogger $audit,
        private readonly DailyCloseNumbers $numbers,
        private readonly Settings $settings,
        private readonly BranchHoursService $hours,
    ) {}

    public function graceMinutes(): int
    {
        $v = $this->settings->get('daily_close.grace_minutes', self::DEFAULT_GRACE_MINUTES);

        return is_numeric($v) && (int) $v >= 0 && (int) $v <= 240 ? (int) $v : self::DEFAULT_GRACE_MINUTES;
    }

    public function fallbackTime(): string
    {
        $v = (string) $this->settings->get('daily_close.fallback_time', self::DEFAULT_FALLBACK_TIME);

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) === 1 ? $v : self::DEFAULT_FALLBACK_TIME;
    }

    /** The moment the day of $date can be closed for this branch (a UTC instant). The branch's hours must be loaded (`hours` relation). */
    public function closesAt(Branch $branch, string $date): CarbonImmutable
    {
        $zone = $branch->timezone ?: BusinessTime::zone();
        $day = CarbonImmutable::parse($date, $zone)->startOfDay();
        $ends = collect($this->hours->windowsForDate($branch, $day))->map(fn (array $w) => $w[1]);
        $closes = $ends->isEmpty() ? $day->setTimeFromTimeString($this->fallbackTime()) : $ends->max()->addMinutes($this->graceMinutes());

        return $closes->utc();
    }

    /**
     * Does the branch have a day to close on this date? A branch that is open that day does; a branch with no opening hours saved at all is treated as open
     * every day (like the order clock); a branch whose hours say it is shut that day has nothing to close unless something happened on it (the caller knows).
     */
    public function opensOn(Branch $branch, string $date): bool
    {
        $zone = $branch->timezone ?: BusinessTime::zone();

        return $branch->hours->isEmpty() || $this->hours->windowsForDate($branch, CarbonImmutable::parse($date, $zone)->startOfDay()) !== [];
    }

    /** @return 'closed'|'ready'|'in_progress' */
    public function state(Branch $branch, string $date, bool $closed, ?CarbonImmutable $now = null): string
    {
        if ($closed) {
            return 'closed';
        }

        return $this->closesAt($branch, $date) <= ($now ?? CarbonImmutable::now()) ? 'ready' : 'in_progress';
    }

    /**
     * The open points of a day's figures, each as the amount or the count that is open. Only positive ones are returned.
     *
     * @param  array<string, mixed>  $numbers  {@see DailyCloseNumbers::for()}
     * @return array<string, int|string>
     */
    public static function flagsOf(array $numbers): array
    {
        $flags = [];
        if (($numbers['open']['never_accepted'] ?? 0) > 0) {
            $flags['never_accepted'] = (int) $numbers['open']['never_accepted'];
        }
        if (! Money::of((string) ($numbers['cash']['with_collectors'] ?? '0'))->isZero()) {
            $flags['cash_with_collectors'] = (string) $numbers['cash']['with_collectors'];
        }
        if (($numbers['cash']['open_variances'] ?? 0) > 0) {
            $flags['open_variances'] = (int) $numbers['cash']['open_variances'];
        }

        return $flags;
    }

    /** Dates of a picker: today back to the oldest day that can still be closed. */
    public function earliestDate(?CarbonImmutable $now = null): string
    {
        return ($now ?? BusinessTime::now())->setTimezone(BusinessTime::zone())->subDays(self::MAX_DAYS_BACK)->toDateString();
    }

    /**
     * One entry per active branch the viewer may see reports for (and the branch filter narrows), in menu order. A closed day shows the figures it
     * was closed with; an open one shows the live figures.
     *
     * Each entry: branch, state, closes_at, close (the DailyClose row or null), numbers, flags, can_close, needs_note.
     *
     * @return list<array<string, mixed>>
     */
    public function board(User $viewer, string $date, ?string $branchId = null): array
    {
        $branches = $this->branchesFor($viewer, $branchId);
        $closes = DailyClose::query()->with('closer:id,name')->where('business_date', $date)->whereIn('branch_id', $branches->modelKeys())->get()->keyBy('branch_id');
        $now = CarbonImmutable::now();
        $out = [];
        foreach ($branches as $branch) {
            $close = $closes[$branch->id] ?? null;
            $numbers = $close !== null ? $close->numbers : $this->numbers->for($branch->id, $date);
            $state = $this->state($branch, $date, $close !== null, $now);
            $flags = $close !== null ? ($close->flags ?? []) : self::flagsOf($numbers);
            $out[] = ['branch' => $branch, 'state' => $state, 'closes_at' => $this->closesAt($branch, $date), 'close' => $close, 'numbers' => $numbers, 'flags' => $flags,
                'can_close' => $state === 'ready' && $date >= $this->earliestDate() && $this->mayClose($viewer, $branch->id), 'needs_note' => $flags !== []];
        }

        return $out;
    }

    /**
     * The active branches whose reports the viewer may see, in menu order, with their opening hours loaded; a branch filter can only narrow them.
     *
     * @return Collection<int, Branch>
     */
    public function branchesFor(User $viewer, ?string $branchId = null): Collection
    {
        $scope = $this->access->scope($viewer, 'reports.view')->narrow($branchId);

        return $scope->apply(Branch::query()->active()->with('hours'), 'id')->orderBy('sort_order')->orderBy('code')->get();
    }

    public function mayClose(User $user, string $branchId): bool
    {
        return $this->access->allows($user, 'reports.view', $branchId) && $this->access->allows($user, 'orders.approve', $branchId);
    }

    /**
     * How many orders the shown branches sold between them. An order shared by two branches is one order, so while no shown branch is closed the count is
     * worked out over the branches together (the dashboard's own figure); a closed branch only has the numbers it was closed with, so then the branches'
     * counts are added up (a shared order counts once in each).
     *
     * @param  list<array<string, mixed>>  $board
     */
    public static function ordersOf(array $board, string $date): int
    {
        if ($board === []) {
            return 0;
        }
        if (collect($board)->contains(fn (array $b) => $b['state'] === 'closed')) {
            return (int) array_sum(array_map(fn (array $b) => (int) $b['numbers']['sales']['orders'], $board));
        }
        [$from, $to] = BusinessTime::dateRange($date, $date);

        return SalesBasis::totals(BranchScope::of(array_map(fn (array $b) => (string) $b['branch']->id, $board)), $from, $to)['orders'];
    }

    /** How many branches (of those the viewer sees) closed the day, and how many there are: the owner's one-line answer. @return array{closed: int, total: int} */
    public static function tally(array $board): array
    {
        return ['closed' => count(array_filter($board, fn (array $b) => $b['state'] === 'closed')), 'total' => count($board)];
    }

    /**
     * Signs the day off. The day must be over for the branch, within the last {@see self::MAX_DAYS_BACK} days, not closed yet, and a day with open
     * points needs a note. Safe against a double tap: one row per branch and day, taken under a lock.
     *
     * @throws DomainException
     */
    public function close(User $actor, string $branchId, string $date, ?string $note): DailyClose
    {
        $branch = Branch::query()->active()->with('hours')->find($branchId) ?? throw $this->refuse('branch_unknown', 404);
        if (! $this->mayClose($actor, $branch->id)) {
            throw $this->refuse('forbidden', 403);
        }
        $this->assertDate($date);
        if ($this->closesAt($branch, $date) > CarbonImmutable::now()) {
            throw $this->refuse('too_early', 422, ['time' => BusinessTime::display($this->closesAt($branch, $date), 'H:i')]);
        }
        $note = $note !== null ? trim(preg_replace('/\s+/u', ' ', $note) ?? '') : null;
        $note = $note === '' ? null : $note;
        if ($note !== null && mb_strlen($note) > 1000) {
            throw $this->refuse('note_too_long', 422);
        }

        return DB::transaction(function () use ($actor, $branch, $date, $note) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['daily_close:'.$branch->id.':'.$date]);
            if (DailyClose::query()->where('branch_id', $branch->id)->where('business_date', $date)->exists()) {
                throw $this->refuse('already_closed', 409);
            }
            $numbers = $this->numbers->for($branch->id, $date);
            $flags = self::flagsOf($numbers);
            if ($flags !== [] && ($note === null || mb_strlen($note) < 3)) {
                throw $this->refuse('note_required', 422);
            }
            $close = DailyClose::create(['branch_id' => $branch->id, 'business_date' => $date, 'closed_by' => $actor->id, 'closed_at' => now(),
                'note' => $note, 'numbers' => $numbers, 'flags' => $flags]);
            $this->audit->log('daily_close.closed', $close, [], ['business_date' => $date, 'flags' => $flags, 'note' => $note, 'sales' => $numbers['sales']['total']],
                ['branch' => $branch->code], branchId: $branch->id, actor: $actor);

            return $close;
        });
    }

    /** Takes a closed day back to "not closed yet" (someone closed the wrong day, or something changed). Needs the settings right of the whole company. */
    public function reopen(User $actor, string $branchId, string $date, ?string $reason): void
    {
        if (! $this->access->allowsGlobally($actor, 'settings.edit')) {
            throw $this->refuse('forbidden', 403);
        }
        $reason = trim((string) $reason);
        if (mb_strlen($reason) < 3) {
            throw $this->refuse('reason_required', 422);
        }
        DB::transaction(function () use ($actor, $branchId, $date, $reason) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['daily_close:'.$branchId.':'.$date]);
            $close = DailyClose::query()->where('branch_id', $branchId)->where('business_date', $date)->lockForUpdate()->first() ?? throw $this->refuse('not_closed', 409);
            $close->delete();
            $this->audit->log('daily_close.reopened', $close, ['closed_by' => $close->closed_by, 'note' => $close->note], ['reason' => $reason], ['business_date' => $date], branchId: $branchId, actor: $actor);
        });
    }

    /** A valid, not-future, not-too-old Qatar date. */
    public function assertDate(string $date): void
    {
        if (! self::isDate($date) || $date > BusinessTime::now()->toDateString()) {
            throw $this->refuse('bad_date', 422);
        }
        if ($date < $this->earliestDate()) {
            throw $this->refuse('too_old', 422, ['days' => self::MAX_DAYS_BACK]);
        }
    }

    /** Is this a real calendar day written as Y-m-d? (a page address or a tampered request can say anything) */
    public static function isDate(string $date): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $date));

        return checkdate($m, $d, $y);
    }

    /** @param array<string, mixed> $context */
    private function refuse(string $code, int $status = 422, array $context = []): DomainException
    {
        return new DomainException('daily_close_'.$code, __('daily_close.errors.'.$code, $context), $status, $context);
    }
}
