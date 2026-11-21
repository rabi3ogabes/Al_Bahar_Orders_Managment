import { Wrench } from 'lucide-react';
import { useI18n } from '../lib/i18n';
import { cx } from '../lib/format';
import type { Money } from '../lib/types';
import { Checkbox } from './ui';

/**
 * "Add assembly and installation +QAR 75 each": one tick per cart line (or on the product page before adding). It is done at the door, so it
 * is for delivery orders only; the price is the server's and is worked out again at checkout. `unit` is the fee for one unit, `total` what the
 * line adds while ticked (null when it does not, for example on a pickup basket).
 */
export function AssemblyChoice({ fee, checked, onChange, total, className, disabled }: { fee: Money; checked: boolean; onChange: (on: boolean) => void; total?: Money | null; className?: string; disabled?: boolean }) {
  const { t } = useI18n();
  return (
    <div className={cx('rounded-2xl border border-line bg-bg px-3.5 py-3 transition-colors', checked && 'border-brass/60 bg-brass-tint/40', disabled && 'pointer-events-none opacity-60', className)} data-testid="assembly-choice" data-checked={checked ? 'true' : 'false'}>
      <Checkbox checked={checked} onChange={onChange}>
        <span className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
          <Wrench size={15} strokeWidth={1.7} aria-hidden="true" className="shrink-0 text-brass-deep" />
          <b className="font-semibold text-ink">{t('assembly.add')}</b>
          <span className="tabular-nums text-brass-deep">{t('assembly.fee_each', { fee: fee.formatted })}</span>
        </span>
        <span className="mt-0.5 block text-[13px] text-muted">{checked && total ? t('assembly.line_total', { amount: total.formatted }) : t('assembly.note')}</span>
      </Checkbox>
    </div>
  );
}
