import { useEffect, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { get } from '../lib/api';
import { useBranch } from '../lib/branch';
import { useI18n } from '../lib/i18n';
import { FILTER_KEYS, activeFilterCount, patched, productParams, readFilters } from '../lib/shopQuery';
import type { Brand, Category, Paged, ProductCard } from '../lib/types';
import { FilterPanel } from './ShopFilters';
import { SideDrawer } from './SideDrawer';
import { Button, Select } from './ui';

/**
 * Shop filters on phones and tablets. Choices are a draft: the number on the pinned button follows every tap
 * ("Show 24 pieces"), and only that button (or Reset) changes the page behind. Closing discards the draft.
 */
export default function FilterDrawer({ open, onClose, sp, cats, brands, onApply }: { open: boolean; onClose: () => void; sp: URLSearchParams; cats: Category[]; brands: Brand[]; onApply: (next: URLSearchParams) => void }) {
  const { t, num } = useI18n();
  const { branchId } = useBranch();
  const [draft, setDraft] = useState(sp);
  useEffect(() => { if (open) setDraft(new URLSearchParams(sp)); }, [open]); // eslint-disable-line react-hooks/exhaustive-deps
  const f = useMemo(() => readFilters(draft, cats, brands), [draft, cats, brands]);
  const key = draft.toString();
  const count = useQuery({
    queryKey: ['products-count', key, branchId], enabled: open, placeholderData: (p) => p, staleTime: 15_000,
    queryFn: async () => (await get<Paged<ProductCard>>('/products', productParams(f, branchId, 1, 1))).meta.total,
  });
  const set = (patch: Record<string, string | null>) => setDraft((d) => patched(d, patch));
  const reset = () => setDraft((d) => patched(d, Object.fromEntries(FILTER_KEYS.map((k) => [k, null]))));
  const n = activeFilterCount(draft);
  return (
    <SideDrawer open={open} onClose={onClose} title={t('filter.title')} size="sm"
      headerAction={n > 0 ? <button type="button" onClick={reset} className="flex h-11 items-center rounded-full px-3 text-sm font-medium text-brass-deep hover:underline">{t('filter.clear')}</button> : undefined}
      footer={<Button size="lg" block disabled={count.data === 0} onClick={() => { onApply(draft); onClose(); }}>{count.data === undefined ? t('filter.show_any') : count.data === 0 ? t('filter.none') : t('filter.show', { n: num(count.data) })}</Button>}>
      <div className="space-y-8 p-6">
        <Select label={t('sort.title')} value={f.sort} onChange={(e) => set({ sort: e.target.value })}>
          {f.q && <option value="relevance">{t('sort.relevance')}</option>}
          <option value="-popularity">{t('sort.popular')}</option><option value="-created_at">{t('sort.newest')}</option>
          <option value="price">{t('sort.price_asc')}</option><option value="-price">{t('sort.price_desc')}</option><option value="-rating">{t('sort.rating')}</option>
        </Select>
        <FilterPanel cats={cats} brands={brands} f={f} set={set} />
      </div>
    </SideDrawer>
  );
}
