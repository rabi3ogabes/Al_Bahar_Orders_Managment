<?php

namespace App\Livewire\Admin\Finance\Concerns;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\BranchScope;
use App\Domain\Payments\Models\CashCollection;
use App\Domain\Payments\Models\CashHandover;
use App\Domain\Payments\Models\CashShift;
use App\Domain\Payments\Services\CashDrawerService;
use App\Domain\Payments\Services\CashShiftService;
use App\Domain\Payments\Services\CashShiftSettings;
use App\Livewire\Admin\Finance\CashIndex;
use App\Support\DomainException;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

/**
 * The cash shift side of the cash page (docs/guides/CASH_SHIFT.md): the Shift panel at the top, the Shifts tab, the open / close / review windows, and
 * the list of collections a cashier ticks when a driver hands cash in. The rules are in {@see CashShiftService} and {@see CashDrawerService}; this only
 * asks, checks the person's rights at the branch again, and shows. Used by {@see CashIndex}, which owns the window state
 * (`dialog`, `showDialog`, `target`, `form`).
 */
trait HandlesCashShifts
{
    /** The most collections the hand-in window lists at once (a driver with more hands them in in two rounds). */
    public const HAND_IN_LIST_LIMIT = 300;

    /** The count typed in the open / close window: how many notes of each value, and the coins as one amount. */
    public array $count = ['notes' => [], 'coins' => ''];

    /** The collections ticked in the hand-in window (ids of open collections of the collector at that branch). */
    public array $pick = [];

    /** Shifts tab filter: '' (all) | open | closed | review. */
    #[Url(except: '')]
    public string $filterShift = '';

    // ── Windows ───────────────────────────────────────────────────────────────────────────────────────────────────

    public function openShiftDialog(string $branchId): void
    {
        $this->authorizeBranch('payments.edit', $branchId);
        $this->prepareShiftDialog('shift_open', $branchId);
    }

    public function closeShiftDialog(string $shiftId): void
    {
        $shift = CashShift::findOrFail($shiftId);
        $this->authorizeBranch('payments.edit', $shift->branch_id);
        if (! $shift->isOpen()) {
            $this->toast(__('errors.cash_shift_already_closed'), 'error');

            return;
        }
        $this->prepareShiftDialog('shift_close', $shift->id);
    }

    public function reviewShiftDialog(string $shiftId): void
    {
        $shift = CashShift::findOrFail($shiftId);
        $this->authorizeBranch('payments.override', $shift->branch_id);
        $this->prepareShiftDialog('shift_review', $shift->id);
    }

    private function prepareShiftDialog(string $dialog, string $target): void
    {
        $this->dialog = $dialog;
        $this->target = $target;
        $this->form = ['counted' => '', 'note' => '', 'resolution' => '', 'reference' => ''];
        $this->count = ['notes' => array_fill_keys(CashShiftService::NOTES, ''), 'coins' => ''];
        $this->resetValidation();
        $this->showDialog = true;
    }

    private function openShift(CashShiftService $shifts, User $user): bool
    {
        $branchId = (string) $this->target;
        $this->authorizeBranch('payments.edit', $branchId);
        $this->validateCount();

        return (bool) $this->attempt(fn () => $shifts->open($user, $branchId, $this->count['notes'] ?? [], $this->count['coins'] ?? ''), __('cash_shift.opened_ok'));
    }

    /** Closes the shift and keeps the window open on "done" (with the Z-report to print), so it returns false even when it worked. */
    private function closeShift(CashShiftService $shifts, User $user): bool
    {
        $shift = CashShift::findOrFail($this->target);
        $this->authorizeBranch('payments.edit', $shift->branch_id);
        $this->validateCount();
        if (! $this->countTyped()) {
            // Closing with every box left empty would count the drawer as empty: the cashier must type something (a 0 is something).
            $this->addError('count', __('cash_shift.count_first'));

            return false;
        }
        $note = trim((string) ($this->form['note'] ?? ''));
        $closed = $this->attempt(fn () => $shifts->close($user, $shift, $this->count['notes'] ?? [], $this->count['coins'] ?? '', $note !== '' ? $note : null));
        if ($closed instanceof CashShift) {
            $this->dialog = 'shift_done';
            $this->target = $closed->id;
            $this->toast(__('cash_shift.closed_ok', ['z' => (string) $closed->zLabel()]));
        }

        return false;
    }

