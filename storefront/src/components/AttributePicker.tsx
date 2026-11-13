import { useEffect, useMemo, useState } from 'react';
import { useI18n } from '../lib/i18n';
import { cx } from '../lib/format';
import type { ProductDetail, Variant } from '../lib/types';

/** Which option values are chosen and the variant they add up to. Shared by the product page and the quick view. */
export function useVariantPicker(p: ProductDetail | undefined) {
  const [sel, setSel] = useState<Record<string, number>>({});
  // Start on the default variant's options.
  useEffect(() => {
    if (!p) return;
    const v = p.variants.find((x) => x.id === p.default_variant_id) ?? p.variants[0];
    const s: Record<string, number> = {};
    if (v) p.attributes.forEach((a) => { const hit = a.values.find((x) => v.attribute_value_ids.includes(x.id)); if (hit) s[a.code] = hit.id; });
    setSel(s);
  }, [p?.id]); // eslint-disable-line react-hooks/exhaustive-deps

  const variant: Variant | undefined = useMemo(() => {
    if (!p) return undefined;
    if (!p.attributes.length) return p.variants.find((x) => x.id === p.default_variant_id) ?? p.variants[0];
    const ids = Object.values(sel);
    return p.variants.find((v) => ids.length === p.attributes.length && ids.every((i) => v.attribute_value_ids.includes(i)));
  }, [p, sel]);

  const optionState = (code: string, valueId: number) => {
    if (!p) return { exists: false, inStock: false };
    const others = Object.entries(sel).filter(([c]) => c !== code).map(([, v]) => v);
    const vs = p.variants.filter((v) => v.attribute_value_ids.includes(valueId) && others.every((o) => v.attribute_value_ids.includes(o)));
    return { exists: vs.length > 0, inStock: vs.some((v) => v.in_stock && v.available_in_branch) };
  };
  return { sel, setSel, variant, optionState };
}

export function AttributePicker({ p, picker }: { p: ProductDetail; picker: ReturnType<typeof useVariantPicker> }) {
  const { t } = useI18n();
  const { sel, setSel, optionState } = picker;
  return (
    <>
      {p.attributes.map((a) => (
        <fieldset key={a.code} className="mt-7">
          <legend className="mb-2.5 text-sm"><span className="font-semibold">{a.name}</span><span className="text-muted"> · {a.values.find((v) => v.id === sel[a.code])?.name ?? t('product.choose')}</span></legend>
          <div className="flex flex-wrap gap-2.5">
            {a.values.map((v) => {
              const st = optionState(a.code, v.id);
              const on = sel[a.code] === v.id;
              return a.type === 'color' && v.color_hex ? (
                <button key={v.id} type="button" disabled={!st.exists} aria-label={v.name} aria-pressed={on} title={v.name} onClick={() => setSel({ ...sel, [a.code]: v.id })}
                  className={cx('relative h-11 w-11 rounded-full ring-offset-2 ring-offset-bg transition-all disabled:opacity-30', on ? 'ring-2 ring-ink' : 'ring-1 ring-line hover:ring-brass')} style={{ background: v.color_hex }}>
                  {!st.inStock && st.exists && <span className="absolute inset-0 rounded-full bg-[linear-gradient(135deg,transparent_47%,var(--bg)_47%,var(--bg)_53%,transparent_53%)]" />}
                </button>
              ) : (
                <button key={v.id} type="button" disabled={!st.exists} aria-pressed={on} onClick={() => setSel({ ...sel, [a.code]: v.id })}
                  className={cx('h-11 rounded-full border px-5 text-sm transition-colors disabled:opacity-30', on ? 'border-primary bg-primary text-bg' : 'border-line bg-paper hover:border-brass', st.exists && !st.inStock && !on && 'text-muted line-through')}>{v.name}</button>
              );
            })}
          </div>
        </fieldset>
      ))}
    </>
  );
}
