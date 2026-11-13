import { forwardRef, useId, useState, type ButtonHTMLAttributes, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react';
import { Link, type LinkProps } from 'react-router-dom';
import { Check, ChevronLeft, ChevronRight, Loader2, Minus, Plus, Star } from 'lucide-react';
import { cx, moneyShort } from '../lib/format';
import { useI18n } from '../lib/i18n';
import type { Price } from '../lib/types';

/* ───────────── Buttons ───────────── */
type Variant = 'primary' | 'brass' | 'ghost' | 'outline' | 'danger';
const vclass: Record<Variant, string> = {
  primary: 'bg-primary text-on-primary hover:bg-primary-soft',
  brass: 'bg-brass text-on-brass hover:bg-brass-deep hover:text-on-primary',
  ghost: 'text-ink hover:bg-sand',
  outline: 'border border-line bg-paper text-ink hover:border-primary',
  danger: 'bg-danger text-white hover:opacity-90',
};
const sclass = { sm: 'h-9 px-4 text-[13px]', md: 'h-11 px-6 text-sm', lg: 'h-[52px] px-8 text-[15px]' };
interface BtnProps extends ButtonHTMLAttributes<HTMLButtonElement> { variant?: Variant; size?: keyof typeof sclass; loading?: boolean; block?: boolean }
export const Button = forwardRef<HTMLButtonElement, BtnProps>(function Button({ variant = 'primary', size = 'md', loading, block, className, children, disabled, ...p }, ref) {
  return (
    <button ref={ref} disabled={disabled || loading} className={cx('inline-flex items-center justify-center gap-2 rounded-full font-medium tracking-wide transition-colors duration-200 disabled:cursor-not-allowed disabled:opacity-50', vclass[variant], sclass[size], block && 'w-full', className)} {...p}>
      {loading && <Loader2 size={16} className="animate-spin" />}
      {children}
    </button>
  );
});
export function LinkButton({ variant = 'primary', size = 'md', block, className, ...p }: LinkProps & { variant?: Variant; size?: keyof typeof sclass; block?: boolean }) {
  return <Link className={cx('inline-flex items-center justify-center gap-2 rounded-full font-medium tracking-wide transition-colors duration-200', vclass[variant], sclass[size], block && 'w-full', className)} {...p} />;
}
export const IconButton = forwardRef<HTMLButtonElement, ButtonHTMLAttributes<HTMLButtonElement> & { label: string }>(function IconButton({ label, className, children, ...p }, ref) {
  return <button ref={ref} aria-label={label} title={label} className={cx('relative inline-flex h-11 w-11 items-center justify-center rounded-full text-ink transition-colors hover:bg-sand', className)} {...p}>{children}</button>;
});

/* ───────────── Form fields ───────────── */
interface FieldProps { label: string; error?: string; hint?: string; className?: string }
const control = 'w-full rounded-2xl border bg-paper px-4 text-[15px] text-ink placeholder:text-muted/70 transition-colors focus:border-brass focus:outline-none focus:ring-2 focus:ring-brass/25';
export const Input = forwardRef<HTMLInputElement, Omit<InputHTMLAttributes<HTMLInputElement>, 'prefix'> & FieldProps & { prefix?: ReactNode }>(function Input({ label, error, hint, className, prefix, ...p }, ref) {
  const id = useId();
  return (
    <div className={className}>
      <label htmlFor={id} className="mb-1.5 block text-[13px] font-medium text-ink-soft">{label}</label>
      <div className="relative">
        {prefix && <span className="pointer-events-none absolute inset-y-0 start-4 flex items-center text-sm text-muted">{prefix}</span>}
        <input ref={ref} id={id} aria-invalid={!!error} aria-describedby={error ? `${id}-e` : undefined} className={cx(control, 'h-12', error ? 'border-danger' : 'border-line')} style={prefix ? { paddingInlineStart: 56 } : undefined} {...p} />
      </div>
      {error ? <p id={`${id}-e`} role="alert" className="mt-1.5 text-[13px] text-danger">{error}</p> : hint ? <p className="mt-1.5 text-[13px] text-muted">{hint}</p> : null}
    </div>
  );
});
export const Textarea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement> & FieldProps>(function Textarea({ label, error, hint, className, ...p }, ref) {
  const id = useId();
  return (
    <div className={className}>
      <label htmlFor={id} className="mb-1.5 block text-[13px] font-medium text-ink-soft">{label}</label>
      <textarea ref={ref} id={id} aria-invalid={!!error} className={cx(control, 'min-h-28 py-3', error ? 'border-danger' : 'border-line')} {...p} />
      {error ? <p role="alert" className="mt-1.5 text-[13px] text-danger">{error}</p> : hint ? <p className="mt-1.5 text-[13px] text-muted">{hint}</p> : null}
    </div>
  );
});
export const Select = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement> & FieldProps>(function Select({ label, error, className, children, ...p }, ref) {
  const id = useId();
  return (
    <div className={className}>
      <label htmlFor={id} className="mb-1.5 block text-[13px] font-medium text-ink-soft">{label}</label>
      <select ref={ref} id={id} aria-invalid={!!error} className={cx(control, 'h-12', error ? 'border-danger' : 'border-line')} {...p}>{children}</select>
      {error && <p role="alert" className="mt-1.5 text-[13px] text-danger">{error}</p>}
    </div>
  );
});
export function Checkbox({ checked, onChange, children }: { checked: boolean; onChange: (v: boolean) => void; children: ReactNode }) {
  return (
    <label className="flex cursor-pointer items-start gap-3 text-sm text-ink-soft">
      <span className={cx('mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-md border transition-colors', checked ? 'border-primary bg-primary text-bg' : 'border-line bg-paper')}>
        {checked && <Check size={13} strokeWidth={3} />}
      </span>
      <input type="checkbox" className="sr-only" checked={checked} onChange={(e) => onChange(e.target.checked)} />
      <span>{children}</span>
    </label>
  );
}

/* ───────────── Feedback ───────────── */
export const Skeleton = ({ className }: { className?: string }) => <div aria-hidden className={cx('skeleton', className)} />;
export const Spinner = ({ size = 20 }: { size?: number }) => <Loader2 size={size} className="animate-spin text-brass" aria-label="Loading" />;
export function Badge({ children, tone = 'sand' }: { children: ReactNode; tone?: 'sand' | 'brass' | 'ink' | 'success' | 'danger' | 'warning' }) {
  const tones = { sand: 'bg-sand text-ink-soft', brass: 'bg-brass-tint text-brass-deep ring-1 ring-inset ring-brass/30', ink: 'bg-primary text-bg', success: 'bg-success/12 text-success', danger: 'bg-danger/12 text-danger', warning: 'bg-warning/15 text-warning' };
  return <span className={cx('inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold tracking-wide', tones[tone])}>{children}</span>;
}
export function EmptyState({ icon, title, text, action }: { icon?: ReactNode; title: string; text?: string; action?: ReactNode }) {
  return (
    <div className="rise mx-auto flex max-w-md flex-col items-center px-4 py-16 text-center">
      {icon && <div className="mb-5 flex h-16 w-16 items-center justify-center rounded-full bg-brass-tint text-brass-deep">{icon}</div>}
      <h2 className="text-2xl">{title}</h2>
      {text && <p className="mt-2 text-muted">{text}</p>}
      {action && <div className="mt-6">{action}</div>}
    </div>
  );
}
export function ErrorState({ onRetry, text }: { onRetry?: () => void; text?: string }) {
  const { t } = useI18n();
  return <EmptyState title={t('error.title')} text={text ?? t('error.generic')} action={onRetry && <Button variant="outline" onClick={onRetry}>{t('action.retry')}</Button>} />;
}
export function Alert({ tone = 'info', children }: { tone?: 'info' | 'warning' | 'danger' | 'success'; children: ReactNode }) {
  const t = { info: 'border-info/30 bg-info/8 text-info', warning: 'border-warning/30 bg-warning/10 text-warning', danger: 'border-danger/30 bg-danger/8 text-danger', success: 'border-success/30 bg-success/8 text-success' };
  return <div role={tone === 'danger' ? 'alert' : 'status'} className={cx('rounded-2xl border px-4 py-3 text-sm', t[tone])}>{children}</div>;
}

/* ───────────── Imagery ───────────── */
/** Product / category picture with an on-brand placeholder when the catalogue has no photo yet. */
export function Pic({ src, alt, ratio = '4/5', className, label, eager }: { src?: string | null; alt: string; ratio?: string; className?: string; label?: string; eager?: boolean }) {
  const [bad, setBad] = useState(false);
  const show = src && !bad;
  return (
    <div className={cx('relative overflow-hidden bg-sand', className)} style={{ aspectRatio: ratio }}>
      {show ? (
        <img src={src} alt={alt} loading={eager ? 'eager' : 'lazy'} decoding="async" onError={() => setBad(true)} className="h-full w-full object-cover transition-transform duration-700 ease-out group-hover:scale-[1.04]" />
      ) : (
        <div aria-label={alt} role="img" className="flex h-full w-full flex-col items-center justify-center gap-2 bg-[radial-gradient(120%_90%_at_30%_10%,var(--brass-tint),var(--sand)_55%,var(--sand-deep))]">
          <svg width="46" height="46" viewBox="0 0 48 48" fill="none" aria-hidden className="text-brass/70"><path d="M6 30c6 0 6-4 12-4s6 4 12 4 6-4 12-4M6 38c6 0 6-4 12-4s6 4 12 4 6-4 12-4M6 22c6 0 6-4 12-4s6 4 12 4 6-4 12-4" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" /></svg>
          {label && <span className="font-display px-4 text-center text-[15px] italic text-brass-deep/80">{label}</span>}
        </div>
      )}
    </div>
  );
}

/* ───────────── Commerce bits ───────────── */
export function PriceTag({ price, size = 'md' }: { price: Price; size?: 'sm' | 'md' | 'lg' }) {
  const { locale } = useI18n();
  const sale = !!price.sale && price.discount_percent > 0;
  const big = size === 'lg' ? 'text-[28px]' : size === 'md' ? 'text-[17px]' : 'text-[15px]';
  return (
    <div className="flex flex-wrap items-baseline gap-x-2.5 gap-y-0.5">
      <span className={cx('font-semibold tabular-nums', big, sale && 'text-danger')}>{moneyShort(price.final, locale)}</span>
      {sale && <span className="text-[13px] text-muted line-through tabular-nums">{moneyShort(price.regular, locale)}</span>}
      {sale && <span className="rounded-full bg-danger/10 px-2 py-0.5 text-[11px] font-semibold text-danger">−{price.discount_percent}%</span>}
    </div>
  );
}
export function Rating({ value, count, size = 14 }: { value: number; count?: number; size?: number }) {
  const { num } = useI18n();
  return (
    <span className="inline-flex items-center gap-1.5 text-[13px] text-muted" aria-label={`${value} / 5`}>
      <span className="flex">{[1, 2, 3, 4, 5].map((i) => <Star key={i} size={size} className={i <= Math.round(value) ? 'fill-brass text-brass' : 'text-line'} />)}</span>
      {count !== undefined && <span>({num(count)})</span>}
    </span>
  );
}
export function QtyStepper({ value, onChange, min = 1, max = 20, size = 'md' }: { value: number; onChange: (n: number) => void; min?: number; max?: number; size?: 'sm' | 'md' }) {
  const { t } = useI18n();
  const h = size === 'sm' ? 'h-9' : 'h-11';
  return (
    <div className={cx('inline-flex items-center rounded-full border border-line bg-paper', h)} role="group" aria-label={t('qty')}>
      <button type="button" aria-label={t('qty.less')} disabled={value <= min} onClick={() => onChange(value - 1)} className={cx('flex items-center justify-center rounded-full disabled:opacity-30', size === 'sm' ? 'h-9 w-9' : 'h-11 w-11')}><Minus size={15} /></button>
      <span className="min-w-8 text-center text-sm font-semibold tabular-nums" aria-live="polite">{value}</span>
      <button type="button" aria-label={t('qty.more')} disabled={value >= max} onClick={() => onChange(value + 1)} className={cx('flex items-center justify-center rounded-full disabled:opacity-30', size === 'sm' ? 'h-9 w-9' : 'h-11 w-11')}><Plus size={15} /></button>
    </div>
  );
}

/* ───────────── Navigation helpers ───────────── */
export function Breadcrumbs({ items }: { items: { to?: string; label: string }[] }) {
  const { dir } = useI18n();
  const Sep = dir === 'rtl' ? ChevronLeft : ChevronRight;
  return (
    <nav aria-label="Breadcrumb" className="flex flex-wrap items-center gap-1.5 text-[13px] text-muted">
      {items.map((it, i) => (
        <span key={i} className="flex items-center gap-1.5">
          {it.to ? <Link to={it.to} className="hover:text-ink">{it.label}</Link> : <span className="text-ink-soft" aria-current="page">{it.label}</span>}
          {i < items.length - 1 && <Sep size={13} />}
        </span>
      ))}
    </nav>
  );
}
export function Segmented<T extends string>({ value, onChange, options, label }: { value: T; onChange: (v: T) => void; options: { value: T; label: string; sub?: string; disabled?: boolean }[]; label: string }) {
  return (
    <div role="radiogroup" aria-label={label} className="grid gap-2" style={{ gridTemplateColumns: `repeat(${options.length}, minmax(0,1fr))` }}>
      {options.map((o) => (
        <button key={o.value} type="button" role="radio" aria-checked={value === o.value} disabled={o.disabled} onClick={() => onChange(o.value)}
          className={cx('rounded-2xl border px-4 py-3 text-start transition-all disabled:cursor-not-allowed disabled:opacity-45', value === o.value ? 'border-primary bg-paper shadow-soft ring-1 ring-ink' : 'border-line bg-paper hover:border-brass')}>
          <span className="block text-sm font-semibold">{o.label}</span>
          {o.sub && <span className="mt-0.5 block text-[12px] text-muted">{o.sub}</span>}
        </button>
      ))}
    </div>
  );
}
export function SectionHead({ eyebrow, title, link }: { eyebrow?: string; title: string; link?: { to: string; label: string } }) {
  const { dir } = useI18n();
  const Arrow = dir === 'rtl' ? ChevronLeft : ChevronRight;
  return (
    <div className="mb-8 flex items-end justify-between gap-4">
      <div>{eyebrow && <p className="eyebrow mb-2">{eyebrow}</p>}<h2 className="text-[clamp(28px,4vw,42px)] leading-[1.1]">{title}</h2></div>
      {link && <Link to={link.to} className="group flex shrink-0 items-center gap-1 text-sm font-medium text-ink-soft hover:text-ink">{link.label}<Arrow size={16} className="transition-transform group-hover:translate-x-0.5 rtl:group-hover:-translate-x-0.5" /></Link>}
    </div>
  );
}
