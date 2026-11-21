import { Bell, BellRing, TrendingDown } from 'lucide-react';
import { useAuth } from '../lib/auth';
import { useBranch } from '../lib/branch';
import { useI18n } from '../lib/i18n';
import { useToast } from '../lib/toast';
import { alertError, useStockAlerts } from '../lib/alerts';
import { productControls, type AlertType } from '../lib/alertsCore';
import { cx } from '../lib/format';
import { useAuthModal } from './AuthModal';

/**
 * The alert controls of a product page: the bell for a product that cannot be bought ("Notify me when it is back", then the subscribed state, tap to
 * cancel) and, while it can be bought and the owner has price alerts on, "Tell me if the price drops". Signed out: the sign-in window opens and the
 * choice is made for the visitor as soon as they are in (the intent is kept). Rules: src/lib/alertsCore.ts.
 */
export function StockAlertButtons({ variantId, canBuy, className }: { variantId: string | null | undefined; canBuy: boolean; className?: string }) {
  const { t } = useI18n();
  const { user } = useAuth();
  const { branchId } = useBranch();
  const toast = useToast();
  const authModal = useAuthModal();
  const alerts = useStockAlerts();
  const controls = productControls({ canBuy, cfg: alerts.config, list: alerts.list, variantId });
  if (!variantId || (controls.bell === 'hidden' && controls.price === 'hidden')) return null;

  const run = async (type: AlertType, subscribed: boolean) => {
    try {
      if (subscribed) {
        const mine = alerts.find(variantId, type);
        if (mine) await alerts.unsubscribe(mine.id);
        toast.push(t('alerts.removed'), 'info');
      } else {
        await alerts.subscribe({ variantId, type, branchId });
        toast.push(type === 'back_in_stock' ? t('product.notify_ok') : t('alerts.price_ok'));
        if (type === 'price_drop' && user && !user.marketing_consent) toast.push(t('alerts.price_consent'), 'info');
      }
    } catch (e) { toast.push(alertError(e, t('error.generic')), 'error'); }
  };
  const press = (type: AlertType, subscribed: boolean, reason: string) => {
    if (alerts.busy) return;
    if (!user) { authModal.open({ reason, onDone: () => { void run(type, false); } }); return; } // signed out: sign in first, then do what they asked
    void run(type, subscribed);
  };
  const row = 'flex min-h-11 w-full items-center gap-2.5 rounded-2xl border px-4 py-2.5 text-start text-sm font-medium transition-colors disabled:opacity-60';

  return (
    <div className={cx('space-y-2.5', className)} data-testid="stock-alerts">
      {controls.bell !== 'hidden' && (
        <button type="button" disabled={alerts.busy} aria-pressed={controls.bell === 'subscribed'} onClick={() => press('back_in_stock', controls.bell === 'subscribed', t('product.notify_reason'))}
          className={cx(row, controls.bell === 'subscribed' ? 'border-brass bg-brass-tint text-brass-deep' : 'border-line bg-paper text-brass-deep hover:border-brass')}>
          {controls.bell === 'subscribed' ? <BellRing size={17} className="shrink-0" /> : <Bell size={17} className="shrink-0" />}
          <span>{controls.bell === 'subscribed' ? t('alerts.notify_on') : t('product.notify')}</span>
        </button>
      )}
      {controls.price !== 'hidden' && (
        <button type="button" disabled={alerts.busy} aria-pressed={controls.price === 'subscribed'} onClick={() => press('price_drop', controls.price === 'subscribed', t('alerts.price_reason'))}
          className={cx(row, controls.price === 'subscribed' ? 'border-brass bg-brass-tint text-brass-deep' : 'border-line bg-paper text-ink-soft hover:border-brass')}>
          <TrendingDown size={17} className="shrink-0" />
          <span>{controls.price === 'subscribed' ? t('alerts.price_on') : t('alerts.price_toggle')}</span>
        </button>
      )}
    </div>
  );
}
