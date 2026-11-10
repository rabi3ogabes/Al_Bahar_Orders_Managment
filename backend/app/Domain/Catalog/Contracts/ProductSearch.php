<?php

namespace App\Domain\Catalog\Contracts;

use Illuminate\Database\Eloquent\Builder;

/**
 * Search abstraction so OpenSearch/Meilisearch can replace PostgreSQL later without touching controllers.
 * Implementations constrain the given product query to matches and may apply relevance ordering.
 */
interface ProductSearch
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query, string $term, bool $orderByRelevance = true): Builder;
}
