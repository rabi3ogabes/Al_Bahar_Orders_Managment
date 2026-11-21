<?php

namespace App\Domain\Reports\Services;

use App\Domain\Notifications\Services\NotificationChannelStatus;
use App\Domain\Settings\Services\Settings;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Scoped;

/**
 * The owner pack's settings, read in one place: whether it is on, who gets it, on which day and at what time (Qatar time), in which language and on
 * which channels. Saved through {@see Settings}, so every change is audited like any other setting. A saved value that is not usable (a hand-edited
 * row) reads as the safe default instead of breaking the send. OFF until the owner switches it on.
 */
#[Scoped]
final class OwnerPackSettings
{
    public const MAX_RECIPIENTS = 10;

    /** The longest address the send log can hold (the column of a recipient). */
    public const MAX_ADDRESS_LENGTH = 191;

    /** ISO weekdays: 1 = Monday ... 7 = Sunday. */
    public const DAYS = [1, 2, 3, 4, 5, 6, 7];

    public const DEFAULT_DAY = 1;

    public const DEFAULT_TIME = '08:00';

    public const LANGUAGES = ['en', 'ar'];

    public function __construct(private readonly Settings $settings, private readonly NotificationChannelStatus $channels) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('owner_pack.enabled', false);
    }

    /** @return list<string> */
    public function emails(): array
    {
        return $this->list('owner_pack.emails', fn (string $v) => strlen($v) <= self::MAX_ADDRESS_LENGTH && filter_var($v, FILTER_VALIDATE_EMAIL) !== false);
    }

    /** @return list<string> the owners' WhatsApp numbers in international form (+974...) */
    public function numbers(): array
    {
        return $this->list('owner_pack.whatsapp_numbers', fn (string $v) => preg_match('/^\+\d{8,15}$/', $v) === 1);
    }

    public function emailOn(): bool
    {
        return (bool) $this->settings->get('owner_pack.email_enabled', true);
    }

    public function whatsappOn(): bool
    {
        return (bool) $this->settings->get('owner_pack.whatsapp_enabled', false);
    }

    public function day(): int
    {
        $v = $this->settings->get('owner_pack.day', self::DEFAULT_DAY);

        return is_numeric($v) && in_array((int) $v, self::DAYS, true) ? (int) $v : self::DEFAULT_DAY;
    }

    public function time(): string
    {
        $v = (string) $this->settings->get('owner_pack.time', self::DEFAULT_TIME);

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) === 1 ? $v : self::DEFAULT_TIME;
    }

    public function language(): string
    {
        $v = (string) $this->settings->get('owner_pack.language', 'en');

        return in_array($v, self::LANGUAGES, true) ? $v : 'en';
    }

    /** Can an e-mail leave this server? (false while the mailer only writes to a log) */
    public function mailReady(): bool
    {
        return $this->channels->isReady('email');
    }

    /** Is a WhatsApp provider connected? Today none is on the live server, so the dashboard says "not connected yet" and the send log records it. */
    public function whatsappReady(): bool
    {
        return $this->channels->isReady('whatsapp');
    }

    /**
     * When the pack for an ISO week is due: the chosen weekday and time AFTER the week has ended (the Sunday night), in Qatar time. With Monday
     * at 08:00 the pack of week 46 is due on Monday morning of week 47.
     */
    public function dueAt(string $week): CarbonImmutable
    {
        [$from] = OwnerPackData::bounds($week);
        $monday = CarbonImmutable::parse($from, BusinessTime::zone())->startOfDay();

        return $monday->addWeek()->addDays($this->day() - 1)->setTimeFromTimeString($this->time())->utc();
    }

    /** @return list<string> */
    private function list(string $key, callable $valid): array
    {
        $raw = $this->settings->get($key, []);
        $seen = [];
        foreach (is_array($raw) ? $raw : [] as $v) {
            $v = is_string($v) ? strtolower(trim($v)) : '';
            if ($v !== '' && $valid($v) && ! in_array($v, $seen, true)) {
                $seen[] = $v;
            }
        }

        return array_slice($seen, 0, self::MAX_RECIPIENTS);
    }

    /**
     * Splits what a person typed into a clean list of entries (nothing validated yet). E-mail addresses never contain a space, so they may be separated
     * by spaces too; a phone number is often typed with spaces ("5512 3456"), so numbers are separated by new lines, commas and semicolons only.
     *
     * @return list<string>
     */
    public static function split(string $text, bool $spaces = true): array
    {
        $parts = preg_split($spaces ? '/[\s,;]+/u' : '/[\n\r,;]+/u', $text) ?: [];

        return array_values(array_unique(array_filter(array_map(fn (string $p) => strtolower(trim($p)), $parts), fn (string $p) => $p !== '')));
    }
}
