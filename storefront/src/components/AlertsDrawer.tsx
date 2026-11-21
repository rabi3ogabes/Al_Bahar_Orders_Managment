import { Link } from 'react-router-dom';
import { Bell, TrendingDown, Trash2 } from 'lucide-react';
import { useI18n } from '../lib/i18n';
import { useToast } from '../lib/toast';
import { alertError, useStockAlerts } from '../lib/alerts';
import { alertStatus, priceMove, productPath, type StockAlert } from '../lib/alertsCore';
import { Badge, EmptyState, ErrorState, LinkButton, Pic, Skeleton } from './ui';
import { SideDrawer } from './SideDrawer';

/** "My alerts": every back-in-stock and price alert of the customer, with what it waits for and a way to remove it. Opened from the account menu and the wishlist. */
export default function AlertsDrawer({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { t, date } = useI18n();
  const toast = useToast();
  const alerts = useStockAlerts();
  const items = alerts.list ?? [];

  const remove = async (a: StockAlert) => {
    try { await alerts.unsubscribe(a.id); toast.push(t('alerts.removed'), 'info'); } catch (e) { toast.push(alertError(e, t('error.generic')), 'error'); }
  };

  return (
    <SideDrawer open={open} onClose={onClose} title={t('alerts.title')}>
      <div className="p-6">
        {alerts.loading && <div className="space-y-4" aria-busy="true">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-24 rounded-3xl" />)}</div>}
        {alerts.failed && !alerts.loading && <ErrorState onRetry={() => alerts.reload()} />}
        {!alerts.loading && !alerts.failed && items.length === 0 && (
          <EmptyState icon={<Bell size={28} />} title={t('alerts.empty')} text={t('alerts.empty_text')} action={<LinkButton to="/shop" onClick={onClose}>{t('alerts.browse')}</LinkButton>} />
        )}
        {items.length > 0 && (
          <ul className="space-y-4">
            {items.map((a) => <AlertRow key={a.id} a={a} onRemove={() => remove(a)} onOpen={onClose} date={(iso) => date(iso, { dateStyle: 'medium', timeZone: 'Asia/Qatar' })} />)}
          </ul>
        )}
      </div>
    </SideDrawer>
  );
}

function AlertRow({ a, onRemove, onOpen, date }: { a: StockAlert; onRemove: () => void; onOpen: () => void; date: (iso: string) => string }) {
  const { t } = useI18n();
  const status = alertStatus(a);
  const move = a.type === 'price_drop' ? priceMove(a) : null;
  return (
    <li className="flex gap-4 rounded-3xl border border-line bg-paper p-3.5">
      <Link to={productPath(a)} onClick={onOpen} className="block w-20 shrink-0" aria-label={a.product.name}><Pic src={a.product.thumb_url ?? a.product.image_url} alt={a.product.name} ratio="1/1" className="rounded-2xl" /></Link>
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <Badge tone={a.type === 'price_drop' ? 'brass' : 'sand'}>{a.type === 'price_drop' ? <TrendingDown size={11} className="me-1" /> : <Bell size={11} className="me-1" />}{t(a.type === 'price_drop' ? 'alerts.price' : 'alerts.back')}</Badge>
          {status === 'available' && <Badge tone="success">{t('alerts.available')}</Badge>}
        </div>
        <Link to={productPath(a)} onClick={onOpen} className="mt-1.5 block truncate font-display text-xl leading-tight hover:text-brass-deep">{a.product.name}</Link>
        {a.variant.name && <p className="truncate text-[13px] text-muted">{a.variant.name}</p>}
        <p className="mt-1 text-[13px] text-ink-soft">{t(`alerts.${status === 'available' ? 'available' : status}`)}</p>
        {a.type === 'price_drop' && a.current_price && (
          <p className="mt-0.5 text-[13px] tabular-nums">
            <b className="font-semibold">{t('alerts.now', { p: a.current_price.formatted })}</b>
            {a.reference_price && <span className="text-muted"> · {t('alerts.was', { p: a.reference_price.formatted })}</span>}
            {move?.dropped && <span className="ms-1 text-success"> · {t('alerts.dropped', { n: Math.abs(move.percent) })}</span>}
          </p>
        )}
        <div className="mt-2 flex items-center justify-between gap-3">
          <span className="text-xs text-muted">{t('alerts.since', { d: date(a.since) })}</span>
          <button type="button" onClick={onRemove} aria-label={`${t('alerts.remove')}: ${a.product.name}`} title={t('alerts.remove')}
            className="flex min-h-11 items-center gap-1.5 rounded-full px-3 text-[13px] font-medium text-danger hover:bg-danger/10"><Trash2 size={15} />{t('alerts.cancel')}</button>
        </div>
      </div>
    </li>
  );
}
