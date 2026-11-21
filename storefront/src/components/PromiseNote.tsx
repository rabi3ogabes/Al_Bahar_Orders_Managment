import { CalendarClock, Store, Truck } from 'lucide-react';
import { useI18n } from '../lib/i18n';
import { cx } from '../lib/format';
import { promiseView, type BasketPromise } from '../lib/promise';
import { Skeleton } from './ui';

/**
 * The delivery or pickup date, in one calm block: "Delivery today before 10 pm" with its small print ("if you order by 3 pm"), one row per
 * branch when the order splits, and a hint that the exact date depends on the address. It never alarms: a date that cannot be promised yet
 * is "We will confirm your delivery date" and buying stays open. Nothing at all while the dashboard switch is off.
 */
export function PromiseNote({ promise, loading, className, compact }: { promise: BasketPromise | null | undefined; loading?: boolean; className?: string; compact?: boolean }) {
  const { t } = useI18n();
  const view = promiseView(promise, t);
  if (!view) return loading ? <Skeleton className={cx(compact ? 'h-10' : 'h-14', 'rounded-2xl', className)} /> : null;
  const Icon = view.tone === 'soft' ? CalendarClock : view.type === 'pickup' ? Store : Truck;
  return (
    <div className={cx('flex items-start gap-3 rounded-2xl px-3.5 py-3', view.tone === 'ok' ? 'bg-brass-tint/50' : 'bg-sand/60', className)} data-testid="promise" data-tone={view.tone} data-type={view.type} aria-live="polite">
      <Icon size={compact ? 18 : 20} strokeWidth={1.6} aria-hidden="true" className="mt-0.5 shrink-0 text-brass-deep" />
      <div className="min-w-0 flex-1 text-sm leading-snug">
        <p className="font-semibold text-ink">{view.headline}</p>
        {view.detail && <p className="mt-0.5 text-[13px] text-muted">{view.detail}</p>}
        {view.rows.length > 0 && (
          <ul className="mt-2 space-y-1.5 text-[13px]" data-testid="promise-parcels">
            {view.rows.map((r) => (
              <li key={r.key} className="flex items-baseline justify-between gap-3"><span className="min-w-0 truncate text-muted">{r.branch}</span><span className={cx('shrink-0 text-end', r.ok ? 'font-medium text-ink-soft' : 'text-muted')}>{r.text}</span></li>
            ))}
          </ul>
        )}
        {view.hint && <p className="mt-1.5 text-xs text-muted">{view.hint}</p>}
      </div>
    </div>
  );
}
