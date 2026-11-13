import { useI18n } from '../lib/i18n';
import { cx } from '../lib/format';
import type { ShopFilters } from '../lib/shopQuery';
import type { Brand, Category } from '../lib/types';
import { Button, Checkbox } from './ui';

/** The filter controls: category tree, brands, price range, availability. `set` writes the URL (page sidebar) or a draft (filter window). */
export function FilterPanel({ cats, brands, f, set }: { cats: Category[]; brands: Brand[]; f: ShopFilters; set: (patch: Record<string, string | null>) => void }) {
  const { t } = useI18n();
  const { cat, brand } = f;
  return (
    <div className="space-y-8">
      <div>
        <h3 className="eyebrow mb-3">{t('filter.category')}</h3>
        <ul className="space-y-1">
          <li><button onClick={() => set({ category: null })} aria-pressed={!cat} className={cx('min-h-11 w-full rounded-xl px-3 py-2 text-start text-sm hover:bg-sand', !cat && 'bg-sand font-semibold')}>{t('filter.all')}</button></li>
          {cats.map((c) => (
            <li key={c.id}>
              <button onClick={() => set({ category: c.slug })} aria-pressed={cat?.id === c.id} className={cx('min-h-11 w-full rounded-xl px-3 py-2 text-start text-sm hover:bg-sand', cat?.id === c.id && 'bg-sand font-semibold')}>{c.name}</button>
              {c.children?.length > 0 && (cat?.id === c.id || c.children.some((x) => x.id === cat?.id)) && (
                <ul className="ms-3 border-s border-line ps-2">{c.children.map((x) => <li key={x.id}><button onClick={() => set({ category: x.slug })} aria-pressed={cat?.id === x.id} className={cx('min-h-11 w-full rounded-lg px-3 py-1.5 text-start text-[13px] hover:bg-sand', cat?.id === x.id && 'font-semibold text-brass-deep')}>{x.name}</button></li>)}</ul>
              )}
            </li>
          ))}
        </ul>
      </div>
      {brands.length > 0 && (
        <div>
          <h3 className="eyebrow mb-3">{t('filter.brand')}</h3>
          <ul className="flex flex-wrap gap-2">
            {brands.map((b) => <li key={b.id}><button aria-pressed={brand?.id === b.id} onClick={() => set({ brand: brand?.id === b.id ? null : b.slug })} className={cx('min-h-11 rounded-full border px-3.5 py-1.5 text-[13px] transition-colors', brand?.id === b.id ? 'border-primary bg-primary text-bg' : 'border-line bg-paper hover:border-brass')}>{b.name}</button></li>)}
          </ul>
        </div>
      )}
      <div>
        <h3 className="eyebrow mb-3">{t('filter.price')} (QAR)</h3>
        <form key={`${f.min}-${f.max}`} className="flex items-center gap-2" onSubmit={(e) => { e.preventDefault(); const d = new FormData(e.currentTarget); let lo = String(d.get('min') || '').trim(), hi = String(d.get('max') || '').trim(); if (lo && hi && Number(lo) > Number(hi)) [lo, hi] = [hi, lo]; set({ min: lo, max: hi }); }}>
          <input name="min" type="number" min={0} inputMode="numeric" defaultValue={f.min} placeholder={t('filter.min')} aria-label={t('filter.min')} className="h-11 w-full rounded-xl border border-line bg-paper px-3 text-sm" />
          <span className="text-muted">–</span>
          <input name="max" type="number" min={0} inputMode="numeric" defaultValue={f.max} placeholder={t('filter.max')} aria-label={t('filter.max')} className="h-11 w-full rounded-xl border border-line bg-paper px-3 text-sm" />
          <Button size="sm" variant="outline" type="submit" className="shrink-0">{t('action.apply')}</Button>
        </form>
      </div>
      <div className="space-y-3">
        <Checkbox checked={f.inStock} onChange={(v) => set({ stock: v ? '1' : null })}>{t('filter.in_stock')}</Checkbox>
        <Checkbox checked={f.onSale} onChange={(v) => set({ sale: v ? '1' : null })}>{t('filter.on_sale')}</Checkbox>
        <Checkbox checked={f.isNew} onChange={(v) => set({ new: v ? '1' : null })}>{t('filter.new')}</Checkbox>
      </div>
    </div>
  );
}
