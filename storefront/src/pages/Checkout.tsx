import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Banknote, Check, CreditCard, Gift, Lock, MapPin, Plus, Store, Tag, Truck } from 'lucide-react';
import { ApiError, get, post, uuid } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useBranch } from '../lib/branch';
import { useCart } from '../lib/cart';
import { useI18n } from '../lib/i18n';
import { useToast } from '../lib/toast';
import { useDocTitle } from '../lib/hooks';
import { addDays, cx, isoDate } from '../lib/format';
import type { Address, Branch, OrderDetail, PaymentAction, Quote, SlotDay } from '../lib/types';
import { AddressForm } from '../components/AddressForm';
import { useAuthModal } from '../components/AuthModal';
import { Alert, Button, EmptyState, Input, Modal, Pic, Segmented, Skeleton, Spinner, Textarea } from '../components/ui';

type Method = 'sadad' | 'cod' | 'pay_at_pickup';

function Step({ n, title, done, children }: { n: number; title: string; done?: boolean; children: React.ReactNode }) {
  return (
    <section className="rounded-[28px] border border-line bg-paper p-5 sm:p-7">
      <h2 className="mb-5 flex items-center gap-3 text-[26px]">
        <span className={cx('flex h-8 w-8 shrink-0 items-center justify-center rounded-full font-sans text-sm font-semibold tabular-nums', done ? 'bg-success text-white' : 'bg-primary text-bg')}>{done ? <Check size={16} /> : n}</span>{title}
      </h2>
      {children}
    </section>
  );
}

function Slots({ branchId, kind, value, date, onPick, required }: { branchId: string; kind: 'delivery' | 'pickup'; value: string | null; date: string | null; onPick: (id: string | null, date: string | null) => void; required: boolean }) {
  const { t, date: fmt } = useI18n();
  const from = isoDate(addDays(new Date(), 0));
  const to = isoDate(addDays(new Date(), 13));
  const days = useQuery({ queryKey: ['slots', kind, branchId, from], queryFn: async () => (await get<{ data: SlotDay[] }>(`/branches/${branchId}/${kind}-slots`, { from, to })).data });
  const [day, setDay] = useState<string | null>(date);
  const open = (days.data ?? []).filter((d) => d.slots.length > 0);
  // Start on the first day that still has a free slot (today's earlier windows are usually gone).
  useEffect(() => { if (!day && open[0]) setDay((open.find((d) => d.available > 0) ?? open[0]).date); }, [open, day]);
  if (days.isLoading) return <Skeleton className="h-28" />;
  if (!open.length) return <Alert tone={required ? 'warning' : 'info'}>{t(required ? 'checkout.no_slots_required' : 'checkout.no_slots')}</Alert>;
  const current = open.find((d) => d.date === day) ?? open[0];
  return (
    <div>
      <div className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-2" role="tablist" aria-label={t('checkout.pick_day')}>
        {open.map((d) => {
          const dt = new Date(`${d.date}T12:00:00`);
          const on = current.date === d.date;
          return (
            <button key={d.date} role="tab" aria-selected={on} onClick={() => { setDay(d.date); onPick(null, null); }} className={cx('flex w-[74px] shrink-0 flex-col items-center rounded-2xl border py-3 transition-colors', on ? 'border-primary bg-primary text-on-primary shadow-soft' : 'border-ink/25 bg-paper text-ink hover:border-primary', !on && d.available === 0 && 'opacity-50')}>
              <span className="text-[11px] uppercase tracking-wider opacity-80">{fmt(dt, { weekday: 'short' })}</span>
              <span className="font-display text-3xl leading-none">{dt.getDate()}</span>
              <span className="text-[11px] opacity-80">{fmt(dt, { month: 'short' })}</span>
            </button>
          );
        })}
      </div>
      <div className="mt-3 grid grid-cols-2 gap-2.5 sm:grid-cols-3" role="radiogroup" aria-label={t('checkout.pick_time')}>
        {current.slots.map((s) => {
          const on = value === s.id;
          return (
            <button key={s.id} role="radio" aria-checked={on} disabled={s.is_full} onClick={() => onPick(s.id, s.date)} className={cx('relative rounded-2xl border px-3 py-3 text-center text-sm transition-colors disabled:cursor-not-allowed disabled:opacity-50', on ? 'border-primary bg-primary text-on-primary shadow-soft' : 'border-ink/25 bg-paper text-ink hover:border-primary', s.is_full && 'line-through')}>
              {on && <Check size={14} className="absolute end-2 top-2" aria-hidden="true" />}
              <span className="block font-semibold tabular-nums" dir="ltr">{s.starts_at} – {s.ends_at}</span>
              <span className={cx('text-[11px]', on ? 'text-on-primary/85' : 'text-ink-soft')}>{s.is_full ? t('checkout.slot_full') : t('checkout.slot_left', { n: s.available })}</span>
            </button>
          );
        })}
      </div>
    </div>
  );
}

