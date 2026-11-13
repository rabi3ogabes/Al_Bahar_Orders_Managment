import type { Brand, Category } from './types';

/** The shop's filters live in the URL (`?category=&brand=&min=&max=&stock=&sale=&new=&q=&sort=`). These helpers are shared by the page and the filter window. */
export const FILTER_KEYS = ['category', 'brand', 'min', 'max', 'stock', 'sale', 'new'] as const;

export const flatCategories = (cs: Category[]): Category[] => cs.flatMap((c) => [c, ...flatCategories(c.children ?? [])]);

export interface ShopFilters { cat?: Category; brand?: Brand; q: string; sort: string; min: string; max: string; inStock: boolean; onSale: boolean; isNew: boolean }

export function readFilters(sp: URLSearchParams, cats: Category[], brands: Brand[]): ShopFilters {
  const catSlug = sp.get('category') ?? '', brandSlug = sp.get('brand') ?? '', q = sp.get('q') ?? '';
  return {
    cat: flatCategories(cats).find((c) => c.slug === catSlug || c.id === catSlug), brand: brands.find((b) => b.slug === brandSlug || b.id === brandSlug),
    q, sort: sp.get('sort') ?? (q ? 'relevance' : '-popularity'), min: sp.get('min') ?? '', max: sp.get('max') ?? '',
    inStock: sp.get('stock') === '1', onSale: sp.get('sale') === '1', isNew: sp.get('new') === '1',
  };
}

/** Query-string parameters for `GET /products`. */
export function productParams(f: ShopFilters, branchId: string | null | undefined, page: number, perPage: number) {
  return {
    q: f.q || undefined, category_id: f.cat?.id, brand_id: f.brand ? [f.brand.id] : undefined, sort: f.sort === 'relevance' && !f.q ? undefined : f.sort,
    price_min: f.min || undefined, price_max: f.max || undefined, in_stock: f.inStock || undefined, on_sale: f.onSale || undefined, new_arrival: f.isNew || undefined,
    branch_id: branchId ?? undefined, page, per_page: perPage,
  };
}

export const activeFilterCount = (sp: URLSearchParams) => FILTER_KEYS.filter((k) => (k === 'min' || k === 'max' ? false : sp.has(k))).length + (sp.has('min') || sp.has('max') ? 1 : 0);

/** Apply `patch` to a copy of `sp` (null or '' removes the key). */
export function patched(sp: URLSearchParams, patch: Record<string, string | null>): URLSearchParams {
  const n = new URLSearchParams(sp);
  for (const [k, v] of Object.entries(patch)) (v === null || v === '') ? n.delete(k) : n.set(k, v);
  return n;
}
