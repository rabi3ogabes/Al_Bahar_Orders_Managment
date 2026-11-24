import { useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useInfiniteQuery, useQuery } from '@tanstack/react-query';
import { SlidersHorizontal, X, SearchX } from 'lucide-react';
import { get } from '../lib/api';
import { useBranch } from '../lib/branch';
import { useI18n } from '../lib/i18n';
import { useDocTitle } from '../lib/hooks';
import type { Brand, Category, Paged, ProductCard as P } from '../lib/types';
import { CardGrid, CardSkeletons, ProductCard } from '../components/ProductCard';
import { Breadcrumbs, Button, EmptyState, ErrorState, Select } from '../components/ui';
import { FilterPanel } from '../components/ShopFilters';
import { FilterWindow } from '../components/windows';
import { patched, productParams, readFilters, toggleAttr } from '../lib/shopQuery';
import { useAttributeFilters } from '../lib/useAttributeFilters';
import { useRooms } from '../lib/rooms';
import { findRoom } from '../lib/roomsCore';

export default function Shop() {
  const { t, num } = useI18n();
  const { branchId, branch } = useBranch();
  const [sp, setSp] = useSearchParams();
  const [sheet, setSheet] = useState(false);

  const catSlug = sp.get('category') ?? '';
  const brandSlug = sp.get('brand') ?? '';

  const cats = useQuery({ queryKey: ['categories'], queryFn: async () => (await get<{ data: Category[] }>('/categories')).data, staleTime: 10 * 60_000 });
  const brands = useQuery({ queryKey: ['brands'], queryFn: async () => (await get<{ data: Brand[] }>('/brands')).data, staleTime: 10 * 60_000 });
  const f = useMemo(() => readFilters(sp, cats.data ?? [], brands.data ?? []), [sp, cats.data, brands.data]);
  const { cat, brand, q, sort, min: pmin, max: pmax, inStock, onSale, isNew, attrs } = f;
  // "Shop by room": the room in the URL (its name for the heading and the chip; an unknown or empty room simply has no products).
  const rooms = useRooms();
  const room = findRoom(rooms.data, f.room);
  const roomLabel = room?.name ?? (f.room && !rooms.isLoading ? f.room : '');
  const attrGroups = useAttributeFilters(cat, cats.data ?? []);
  useDocTitle(cat?.name ?? room?.name ?? (q ? `“${q}”` : t('nav.shop')));

  // A category/brand slug in the URL must resolve before we query, otherwise we would flash the unfiltered catalogue.
  const waiting = (!!catSlug && cats.isLoading) || (!!brandSlug && brands.isLoading);
  const unknownFilter = (!!catSlug && !cats.isLoading && !cat) || (!!brandSlug && !brands.isLoading && !brand);

  const list = useInfiniteQuery({
    queryKey: ['products', { q, cat: cat?.id, room: f.room, brand: brand?.id, sort, pmin, pmax, inStock, onSale, isNew, attrs, branchId }],
    enabled: !waiting && !unknownFilter,
    initialPageParam: 1,
    queryFn: async ({ pageParam }) => get<Paged<P>>('/products', productParams(f, branchId, pageParam, 24)),
    getNextPageParam: (last) => (last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined),
  });
  const items = list.data?.pages.flatMap((p) => p.data) ?? [];
  const total = list.data?.pages[0]?.meta.total ?? 0;

  const set = (patch: Record<string, string | null>) => setSp(patched(sp, patch), { replace: true });
  const chips: { label: string; clear: () => void }[] = [
    ...(cat ? [{ label: cat.name, clear: () => set({ category: null, attr: null }) }] : []),
    ...(f.room && roomLabel ? [{ label: roomLabel, clear: () => set({ room: null }) }] : []),
    ...(brand ? [{ label: brand.name, clear: () => set({ brand: null }) }] : []),
    ...(q ? [{ label: `“${q}”`, clear: () => set({ q: null }) }] : []),
    ...(pmin || pmax ? [{ label: `${pmin || '0'} – ${pmax || '∞'} QAR`, clear: () => set({ min: null, max: null }) }] : []),
    ...(inStock ? [{ label: t('filter.in_stock'), clear: () => set({ stock: null }) }] : []),
    ...(onSale ? [{ label: t('filter.on_sale'), clear: () => set({ sale: null }) }] : []),
    ...(isNew ? [{ label: t('filter.new'), clear: () => set({ new: null }) }] : []),
    // Each chosen colour / material / size is its own chip ("Colour: Grey") that takes just that choice off.
    ...attrs.map((id) => {
      const g = attrGroups.find((x) => x.values.some((v) => String(v.id) === id));
      const v = g?.values.find((x) => String(x.id) === id);
      return { label: g && v ? `${g.attribute.name}: ${v.name}` : t('filter.attr_other'), clear: () => set(toggleAttr(attrs, id)) };
    }),
  ];

  return (
    <div className="wrap pb-6 pt-6 md:pt-10">
      <Breadcrumbs items={[{ to: '/', label: t('nav.home') }, { to: cat || room ? '/shop' : undefined, label: t('nav.shop') }, ...(cat ? [{ label: cat.name }] : room ? [{ label: room.name }] : [])]} />
      <div className="mb-8 mt-4 flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-[clamp(38px,5vw,60px)] leading-none">{cat?.name ?? room?.name ?? (q ? t('shop.results_for', { q }) : t('shop.title'))}</h1>
          {(cat?.description ?? (cat ? null : room?.description)) && <p className="mt-3 max-w-2xl text-ink-soft">{cat?.description ?? room?.description}</p>}
          {branch && <p className="mt-2 text-[13px] text-muted">{t('shop.showing_for', { branch: branch.name })}</p>}
        </div>
      </div>

      {cat && cat.children?.length > 0 && (
        <div className="mb-6 flex flex-wrap gap-2">{cat.children.map((c) => <Link key={c.id} to={`/shop?category=${c.slug}`} className="rounded-full border border-line bg-paper px-4 py-2 text-sm hover:border-brass">{c.name}</Link>)}</div>
      )}

      <div className="grid gap-10 lg:grid-cols-[250px_minmax(0,1fr)]">
        <aside className="hidden lg:block" aria-label={t('filter.title')}><div className="sticky top-32"><h2 className="sr-only">{t('filter.title')}</h2><FilterPanel cats={cats.data ?? []} brands={brands.data ?? []} f={f} set={set} /></div></aside>
        <section aria-live="polite">
          <h2 className="sr-only">{t('shop.products')}</h2>
          <div className="mb-6 flex flex-wrap items-center justify-between gap-3 border-b border-line pb-4">
            <div className="flex items-center gap-3">
              <Button variant="outline" size="sm" className="lg:hidden" onClick={() => setSheet(true)}><SlidersHorizontal size={15} />{t('filter.title')}{chips.length > 0 && ` (${chips.length})`}</Button>
              <p className="text-sm text-muted">{list.isLoading ? '…' : total === 1 ? t('shop.count_one') : t('shop.count', { n: num(total) })}</p>
            </div>
            <div className="w-52"><Select label={t('sort.title')} value={sort} onChange={(e) => set({ sort: e.target.value })} className="[&_label]:sr-only [&_select]:h-11 [&_select]:text-sm">
              {q && <option value="relevance">{t('sort.relevance')}</option>}
              <option value="-popularity">{t('sort.popular')}</option><option value="-created_at">{t('sort.newest')}</option>
              <option value="price">{t('sort.price_asc')}</option><option value="-price">{t('sort.price_desc')}</option><option value="-rating">{t('sort.rating')}</option>
            </Select></div>
          </div>

          {chips.length > 0 && (
            <div className="mb-6 flex flex-wrap items-center gap-2">
              {chips.map((c, i) => <button key={i} onClick={c.clear} className="flex min-h-11 items-center gap-1.5 rounded-full bg-sand px-3.5 py-1.5 text-[13px] hover:bg-sand-deep">{c.label}<X size={13} /></button>)}
              <button onClick={() => setSp(new URLSearchParams(), { replace: true })} className="inline-flex min-h-11 items-center text-[13px] font-medium text-brass-deep underline-offset-2 hover:underline">{t('filter.clear')}</button>
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

      <FilterWindow open={sheet} onClose={() => setSheet(false)} sp={sp} cats={cats.data ?? []} brands={brands.data ?? []} onApply={(next) => setSp(next, { replace: true })} />
    </div>
  );
}
