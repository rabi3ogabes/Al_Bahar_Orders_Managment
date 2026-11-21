import { useI18n } from '../lib/i18n';
import { cx } from '../lib/format';
import { toggleAttr, type ShopFilters } from '../lib/shopQuery';
import { useAttributeFilters } from '../lib/useAttributeFilters';
import { useRooms } from '../lib/rooms';
import type { AttributeFilterGroup, Brand, Category } from '../lib/types';
import { Button, Checkbox } from './ui';

/** The filter controls: category tree, brands, price range, availability. `set` writes the URL (page sidebar) or a draft (filter window). */
/** Colour, material and size chips. Each group is one attribute (choosing two colours shows either); the choice is in the URL as `attr`. */
export function AttributeChips({ groups, selected, set }: { groups: AttributeFilterGroup[]; selected: string[]; set: (patch: Record<string, string | null>) => void }) {
  const { t } = useI18n();
  if (!groups.length) return null;
  return (
    <div className="space-y-6" data-testid="attribute-filters">
      {groups.map((g, gi) => (
        <div key={g.attribute.code} role="group" aria-label={g.attribute.name}>
          <div className="mb-3 flex items-center justify-between gap-3">
            <h3 className="eyebrow">{g.attribute.name}</h3>
            {gi === 0 && selected.length > 0 && <button type="button" onClick={() => set({ attr: null })} className="min-h-11 px-1 text-[13px] font-medium text-brass-deep underline-offset-2 hover:underline">{t('filter.attr_clear')}</button>}
          </div>
          <ul className="flex flex-wrap gap-2">
            {g.values.map((v) => {
              const on = selected.includes(String(v.id));
              return (
                <li key={v.id}>
                  <button type="button" aria-pressed={on} onClick={() => set(toggleAttr(selected, String(v.id)))}
                    className={cx('flex min-h-11 items-center gap-2 rounded-full border px-3.5 py-1.5 text-[13px] transition-colors', on ? 'border-primary bg-primary text-on-primary' : 'border-line bg-paper hover:border-brass')}>
                    {g.attribute.type === 'color' && v.color_hex && <span aria-hidden="true" className="h-4 w-4 shrink-0 rounded-full border border-ink/20" style={{ background: v.color_hex }} />}
                    {v.name}
                  </button>
                </li>
              );
            })}
          </ul>
        </div>
      ))}
    </div>
  );
}

export function FilterPanel({ cats, brands, f, set }: { cats: Category[]; brands: Brand[]; f: ShopFilters; set: (patch: Record<string, string | null>) => void }) {
  const { t } = useI18n();
  const { cat, brand } = f;
  const groups = useAttributeFilters(cat, cats);
  const rooms = useRooms().data ?? [];
  return (
    <div className="space-y-8">
      <div>
        <h3 className="eyebrow mb-3">{t('filter.category')}</h3>
        <ul className="space-y-1">
          <li><button onClick={() => set({ category: null, attr: null })} aria-pressed={!cat} className={cx('min-h-11 w-full rounded-xl px-3 py-2 text-start text-sm hover:bg-sand', !cat && 'bg-sand font-semibold')}>{t('filter.all')}</button></li>
          {cats.map((c) => (
            <li key={c.id}>
              <button onClick={() => set({ category: c.slug, attr: null })} aria-pressed={cat?.id === c.id} className={cx('min-h-11 w-full rounded-xl px-3 py-2 text-start text-sm hover:bg-sand', cat?.id === c.id && 'bg-sand font-semibold')}>{c.name}</button>
              {c.children?.length > 0 && (cat?.id === c.id || c.children.some((x) => x.id === cat?.id)) && (
                <ul className="ms-3 border-s border-line ps-2">{c.children.map((x) => <li key={x.id}><button onClick={() => set({ category: x.slug, attr: null })} aria-pressed={cat?.id === x.id} className={cx('min-h-11 w-full rounded-lg px-3 py-1.5 text-start text-[13px] hover:bg-sand', cat?.id === x.id && 'font-semibold text-brass-deep')}>{x.name}</button></li>)}</ul>
              )}
            </li>
          ))}
        </ul>
      </div>
      {rooms.length > 0 && (
        <div>
          <h3 className="eyebrow mb-3">{t('filter.room')}</h3>
          <ul className="flex flex-wrap gap-2">
            {rooms.map((r) => <li key={r.id}><button type="button" aria-pressed={f.room === r.slug} onClick={() => set({ room: f.room === r.slug ? null : r.slug })} className={cx('min-h-11 rounded-full border px-3.5 py-1.5 text-[13px] transition-colors', f.room === r.slug ? 'border-primary bg-primary text-on-primary' : 'border-line bg-paper hover:border-brass')}>{r.name}</button></li>)}
          </ul>
        </div>
      )}
      {brands.length > 0 && (
        <div>
          <h3 className="eyebrow mb-3">{t('filter.brand')}</h3>
          <ul className="flex flex-wrap gap-2">
            {brands.map((b) => <li key={b.id}><button aria-pressed={brand?.id === b.id} onClick={() => set({ brand: brand?.id === b.id ? null : b.slug })} className={cx('min-h-11 rounded-full border px-3.5 py-1.5 text-[13px] transition-colors', brand?.id === b.id ? 'border-primary bg-primary text-bg' : 'border-line bg-paper hover:border-brass')}>{b.name}</button></li>)}
          </ul>
        </div>
      )}
      <AttributeChips groups={groups} selected={f.attrs} set={set} />
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
