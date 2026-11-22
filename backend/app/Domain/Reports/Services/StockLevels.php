<?php

namespace App\Domain\Reports\Services;

use App\Domain\Settings\Services\Settings;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What "low stock" and "out of stock" mean, for the dashboard tile and the stock report (one definition, so they cannot disagree).
 *
 *  - Available = on hand minus reserved.
 *  - Out of stock: nothing is available AND the item has been in stock at that branch before (it was counted in, received, sold...). A variant a
 *    branch never held is not "out of stock"; it is simply not stocked there (Postgres rows exist for it because a checkout once looked).
 *  - Low stock: something is left, but no more than the item's own minimum, or, when nobody set a minimum, the shop-wide default
 *    (setting inventory.low_stock_default, 3 until the owner changes it).
 */
final class StockLevels
{
    public const DEFAULT_THRESHOLD = 3;

    public static function defaultThreshold(): int
    {
        return max(1, (int) app(Settings::class)->get('inventory.low_stock_default', self::DEFAULT_THRESHOLD));
    }

    /** "Has ever held stock": on hand now, or any movement that changed the count. Index-backed through stock_movements (branch, variant, created_at). */
    public static function hadStockSql(string $alias = 'bi'): string
    {
        return "({$alias}.on_hand > 0 or exists (select 1 from stock_movements sm where sm.branch_id = {$alias}.branch_id
            and sm.product_variant_id = {$alias}.product_variant_id and sm.quantity <> 0))";
    }

    /** @return array{0: string, 1: list<int>} SQL and bindings */
    public static function lowSql(string $alias = 'bi', ?int $threshold = null): array
    {
        return ["({$alias}.on_hand - {$alias}.reserved) > 0 and ({$alias}.on_hand - {$alias}.reserved) <= coalesce(nullif({$alias}.minimum_stock, 0), ?)", [$threshold ?? self::defaultThreshold()]];
    }

    public static function outSql(string $alias = 'bi'): string
    {
        return "({$alias}.on_hand - {$alias}.reserved) <= 0 and ".self::hadStockSql($alias);
    }

    /**
     * The same two counts branch by branch, in one query.
     *
     * @param  list<string>  $branchIds
     * @return array<string, array{low: int, out: int}> branch id => counts (a branch with no stock rows has no entry)
     */
    public static function countsByBranch(array $branchIds): array
    {
        [$low, $bindings] = self::lowSql();
        $rows = DB::table('branch_inventory as bi')->whereIn('bi.branch_id', $branchIds)->groupBy('bi.branch_id')
            ->selectRaw("bi.branch_id, count(*) filter (where {$low}) as low, count(*) filter (where ".self::outSql().') as out', $bindings)->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->branch_id] = ['low' => (int) $r->low, 'out' => (int) $r->out];
        }

        return $out;
    }

    /** @return array{low: int, out: int} counts over a branch_inventory query (alias bi) that is already limited to the viewer's branches */
    public static function counts(Builder $scoped): array
    {
        [$low, $bindings] = self::lowSql();
        $row = (clone $scoped)->selectRaw("count(*) filter (where {$low}) as low, count(*) filter (where ".self::outSql().') as out', $bindings)->first();

        return ['low' => (int) ($row->low ?? 0), 'out' => (int) ($row->out ?? 0)];
    }
}
