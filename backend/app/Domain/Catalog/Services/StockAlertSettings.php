<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Marketing\Support\QuietHours;
use App\Domain\Settings\Services\Settings;
use App\Support\Money;

/**
 * The owner's rules for back-in-stock and price-drop alerts (Settings > Notifications > "Back in stock and price alerts"), read with the limits the
 * dashboard enforces, so a hand-edited or older value can never produce an absurd threshold. Defaults live in config/platform.php (`alerts`).
 */
final class StockAlertSettings
{
    /** The most alerts one customer can have at once (a stable limit, not a setting: it only protects the list and the scan). */
    public const MAX_PER_CUSTOMER = 100;

    public const PERCENT_RANGE = [1, 90];

    public const WEEKLY_RANGE = [1, 14];

    public const RETENTION_RANGE = [7, 365];

    public function __construct(private readonly Settings $settings) {}

    /** @param  'back_in_stock'|'price_drop'  $type */
    public function enabled(string $type): bool
    {
        return (bool) $this->settings->get('alerts.'.$type.'_enabled', $type === 'back_in_stock');
    }

    /** A price must fall by at least this many percent of what the customer last saw. */
    public function dropPercent(): int
    {
        return $this->clamp($this->settings->get('alerts.price_drop_percent', 5), self::PERCENT_RANGE, 5);
    }

    /** ... and by at least this much money, so a few dirhams off a cheap item is not worth a push. */
    public function minAmount(): Money
    {
        $raw = (string) $this->settings->get('alerts.price_drop_min_amount', '5.00');

        return preg_match('/^\d{1,6}(\.\d{1,2})?$/', $raw) === 1 ? Money::of($raw) : Money::of('5.00');
    }

    /** The most price-drop messages one customer gets in any 7 days. */
    public function weeklyCap(): int
    {
        return $this->clamp($this->settings->get('alerts.max_price_pushes_per_week', 3), self::WEEKLY_RANGE, 3);
    }

    /** Days an alert nobody acted on is kept (counted from the day it was made). */
    public function retentionDays(): int
    {
        return $this->clamp($this->settings->get('alerts.retention_days', 90), self::RETENTION_RANGE, 90);
    }

    /** No alert goes out inside these hours (Qatar time); it waits and goes at the end of them, if it is still true then. */
    public function quietHours(): QuietHours
    {
        $from = $this->settings->get('alerts.quiet_start');
        $to = $this->settings->get('alerts.quiet_end');

        return new QuietHours(is_string($from) && $from !== '' ? $from : null, is_string($to) && $to !== '' ? $to : null);
    }

    /**
     * What the apps may know without signing in: which alerts the store offers and how big a drop has to be, so the "Tell me if the price drops" choice is
     * only shown when it can ever fire.
     *
     * @return array{back_in_stock: bool, price_drop: bool, price_drop_percent: int, price_drop_min_amount: string}
     */
    public function forApi(): array
    {
        return ['back_in_stock' => $this->enabled('back_in_stock'), 'price_drop' => $this->enabled('price_drop'),
            'price_drop_percent' => $this->dropPercent(), 'price_drop_min_amount' => $this->minAmount()->toDecimal()];
    }

    /** @param  array{0: int, 1: int}  $range */
    private function clamp(mixed $value, array $range, int $default): int
    {
        return is_numeric($value) ? max($range[0], min($range[1], (int) $value)) : $default;
    }
}