    private function reviewShift(CashShiftService $shifts, User $user): bool
    {
        $shift = CashShift::findOrFail($this->target);
        $this->authorizeBranch('payments.override', $shift->branch_id);
        $f = $this->validate(['form.resolution' => 'required|string|min:3|max:500'], [], ['form.resolution' => __('finance_admin.resolution')])['form'];

        return (bool) $this->attempt(fn () => $shifts->reviewVariance($user, $shift, $f['resolution']), __('cash_shift.reviewed_ok'));
    }

    /** Has the cashier typed anything in the count (a 0 counts)? */
    private function countTyped(): bool
    {
        return collect($this->count['notes'] ?? [])->contains(fn ($v) => $v !== '' && $v !== null) || trim((string) ($this->count['coins'] ?? '')) !== '';
    }

    private function validateCount(): void
    {
        $this->validate(['count.notes.*' => 'nullable|integer|min:0|max:100000', 'count.coins' => ['nullable', 'regex:/^\d{1,7}(\.\d{1,2})?$/']], [], ['count.notes.*' => __('cash_shift.notes'), 'count.coins' => __('cash_shift.coins')]);
    }

    // ── Hand-in by selected collections ───────────────────────────────────────────────────────────────────────────

    /** Every open collection of the collector at the branch is ticked to begin with (the usual "whole balance"); the cashier unticks what is not being handed in. */
    private function tickAll(string $collectorId, string $branchId): void
    {
        $this->pick = $this->openCollectionsOf($collectorId, $branchId)->pluck('id')->all();
    }

    public function selectAllCollections(): void
    {
        [$collectorId, $branchId] = array_pad(explode('|', (string) $this->target), 2, null);
        if ($this->dialog !== 'handin' || $collectorId === null || $branchId === null) {
            return;
        }
        $this->authorizeBranch('payments.edit', $branchId);
        $this->tickAll($collectorId, $branchId);
    }

    public function clearCollections(): void
    {
        $this->pick = [];
    }

    /** @return Collection<int, CashCollection> the oldest first, with the order number; bounded so a window never loads a huge list */
    private function openCollectionsOf(string $collectorId, string $branchId): Collection
    {
        return app(CashDrawerService::class)->openCollections($collectorId, $branchId)->with('payment.order:id,number')
            ->orderBy('collected_at')->orderBy('id')->limit(self::HAND_IN_LIST_LIMIT)->get();
    }

    // ── What the page shows ───────────────────────────────────────────────────────────────────────────────────────

    /**
     * The Shift panel, the Shifts tab and the windows of the shift side of the page.
     *
     * @return array<string, mixed>
     */
    private function shiftViewData(CashShiftService $shifts, CashShiftSettings $settings, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $scope = $this->branchScope('payments.view');
        $branches = $this->scopedBranches('payments.view')->when($this->branch !== '', fn (Collection $b) => $b->where('id', $this->branch))->values();
        $ids = $branches->pluck('id')->all();

        $open = $ids === [] ? collect() : CashShift::query()->whereIn('branch_id', $ids)->where('status', 'open')->with('opener:id,name')->orderBy('opened_at')->get()->keyBy('branch_id');
        $lastIds = $ids === [] ? [] : DB::table('cash_shifts')->selectRaw('DISTINCT ON (branch_id) id')->where('status', 'closed')->whereIn('branch_id', $ids)->orderBy('branch_id')->orderByDesc('closed_at')->pluck('id')->all();
        $last = $lastIds === [] ? collect() : CashShift::query()->whereIn('id', $lastIds)->get()->keyBy('branch_id');
        $figures = $shifts->figuresFor($open->values());

        $reviews = $this->shiftsQuery($scope)->where('variance_status', 'open')->count();
        $history = null;
        if ($this->tab === 'shifts') {
            $history = $this->shiftsQuery($scope)->with(['branch:id,code', 'opener:id,name', 'closer:id,name'])
                ->where(fn ($w) => $w->whereBetween('opened_at', [$start, $end])->orWhere('status', 'open')->orWhere('variance_status', 'open'))
                ->when($this->filterShift === 'open', fn ($q) => $q->where('status', 'open'))
                ->when($this->filterShift === 'closed', fn ($q) => $q->where('status', 'closed'))
                ->when($this->filterShift === 'review', fn ($q) => $q->where('variance_status', 'open'))
                ->orderByDesc('opened_at')->orderByDesc('id')->paginate($this->perPage);
        }

        return [
            'shiftBranches' => $branches, 'openShifts' => $open, 'lastShifts' => $last, 'shiftFigures' => $figures, 'shiftReviews' => $reviews, 'shiftHistory' => $history,
            'shiftRules' => ['note' => $settings->noteAbove(), 'review' => $settings->reviewAbove(), 'maxHours' => $settings->maxOpenHours(), 'blocks' => $settings->blocksWithoutShift()],
            'shiftDialog' => $this->shiftDialogData($shifts, $settings),
            'handinCollections' => $this->dialog === 'handin' ? $this->handinCollections() : collect(),
            'handinBranch' => $this->dialog === 'handin' ? (explode('|', (string) $this->target)[1] ?? null) : null,
            'handinBlocked' => $this->dialog === 'handin' && $settings->blocksWithoutShift() && $shifts->openShift((string) (explode('|', (string) $this->target)[1] ?? '')) === null,
        ];
    }

