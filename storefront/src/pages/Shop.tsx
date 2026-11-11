import { useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useInfiniteQuery, useQuery } from '@tanstack/react-query';
import { SlidersHorizontal, X, SearchX } from 'lucide-react';
import { get } from '../lib/api';
import { useBranch } from '../lib/branch';
import { useI18n } from '../lib/i18n';
import { useDocTitle } from '../lib/hooks';
import { cx } from '../lib/format';
import type { Brand, Category, Paged, ProductCard as P } from '../lib/types';
import { CardGrid, CardSkeletons, ProductCard } from '../components/ProductCard';
import { Breadcrumbs, Button, Checkbox, Drawer, EmptyState, ErrorState, Select } from '../components/ui';

const flat = (cs: Category[]): Category[] => cs.flatMap((c) => [c, ...flat(c.children ?? [])]);

export default function Shop() {
  const { t, num } = useI18n();
  const { branchId, branch } = useBranch();
  const [sp, setSp] = useSearchParams();
  const [sheet, setSheet] = useState(false);

  const q = sp.get('q') ?? '';
  const catSlug = sp.get('category') ?? '';
  const brandSlug = sp.get('brand') ?? '';
  const sort = sp.get('sort') ?? (q ? 'relevance' : '-popularity');
  const pmin = sp.get('min') ?? '';
  const pmax = sp.get('max') ?? '';
  const inStock = sp.get('stock') === '1';
  const onSale = sp.get('sale') === '1';
  const isNew = sp.get('new') === '1';

  const cats = useQuery({ queryKey: ['categories'], queryFn: async () => (await get<{ data: Category[] }>('/categories')).data, staleTime: 10 * 60_000 });
  const brands = useQuery({ queryKey: ['brands'], queryFn: async () => (await get<{ data: Brand[] }>('/brands')).data, staleTime: 10 * 60_000 });
  const all = useMemo(() => flat(cats.data ?? []), [cats.data]);
  const cat = all.find((c) => c.slug === catSlug || c.id === catSlug);
  const brand = (brands.data ?? []).find((b) => b.slug === brandSlug || b.id === brandSlug);
  useDocTitle(cat?.name ?? (q ? `“${q}”` : t('nav.shop')));

  // A category/brand slug in the URL must resolve before we query, otherwise we would flash the unfiltered catalogue.
  const waiting = (!!catSlug && cats.isLoading) || (!!brandSlug && brands.isLoading);
  const unknownFilter = (!!catSlug && !cats.isLoading && !cat) || (!!brandSlug && !brands.isLoading && !brand);

  const list = useInfiniteQuery({
    queryKey: ['products', { q, cat: cat?.id, brand: brand?.id, sort, pmin, pmax, inStock, onSale, isNew, branchId }],
    enabled: !waiting && !unknownFilter,
    initialPageParam: 1,
    queryFn: async ({ pageParam }) => get<Paged<P>>('/products', {
      q: q || undefined, category_id: cat?.id, brand_id: brand ? [brand.id] : undefined, sort: sort === 'relevance' && !q ? undefined : sort,
      price_min: pmin || undefined, price_max: pmax || undefined, in_stock: inStock || undefined, on_sale: onSale || undefined, new_arrival: isNew || undefined,
      branch_id: branchId, page: pageParam, per_page: 24,
    }),
    getNextPageParam: (last) => (last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined),
  });
  const items = list.data?.pages.flatMap((p) => p.data) ?? [];
  const total = list.data?.pages[0]?.meta.total ?? 0;

  const set = (patch: Record<string, string | null>) => {
    const n = new URLSearchParams(sp);
    for (const [k, v] of Object.entries(patch)) (v === null || v === '') ? n.delete(k) : n.set(k, v);
    setSp(n, { replace: true });
  };
  const chips: { label: string; clear: () => void }[] = [
    ...(cat ? [{ label: cat.name, clear: () => set({ category: null }) }] : []),
    ...(brand ? [{ label: brand.name, clear: () => set({ brand: null }) }] : []),
    ...(q ? [{ label: `“${q}”`, clear: () => set({ q: null }) }] : []),
    ...(pmin || pmax ? [{ label: `${pmin || '0'} – ${pmax || '∞'} QAR`, clear: () => set({ min: null, max: null }) }] : []),
    ...(inStock ? [{ label: t('filter.in_stock'), clear: () => set({ stock: null }) }] : []),
    ...(onSale ? [{ label: t('filter.on_sale'), clear: () => set({ sale: null }) }] : []),
    ...(isNew ? [{ label: t('filter.new'), clear: () => set({ new: null }) }] : []),
  ];

  const Filters = (
    <div className="space-y-8">
      <div>
        <h3 className="eyebrow mb-3">{t('filter.category')}</h3>
        <ul className="space-y-1">
          <li><button onClick={() => set({ category: null })} className={cx('w-full rounded-xl px-3 py-2 text-start text-sm hover:bg-sand', !cat && 'bg-sand font-semibold')}>{t('filter.all')}</button></li>
          {(cats.data ?? []).map((c) => (
            <li key={c.id}>
              <button onClick={() => set({ category: c.slug })} className={cx('w-full rounded-xl px-3 py-2 text-start text-sm hover:bg-sand', cat?.id === c.id && 'bg-sand font-semibold')}>{c.name}</button>
              {c.children?.length > 0 && (cat?.id === c.id || c.children.some((x) => x.id === cat?.id)) && (
                <ul className="ms-3 border-s border-line ps-2">{c.children.map((x) => <li key={x.id}><button onClick={() => set({ category: x.slug })} className={cx('w-full rounded-lg px-3 py-1.5 text-start text-[13px] hover:bg-sand', cat?.id === x.id && 'font-semibold text-brass-deep')}>{x.name}</button></li>)}</ul>
              )}
            </li>
          ))}
        </ul>
      </div>
      {(brands.data?.length ?? 0) > 0 && (
        <div>
          <h3 className="eyebrow mb-3">{t('filter.brand')}</h3>
          <ul className="flex flex-wrap gap-2">
            {brands.data!.map((b) => <li key={b.id}><button aria-pressed={brand?.id === b.id} onClick={() => set({ brand: brand?.id === b.id ? null : b.slug })} className={cx('rounded-full border px-3.5 py-1.5 text-[13px] transition-colors', brand?.id === b.id ? 'border-primary bg-primary text-bg' : 'border-line bg-paper hover:border-brass')}>{b.name}</button></li>)}
          </ul>
        </div>
      )}
      <div>
        <h3 className="eyebrow mb-3">{t('filter.price')} (QAR)</h3>
        <form className="flex items-center gap-2" onSubmit={(e) => { e.preventDefault(); const f = new FormData(e.currentTarget); set({ min: String(f.get('min') || ''), max: String(f.get('max') || '') }); }}>
          <input name="min" type="number" min={0} inputMode="numeric" defaultValue={pmin} placeholder={t('filter.min')} aria-label={t('filter.min')} className="h-11 w-full rounded-xl border border-line bg-paper px-3 text-sm" />
          <span className="text-muted">–</span>
          <input name="max" type="number" min={0} inputMode="numeric" defaultValue={pmax} placeholder={t('filter.max')} aria-label={t('filter.max')} className="h-11 w-full rounded-xl border border-line bg-paper px-3 text-sm" />
          <Button size="sm" variant="outline" type="submit" className="shrink-0">{t('action.apply')}</Button>
        </form>
      </div>
      <div className="space-y-3">
        <Checkbox checked={inStock} onChange={(v) => set({ stock: v ? '1' : null })}>{t('filter.in_stock')}</Checkbox>
        <Checkbox checked={onSale} onChange={(v) => set({ sale: v ? '1' : null })}>{t('filter.on_sale')}</Checkbox>
        <Checkbox checked={isNew} onChange={(v) => set({ new: v ? '1' : null })}>{t('filter.new')}</Checkbox>
      </div>
    </div>
  );

  return (
    <div className="wrap pb-6 pt-6 md:pt-10">
      <Breadcrumbs items={[{ to: '/', label: t('nav.home') }, { to: cat ? '/shop' : undefined, label: t('nav.shop') }, ...(cat ? [{ label: cat.name }] : [])]} />
      <div className="mb-8 mt-4 flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-[clamp(38px,5vw,60px)] leading-none">{cat?.name ?? (q ? t('shop.results_for', { q }) : t('shop.title'))}</h1>
          {cat?.description && <p className="mt-3 max-w-2xl text-ink-soft">{cat.description}</p>}
          {branch && <p className="mt-2 text-[13px] text-muted">{t('shop.showing_for', { branch: branch.name })}</p>}
        </div>
      </div>

      {cat && cat.children?.length > 0 && (
        <div className="mb-6 flex flex-wrap gap-2">{cat.children.map((c) => <Link key={c.id} to={`/shop?category=${c.slug}`} className="rounded-full border border-line bg-paper px-4 py-2 text-sm hover:border-brass">{c.name}</Link>)}</div>
      )}

      <div className="grid gap-10 lg:grid-cols-[250px_minmax(0,1fr)]">
        <aside className="hidden lg:block" aria-label={t('filter.title')}><div className="sticky top-32">{Filters}</div></aside>
        <section aria-live="polite">
          <div className="mb-6 flex flex-wrap items-center justify-between gap-3 border-b border-line pb-4">
            <div className="flex items-center gap-3">
              <Button variant="outline" size="sm" className="lg:hidden" onClick={() => setSheet(true)}><SlidersHorizontal size={15} />{t('filter.title')}{chips.length > 0 && ` (${chips.length})`}</Button>
              <p className="text-sm text-muted">{list.isLoading ? '…' : t('shop.count', { n: num(total) })}</p>
            </div>
            <div className="w-52"><Select label={t('sort.title')} value={sort} onChange={(e) => set({ sort: e.target.value })} className="[&_label]:sr-only [&_select]:h-10 [&_select]:text-sm">
              {q && <option value="relevance">{t('sort.relevance')}</option>}
              <option value="-popularity">{t('sort.popular')}</option><option value="-created_at">{t('sort.newest')}</option>
              <option value="price">{t('sort.price_asc')}</option><option value="-price">{t('sort.price_desc')}</option><option value="-rating">{t('sort.rating')}</option>
            </Select></div>
          </div>

          {chips.length > 0 && (
            <div className="mb-6 flex flex-wrap items-center gap-2">
              {chips.map((c, i) => <button key={i} onClick={c.clear} className="flex items-center gap-1.5 rounded-full bg-sand px-3.5 py-1.5 text-[13px] hover:bg-sand-deep">{c.label}<X size={13} /></button>)}
              <button onClick={() => setSp(new URLSearchParams(), { replace: true })} className="text-[13px] font-medium text-brass-deep underline-offset-2 hover:underline">{t('filter.clear')}</button>
            </div>
          )}

          {(list.isLoading || waiting) && <CardSkeletons n={9} />}
          {list.isError && <ErrorState onRetry={() => list.refetch()} />}
          {(unknownFilter || (list.isSuccess && items.length === 0)) && (
            <EmptyState icon={<SearchX size={28} />} title={t('shop.empty')} text={t('shop.empty_text')} action={<Button variant="outline" onClick={() => setSp(new URLSearchParams(), { replace: true })}>{t('filter.clear')}</Button>} />
          )}
          {items.length > 0 && (
            <>
              <CardGrid cols={3}>{items.map((p, i) => <ProductCard key={p.id} p={p} priority={i < 3} />)}</CardGrid>
              <div className="mt-14 flex flex-col items-center gap-3">
                <p className="text-sm text-muted">{t('shop.shown', { a: num(items.length), b: num(total) })}</p>
                <div className="h-1 w-48 overflow-hidden rounded-full bg-sand"><div className="h-full rounded-full bg-brass" style={{ width: `${Math.min(100, (items.length / Math.max(1, total)) * 100)}%` }} /></div>
                {list.hasNextPage && <Button variant="outline" size="lg" loading={list.isFetchingNextPage} onClick={() => list.fetchNextPage()}>{t('action.load_more')}</Button>}
              </div>
            </>
          )}
        </section>
      </div>

      <Drawer open={sheet} onClose={() => setSheet(false)} title={t('filter.title')} side="start" footer={<Button size="lg" block onClick={() => setSheet(false)}>{t('filter.show', { n: num(total) })}</Button>}>
        <div className="p-6">{Filters}</div>
      </Drawer>
    </div>
  );
}
