<?php

namespace App\Domain\Marketing\Support;

use App\Support\BusinessTime;
use Carbon\CarbonImmutable;

/**
 * The hours of the night in which no automatic message goes out, read in Qatar time (which has no daylight saving, so a day is always 24 hours).
 * "22:00 to 08:00" crosses midnight; "13:00 to 15:00" does not. Empty or equal times mean there is no quiet period.
 */
final class QuietHours
{
    public function __construct(public readonly ?string $from, public readonly ?string $to) {}

    public function isSet(): bool
    {
        return $this->from !== null && $this->to !== null && $this->from !== '' && $this->to !== '' && $this->from !== $this->to;
    }

    /** Is this moment inside the quiet period? */
    public function contains(CarbonImmutable $at): bool
    {
        if (! $this->isSet()) {
            return false;
        }
        $now = BusinessTime::local($at)?->format('H:i') ?? '';

        return $this->from < $this->to ? ($now >= $this->from && $now < $this->to) : ($now >= $this->from || $now < $this->to);
    }

    /** When the quiet period that contains $at ends (UTC); $at itself when it is not quiet. */
    public function endAfter(CarbonImmutable $at): CarbonImmutable
    {
        if (! $this->contains($at) || $this->to === null) {
            return $at;
        }
        [$h, $m] = array_map('intval', explode(':', $this->to));
        $local = BusinessTime::local($at) ?? $at;
        $end = $local->setTime($h, $m, 0);
        if ($end->lessThanOrEqualTo($local)) {
            $end = $end->addDay();
        }

        return $end->utc();
    }
}
