<?php

namespace App\Domain\Reports\Services;

/**
 * A scorecard tab as the rows of a CSV: the same numbers as the page, one line per branch (and one for all of them together) or per driver. A figure with
 * too few cases behind it is written as the words "Not enough data yet", never as a number; one with no cases at all is left empty. Names are made safe
 * for a spreadsheet (a name that starts like a formula is never run as one). Aggregates and staff names only: no customer appears.
 */
final class ScorecardCsv
{
    /**
     * @param  array<string, mixed>  $data  {@see ScorecardService::branches()} or {@see ScorecardService::drivers()}
     * @return array{header: list<string>, rows: list<list<int|float|string|null>>}
     */
    public static function rows(string $tab, array $data, string $locale): array
    {
        return $tab === 'drivers' ? self::drivers($data) : self::branches($data, $locale);
    }

    /** @param  array<string, mixed>  $data */
    private static function branches(array $data, string $locale): array
    {
        $t = fn (string $k) => __('scorecard.csv.'.$k);
        $header = array_map($t, ['branch', 'code', 'from', 'to', 'orders', 'orders_before', 'accept_median', 'accept_p90', 'accept_n', 'prepare_median', 'prepare_p90', 'prepare_n', 'delivered',
            'on_time_pct', 'on_time_before', 'promised', 'moved', 'pickup_pct', 'pickup_n', 'cancelled', 'cancel_pct', 'failed', 'failed_pct', 'failed_customer', 'failed_team', 'failed_other',
            'claims', 'claims_pct', 'subs', 'subs_pct', 'handins', 'cash_net', 'cash_abs', 'variances', 'open_variances', 'cash_late', 'cash_late_amount']);
        $period = $data['period'];
        $line = fn (array $b, string $name, string $code) => [self::safe($name), $code, $period['from'], $period['to'], $b['orders']['value'], $b['orders']['previous'],
            self::cell($b['accept']['median']), self::cell($b['accept']['p90']), $b['accept']['n'], self::cell($b['prepare']['median']), self::cell($b['prepare']['p90']), $b['prepare']['n'],
            $b['delivery']['delivered'], self::cell($b['delivery']['on_time']), $b['delivery']['on_time']['previous'], $b['delivery']['promised'], $b['delivery']['moved'],
            self::cell($b['pickup']['in_time']), $b['pickup']['in_time']['n'], $b['cancel']['count'], self::cell($b['cancel']['rate']), $b['failed']['count'], self::cell($b['failed']['rate']),
            $b['failed']['customer'], $b['failed']['team'], $b['failed']['other'], $b['claims']['count'], self::cell($b['claims']['rate']), $b['substitutions']['count'], self::cell($b['substitutions']['rate']),
            $b['cash']['handins'], $b['cash']['net'], $b['cash']['abs'], $b['cash']['variances'], $b['cash']['open_variances'], $b['cash']['late'], $b['cash']['late_amount']];
        $rows = [];
        foreach ($data['rows'] as $b) {
            $rows[] = $line($b, $locale === 'ar' ? $b['name_ar'] : $b['name_en'], $b['code']);
        }
        if ($data['total'] !== null) {
            $rows[] = $line($data['total'], __('scorecard.together', ['count' => count($data['rows'])]), '');
        }

        return ['header' => $header, 'rows' => $rows];
    }

    /** @param  array<string, mixed>  $data */
    private static function drivers(array $data): array
    {
        $t = fn (string $k) => __('scorecard.csv.'.$k);
        $header = array_map($t, ['driver', 'from', 'to', 'delivered', 'on_time_pct', 'promised', 'trip_median', 'trip_p90', 'trip_n', 'failed', 'failed_pct', 'failed_customer', 'failed_team',
            'failed_other', 'declined', 'timeouts', 'cash_held', 'cash_oldest', 'handins', 'cash_net', 'cash_abs', 'variances']);
        $period = $data['period'];
        $rows = [];
        foreach ($data['rows'] as $d) {
            $rows[] = [self::safe($d['name']), $period['from'], $period['to'], $d['delivered']['value'], self::cell($d['on_time']), $d['on_time']['promised'], self::cell($d['trip']['median']),
                self::cell($d['trip']['p90']), $d['trip']['n'], $d['failed']['count'], self::cell($d['failed']['rate']), $d['failed']['customer'], $d['failed']['team'], $d['failed']['other'],
                $d['jobs']['declined'], $d['jobs']['timeouts'], $d['cash']['amount'], $d['cash']['oldest_minutes'], $d['cash']['handins'], $d['cash']['net'], $d['cash']['abs'], $d['cash']['variances']];
        }

        return ['header' => $header, 'rows' => $rows];
    }

    /** The number, the words "Not enough data yet", or nothing at all. @param  array{value: ?float, state: string}  $metric */
    private static function cell(array $metric): float|string|null
    {
        return match ($metric['state']) {
            ScorecardStats::OK => $metric['value'],
            ScorecardStats::FEW => __('scorecard.csv.not_enough'),
            default => null,
        };
    }

    /** A name a spreadsheet would run as a formula gets a quote in front of it. */
    private static function safe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
