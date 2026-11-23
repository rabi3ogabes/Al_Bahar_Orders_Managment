<?php

namespace App\Domain\Partners\Services;

/**
 * What one partner link may see. The old shared link has no scope (null): everything, as it always did. A per-partner scope
 * limits the data to some branches and never carries personal data (see PartnerReport).
 */
final class PartnerScope
{
    /** @param list<string>|null $branchIds null = every branch */
    public function __construct(public readonly ?array $branchIds, public readonly string $partnerId) {}
}
