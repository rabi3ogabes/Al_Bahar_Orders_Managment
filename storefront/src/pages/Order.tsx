import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { CheckCircle2, Clock, Download, RotateCcw, XCircle, CreditCard } from 'lucide-react';
import { ApiError } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useI18n } from '../lib/i18n';
import { useDocTitle } from '../lib/hooks';
import { useAuthModal } from '../components/AuthModal';
import { PushPrompt } from '../components/PushUI';
import { CancelOrderDialog, FulfillmentCard, ReturnForm, TimelineCard, TotalsCard, statusTone, useInvoice, useOrderQuery, usePay, useReorder } from '../components/OrderParts';
import { SideDrawer } from '../components/SideDrawer';
import { Alert, Badge, Button, EmptyState, LinkButton, Skeleton, Spinner } from '../components/ui';

export { statusTone };

export default function Order() {
  const { id = '' } = useParams();
  const [sp] = useSearchParams();
  const { t, date } = useI18n();
  const nav = useNavigate();
  const { user, loading: authLoading } = useAuth();
  const authModal = useAuthModal();
  const placed = sp.get('placed') === '1';
  const returning = !!sp.get('payment');
  const [cancelOpen, setCancelOpen] = useState(false);
  const [returnOpen, setReturnOpen] = useState(false);

  // After SADAD redirects back, the callback may still be in flight: poll for up to ~90 s.
  const { order, timeline } = useOrderQuery(id, !!user, returning);
  const o = order.data;
  useDocTitle(o ? `${t('order.title')} ${o.number}` : t('order.title'));
  useEffect(() => { try { localStorage.removeItem('albahar.web_checkout'); } catch { /* ignore */ } }, []);

  const pay = usePay(id);
  const reorder = useReorder(id);
  const invoice = useInvoice(id);

  if (authLoading) return <div className="wrap py-20"><Spinner /></div>;
  if (!user) return <div className="wrap py-20"><EmptyState title={t('order.signin_title')} text={t('order.signin_text')} action={<Button size="lg" onClick={() => authModal.open()}>{t('auth.sign_in')}</Button>} /></div>;
  if (order.isError) {
    // Somebody else's order answers 403/404: to the visitor it simply does not exist.
    const nf = order.error instanceof ApiError && [403, 404].includes(order.error.status);
    return <div className="wrap py-20"><EmptyState title={nf ? t('order.not_found') : t('error.title')} action={<div className="flex gap-3">{!nf && <Button variant="outline" onClick={() => void order.refetch()}>{t('action.retry')}</Button>}<Button onClick={() => nav('/account/orders')}>{t('account.orders')}</Button></div>} /></div>;
  }
  if (!o) return <div className="wrap space-y-4 py-12"><Skeleton className="h-16 w-1/2" /><Skeleton className="h-64" /></div>;

  const paid = o.payment_status === 'paid';
  const awaiting = o.payment_status === 'pending' && o.payment_method === 'sadad';
  const failed = ['failed', 'expired'].includes(o.payment_status);

  return (
    <div className="wrap py-8 md:py-12">
      {(placed || returning) && (
        <div className="rise mb-10 flex flex-col items-center rounded-[32px] bg-[linear-gradient(135deg,var(--brass-tint),var(--sand))] px-6 py-12 text-center">
          {failed ? <XCircle size={54} strokeWidth={1.3} className="text-danger" /> : awaiting ? <Clock size={54} strokeWidth={1.3} className="animate-pulse text-warning" /> : <CheckCircle2 size={54} strokeWidth={1.3} className="text-success" />}
          <h1 className="mt-4 text-[clamp(34px,5vw,56px)] leading-tight">{failed ? t('order.payment_failed') : awaiting ? t('order.confirming') : t('order.thanks')}</h1>
          <p className="mt-2 max-w-md text-ink-soft">{failed ? t('order.payment_failed_text') : awaiting ? t('order.confirming_text') : t('order.thanks_text', { n: o.number })}</p>
        </div>
      )}

      {(placed || returning) && !failed && !awaiting && <PushPrompt className="mb-10 -mt-4" />}

      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <p className="eyebrow">{t('order.title')}</p>
          {placed || returning ? <h2 className="font-display text-5xl leading-none">{o.number}</h2> : <h1 className="font-display text-5xl leading-none">{o.number}</h1>}
          <p className="mt-2 text-sm text-muted">{t('order.placed_on', { d: date(o.placed_at) })}</p>
        </div>
        <div className="flex flex-wrap gap-2"><Badge tone={statusTone(o.status)}>{o.status_label}</Badge><Badge tone={statusTone(o.payment_status)}>{t(`payment.${o.payment_status}`)}</Badge></div>
      </div>

      {(awaiting || failed) && o.can_pay !== false && (
        <div className="mt-6"><Alert tone="warning"><div className="flex flex-wrap items-center justify-between gap-3"><span>{t('order.unpaid')}</span><Button size="sm" loading={pay.isPending} onClick={() => pay.mutate()}><CreditCard size={15} />{t('order.pay_now')}</Button></div></Alert></div>
      )}

      <div className="mt-8 grid gap-8 lg:grid-cols-[minmax(0,1fr)_380px]">
        <div className="space-y-6">
          {o.fulfillments.map((f) => <FulfillmentCard key={f.id} o={o} f={f} />)}
          <TimelineCard events={timeline.data} loading={timeline.isLoading} />
        </div>

        <aside className="h-fit space-y-4">
          <TotalsCard o={o} />
          <div className="grid gap-2.5">
            {paid && <Button variant="outline" onClick={() => void invoice()}><Download size={16} />{t('order.invoice')}</Button>}
            <Button variant="outline" loading={reorder.isPending} onClick={() => reorder.mutate()}><RotateCcw size={16} />{t('order.reorder')}</Button>
            {o.can_return && <Button variant="outline" onClick={() => setReturnOpen(true)}><RotateCcw size={16} />{t('return.title')}</Button>}
            {o.can_cancel && <Button variant="ghost" className="text-danger hover:bg-danger/10" onClick={() => setCancelOpen(true)}><XCircle size={16} />{t('order.cancel')}</Button>}
            <LinkButton to="/account/support" variant="ghost">{t('order.help')}</LinkButton>
          </div>
        </aside>
      </div>

      <CancelOrderDialog id={id} open={cancelOpen} onClose={() => setCancelOpen(false)} />
      <SideDrawer open={returnOpen} onClose={() => setReturnOpen(false)} title={t('return.title')}>
        <ReturnForm o={o} onDone={() => setReturnOpen(false)} onCancel={() => setReturnOpen(false)} />
      </SideDrawer>
      <p className="mt-10 text-center text-sm"><Link to="/account/orders" className="text-brass-deep hover:underline">{t('account.orders')}</Link></p>
    </div>
  );
}
