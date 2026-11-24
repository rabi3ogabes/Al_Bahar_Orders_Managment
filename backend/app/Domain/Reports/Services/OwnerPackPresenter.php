<?php

namespace App\Domain\Reports\Services;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Turns the owner pack's figures ({@see OwnerPackData}) into words and numbers ready to print, in one language: the dashboard preview, the HTML e-mail,
 * its plain-text version and the WhatsApp text all read THIS, so they can never say different things. It formats; it never queries.
 *
 * A change against the week before is always written (an arrow and a signed percent), never only coloured, and its tone says whether the move is
 * good news: more sales is good, more claims or more empty shelves are not.
 */
final class OwnerPackPresenter
{
    /** The columns of the branch table: key => whether a rise is good (true), bad (false) or neither (null). */
    public const COLUMNS = ['sales' => true, 'orders' => true, 'average' => true, 'on_time_rate' => true, 'claims_rate' => false, 'stock_out' => false, 'cash_variance' => null];

    /**
     * @param  array<string, mixed>  $data  {@see OwnerPackData::for()}
     * @return array<string, mixed>
     */
    public static function present(array $data, string $lang): array
    {
        $t = fn (string $key, array $replace = []) => (string) __('owner_pack.'.$key, $replace, $lang);
        $day = fn (string $date) => CarbonImmutable::parse($date)->locale($lang)->translatedFormat('d M');
        $weekNo = (int) substr($data['week'], -2);
        $headline = [];
        foreach (['sales', 'orders', 'average', 'on_time_rate', 'claims_rate'] as $key) {
            $headline[] = ['key' => $key, 'label' => $t('metric.'.$key)] + self::cell($key, $data['total']['this'], $data['total']['prev'], $lang, true);
        }

        $rows = [];
        foreach ($data['branches'] as $b) {
            $rows[] = ['name' => $lang === 'ar' ? $b['name_ar'] : $b['name_en'], 'code' => $b['code'], 'total' => false,
                'cells' => array_map(fn (string $key) => self::cell($key, $b['this'], $b['prev'], $lang), array_keys(self::COLUMNS))];
        }
        $rows[] = ['name' => $t('total'), 'code' => '', 'total' => true, 'cells' => array_map(fn (string $key) => self::cell($key, $data['total']['this'], $data['total']['prev'], $lang), array_keys(self::COLUMNS))];

        $product = fn (array $p, int $i) => ['rank' => $i + 1, 'name' => $lang === 'ar' ? $p['name_ar'] : $p['name_en'], 'units' => number_format($p['units']),
            'revenue' => Money::of($p['revenue'])->format($lang)];

        return [
            'lang' => $lang, 'dir' => $lang === 'ar' ? 'rtl' : 'ltr',
            'title' => $t('title'),
            'period' => $t('period', ['week' => $weekNo, 'from' => $day($data['from']), 'to' => $day($data['to']).' '.CarbonImmutable::parse($data['to'])->year]),
            'versus' => $t('versus', ['from' => $day($data['prev_from']), 'to' => $day($data['prev_to'])]),
            'headline' => $headline,
            'columns' => array_map(fn (string $key) => ['key' => $key, 'label' => $t('metric.'.$key.'_short')], array_keys(self::COLUMNS)),
            'rows' => $rows,
            'assembly' => self::assembly($data, $lang),
            'top_units_title' => $t('top_units'), 'top_revenue_title' => $t('top_revenue'),
            'top_units' => array_map($product, $data['top_units'], array_keys($data['top_units'])),
            'top_revenue' => array_map($product, $data['top_revenue'], array_keys($data['top_revenue'])),
            'top_empty' => $t('top_empty'),
            'attention_title' => $t('attention_title'),
            'attention' => array_map(fn (array $a) => self::sentence($a, $lang), $data['attention']),
            'attention_clear' => $t('attention_clear'),
            'footer' => $t('footer'),
            'definitions' => [
                $t('def.sales'), $t('def.orders'), $t('def.average'), $t('def.on_time_rate'), $t('def.claims_rate'), $t('def.stock_out'), $t('def.cash_variance'), $t('def.assembly'),
            ],
        ];
    }

    /**
     * The assembly block: one line per branch and the total (net assembly sold, visits to do, visits the driver reported as not done). Aggregates only.
     *
     * @param  array<string, mixed>  $data  {@see OwnerPackData::for()}
     * @return array{title: string, columns: list<string>, rows: list<array{name: string, total: bool, cells: list<string>}>}
     */
    private static function assembly(array $data, string $lang): array
    {
        $t = fn (string $key) => (string) __('owner_pack.'.$key, [], $lang);
        $cells = fn (array $w) => [Money::of((string) $w['assembly_net'])->format($lang), number_format((int) $w['assembly_jobs']), number_format((int) $w['assembly_not_done'])];
        $rows = [];
        foreach ($data['branches'] as $b) {
            $rows[] = ['name' => $lang === 'ar' ? $b['name_ar'] : $b['name_en'], 'total' => false, 'cells' => $cells($b['this'])];
        }
        $rows[] = ['name' => $t('total'), 'total' => true, 'cells' => $cells($data['total']['this'])];

        return ['title' => $t('assembly_title'), 'columns' => [$t('assembly_net'), $t('assembly_jobs'), $t('assembly_not_done')], 'rows' => $rows];
    }

