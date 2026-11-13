import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import QRCode from 'qrcode';
import { CheckCircle2, Clock, Download, MapPin, Phone, RotateCcw, XCircle, CreditCard, Store, Truck, PackageCheck } from 'lucide-react';
import { ApiError, api, get, post, uuid } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useCart } from '../lib/cart';
import { useI18n } from '../lib/i18n';
import { useToast } from '../lib/toast';
import { useDocTitle } from '../lib/hooks';
import { cx, mapsHref, telHref } from '../lib/format';
import type { OrderDetail, PaymentAction, TimelineEvent } from '../lib/types';
import { useAuthModal } from '../components/AuthModal';
import { Alert, Badge, Button, EmptyState, LinkButton, Modal, Pic, Select, Skeleton, Spinner, Textarea } from '../components/ui';

export const statusTone = (s: string): 'success' | 'danger' | 'warning' | 'brass' | 'sand' => {
  if (['completed', 'delivered', 'picked_up', 'paid'].includes(s)) return 'success';
  if (['cancelled', 'failed', 'expired', 'refunded', 'rejected'].includes(s)) return 'danger';
  if (['pending_payment', 'pending', 'processing', 'on_hold'].includes(s)) return 'warning';
  return 'brass';
};

function PickupPass({ orderId, fulfillmentId }: { orderId: string; fulfillmentId: string }) {
  const { t, date } = useI18n();
  const pass = useQuery({ queryKey: ['pass', orderId, fulfillmentId], retry: false, queryFn: async () => (await get<{ data: { qr_payload: string; expires_at: string | null; branch: { name: string } } }>(`/orders/${orderId}/fulfillments/${fulfillmentId}/pickup-pass`)).data });
  const [svg, setSvg] = useState('');
  useEffect(() => { if (pass.data) QRCode.toString(pass.data.qr_payload, { type: 'svg', margin: 1, width: 220, color: { dark: '#2a1a20', light: '#ffffff' } }).then(setSvg).catch(() => setSvg('')); }, [pass.data]);
  if (!pass.data) return null;
  return (
    <div className="mt-4 flex flex-col items-center gap-3 rounded-3xl border border-brass/40 bg-brass-tint/50 p-6 text-center">
      <p className="eyebrow">{t('order.pickup_pass')}</p>
      <div className="rounded-2xl bg-white p-3" dangerouslySetInnerHTML={{ __html: svg }} aria-label={t('order.pickup_pass')} role="img" />
      <p className="text-sm text-ink-soft">{t('order.pickup_pass_text', { branch: pass.data.branch.name })}</p>
      {pass.data.expires_at && <p className="text-xs text-muted">{t('order.valid_until', { d: date(pass.data.expires_at) })}</p>}
    </div>
  );
}

