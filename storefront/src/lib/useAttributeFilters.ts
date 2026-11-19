import { useQueries } from '@tanstack/react-query';
import { get } from './api';
import { mergeAttributeFilters } from './shopQuery';
import type { AttributeFilterGroup, Category } from './types';

/** How many top-level categories are asked when no category is chosen (a search across the shop): the attributes of the shop's main sections. */
const SEARCH_SECTIONS = 6;

/**
 * The colour / material / size choices to offer. With a category chosen: that category's own list (`GET /categories/{id}` → `filters`, the same
 * list the app shows). With none (a search across the whole shop): the lists of the main sections joined, so a shopper searching "sofa" can still pick a colour.
 */
export function useAttributeFilters(cat: Category | undefined, categories: Category[]): AttributeFilterGroup[] {
  const ids = cat ? [cat.id] : categories.slice(0, SEARCH_SECTIONS).map((c) => c.id);
  return useQueries({
    queries: ids.map((id) => ({
      queryKey: ['category-filters', id], staleTime: 10 * 60_000, retry: 1,
      queryFn: async () => (await get<{ data: { filters?: AttributeFilterGroup[] } }>(`/categories/${id}`)).data.filters ?? [],
    })),
    combine: (results) => mergeAttributeFilters(results.map((r) => r.data ?? [])),
  });
}
