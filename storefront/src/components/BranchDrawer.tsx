import { useBranch } from '../lib/branch';
import { useI18n } from '../lib/i18n';
import { cx } from '../lib/format';
import { SideDrawer } from './SideDrawer';

/** Showroom picker: choosing one applies it at once (stock and pickup follow the showroom) and closes the window. */
export default function BranchDrawer({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { t } = useI18n();
  const { branches, branchId, select } = useBranch();
  const pick = (id: string | null) => { select(id); onClose(); };
  return (
    <SideDrawer open={open} onClose={onClose} title={t('branch.pick_title')} description={t('branch.pick_text')} size="sm">
      <p className="px-6 pt-4 text-sm text-muted">{t('branch.pick_text')}</p>
      <div className="space-y-2.5 p-6" role="radiogroup" aria-label={t('branch.pick_title')}>
        <button type="button" role="radio" aria-checked={!branchId} onClick={() => pick(null)} className={cx('w-full rounded-2xl border p-4 text-start transition-colors', !branchId ? 'border-primary ring-1 ring-ink' : 'border-line hover:border-brass')}>
          <span className="block font-semibold">{t('branch.all')}</span><span className="text-[13px] text-muted">{t('branch.all_sub')}</span>
        </button>
        {branches.map((b) => (
          <button key={b.id} type="button" role="radio" aria-checked={branchId === b.id} onClick={() => pick(b.id)} className={cx('w-full rounded-2xl border p-4 text-start transition-colors', branchId === b.id ? 'border-primary ring-1 ring-ink' : 'border-line hover:border-brass')}>
            <span className="flex items-center justify-between gap-3"><span className="font-semibold">{b.name}</span>
              <span className={cx('rounded-full px-2 py-0.5 text-[11px] font-semibold', b.opening.is_open ? 'bg-success/12 text-success' : 'bg-muted/15 text-muted')}>{b.opening.is_open ? t('branch.open') : t('branch.closed')}</span></span>
            <span className="text-[13px] text-muted">{b.area}{b.address ? ` · ${b.address}` : ''}</span>
          </button>
        ))}
      </div>
    </SideDrawer>
  );
}
