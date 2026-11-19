<?php

namespace App\Domain\Operations\Support;

use App\Domain\Operations\Services\OpsClock;
use Carbon\CarbonImmutable;

/**
 * How long something has been waiting for a person, and how worried the dashboard should be: the answer of {@see OpsClock}.
 *
 *  - `state`        ok | amber | red (never colour alone on screen: the chip also has a shape and a word)
 *  - `ageMinutes`   the minutes that COUNT (opening hours only for the branch steps; plain time for pickups, cash and refunds)
 *  - `wallMinutes`  the minutes that really passed on the wall clock, shown in the tooltip when the two differ
 *  - `label`        the counted age as short plain text in the dashboard language ("12 min", "2 h 05 min", "3 d")
 *  - `since`        when the wait began
 *  - `paused`       true while the branch is closed, so the clock is standing still
 */
final class OpsAge
{
    public const OK = 'ok';

    public const AMBER = 'amber';

    public const RED = 'red';

    public function __construct(
        public readonly string $kind,
        public readonly string $state,
        public readonly int $ageMinutes,
        public readonly int $wallMinutes,
        public readonly string $label,
        public readonly CarbonImmutable $since,
        public readonly bool $paused = false,
    ) {}

    /** Higher is worse: red 2, amber 1, ok 0 (for sorting and for "the worst of these"). */
    public function rank(): int
    {
        return self::rankOf($this->state);
    }

    public static function rankOf(string $state): int
    {
        return match ($state) {
            self::RED => 2,
            self::AMBER => 1,
            default => 0,
        };
    }

    public function isLate(): bool
    {
        return $this->state !== self::OK;
    }

    /** @return array{kind: string, state: string, ageMinutes: int, wallMinutes: int, label: string, since: string, paused: bool} */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind, 'state' => $this->state, 'ageMinutes' => $this->ageMinutes, 'wallMinutes' => $this->wallMinutes,
            'label' => $this->label, 'since' => $this->since->toIso8601String(), 'paused' => $this->paused,
        ];
    }
}
