import { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import QRCode from 'qrcode';
import { Clock, MapPin, PackageCheck, Phone, Store, Truck } from 'lucide-react';
import { ApiError, get, post, uuid } from '../lib/api';
import { useCart } from '../lib/cart';
import { useI18n } from '../lib/i18n';
import { useLivePoll } from '../lib/realtime';
import { useToast } from '../lib/toast';
import { cx, mapsHref, telHref } from '../lib/format';
import type { OrderDetail, PaymentAction, TimelineEvent } from '../lib/types';
import { Badge, Pic, QtyStepper, Select, Skeleton, Textarea } from './ui';
import { Button } from './ui';
import { ConfirmDialog } from './Dialogs';

/** The pieces of an order, shared by the order page (/orders/:id) and the order side window (?order=<id>). */

export const statusTone = (s: string): 'success' | 'danger' | 'warning' | 'brass' | 'sand' => {
  if (['completed', 'delivered', 'picked_up', 'paid'].includes(s)) return 'success';
  if (['cancelled', 'failed', 'expired', 'refunded', 'rejected'].includes(s)) return 'danger';
  if (['pending_payment', 'pending', 'processing', 'on_hold'].includes(s)) return 'warning';
  return 'brass';
};

export function PickupPass({ orderId, fulfillmentId }: { orderId: string; fulfillmentId: string }) {
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

/** The order and its timeline. Live updates (socket) invalidate these keys; without a socket they poll. */
export function useOrderQuery(id: string, enabled: boolean, returning = false) {
  const polls = useRef(0);
  const poll = useLivePoll();
  const order = useQuery({
    queryKey: ['order', id], enabled, queryFn: async () => (await get<{ data: OrderDetail }>(`/orders/${id}`)).data,
    // Payment callback in flight: poll fast. Otherwise updates arrive live; poll only while the socket is down and the order is still open.
    refetchInterval: (q) => (returning && q.state.data?.payment_status === 'pending' && polls.current++ < 30 ? 3000 : (['completed', 'cancelled'].includes(q.state.data?.status ?? '') ? false : poll)),
  });
  const timeline = useQuery({ queryKey: ['timeline', id], enabled, queryFn: async () => (await get<{ data: TimelineEvent[] }>(`/orders/${id}/timeline`)).data, refetchInterval: 60_000 });
  return { order, timeline };
}

const failMsg = (e: unknown, fallback: string) => (e instanceof ApiError ? e.message : fallback);

export function usePay(id: string) {
  const { t } = useI18n();
  const toast = useToast();
  return useMutation({
    mutationFn: async () => (await post<{ data: PaymentAction }>(`/orders/${id}/payments`, {}, { 'Idempotency-Key': uuid() })).data,
    onSuccess: (a) => { if (a.redirect_url) { try { localStorage.setItem('albahar.web_checkout', '1'); } catch { /* ignore */ } window.location.assign(a.redirect_url); } },
    onError: (e) => toast.push(failMsg(e, t('error.generic')), 'error'),
  });
}
export function useReorder(id: string) {
  const { t } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const cart = useCart();
  return useMutation({
    mutationFn: () => post(`/orders/${id}/reorder`, {}),
    onSuccess: async () => { await qc.invalidateQueries({ queryKey: ['cart'] }); cart.openDrawer(); },
    onError: (e) => toast.push(failMsg(e, t('error.generic')), 'error'),
  });
}
export function useInvoice(id: string) {
  const { t } = useI18n();
  const toast = useToast();
  return async () => {
    try {
      const res = await fetch(`/api/v1/orders/${id}/invoice`, { headers: { Authorization: `Bearer ${JSON.parse(localStorage.getItem('albahar.tokens') || '{}').access_token}`, Accept: 'application/pdf,text/html' } });
      if (!res.ok) throw new Error();
      const url = URL.createObjectURL(await res.blob());
      window.open(url, '_blank', 'noopener');
      setTimeout(() => URL.revokeObjectURL(url), 60_000);
    } catch { toast.push(t('order.invoice_unavailable'), 'error'); }
  };
}

/** "Cancel this order?": a decision, so a centred question with the reason inside, Cancel focused. */
export function CancelOrderDialog({ id, open, onClose }: { id: string; open: boolean; onClose: () => void }) {
  const { t } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const [reason, setReason] = useState('changed_mind');
  const [note, setNote] = useState('');
  return (
    <ConfirmDialog open={open} onClose={onClose} title={t('order.cancel_title')} body={t('order.cancel_text')} confirmLabel={t('order.cancel_confirm')} cancelLabel={t('order.keep')}
      onConfirm={async () => {
        await post(`/orders/${id}/cancel`, { reason_code: reason, reason: note || undefined });
        toast.push(t('order.cancel_ok'));
        onClose();
        void qc.invalidateQueries({ queryKey: ['order', id] }); void qc.invalidateQueries({ queryKey: ['orders'] });
      }}>
      <div className="space-y-4">
        <Select label={t('order.cancel_reason')} value={reason} onChange={(e) => setReason(e.target.value)}>
          {['changed_mind', 'ordered_by_mistake', 'found_better_price', 'delivery_too_long', 'payment_issue', 'other'].map((r) => <option key={r} value={r}>{t(`cancel.${r}`)}</option>)}
        </Select>
        <Textarea label={t('order.cancel_note')} value={note} onChange={(e) => setNote(e.target.value)} maxLength={500} className="[&_textarea]:min-h-20" />
      </div>
    </ConfirmDialog>
  );
}

const card = (compact?: boolean) => (compact ? 'rounded-3xl border border-line bg-paper p-4' : 'rounded-[28px] border border-line bg-paper p-6');

export function FulfillmentCard({ o, f, compact }: { o: OrderDetail; f: OrderDetail['fulfillments'][number]; compact?: boolean }) {
  const { t } = useI18n();
  return (
    <section className={card(compact)}>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h3 className={cx('flex items-center gap-2', compact ? 'text-xl' : 'text-2xl')}>{f.type === 'pickup' ? <Store size={20} className="text-brass" /> : <Truck size={20} className="text-brass" />}{f.type === 'pickup' ? t('order.pickup_at', { b: f.branch.name }) : t('order.delivery_from', { b: f.branch.name })}</h3>
        <Badge tone={statusTone(f.status)}>{f.status_label}</Badge>
      </div>
      {f.slot && <p className="mt-2 flex items-center gap-2 text-sm text-ink-soft"><Clock size={15} className="text-brass" /><bdi>{f.slot.date} · {f.slot.starts_at}–{f.slot.ends_at}</bdi></p>}
      <ul className="mt-4 divide-y divide-line">
        {o.items.filter((i) => i.fulfillment_id === f.id).map((i) => (
          <li key={i.id} className="flex gap-4 py-4">
            <div className={compact ? 'w-16 shrink-0' : 'w-20 shrink-0'}><Pic src={i.image_url} alt={i.name} ratio="1/1" className="rounded-2xl" /></div>
            <div className="min-w-0 flex-1"><p className={cx('font-display leading-tight', compact ? 'text-lg' : 'text-xl')}>{i.name}</p>{i.variant_name && <p className="text-sm text-muted">{i.variant_name}</p>}<p className="text-sm text-muted">{i.quantity} × {i.unit_price.formatted}</p></div>
            <p className="font-semibold tabular-nums">{i.line_total.formatted}</p>
          </li>
        ))}
      </ul>
      <div className="mt-1 flex flex-wrap gap-x-5 text-sm">
        {f.branch.phone && <a href={telHref(f.branch.phone)} className="flex min-h-11 items-center gap-1.5 text-brass-deep hover:underline"><Phone size={14} /><bdi>{f.branch.phone}</bdi></a>}
        {mapsHref(f.branch.latitude, f.branch.longitude, f.branch.name) && <a href={mapsHref(f.branch.latitude, f.branch.longitude, f.branch.name)} target="_blank" rel="noreferrer" className="flex min-h-11 items-center gap-1.5 text-brass-deep hover:underline"><MapPin size={14} />{t('order.directions')}</a>}
      </div>
      {f.type === 'pickup' && f.pickup && ['ready', 'partially_collected'].includes(f.pickup.status) && <PickupPass orderId={o.id} fulfillmentId={f.id} />}
    </section>
  );
}

export function TimelineCard({ events, loading, compact }: { events: TimelineEvent[] | undefined; loading: boolean; compact?: boolean }) {
  const { t, date } = useI18n();
  return (
    <section className={card(compact)}>
      <h3 className={cx('flex items-center gap-2', compact ? 'mb-4 text-xl' : 'mb-5 text-2xl')}><PackageCheck size={20} className="text-brass" />{t('order.progress')}</h3>
      <ol className="relative ms-3 space-y-6 border-s border-line ps-6">
        {(events ?? []).map((e, i, a) => (
          <li key={i} className="relative" aria-current={i === a.length - 1 ? 'step' : undefined}>
            <span className={cx('absolute -start-[31px] top-1 h-3 w-3 rounded-full ring-4 ring-paper', i === a.length - 1 ? 'bg-brass' : 'bg-line')} />
            <p className={cx('font-medium', i === a.length - 1 ? 'text-ink' : 'text-ink-soft')}>{e.label}</p><p className="text-xs text-muted">{date(e.at)}</p>
          </li>
        ))}
        {loading && <Skeleton className="h-10" />}
      </ol>
    </section>
  );
}

export function TotalsCard({ o, compact }: { o: OrderDetail; compact?: boolean }) {
  const { t } = useI18n();
  return (
    <section className={card(compact)}>
      <h3 className={compact ? 'text-xl' : 'text-2xl'}>{t('checkout.summary')}</h3>
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
  );
}

const RETURN_REASONS = ['damaged', 'wrong_item', 'not_as_described', 'changed_mind', 'other'] as const;

/** "Request a return": pick the pieces and a reason. The server checks the return window and what was handed over. */
export function ReturnForm({ o, onDone, onCancel }: { o: OrderDetail; onDone: () => void; onCancel: () => void }) {
  const { t } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const rows = o.items.map((i) => ({ item: i, max: Math.max(0, i.quantity - (i.quantity_cancelled ?? 0) - (i.quantity_returned ?? 0)) })).filter((r) => r.max > 0);
  const [qty, setQty] = useState<Record<string, number>>({});
  const [reason, setReason] = useState<(typeof RETURN_REASONS)[number]>('damaged');
  const [note, setNote] = useState('');
  const [err, setErr] = useState('');
  const picked = Object.entries(qty).filter(([, n]) => n > 0);
  const send = useMutation({
    mutationFn: () => post(`/orders/${o.id}/returns`, { items: picked.map(([order_item_id, quantity]) => ({ order_item_id, quantity })), reason_code: reason, reason: note.trim() || undefined }),
    onSuccess: () => { toast.push(t('return.sent')); void qc.invalidateQueries({ queryKey: ['order', o.id] }); onDone(); },
    onError: (e) => setErr(failMsg(e, t('error.generic'))),
  });
  return (
    <form onSubmit={(e) => { e.preventDefault(); setErr(''); if (picked.length && !send.isPending) send.mutate(); }} className="flex min-h-full flex-col">
      <div className="flex-1 space-y-6 p-6">
        <p className="text-sm text-muted">{t('return.intro')}</p>
        {err && <p role="alert" className="rounded-2xl border border-danger/30 bg-danger/8 px-4 py-3 text-sm text-danger">{err}</p>}
        {rows.length === 0 ? <p className="rounded-2xl bg-sand/60 p-5 text-center text-muted">{t('return.none')}</p> : (
          <ul className="divide-y divide-line rounded-3xl border border-line bg-paper px-4">
            {rows.map(({ item, max }) => (
              <li key={item.id} className="flex items-center gap-3 py-3">
                <div className="w-14 shrink-0"><Pic src={item.image_url} alt="" ratio="1/1" className="rounded-xl" /></div>
                <div className="min-w-0 flex-1"><p className="line-clamp-2 text-[15px] font-medium leading-snug">{item.name}</p>{item.variant_name && <p className="text-xs text-muted">{item.variant_name}</p>}<p className="text-xs text-muted">{t('return.of', { n: max })}</p></div>
                <QtyStepper value={qty[item.id] ?? 0} min={0} max={max} onChange={(n) => setQty({ ...qty, [item.id]: n })} />
              </li>
            ))}
          </ul>
        )}
        <Select label={t('return.reason')} value={reason} onChange={(e) => setReason(e.target.value as (typeof RETURN_REASONS)[number])}>
          {RETURN_REASONS.map((r) => <option key={r} value={r}>{t(`return.reason.${r}`)}</option>)}
        </Select>
        <Textarea label={t('return.note')} value={note} onChange={(e) => setNote(e.target.value)} maxLength={1000} className="[&_textarea]:min-h-24" />
      </div>
      <div className="sticky bottom-0 border-t border-line bg-paper px-6 pt-4 pb-[max(16px,env(safe-area-inset-bottom))]">
        <Button type="submit" size="lg" block loading={send.isPending} disabled={picked.length === 0}>{picked.length ? t('return.submit_n', { n: picked.reduce((s, [, n]) => s + n, 0) }) : t('return.submit')}</Button>
        <Button type="button" variant="ghost" block className="mt-1.5" onClick={onCancel}>{t('action.cancel')}</Button>
      </div>
    </form>
  );
}
