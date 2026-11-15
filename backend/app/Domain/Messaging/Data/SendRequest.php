<?php

namespace App\Domain\Messaging\Data;

use App\Domain\Marketing\Models\JourneyTemplate;
use Carbon\CarbonImmutable;

/** Everything the composer submits for one send. */
final class SendRequest
{
    /**
     * @param  list<string>  $channels  notification | whatsapp
     * @param  array<string, mixed>  $content  title_en ... deep_link, coupon{} as typed (cleaned and validated by MessageContent)
     */
    public function __construct(
        public readonly AudienceSelection $audience,
        public readonly array $channels,
        public readonly string $kind,
        public readonly array $content,
        public readonly ?JourneyTemplate $template = null,
        public readonly ?CarbonImmutable $scheduleAt = null,
        public readonly bool $ignoreCooldown = false,
    ) {}
}