    /**
     * One figure with its move against the week before.
     *
     * @param  array<string, mixed>  $now
     * @param  array<string, mixed>  $before
     * @return array{value: string, change: ?string, tone: string, before: ?string}
     */
    public static function cell(string $key, array $now, array $before, string $lang, bool $withBefore = false): array
    {
        $value = $now[$key] ?? null;
        $prev = $before[$key] ?? null;
        $shown = match ($key) {
            'sales', 'average' => Money::of((string) $value)->format($lang),
            'cash_variance' => Money::of((string) $value)->format($lang),
            'on_time_rate', 'claims_rate' => $value === null ? __('owner_pack.none', [], $lang) : rtrim(rtrim(number_format((float) $value, 1), '0'), '.').'%',
            default => number_format((int) $value),
        };
        $change = null;
        $tone = 'none';
        if (in_array($key, ['sales', 'orders', 'average'], true)) {
            $pct = OwnerPackData::change((string) $value, (string) $prev);
            if ($pct !== null) {
                $change = ($pct > 0 ? '▲ +' : ($pct < 0 ? '▼ ' : '= ')).number_format($pct, 1).'%';
                $tone = $pct === 0.0 ? 'flat' : ($pct > 0 ? 'good' : 'bad');
            }
        } elseif (in_array($key, ['on_time_rate', 'claims_rate'], true) && $value !== null && $prev !== null) {
            $points = round((float) $value - (float) $prev, 1);
            $change = ($points > 0 ? '▲ +' : ($points < 0 ? '▼ ' : '= ')).number_format($points, 1).' '.__('owner_pack.points', [], $lang);
            $good = self::COLUMNS[$key];
            $tone = $points === 0.0 ? 'flat' : (($points > 0) === $good ? 'good' : 'bad');
        } elseif ($key === 'cash_variance' && ! Money::of((string) $value)->isZero()) {
            $tone = 'bad'; // any difference found when counting cash is worth a look, whichever way it points
        } elseif ($key === 'stock_out' && (int) $value > 0) {
            $tone = 'bad';
        }

        return ['value' => $shown, 'change' => $change, 'tone' => $tone,
            'before' => $withBefore && $prev !== null ? __('owner_pack.was', ['value' => self::cell($key, $before, $before, $lang)['value']], $lang) : null];
    }

    /** One "what needs attention" line in plain words. @param array{key: string, count: int|string, branches?: list<string>} $item */
    public static function sentence(array $item, string $lang): string
    {
        $where = ! empty($item['branches']) ? ' ('.implode(', ', $item['branches']).')' : '';
        $key = 'owner_pack.attention.'.$item['key'];
        $replace = ['percent' => OwnerPackData::SALES_DROP_PERCENT, 'floor' => OwnerPackData::ON_TIME_FLOOR_PERCENT];
        // The cash line is an amount, every other line is a count whose wording depends on the number (one order, two orders...).
        $text = $item['key'] === 'cash_with_collectors'
            ? __($key, ['count' => Money::of((string) $item['count'])->format($lang)], $lang)
            : trans_choice($key, (int) $item['count'], $replace + ['count' => (int) $item['count']], $lang);

        return (string) $text.$where;
    }

    /** The whole pack as plain text: the e-mail's text part and the WhatsApp message. @param array<string, mixed> $p {@see self::present()} */
    public static function text(array $p): string
    {
        $lines = [$p['title'], $p['period'], $p['versus'], ''];
        foreach ($p['headline'] as $h) {
            $lines[] = $h['label'].': '.$h['value'].($h['change'] ? '  '.$h['change'] : '');
        }
        $lines[] = '';
        foreach ($p['rows'] as $row) {
            $parts = [];
            foreach ($p['columns'] as $i => $col) {
                $parts[] = $col['label'].' '.$row['cells'][$i]['value'];
            }
            $lines[] = $row['name'].': '.implode(' | ', $parts);
        }
        $lines[] = $p['assembly']['title'];
        foreach ($p['assembly']['rows'] as $row) {
            $lines[] = $row['name'].': '.implode(' | ', array_map(fn (string $col, string $cell) => $col.' '.$cell, $p['assembly']['columns'], $row['cells']));
        }
        foreach ([['top_units_title', 'top_units'], ['top_revenue_title', 'top_revenue']] as [$title, $list]) {
            $lines[] = '';
            $lines[] = $p[$title];
            foreach ($p[$list] as $item) {
                $lines[] = $item['rank'].'. '.$item['name'].' — '.$item['units'].' / '.$item['revenue'];
            }
            if ($p[$list] === []) {
                $lines[] = $p['top_empty'];
            }
        }
        $lines[] = '';
        $lines[] = $p['attention_title'];
        foreach ($p['attention'] === [] ? [$p['attention_clear']] : $p['attention'] as $a) {
            $lines[] = '- '.$a;
        }
        $lines[] = '';
        $lines[] = $p['footer'];

        return implode("\n", $lines);
    }
}
