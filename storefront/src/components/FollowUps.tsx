import { useRef, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { CalendarCheck, CalendarX2, Check, ImageOff, PackageCheck, Wrench } from 'lucide-react';
import { ApiError, post, uuid } from '../lib/api';
import { assemblyShown, offerView, pickedTime, pickFailureKey, proofKey, timeKey, type AssemblyAnswer, type DeliveryProof, type SlotOffer } from '../lib/followUps';
import { cx } from '../lib/format';
import { useI18n } from '../lib/i18n';
import { useToast } from '../lib/toast';
import type { OrderDetail } from '../lib/types';
import { Button } from './ui';

/** "We could not deliver. Choose a new time", the proof of delivery and the assembly answer on the order (docs/guides/DELIVERY_FOLLOW_UPS.md). */

function Calm({ icon, title, text, testid }: { icon: React.ReactNode; title: string; text: string; testid: string }) {
  return (
    <section className="flex gap-3 rounded-3xl bg-sand/60 p-5" data-testid={testid} role="status">
      <span aria-hidden className="mt-0.5 shrink-0 text-brass">{icon}</span>
      <div className="min-w-0"><h3 className="font-display text-xl leading-tight">{title}</h3><p className="mt-1 text-[15px] text-ink-soft [overflow-wrap:anywhere]">{text}</p></div>
    </section>
  );
}

function Offer({ offer, orderId }: { offer: SlotOffer; orderId: string }) {
  const { t, date } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const [picked, setPicked] = useState<string | null>(null);
  const [err, setErr] = useState('');
  // A new choice is a new request (its own key); the same choice sent again after a lost answer keeps its key, so it never books twice.
  const key = useRef({ for: '', id: uuid() });
  const choose = (k: string) => { setPicked(k); setErr(''); if (key.current.for !== k) key.current = { for: k, id: uuid() }; };
  const time = pickedTime(offer, picked);
  const pick = useMutation({
    mutationFn: () => post(`/orders/${orderId}/slot-offers/${offer.id}/choose`, { slot_id: time!.slot_id, date: time!.date }, { 'Idempotency-Key': key.current.id }),
    onSuccess: () => { setErr(''); toast.push(t('offer.chosen_toast', { time: time!.label })); void qc.invalidateQueries({ queryKey: ['order', orderId] }); },
    onError: (e) => {
      setErr(e instanceof ApiError && (e.status === 0 || e.code === 'session_refresh_unavailable') ? e.message : t(e instanceof ApiError ? pickFailureKey(e.status, e.code) : 'error.generic'));
      void qc.invalidateQueries({ queryKey: ['order', orderId] }); // the offer may have moved on, or a time may have been taken: show what it is now
    },
  });
  const view = offerView(offer);
  if (view === 'chosen' && offer.chosen) return <Calm testid="offer-chosen" icon={<CalendarCheck size={22} />} title={t('offer.chosen_title')} text={offer.chosen.label} />;
  if (view === 'expired') return <Calm testid="offer-expired" icon={<CalendarX2 size={22} />} title={t('offer.title')} text={t('offer.expired')} />;
  if (view === 'all_taken') return <Calm testid="offer-all-taken" icon={<CalendarX2 size={22} />} title={t('offer.title')} text={t('offer.all_taken')} />;
  if (view !== 'choose') return null;
  return (
    <section className="space-y-4 rounded-3xl border border-brass/40 bg-brass-tint/50 p-5 shadow-soft" data-testid="offer-open" aria-labelledby={`offer-${offer.id}`}>
      <div>
        <h3 id={`offer-${offer.id}`} className="font-display text-2xl leading-tight">{t('offer.title')}</h3>
        <p className="mt-1 text-[15px]">{t('offer.intro')}</p>
        <p className="mt-1 text-xs text-muted">{t('offer.until', { when: date(offer.expires_at) })}</p>
      </div>
      <div role="radiogroup" aria-label={t('offer.title')} className="grid gap-2">
        {offer.options.map((o) => {
          const k = timeKey(o); const on = picked === k;
          return (
            <button key={k} type="button" role="radio" aria-checked={on} disabled={!o.available || pick.isPending} onClick={() => choose(k)} data-testid={`offer-time-${k}`}
              className={cx('flex min-h-12 items-center gap-3 rounded-2xl border px-4 py-2.5 text-start text-[15px] transition-colors focus-visible:outline-2 focus-visible:outline-brass disabled:cursor-not-allowed', on ? 'border-brass bg-brass/10 font-medium' : 'border-line bg-paper', !o.available && 'text-muted line-through')}>
              <span aria-hidden className={cx('flex h-5 w-5 shrink-0 items-center justify-center rounded-full border', on ? 'border-brass bg-brass text-paper' : 'border-line')}>{on && <Check size={13} />}</span>
              <span className="min-w-0 flex-1"><bdi>{o.label}</bdi></span>
              {!o.available && <span className="text-xs no-underline">{t('offer.taken')}</span>}
            </button>
          );
        })}
      </div>
      {err && <p role="alert" className="rounded-2xl border border-danger/30 bg-danger/8 px-4 py-3 text-sm text-danger">{err}</p>}
      <Button size="lg" block loading={pick.isPending} disabled={!time || pick.isPending} onClick={() => pick.mutate()} data-testid="offer-confirm"><Check size={18} />{t('offer.confirm')}</Button>
    </section>
  );
}

/** One card per part whose delivery failed and was offered new times: the open ones ask, the rest are calm lines. */
export function SlotOfferCards({ o }: { o: OrderDetail }) {
  const parts = o.fulfillments.filter((f) => f.slot_offer);
  if (!parts.length) return null;
  return <div className="space-y-4">{parts.map((f) => <Offer key={f.id} offer={f.slot_offer as SlotOffer} orderId={o.id} />)}</div>;
}

/** "Delivered on {when}, received by {name}" with the photo. The photo is private and its link lasts ten minutes; the server sends no link when the shop hides it. */
export function DeliveredProof({ proof }: { proof: DeliveryProof }) {
  const { t, date } = useI18n();
  const [failed, setFailed] = useState(false);
  const line = proof.delivered_at ? t(proofKey(proof), { when: date(proof.delivered_at), name: proof.received_by ?? '' }) : null;
  if (!line && !proof.photo) return null;
  return (
    <div className="mt-4 space-y-3 rounded-2xl bg-sand/50 p-4" data-testid="delivery-proof">
      {line && <p className="flex items-start gap-2 text-[15px]"><PackageCheck size={18} className="mt-0.5 shrink-0 text-brass" aria-hidden /><span className="[overflow-wrap:anywhere]">{line}</span></p>}
      {proof.photo && (failed
        ? <p className="flex items-center gap-2 text-sm text-muted" role="status"><ImageOff size={16} aria-hidden />{t('proof.photo_failed')}</p>
        : <img src={proof.photo.url} alt={t('proof.photo_alt')} loading="lazy" referrerPolicy="no-referrer" onError={() => setFailed(true)} className="max-h-72 w-full rounded-2xl object-cover" data-testid="delivery-proof-photo" />)}
    </div>
  );
}

/** "Assembled on {when}" once the driver said it was done, or a calm line saying it was not and the branch will be in touch. Nothing while he has not answered. */
export function AssemblyLine({ a }: { a: AssemblyAnswer }) {
  const { t, date } = useI18n();
  if (!assemblyShown(a)) return null;
  return (
    <p className="mt-3 flex items-start gap-2 text-[15px]" data-testid="assembly-line"><Wrench size={18} className="mt-0.5 shrink-0 text-brass" aria-hidden />
      <span className="[overflow-wrap:anywhere]">{a.status === 'done' && a.done_at ? t('assembly.done_on', { when: date(a.done_at) }) : t('assembly.not_done', { reason: a.reason_label ?? '' })}</span></p>
  );
}
