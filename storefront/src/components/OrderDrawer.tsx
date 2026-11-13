import { useEffect, useState } from 'react';
import { ArrowLeft, ArrowRight, CreditCard, Download, ExternalLink, PackageX, RotateCcw, XCircle } from 'lucide-react';
import { ApiError } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useI18n } from '../lib/i18n';
import { LivePill, useFlashingOrders } from '../lib/realtime';
import { cx } from '../lib/format';
import type { OrderDetail } from '../lib/types';
import { useAuthModal } from './AuthModal';
import { DrawerLink } from './drawerLink';
import { CancelOrderDialog, FulfillmentCard, ReturnForm, TimelineCard, TotalsCard, statusTone, useInvoice, usePay, useReorder, useOrderQuery } from './OrderParts';
import { SideDrawer } from './SideDrawer';
import { Alert, Badge, Button, EmptyState, Skeleton } from './ui';

/**
 * An order in a side window (`?order=<id>`): status timeline, live status, items, totals, pickup pass, reorder, return.
 * The full page /orders/:id keeps working: push notifications and emails link to it.
 */
export default function OrderDrawer({ id, open, onClose }: { id: string; open: boolean; onClose: () => void }) {
  const { t, date, dir } = useI18n();
  const { user, loading } = useAuth();
  const authModal = useAuthModal();
  const { order, timeline } = useOrderQuery(id, !!user && open);
  const flashing = useFlashingOrders();
  const [step, setStep] = useState<'order' | 'return'>('order');
  const [cancelOpen, setCancelOpen] = useState(false);
  const o = order.data;
  const pay = usePay(id);
  const reorder = useReorder(id);
  const invoice = useInvoice(id);
  useEffect(() => { setStep('order'); setCancelOpen(false); }, [id]);

  const Back = dir === 'rtl' ? ArrowRight : ArrowLeft;
  const returning = step === 'return' && !!o;
  const title = returning ? t('return.title') : o ? `${t('order.title')} ${o.number}` : t('order.title');
  const unpaid = !!o && (o.payment_status === 'pending' && o.payment_method === 'sadad' || ['failed', 'expired'].includes(o.payment_status)) && o.can_pay !== false;

  const footer = !o || returning ? null : unpaid
    ? <Button size="lg" block loading={pay.isPending} onClick={() => pay.mutate()}><CreditCard size={18} />{t('order.pay_now')} · {o.totals.grand_total.formatted}</Button>
    : <Button size="lg" block loading={reorder.isPending} onClick={() => reorder.mutate()}><RotateCcw size={18} />{t('order.reorder')}</Button>;

  return (
    <SideDrawer open={open} onClose={onClose} urlBacked size="md" title={title} footer={footer}
      headerAction={returning
        ? <button type="button" onClick={() => setStep('order')} aria-label={t('return.back')} title={t('return.back')} className="order-first flex h-11 w-11 shrink-0 items-center justify-center rounded-full hover:bg-sand"><Back size={20} /></button>
        : <DrawerLink to={`/orders/${id}`} aria-label={t('order.open_page')} title={t('order.open_page')} className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-ink transition-colors hover:bg-sand"><ExternalLink size={19} /></DrawerLink>}>
      {loading ? <Skeleton className="m-6 h-64" />
        : !user ? <EmptyState title={t('order.signin_title')} text={t('order.signin_text')} action={<Button size="lg" onClick={() => authModal.open()}>{t('auth.sign_in')}</Button>} />
        : order.isError ? <OrderError error={order.error} retry={() => void order.refetch()} />
        : !o ? <div className="space-y-4 p-6"><Skeleton className="h-16" /><Skeleton className="h-48" /><Skeleton className="h-40" /></div>
        : returning ? <ReturnForm o={o} onDone={() => setStep('order')} onCancel={() => setStep('order')} />
        : (
          <div className="space-y-5 p-6">
            <div className={cx('rounded-3xl', flashing.has(o.id) && 'live-flash')}>
              <div className="flex flex-wrap items-center gap-2"><Badge tone={statusTone(o.status)}>{o.status_label}</Badge><Badge tone={statusTone(o.payment_status)}>{t(`payment.${o.payment_status}`)}</Badge><LivePill /></div>
              <p className="mt-2 text-sm text-muted">{t('order.placed_on', { d: date(o.placed_at) })}</p>
            </div>
            {unpaid && <Alert tone="warning">{t('order.unpaid')}</Alert>}
            <TimelineCard events={timeline.data} loading={timeline.isLoading} compact />
            {o.fulfillments.map((f) => <FulfillmentCard key={f.id} o={o} f={f} compact />)}
            <TotalsCard o={o} compact />
            <OrderActions o={o} onInvoice={() => void invoice()} onReturn={() => setStep('return')} onCancel={() => setCancelOpen(true)} />
          </div>
        )}
      {o && <CancelOrderDialog id={id} open={cancelOpen} onClose={() => setCancelOpen(false)} />}
    </SideDrawer>
  );
}

function OrderError({ error, retry }: { error: unknown; retry: () => void }) {
  const { t } = useI18n();
  // Somebody else's order answers 403/404: to the visitor it simply does not exist.
  const nf = error instanceof ApiError && [403, 404].includes(error.status);
  return <EmptyState icon={<PackageX size={28} />} title={nf ? t('order.not_found') : t('error.title')} action={!nf ? <Button variant="outline" onClick={retry}>{t('action.retry')}</Button> : undefined} />;
}

/** Secondary actions (the primary one is pinned in the footer). */
function OrderActions({ o, onInvoice, onReturn, onCancel }: { o: OrderDetail; onInvoice: () => void; onReturn: () => void; onCancel: () => void }) {
  const { t } = useI18n();
  const paid = o.payment_status === 'paid';
  return (
    <div className="grid gap-2.5 pb-2">
      {paid && <Button variant="outline" onClick={onInvoice}><Download size={16} />{t('order.invoice')}</Button>}
      {o.can_return && <Button variant="outline" onClick={onReturn}><RotateCcw size={16} />{t('return.title')}</Button>}
      {o.can_cancel && <Button variant="ghost" className="text-danger hover:bg-danger/10" onClick={onCancel}><XCircle size={16} />{t('order.cancel')}</Button>}
      <DrawerLink to="/account/support" className="flex h-11 items-center justify-center rounded-full text-sm font-medium text-ink-soft transition-colors hover:bg-sand">{t('order.help')}</DrawerLink>
    </div>
  );
}
