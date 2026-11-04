<?php

namespace App\Domain\Branches\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchHoliday;
use App\Domain\Branches\Models\BranchSetting;
use Illuminate\Support\Facades\DB;

class BranchService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(array $data): Branch
    {
        return DB::transaction(function () use ($data) {
            $hours = $data['hours'] ?? null;
            unset($data['hours']);
            $branch = Branch::create($data);
            $this->setWeeklyHours($branch, $hours ?? $this->defaultHours(), audit: false);
            $this->audit->log('branch.created', $branch, [], $branch->only(['code', 'name_en', 'status']));

            return $branch;
        });
    }

    public function update(Branch $branch, array $data): Branch
    {
        $branch->fill($data);
        $dirty = $branch->getDirty();
        $old = array_intersect_key($branch->getOriginal(), $dirty);
        $branch->save();
        if ($dirty) {
            $this->audit->log('branch.updated', $branch, $old, $dirty);
        }

        return $branch;
    }

    public function setStatus(Branch $branch, string $status): Branch
    {
        return $this->update($branch, ['status' => $status]);
    }

    /** @param array<int, array{is_closed?: bool, opens_at?: ?string, closes_at?: ?string, break_starts_at?: ?string, break_ends_at?: ?string}> $week keyed 0..6 */
    public function setWeeklyHours(Branch $branch, array $week, bool $audit = true): void
    {
        DB::transaction(function () use ($branch, $week, $audit) {
            $old = $branch->hours()->get()->keyBy('day_of_week')->map->only(['is_closed', 'opens_at', 'closes_at'])->all();
            foreach (range(0, 6) as $dow) {
                $day = $week[$dow] ?? ['is_closed' => true];
                $closed = (bool) ($day['is_closed'] ?? false) || empty($day['opens_at']) || empty($day['closes_at']);
                $branch->hours()->updateOrCreate(['day_of_week' => $dow], [
                    'is_closed' => $closed,
                    'opens_at' => $closed ? null : $day['opens_at'],
                    'closes_at' => $closed ? null : $day['closes_at'],
                    'break_starts_at' => $closed ? null : ($day['break_starts_at'] ?? null ?: null),
                    'break_ends_at' => $closed ? null : ($day['break_ends_at'] ?? null ?: null),
                ]);
            }
            $branch->unsetRelation('hours');
            if ($audit) {
                $this->audit->log('branch.hours_updated', $branch, ['hours' => $old], ['hours' => $week]);
            }
        });
    }

    public function addHoliday(Branch $branch, array $data): BranchHoliday
    {
        $holiday = $branch->holidays()->create([...$data, 'created_by' => auth()->id()]);
        $branch->unsetRelation('holidays');
        $this->audit->log('branch.holiday_added', $branch, [], $holiday->only(['type', 'name_en', 'starts_at', 'ends_at']));

        return $holiday;
    }

    public function removeHoliday(BranchHoliday $holiday): void
    {
        $holiday->delete();
        $this->audit->log('branch.holiday_removed', 'BranchHoliday', $holiday->toArray(), [], ['branch_id' => $holiday->branch_id], branchId: $holiday->branch_id);
    }

    public function setSetting(Branch $branch, string $key, mixed $value): void
    {
        $old = $branch->setting($key);
        BranchSetting::updateOrCreate(['branch_id' => $branch->id, 'key' => $key], ['value' => ['v' => $value]]);
        $branch->unsetRelation('settings');
        $this->audit->log('branch.setting_updated', $branch, [$key => $old], [$key => $value]);
    }

    /** Typical Qatar retail hours: Sat–Thu 10:00–22:00, Friday 14:00–22:00 (after prayers). */
    public function defaultHours(): array
    {
        $week = [];
        foreach (range(0, 6) as $dow) {
            $week[$dow] = $dow === 5
                ? ['is_closed' => false, 'opens_at' => '14:00', 'closes_at' => '22:00']
                : ['is_closed' => false, 'opens_at' => '10:00', 'closes_at' => '22:00'];
        }

        return $week;
    }
}
