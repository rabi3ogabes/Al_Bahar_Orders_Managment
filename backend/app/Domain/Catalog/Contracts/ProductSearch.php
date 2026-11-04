<?php

namespace App\Domain\Catalog\Contracts;

use Illuminate\Database\Eloquent\Builder;

/**
 * Search abstraction so OpenSearch/Meilisearch can replace PostgreSQL later without touching controllers.
 * Implementations constrain the given product query to matches and may apply relevance ordering.
 */
interface ProductSearch
{
    public function apply(Builder $query, string $term, bool $orderByRelevance = true): Builder;
}
