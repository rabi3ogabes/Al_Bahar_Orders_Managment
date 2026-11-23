<?php

namespace App\Support;

use Illuminate\Contracts\Database\Eloquent\Builder as EloquentContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;

/**
 * A page of a list whose total is COUNTED UP TO A CAP, never exactly (docs/guides/SCALE_PROOF.md). An exact `count(*)` of a table with millions of rows reads
 * all of it on every page view; counting "up to 10,000" reads at most 10,001 rows however big the table grows. The paginator it returns behaves like the
 * normal one (page links, current page); `$list->total()` is the capped number and {@see self::label()} writes it as "10,000+" when the cap was reached.
 * A page beyond the cap is simply empty: people narrow the list with a filter or a search long before that.
 */
final class BoundedPage
{
    /** The most rows a list counts. */
    public const CAP = 10_000;

    /**
     * @param  Builder<*>|QueryBuilder  $query  the filtered, ordered query of the list
     */
    public static function paginate(Builder|QueryBuilder $query, int $perPage, ?int $page = null): LengthAwarePaginator
    {
        $page ??= Paginator::resolveCurrentPage();
        $base = $query instanceof EloquentContract ? $query->toBase() : $query;
        $total = self::count($base);
        $rows = $total === 0 ? $query->limit(0)->get() : $query->forPage($page, $perPage)->get();

        return new LengthAwarePaginator($rows, min($total, self::CAP), $perPage, $page, ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page']);
    }

    /** Rows of the query, counted up to CAP + 1 (so "more than the cap" is known). */
    public static function count(QueryBuilder $query): int
    {
        $limited = (clone $query)->reorder()->select(DB::raw('1'))->limit(self::CAP + 1);

        return (int) DB::query()->fromSub($limited, 'capped')->count();
    }

    /** A count taken with {@see self::count()} as people read it: "128", or "10,000+" past the cap. */
    public static function show(int $counted): string
    {
        return $counted > self::CAP ? number_format(self::CAP).'+' : number_format($counted);
    }

    /** True when the list has more rows than the cap: show its total as "10,000+". */
    public static function isCapped(LengthAwarePaginator $page): bool
    {
        return $page->total() >= self::CAP;
    }

    /** The total as people read it: "128", or "10,000+" at the cap. */
    public static function label(LengthAwarePaginator $page): string
    {
        return number_format($page->total()).(self::isCapped($page) ? '+' : '');
    }
}
