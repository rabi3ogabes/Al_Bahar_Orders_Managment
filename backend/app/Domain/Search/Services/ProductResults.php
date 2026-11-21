<?php

namespace App\Domain\Search\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Search\Support\SearchGroup;
use App\Domain\Search\Support\SearchQuery;
use Illuminate\Support\Facades\DB;

/**
 * Products and their variants for the global search, for staff who may open the catalogue: by name (English or Arabic, Arabic spellings
 * folded the same way the catalogue search does), by SKU and by barcode, of the product and of each of its variants.
 *
 * ONE statement: five short lookups (name, product SKU, product barcode, variant SKU, variant barcode), each cut at 30 rows and each served by
 * an index (the trigram index of product_translations.search_text, the pattern-ops indexes of lower(sku) and lower(barcode)), merged
 * so a product that matched in several ways appears once with its best match, then joined to the few rows that are shown. The catalogue is
 * the same for every branch, so there is no branch rule here, as in the Products list.
 */
final class ProductResults
{
    /** A match on a code ranks above a match on a name. */
    private const CODE = 1;

    private const NAME = 2;

    /** Rows each lookup may bring; plenty to merge, never a scan. */
    private const LEG = 30;

    public function __construct(private readonly AccessControl $acl) {}

    public function find(User $user, SearchQuery $query): ?SearchGroup
    {
        if (! $this->acl->allows($user, 'catalog.view') || ! $query->searchesTables()) {
            return null;
        }
        $legs = [];
        $bindings = [];
        $code = SearchQuery::startsWith(mb_strtolower(str_replace(' ', '', $query->raw)));
        if ($query->text !== '' && mb_strlen($query->text) >= SearchQuery::MIN_TABLE) {
            $legs[] = '(select t.product_id, '.self::NAME.' as rank, null::uuid as variant_id from product_translations t where t.search_text like ? limit '.self::LEG.')';
            $bindings[] = SearchQuery::contains($query->text);
        }
        foreach (['sku', 'barcode'] as $column) {
            $legs[] = '(select p.id, '.self::CODE.' as rank, null::uuid as variant_id from products p where lower(p.'.$column.') like ? limit '.self::LEG.')';
            $legs[] = '(select v.product_id, '.self::CODE.' as rank, v.id as variant_id from product_variants v where lower(v.'.$column.') like ? limit '.self::LEG.')';
            array_push($bindings, $code, $code);
        }
        $locale = app()->getLocale() === 'ar' ? 'ar' : 'en';
        $limit = SearchGroup::LIMIT + 1;

        $rows = DB::select(
            'with m as (
                select u.product_id, min(u.rank) as rank, (array_agg(u.variant_id) filter (where u.variant_id is not null))[1] as variant_id
                from ('.implode(' union all ', $legs).') u group by u.product_id order by min(u.rank), u.product_id limit '.$limit.'
             )
             select p.id, p.sku, p.status, v.sku as variant_sku, v.name_en as variant_name_en, v.name_ar as variant_name_ar,
                    coalesce(tl.name, te.name, p.sku) as name
             from m
             join products p on p.id = m.product_id
             left join product_variants v on v.id = m.variant_id
             left join product_translations tl on tl.product_id = p.id and tl.locale = ?
             left join product_translations te on te.product_id = p.id and te.locale = \'en\'
             order by m.rank, p.sku, p.id',
            [...$bindings, $locale],
        );
        if ($rows === []) {
            return null;
        }

        $items = array_map(function (\stdClass $r) use ($locale): array {
            $variant = $r->variant_sku === null ? null : trim((string) ($locale === 'ar' ? ($r->variant_name_ar ?: $r->variant_name_en) : ($r->variant_name_en ?: $r->variant_name_ar)));

            return [
                'id' => (string) $r->id,
                'title' => (string) $r->name,
                'subtitle' => trim(($r->variant_sku ?? $r->sku).($variant !== null && $variant !== '' ? ' · '.$variant : '')),
                'badge' => $r->status === 'active' ? null : __('status.'.$r->status),
                'meta' => null,
                'url' => route('admin.products.index', ['view' => $r->id]),
                'icon' => 'sofa',
                'ltr' => false,
            ];
        }, array_slice($rows, 0, SearchGroup::LIMIT));

        return new SearchGroup('products', __('search.group.products'), $items, count($rows) > SearchGroup::LIMIT
            ? ['label' => __('search.see_all.products'), 'url' => route('admin.products.index', ['search' => $query->raw])] : null);
    }
}
