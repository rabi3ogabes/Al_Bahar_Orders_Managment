<?php

namespace App\Domain\Notifications\Contracts;

interface SmsSender
{
    /**
     * @param  string  $category  otp | transactional | marketing — providers may route OTP via a dedicated sender ID.
     * @return array{status: string, provider_message_id: ?string}
     */
    public function send(string $phoneE164, string $message, string $category = 'transactional'): array;

    public function name(): string;
}