    /** @return Builder<CashShift> */
    private function shiftsQuery(BranchScope $scope)
    {
        $q = CashShift::query();
        $scope->apply($q->getQuery(), 'branch_id');

        return $q;
    }

    /** @return Collection<int, CashCollection> */
    private function handinCollections(): Collection
    {
        [$collectorId, $branchId] = array_pad(explode('|', (string) $this->target), 2, null);

        return $collectorId === null || $branchId === null ? collect() : $this->openCollectionsOf($collectorId, $branchId);
    }

    /**
     * What the open / close / review / done windows need: the shift, the figures, the typed count and what follows from it.
     *
     * @return array<string, mixed>|null
     */
    private function shiftDialogData(CashShiftService $shifts, CashShiftSettings $settings): ?array
    {
        if (! in_array($this->dialog, ['shift_open', 'shift_close', 'shift_review', 'shift_done'], true) || ! $this->target) {
            return null;
        }
        $count = null;
        try {
            $count = $shifts->count($this->count['notes'] ?? [], $this->count['coins'] ?? '');
        } catch (DomainException) {
            // a half-typed value: the total shows nothing until it is a proper count
        }

        if ($this->dialog === 'shift_open') {
            $branch = $this->scopedBranches('payments.edit')->firstWhere('id', $this->target);
            $previous = CashShift::query()->where('branch_id', $this->target)->where('status', 'closed')->orderByDesc('closed_at')->first();
            $gap = $previous === null ? null : CashHandover::query()->where('branch_id', $this->target)->whereNull('cash_shift_id')->where('created_at', '>=', $previous->closed_at)
                ->toBase()->selectRaw('count(*) as n, coalesce(sum(counted_amount), 0) as total')->first();

            return ['branch' => $branch, 'previous' => $previous, 'count' => $count,
                'gapCount' => (int) ($gap->n ?? 0), 'gapTotal' => Money::of((string) ($gap->total ?? '0'))];
        }

        $shift = CashShift::query()->with(['branch:id,code,name_en,name_ar', 'opener:id,name', 'closer:id,name', 'reviewer:id,name'])->find($this->target);
        if ($shift === null) {
            return null;
        }
        $data = ['shift' => $shift, 'count' => $count];
        if ($this->dialog === 'shift_close') {
            $figures = $shifts->figures($shift);
            $variance = $count === null || ! $this->countTyped() ? null : $count['total']->minus($figures['expected']);
            $size = $variance === null ? null : Money::ofMinor(abs($variance->minor));
            $data += ['figures' => $figures, 'variance' => $variance,
                'needsNote' => $size !== null && $size->greaterThan($settings->noteAbove()), 'needsReview' => $size !== null && $size->greaterThan($settings->reviewAbove())];
        }

        return $data;
    }

    // ── Open and close the window of a shift ──────────────────────────────────────────────────────────────────────

    /** True when the window is one of the shift windows (the page uses a wider window for them). */
    public function isShiftDialog(): bool
    {
        return in_array($this->dialog, ['shift_open', 'shift_close', 'shift_review', 'shift_done'], true);
    }
}
