<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Models\NotificationTemplate;

/** Resolves an AR/EN template for (event, channel, locale) and fills {{placeholders}}; falls back to lang files. */
class TemplateRenderer
{
    /** @return array{subject: string, body: string} */
    public function render(string $event, string $channel, string $locale, array $data): array
    {
        $tpl = NotificationTemplate::where('event', $event)->where('channel', $channel)->where('locale', $locale)->where('is_active', true)->first()
            ?? NotificationTemplate::where('event', $event)->where('channel', $channel)->where('locale', 'en')->where('is_active', true)->first();
        $subject = $tpl?->subject ?? trans('notifications.'.$event.'.title', [], $locale);
        $body = $tpl?->body ?? trans('notifications.'.$event.'.body', [], $locale);
        $fill = fn (string $s) => preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', fn ($m) => (string) ($data[$m[1]] ?? ''), $s);

        return ['subject' => $fill((string) $subject), 'body' => $fill((string) $body)];
    }
}