export default function Order() {
  const { id = '' } = useParams();
  const [sp, setSp] = useSearchParams();
  const { t, date } = useI18n();
  const nav = useNavigate();
  const qc = useQueryClient();
  const toast = useToast();
  const cart = useCart();
  const { user, loading: authLoading } = useAuth();
  const authModal = useAuthModal();
  const placed = sp.get('placed') === '1';
  const returning = !!sp.get('payment');
  const [cancelOpen, setCancelOpen] = useState(false);
  const [reason, setReason] = useState('changed_mind');
  const [note, setNote] = useState('');
  const polls = useRef(0);

  // After SADAD redirects back, the callback may still be in flight: poll for up to ~90 s.
  const order = useQuery({
    queryKey: ['order', id], enabled: !!user, queryFn: async () => (await get<{ data: OrderDetail }>(`/orders/${id}`)).data,
    refetchInterval: (q) => (returning && q.state.data?.payment_status === 'pending' && polls.current++ < 30 ? 3000 : false),
  });
  const timeline = useQuery({ queryKey: ['timeline', id], enabled: !!user, queryFn: async () => (await get<{ data: TimelineEvent[] }>(`/orders/${id}/timeline`)).data, refetchInterval: 60_000 });
  const o = order.data;
  useDocTitle(o ? `${t('order.title')} ${o.number}` : t('order.title'));
  useEffect(() => { try { localStorage.removeItem('albahar.web_checkout'); } catch { /* ignore */ } }, []);

  const cancel = useMutation({
    mutationFn: () => post(`/orders/${id}/cancel`, { reason_code: reason, reason: note || undefined }),
    onSuccess: () => { toast.push(t('order.cancel_ok')); setCancelOpen(false); void qc.invalidateQueries({ queryKey: ['order', id] }); void qc.invalidateQueries({ queryKey: ['orders'] }); },
    onError: (e) => toast.push(e instanceof ApiError ? e.message : t('error.generic'), 'error'),
  });
  const pay = useMutation({
    mutationFn: async () => (await post<{ data: PaymentAction }>(`/orders/${id}/payments`, {}, { 'Idempotency-Key': uuid() })).data,
    onSuccess: (a) => { if (a.redirect_url) { try { localStorage.setItem('albahar.web_checkout', '1'); } catch { /* ignore */ } window.location.assign(a.redirect_url); } },
    onError: (e) => toast.push(e instanceof ApiError ? e.message : t('error.generic'), 'error'),
  });
  const reorder = useMutation({
    mutationFn: () => post(`/orders/${id}/reorder`, {}),
    onSuccess: async () => { await qc.invalidateQueries({ queryKey: ['cart'] }); cart.openDrawer(); },
    onError: (e) => toast.push(e instanceof ApiError ? e.message : t('error.generic'), 'error'),
  });
  const invoice = async () => {
    try {
      const res = await fetch(`/api/v1/orders/${id}/invoice`, { headers: { Authorization: `Bearer ${JSON.parse(localStorage.getItem('albahar.tokens') || '{}').access_token}`, Accept: 'application/pdf,text/html' } });
      if (!res.ok) throw new Error();
      const url = URL.createObjectURL(await res.blob());
      window.open(url, '_blank', 'noopener');
      setTimeout(() => URL.revokeObjectURL(url), 60_000);
    } catch { toast.push(t('order.invoice_unavailable'), 'error'); }
  };

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
  void api; void setSp;

  return (
    <div className="wrap py-8 md:py-12">
      {(placed || returning) && (
        <div className="rise mb-10 flex flex-col items-center rounded-[32px] bg-[linear-gradient(135deg,var(--brass-tint),var(--sand))] px-6 py-12 text-center">
          {failed ? <XCircle size={54} strokeWidth={1.3} className="text-danger" /> : awaiting ? <Clock size={54} strokeWidth={1.3} className="animate-pulse text-warning" /> : <CheckCircle2 size={54} strokeWidth={1.3} className="text-success" />}
          <h1 className="mt-4 text-[clamp(34px,5vw,56px)] leading-tight">{failed ? t('order.payment_failed') : awaiting ? t('order.confirming') : t('order.thanks')}</h1>
          <p className="mt-2 max-w-md text-ink-soft">{failed ? t('order.payment_failed_text') : awaiting ? t('order.confirming_text') : t('order.thanks_text', { n: o.number })}</p>
        </div>
      )}

      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <p className="eyebrow">{t('order.title')}</p>
          <h2 className="font-display text-5xl leading-none">{o.number}</h2>
          <p className="mt-2 text-sm text-muted">{t('order.placed_on', { d: date(o.placed_at) })}</p>
        </div>
        <div className="flex flex-wrap gap-2"><Badge tone={statusTone(o.status)}>{o.status_label}</Badge><Badge tone={statusTone(o.payment_status)}>{t(`payment.${o.payment_status}`)}</Badge></div>
      </div>

      {(awaiting || failed) && o.can_pay !== false && (
        <div className="mt-6"><Alert tone="warning"><div className="flex flex-wrap items-center justify-between gap-3"><span>{t('order.unpaid')}</span><Button size="sm" loading={pay.isPending} onClick={() => pay.mutate()}><CreditCard size={15} />{t('order.pay_now')}</Button></div></Alert></div>
      )}

      <div className="mt-8 grid gap-8 lg:grid-cols-[minmax(0,1fr)_380px]">
        <div className="space-y-6">
          {o.fulfillments.map((f) => (
            <section key={f.id} className="rounded-[28px] border border-line bg-paper p-6">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <h3 className="flex items-center gap-2 text-2xl">{f.type === 'pickup' ? <Store size={20} className="text-brass" /> : <Truck size={20} className="text-brass" />}{f.type === 'pickup' ? t('order.pickup_at', { b: f.branch.name }) : t('order.delivery_from', { b: f.branch.name })}</h3>
                <Badge tone={statusTone(f.status)}>{f.status_label}</Badge>
              </div>
              {f.slot && <p className="mt-2 flex items-center gap-2 text-sm text-ink-soft"><Clock size={15} className="text-brass" /><bdi>{f.slot.date} · {f.slot.starts_at}–{f.slot.ends_at}</bdi></p>}
              <ul className="mt-5 divide-y divide-line">
                {o.items.filter((i) => (i as any).fulfillment_id === f.id).map((i) => (
                  <li key={i.id} className="flex gap-4 py-4">
                    <div className="w-20 shrink-0"><Pic src={i.image_url} alt={i.name} ratio="1/1" className="rounded-2xl" /></div>
                    <div className="min-w-0 flex-1"><p className="font-display text-xl leading-tight">{i.name}</p>{i.variant_name && <p className="text-sm text-muted">{i.variant_name}</p>}<p className="text-sm text-muted">{i.quantity} × {i.unit_price.formatted}</p></div>
                    <p className="font-semibold tabular-nums">{i.line_total.formatted}</p>
                  </li>
                ))}
              </ul>
              <div className="mt-3 flex flex-wrap gap-4 text-sm">
                {f.branch.phone && <a href={telHref(f.branch.phone)} className="flex items-center gap-1.5 text-brass-deep hover:underline"><Phone size={14} />{f.branch.phone}</a>}
                {mapsHref(f.branch.latitude, f.branch.longitude, f.branch.name) && <a href={mapsHref(f.branch.latitude, f.branch.longitude, f.branch.name)} target="_blank" rel="noreferrer" className="flex items-center gap-1.5 text-brass-deep hover:underline"><MapPin size={14} />{t('order.directions')}</a>}
              </div>
              {f.type === 'pickup' && f.pickup && ['ready', 'partially_collected'].includes(f.pickup.status) && <PickupPass orderId={o.id} fulfillmentId={f.id} />}
            </section>
          ))}

          <section className="rounded-[28px] border border-line bg-paper p-6">
            <h3 className="mb-5 flex items-center gap-2 text-2xl"><PackageCheck size={20} className="text-brass" />{t('order.progress')}</h3>
            <ol className="relative ms-3 space-y-6 border-s border-line ps-6">
              {(timeline.data ?? []).map((e, i, a) => (
                <li key={i} className="relative">
                  <span className={cx('absolute -start-[31px] top-1 h-3 w-3 rounded-full ring-4 ring-paper', i === a.length - 1 ? 'bg-brass' : 'bg-line')} />
                  <p className={cx('font-medium', i === a.length - 1 ? 'text-ink' : 'text-ink-soft')}>{e.label}</p><p className="text-xs text-muted">{date(e.at)}</p>
                </li>
              ))}
              {timeline.isLoading && <Skeleton className="h-10" />}
            </ol>
          </section>
        </div>

        <aside className="h-fit space-y-4">
          <section className="rounded-[28px] border border-line bg-paper p-6">
            <h3 className="text-2xl">{t('checkout.summary')}</h3>
            <dl className="mt-4 space-y-2.5 text-sm">
              {(['subtotal', 'discount', 'loyalty_discount', 'delivery_fee', 'service_fee', 'cod_fee', 'tax'] as const).map((k) => {
                const m = o.totals[k]; if (!m || parseFloat(m.amount) === 0) return null;
                const neg = k === 'discount' || k === 'loyalty_discount';
                return <div key={k} className="flex justify-between"><dt className="text-muted">{t(`order.total.${k}`)}</dt><dd className={cx('tabular-nums', neg && 'text-success')}>{neg ? '− ' : ''}{m.formatted}</dd></div>;
              })}
              <div className="flex items-baseline justify-between border-t border-line pt-3"><dt className="font-semibold">{t('checkout.total')}</dt><dd className="font-display text-3xl tabular-nums">{o.totals.grand_total.formatted}</dd></div>
              {o.totals.paid && parseFloat(o.totals.paid.amount) > 0 && <div className="flex justify-between text-success"><dt>{t('order.paid')}</dt><dd className="tabular-nums">{o.totals.paid.formatted}</dd></div>}
              {o.totals.refunded && parseFloat(o.totals.refunded.amount) > 0 && <div className="flex justify-between"><dt className="text-muted">{t('order.refunded')}</dt><dd className="tabular-nums">{o.totals.refunded.formatted}</dd></div>}
            </dl>
            <p className="mt-4 text-xs text-muted">{t('order.method')}: {t(`pay.${o.payment_method === 'pay_at_pickup' ? 'pickup' : o.payment_method}`)}</p>
            {o.customer_notes && <p className="mt-3 rounded-2xl bg-sand/60 p-3 text-sm text-ink-soft">“{o.customer_notes}”</p>}
          </section>
          <div className="grid gap-2.5">
            {paid && <Button variant="outline" onClick={invoice}><Download size={16} />{t('order.invoice')}</Button>}
            <Button variant="outline" loading={reorder.isPending} onClick={() => reorder.mutate()}><RotateCcw size={16} />{t('order.reorder')}</Button>
            {o.can_cancel && <Button variant="ghost" className="text-danger hover:bg-danger/10" onClick={() => setCancelOpen(true)}><XCircle size={16} />{t('order.cancel')}</Button>}
            <LinkButton to="/account/support" variant="ghost">{t('order.help')}</LinkButton>
          </div>
        </aside>
      </div>

      <Modal open={cancelOpen} onClose={() => setCancelOpen(false)} title={t('order.cancel_title')}>
        <p className="mb-4 text-sm text-muted">{t('order.cancel_text')}</p>
        <div className="space-y-4">
          <Select label={t('order.cancel_reason')} value={reason} onChange={(e) => setReason(e.target.value)}>
            {['changed_mind', 'ordered_by_mistake', 'found_better_price', 'delivery_too_long', 'payment_issue', 'other'].map((r) => <option key={r} value={r}>{t(`cancel.${r}`)}</option>)}
          </Select>
          <Textarea label={t('order.cancel_note')} value={note} onChange={(e) => setNote(e.target.value)} maxLength={500} />
          <div className="flex gap-3"><Button variant="danger" loading={cancel.isPending} onClick={() => cancel.mutate()}>{t('order.cancel_confirm')}</Button><Button variant="ghost" onClick={() => setCancelOpen(false)}>{t('order.keep')}</Button></div>
        </div>
      </Modal>
      <p className="mt-10 text-center text-sm"><Link to="/account/orders" className="text-brass-deep hover:underline">{t('account.orders')}</Link></p>
    </div>
  );
}