export default function Checkout() {
  const { t } = useI18n();
  const { user, loading: authLoading } = useAuth();
  const authModal = useAuthModal();
  const cart = useCart();
  const { branches, branchId: pref } = useBranch();
  const nav = useNavigate();
  const qc = useQueryClient();
  const toast = useToast();
  useDocTitle(t('checkout.title'));

  const [ful, setFul] = useState<'delivery' | 'pickup'>('delivery');
  const [addressId, setAddressId] = useState<string | null>(null);
  const [pickBranch, setPickBranch] = useState<string | null>(pref);
  const [slotId, setSlotId] = useState<string | null>(null);
  const [slotDate, setSlotDate] = useState<string | null>(null);
  const [method, setMethod] = useState<Method | null>(null);
  const [coupon, setCoupon] = useState('');
  const [couponApplied, setCouponApplied] = useState('');
  const [points, setPoints] = useState(0);
  const [notes, setNotes] = useState('');
  const [newAddr, setNewAddr] = useState(false);
  const [err, setErr] = useState('');
  const idem = useRef(uuid());

  const addresses = useQuery({ queryKey: ['addresses', user?.id], enabled: !!user, queryFn: async () => (await get<{ data: Address[] }>('/me/addresses')).data });
  useEffect(() => { if (!addressId && addresses.data?.length) setAddressId((addresses.data.find((a) => a.is_default) ?? addresses.data[0]).id); }, [addresses.data, addressId]);

  const pickupBranches = branches.filter((b) => b.pickup_enabled);
  useEffect(() => { if (ful === 'pickup' && !pickBranch && pickupBranches[0]) setPickBranch(pickupBranches[0].id); }, [ful, pickBranch, pickupBranches]);
  const deliveryBranches = branches.filter((b) => b.delivery_enabled);
  const anyDelivery = deliveryBranches.length > 0;
  const anyPickup = pickupBranches.length > 0;
  useEffect(() => { if (!anyDelivery && anyPickup) setFul('pickup'); }, [anyDelivery, anyPickup]);

  const ready = ful === 'delivery' ? !!addressId : !!pickBranch;
  const options = useMemo(() => ({
    fulfillment_type: ful,
    address_id: ful === 'delivery' ? addressId : undefined,
    branch_id: ful === 'pickup' ? pickBranch : undefined,
    ...(ful === 'delivery' ? { delivery_slot_id: slotId || undefined, delivery_date: slotDate || undefined } : { pickup_slot_id: slotId || undefined, pickup_date: slotDate || undefined }),
    payment_method: method || undefined, coupon_code: couponApplied || undefined, loyalty_points: points || undefined,
  }), [ful, addressId, pickBranch, slotId, slotDate, method, couponApplied, points]);

  const quote = useQuery({
    queryKey: ['quote', options, cart.count], enabled: !!user && cart.lines.length > 0 && ready, placeholderData: (p) => p,
    queryFn: async () => (await post<{ data: Quote }>('/checkout/quote', options)).data,
    retry: false,
  });
  const q = quote.data;
  const quoteErr = quote.error instanceof ApiError ? quote.error : null;

  // A new quote means a new order intent: a fresh idempotency key.
  useEffect(() => { idem.current = uuid(); }, [q?.quote_hash]);
  // Reset the slot when the allocation changes.
  useEffect(() => { setSlotId(null); setSlotDate(null); }, [ful, addressId, pickBranch]);
  // Pick the first available payment method.
  useEffect(() => {
    if (!q) return;
    const avail = q.payment_methods.filter((m) => m.available).map((m) => m.code);
    if (!method || !avail.includes(method)) setMethod((avail[0] as Method) ?? null);
  }, [q, method]);

  // A smaller basket can lower the redeemable maximum below what was already chosen.
  const maxPoints = q?.loyalty.max_redeemable_points;
  useEffect(() => { if (maxPoints !== undefined && points > maxPoints) setPoints(maxPoints); }, [maxPoints, points]);

  const slotBranch = q?.fulfillments[0]?.branch.id ?? (ful === 'pickup' ? pickBranch : null);
  const slotRequired = !!q?.fulfillments[0]?.slot_required;
  const showSlots = ready && !!slotBranch;

  const place = useMutation({
    mutationFn: async () => (await post<{ data: { order: OrderDetail; payment: PaymentAction } }>('/checkout/orders', { ...options, quote_hash: q!.quote_hash, customer_notes: notes.trim() || undefined, payment_method: method }, { 'Idempotency-Key': idem.current })).data,
    onSuccess: async (r) => {
      await qc.invalidateQueries({ queryKey: ['cart'] });
      if (r.payment.action === 'redirect' && r.payment.redirect_url) {
        try { localStorage.setItem('albahar.web_checkout', '1'); } catch { /* ignore */ }
        window.location.assign(r.payment.redirect_url);
        return;
      }
      nav(`/orders/${r.order.id}?placed=1`, { replace: true });
    },
    onError: (e) => {
      if (e instanceof ApiError && e.code === 'quote_changed') { toast.push(t('checkout.quote_changed'), 'info'); void quote.refetch(); return; }
      setErr(e instanceof ApiError ? e.message : t('error.generic'));
    },
  });

  if (authLoading) return <div className="wrap py-16"><Spinner /></div>;
  if (!user) {
    return <div className="wrap py-16"><EmptyState icon={<Lock size={28} />} title={t('checkout.signin_title')} text={t('checkout.signin_text')} action={<Button size="lg" onClick={() => authModal.open({ reason: t('auth.reason_checkout') })}>{t('auth.sign_in')}</Button>} /></div>;
  }
  if (!cart.loading && cart.lines.length === 0) {
    return <div className="wrap py-16"><EmptyState title={t('cart.empty')} text={t('cart.empty_text')} action={<Button size="lg" onClick={() => nav('/shop')}>{t('cart.continue')}</Button>} /></div>;
  }

  const methodMeta: Record<Method, { icon: React.ReactNode; title: string; text: string }> = {
    sadad: { icon: <CreditCard size={22} />, title: t('pay.sadad'), text: t('pay.sadad_text') },
    cod: { icon: <Banknote size={22} />, title: t('pay.cod'), text: t('pay.cod_text') },
    pay_at_pickup: { icon: <Store size={22} />, title: t('pay.pickup'), text: t('pay.pickup_text') },
  };
  const grand = q?.totals.grand_total.formatted;
  const canPlace = !!q && !!method && !place.isPending && (!slotRequired || !!slotId) && !quote.isFetching && !quoteErr;

  return (
    <div className="wrap py-8 md:py-12">
      <h1 className="text-[clamp(38px,5vw,60px)] leading-none">{t('checkout.title')}</h1>
      <p className="mt-2 flex items-center gap-2 text-sm text-muted"><Lock size={14} className="text-success" />{t('checkout.secure')}</p>

      <div className="mt-10 grid gap-8 lg:grid-cols-[minmax(0,1fr)_400px]">
        <div className="space-y-6">
          <Step n={1} title={t('checkout.how')} done={ready}>
            <Segmented label={t('checkout.how')} value={ful} onChange={setFul} options={[
              { value: 'delivery', label: t('checkout.delivery'), sub: t('checkout.delivery_sub'), disabled: !anyDelivery },
              { value: 'pickup', label: t('checkout.pickup'), sub: t('checkout.pickup_sub'), disabled: !anyPickup },
            ]} />

            {ful === 'delivery' ? (
              <div className="mt-6">
                <div className="mb-3 flex items-center justify-between"><h3 className="flex items-center gap-2 text-lg"><MapPin size={18} className="text-brass" />{t('checkout.address')}</h3>
                  <button onClick={() => setNewAddr(true)} className="flex items-center gap-1 text-sm font-medium text-brass-deep hover:underline"><Plus size={15} />{t('address.add')}</button></div>
                {addresses.isLoading ? <Skeleton className="h-24" /> : addresses.data?.length ? (
                  <div role="radiogroup" aria-label={t('checkout.address')} className="grid gap-3 sm:grid-cols-2">
                    {addresses.data.map((a) => (
                      <button key={a.id} role="radio" aria-checked={addressId === a.id} onClick={() => setAddressId(a.id)} className={cx('rounded-2xl border p-4 text-start transition-colors', addressId === a.id ? 'border-primary bg-bg ring-1 ring-ink' : 'border-line bg-bg hover:border-brass')}>
                        <span className="flex items-center justify-between"><b className="text-sm">{a.label || a.recipient_name}</b>{a.is_default && <span className="text-[11px] text-brass-deep">{t('address.default')}</span>}</span>
                        <span className="mt-1 block text-[13px] text-muted">{a.summary}{a.area ? `, ${a.area}` : ''}</span>
                        <span className="block text-[13px] text-muted">{a.recipient_name} · <bdi>{a.recipient_phone}</bdi></span>
                      </button>
                    ))}
                  </div>
                ) : <button onClick={() => setNewAddr(true)} className="w-full rounded-2xl border border-dashed border-brass bg-brass-tint/50 p-6 text-center text-sm font-medium text-brass-deep">{t('address.add_first')}</button>}
              </div>
            ) : (
              <div className="mt-6">
                <h3 className="mb-3 flex items-center gap-2 text-lg"><Store size={18} className="text-brass" />{t('checkout.pickup_from')}</h3>
                <div role="radiogroup" className="grid gap-3 sm:grid-cols-2">
                  {pickupBranches.map((b: Branch) => (
                    <button key={b.id} role="radio" aria-checked={pickBranch === b.id} onClick={() => setPickBranch(b.id)} className={cx('rounded-2xl border p-4 text-start transition-colors', pickBranch === b.id ? 'border-primary bg-bg ring-1 ring-ink' : 'border-line bg-bg hover:border-brass')}>
                      <span className="flex items-center justify-between gap-2"><b className="text-sm">{b.name}</b><span className={cx('text-[11px] font-semibold', b.opening.is_open ? 'text-success' : 'text-muted')}>{b.opening.is_open ? t('branch.open') : t('branch.closed')}</span></span>
                      <span className="text-[13px] text-muted">{b.area}{b.address ? ` · ${b.address}` : ''}</span>
                    </button>
                  ))}
                </div>
              </div>
            )}
          </Step>

          {showSlots && (
            <Step n={2} title={ful === 'delivery' ? t('checkout.when_delivery') : t('checkout.when_pickup')} done={!!slotId || !slotRequired}>
              <Slots key={`${ful}-${slotBranch}`} branchId={slotBranch} kind={ful} value={slotId} date={slotDate} required={slotRequired} onPick={(id, d) => { setSlotId(id); setSlotDate(d); }} />
              {slotRequired && !slotId && <p className="mt-3 text-sm font-medium text-warning">{t('checkout.slot_required')}</p>}
            </Step>
          )}

          <Step n={showSlots ? 3 : 2} title={t('checkout.pay')} done={!!method}>
            {!q ? <p className="text-sm text-muted">{t('checkout.pay_after')}</p> : (
              <div role="radiogroup" aria-label={t('checkout.pay')} className="space-y-3">
                {q.payment_methods.map((m) => {
                  const meta = methodMeta[m.code]; const on = method === m.code;
                  return (
                    <button key={m.code} role="radio" aria-checked={on} disabled={!m.available} onClick={() => setMethod(m.code)} className={cx('flex w-full items-start gap-4 rounded-2xl border p-4 text-start transition-colors disabled:cursor-not-allowed disabled:opacity-50', on ? 'border-primary bg-bg ring-1 ring-ink' : 'border-line bg-bg hover:border-brass')}>
                      <span className="mt-0.5 text-brass">{meta.icon}</span>
                      <span className="flex-1"><b className="block text-sm">{meta.title}</b><span className="text-[13px] text-muted">{m.available ? meta.text : t(`pay.reason.${m.reason ?? 'unavailable'}`)}</span></span>
                      <span className={cx('mt-1 flex h-5 w-5 items-center justify-center rounded-full border', on ? 'border-primary bg-primary text-bg' : 'border-line')}>{on && <Check size={12} />}</span>
                    </button>
                  );
                })}
              </div>
            )}
          </Step>

          <Step n={showSlots ? 4 : 3} title={t('checkout.extras')}>
            <form className="flex items-end gap-3" onSubmit={(e) => { e.preventDefault(); setCouponApplied(coupon.trim()); }}>
              <Input className="flex-1" label={t('checkout.coupon')} value={coupon} onChange={(e) => setCoupon(e.target.value.toUpperCase().replace(/\s/g, ''))} maxLength={48} autoComplete="off" autoCapitalize="characters" spellCheck={false} prefix={<Tag size={15} />} />
              <Button type="submit" variant="outline" disabled={!coupon.trim() || coupon.trim() === couponApplied}>{t('action.apply')}</Button>
            </form>
            {couponApplied && (
              <p className="mt-2 flex flex-wrap items-center gap-x-3 text-sm">
                {q && !q.discounts.length && !quote.isFetching && !q.warnings?.length ? <span className="text-warning">{t('checkout.coupon_none')}</span> : <span className="text-muted"><bdi>{couponApplied}</bdi></span>}
                <button type="button" onClick={() => { setCoupon(''); setCouponApplied(''); }} className="font-medium text-brass-deep underline-offset-2 hover:underline">{t('checkout.coupon_remove')}</button>
              </p>
            )}
            {q && q.loyalty.balance > 0 && (
              <div className="mt-6 rounded-2xl bg-brass-tint/60 p-4">
                <div className="flex items-center justify-between text-sm"><span className="flex items-center gap-2 font-semibold"><Gift size={16} className="text-brass-deep" />{t('checkout.points', { n: q.loyalty.balance })}</span>
                  <span className="tabular-nums text-brass-deep">{points} {t('checkout.pts')}</span></div>
                <input type="range" min={0} max={q.loyalty.max_redeemable_points} step={10} value={points} onChange={(e) => setPoints(parseInt(e.target.value))} aria-label={t('checkout.points_use')} className="mt-3 w-full accent-[var(--brass-deep)]" />
              </div>
            )}
            <Textarea className="mt-6" label={t('checkout.notes')} value={notes} onChange={(e) => setNotes(e.target.value)} maxLength={1000} />
          </Step>
        </div>

        {/* Summary */}
        <aside className="h-fit space-y-4 lg:sticky lg:top-32">
          <div className="rounded-[28px] border border-line bg-paper p-6">
            <h2 className="text-3xl">{t('checkout.summary')}</h2>
            <ul className="mt-5 max-h-64 space-y-4 overflow-y-auto pe-1">
              {cart.lines.map((l) => (
                <li key={l.key} className="flex gap-3.5">
                  <div className="relative w-16 shrink-0"><Pic src={l.image} alt={l.name} ratio="1/1" className="rounded-xl" /><span className="absolute -end-1.5 -top-1.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-primary px-1 text-[11px] font-semibold text-bg">{l.qty}</span></div>
                  <div className="min-w-0 flex-1 text-sm"><p className="line-clamp-2 font-display text-lg leading-tight">{l.name}</p>{l.variantName && <p className="text-xs text-muted">{l.variantName}</p>}</div>
                  <span className="text-sm font-semibold tabular-nums">{l.total.formatted}</span>
                </li>
              ))}
            </ul>
            {quoteErr && <div className="mt-5"><Alert tone="danger">{quoteErr.message}</Alert></div>}
            {q?.warnings?.map((w, i) => <div key={i} className="mt-4"><Alert tone="warning">{w.message ?? w.code}</Alert></div>)}
            <dl className={cx('mt-5 space-y-2.5 border-t border-line pt-5 text-sm transition-opacity', quote.isFetching && 'opacity-60')}>
              <Row k={t('cart.subtotal')} v={(q?.totals.subtotal ?? cart.subtotal).formatted} />
              {q && parseFloat(q.totals.discount.amount) > 0 && <Row k={t('checkout.discount')} v={`− ${q.totals.discount.formatted}`} good />}
              {q && parseFloat(q.totals.loyalty_discount.amount) > 0 && <Row k={t('checkout.points_discount')} v={`− ${q.totals.loyalty_discount.formatted}`} good />}
              {q ? <Row k={t('checkout.delivery_fee')} v={parseFloat(q.totals.delivery_fee.amount) > 0 ? q.totals.delivery_fee.formatted : t('checkout.free')} /> : <Row k={t('checkout.delivery_fee')} v={t('cart.at_checkout')} muted />}
              {q && parseFloat(q.totals.cod_fee.amount) > 0 && <Row k={t('checkout.cod_fee')} v={q.totals.cod_fee.formatted} />}
              {q && parseFloat(q.totals.service_fee.amount) > 0 && <Row k={t('checkout.service_fee')} v={q.totals.service_fee.formatted} />}
              {q && parseFloat(q.totals.tax.amount) > 0 && <Row k={t('checkout.tax')} v={q.totals.tax.formatted} />}
              <div className="flex items-baseline justify-between border-t border-line pt-4"><dt className="text-base font-semibold">{t('checkout.total')}</dt><dd className="font-display text-4xl tabular-nums">{grand ?? '—'}</dd></div>
            </dl>
            {q && q.loyalty.points_to_earn ? <p className="mt-3 flex items-center gap-2 text-[13px] text-brass-deep"><Gift size={14} />{t('checkout.earn', { n: q.loyalty.points_to_earn })}</p> : null}
            {err && <div className="mt-4"><Alert tone="danger">{err}</Alert></div>}
            <Button size="lg" block className="mt-6" disabled={!canPlace} loading={place.isPending} onClick={() => { setErr(''); place.mutate(); }}>
              <Lock size={16} />{method === 'sadad' ? t('checkout.pay_now') : t('checkout.place')}{grand ? ` · ${grand}` : ''}
            </Button>
            <p className="mt-3 text-center text-xs text-muted">{t('checkout.agree')} <Link to="/pages/terms" className="underline">{t('footer.terms')}</Link></p>
          </div>
          <div className="flex items-center gap-3 rounded-2xl bg-sand/70 px-5 py-4 text-sm text-ink-soft"><Truck size={20} className="shrink-0 text-brass" />{t('checkout.assure')}</div>
        </aside>
      </div>

      <Modal open={newAddr} onClose={() => setNewAddr(false)} title={t('address.add')} wide>
        <AddressForm onCancel={() => setNewAddr(false)} onSaved={(a) => { setNewAddr(false); setAddressId(a.id); void qc.invalidateQueries({ queryKey: ['addresses'] }); }} />
      </Modal>
    </div>
  );
}

function Row({ k, v, good, muted }: { k: string; v: string; good?: boolean; muted?: boolean }) {
  return <div className="flex justify-between gap-4"><dt className="text-muted">{k}</dt><dd className={cx('tabular-nums', good && 'text-success', muted ? 'text-muted' : 'font-medium')}>{v}</dd></div>;
}
