import type { AttributeFilterGroup, Brand, Category } from './types';

/**
 * The shop's filters live in the URL (`?category=&brand=&min=&max=&stock=&sale=&new=&attr=&q=&sort=`). These helpers are shared by the page and the filter window.
 * `attr` holds the chosen colour / material / size values (the API's attribute value ids) separated by commas: `attr=12,15,31`.
 */
export const FILTER_KEYS = ['category', 'brand', 'min', 'max', 'stock', 'sale', 'new', 'attr'] as const;
const MAX_ATTRS = 30;

/** The attribute value ids in a URL: whole numbers only, each once. Anything else in the address is ignored, never sent to the server. */
export const attrIds = (sp: URLSearchParams): string[] => [...new Set((sp.get('attr') ?? '').split(',').map((x) => x.trim()).filter((x) => /^\d{1,10}$/.test(x)))].slice(0, MAX_ATTRS);

/** The URL change that selects or un-selects one attribute value (every other chosen value stays). */
export function toggleAttr(current: string[], id: string): Record<string, string | null> {
  const next = current.includes(id) ? current.filter((x) => x !== id) : [...current, id];
  return { attr: next.length ? next.join(',') : null };
}

/** One list of attribute filters from several categories' lists: the same attribute (by code) once, its values joined without repeats, in the order first seen. */
export function mergeAttributeFilters(lists: AttributeFilterGroup[][]): AttributeFilterGroup[] {
  const byCode = new Map<string, AttributeFilterGroup>();
  for (const list of lists) {
    for (const g of list) {
      const have = byCode.get(g.attribute.code);
      if (!have) { byCode.set(g.attribute.code, { attribute: g.attribute, values: [...g.values] }); continue; }
      for (const v of g.values) if (!have.values.some((x) => x.id === v.id)) have.values.push(v);
    }
  }
  return [...byCode.values()].filter((g) => g.values.length > 0);
}

export const flatCategories = (cs: Category[]): Category[] => cs.flatMap((c) => [c, ...flatCategories(c.children ?? [])]);

export interface ShopFilters { cat?: Category; brand?: Brand; q: string; sort: string; min: string; max: string; inStock: boolean; onSale: boolean; isNew: boolean; attrs: string[] }

export function readFilters(sp: URLSearchParams, cats: Category[], brands: Brand[]): ShopFilters {
  const catSlug = sp.get('category') ?? '', brandSlug = sp.get('brand') ?? '', q = sp.get('q') ?? '';
  return {
    cat: flatCategories(cats).find((c) => c.slug === catSlug || c.id === catSlug), brand: brands.find((b) => b.slug === brandSlug || b.id === brandSlug),
    q, sort: sp.get('sort') ?? (q ? 'relevance' : '-popularity'), min: sp.get('min') ?? '', max: sp.get('max') ?? '',
    inStock: sp.get('stock') === '1', onSale: sp.get('sale') === '1', isNew: sp.get('new') === '1', attrs: attrIds(sp),
  };
}

/** Query-string parameters for `GET /products`. */
export function productParams(f: ShopFilters, branchId: string | null | undefined, page: number, perPage: number) {
  return {
    q: f.q || undefined, category_id: f.cat?.id, brand_id: f.brand ? [f.brand.id] : undefined, sort: f.sort === 'relevance' && !f.q ? undefined : f.sort,
    price_min: f.min || undefined, price_max: f.max || undefined, in_stock: f.inStock || undefined, on_sale: f.onSale || undefined, new_arrival: f.isNew || undefined,
    attribute_values: f.attrs.length ? f.attrs : undefined,
    branch_id: branchId ?? undefined, page, per_page: perPage,
  };
}

/** How many filters are on: each chosen value counts once, and so does the price range as a whole. */
export const activeFilterCount = (sp: URLSearchParams) => FILTER_KEYS.filter((k) => (k === 'min' || k === 'max' || k === 'attr' ? false : sp.has(k))).length + (sp.has('min') || sp.has('max') ? 1 : 0) + attrIds(sp).length;

/** Apply `patch` to a copy of `sp` (null or '' removes the key). */
export function patched(sp: URLSearchParams, patch: Record<string, string | null>): URLSearchParams {
  const n = new URLSearchParams(sp);
  for (const [k, v] of Object.entries(patch)) (v === null || v === '') ? n.delete(k) : n.set(k, v);
  return n;
}
