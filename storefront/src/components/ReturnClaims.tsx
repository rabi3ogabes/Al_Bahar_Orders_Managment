import { useEffect, useMemo, useRef, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Camera, Check, ImageOff, X } from 'lucide-react';
import { api, ApiError, post, uuid } from '../lib/api';
import { useI18n } from '../lib/i18n';
import { useToast } from '../lib/toast';
import { cx } from '../lib/format';
import {
  CLAIM_TYPES, MAX_CLAIM_NOTE, MAX_CLAIM_PHOTOS, PHOTO_MIMES, claimIsOpen, claimNeedsPhoto, claimStepKey, photoProblem, refundHowKey, returnFailureKey, returnStatusKey, returnTone, returnableOf,
  type ClaimStep, type ClaimType, type ReturnClaim,
} from '../lib/returns';
import type { OrderDetail } from '../lib/types';
import { ConfirmDialog, Modal } from './Dialogs';
import { Badge, Button, Pic, QtyStepper, Textarea } from './ui';

/** Return and damage claims on the order: a compact timeline per claim, the photos, adding more while it waits, cancelling, and the form that starts one. */

const day = (date: (iso: string, o?: Intl.DateTimeFormatOptions) => string, iso?: string | null) => (iso ? date(iso, { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'Asia/Qatar' }) : '');

/** A claim photo is private: it is fetched with the customer's sign-in and shown from memory, never linked. */
function useClaimImage(path: string): { url: string | null; failed: boolean } {
  const [state, setState] = useState<{ url: string | null; failed: boolean }>({ url: null, failed: false });
  useEffect(() => {
    let alive = true;
    let made: string | null = null;
    setState({ url: null, failed: false });
    api<Blob>(`/${path}`, { blob: true })
      .then((b) => { if (!alive) return; made = URL.createObjectURL(b); setState({ url: made, failed: false }); })
      .catch(() => { if (alive) setState({ url: null, failed: true }); });
    return () => { alive = false; if (made) URL.revokeObjectURL(made); };
  }, [path]);
  return state;
}

function Thumb({ path, n, onOpen }: { path: string; n: number; onOpen: (url: string) => void }) {
  const { t } = useI18n();
  const { url, failed } = useClaimImage(path);
  return (
    <button type="button" disabled={!url} onClick={() => url && onOpen(url)} aria-label={t('return.photos.open', { n })}
      className="relative block aspect-square w-full overflow-hidden rounded-2xl border border-line bg-sand focus-visible:outline-2 focus-visible:outline-brass">
      {url ? <img src={url} alt={t('return.photos.alt', { n })} className="h-full w-full object-cover" />
        : <span className="flex h-full w-full items-center justify-center text-muted" role={failed ? 'img' : 'status'} aria-label={failed ? t('return.photos.failed') : t('return.photos.loading')}>{failed ? <ImageOff size={20} /> : <span className="skeleton h-full w-full" aria-hidden />}</span>}
    </button>
  );
}

function ClaimPhotos({ claim }: { claim: ReturnClaim }) {
  const { t } = useI18n();
  const [big, setBig] = useState<string | null>(null);
  if (!claim.photos?.length) return null;
  return (
    <div>
      <p className="eyebrow mb-2">{t('return.photos.title')}</p>
      <ul className="grid grid-cols-4 gap-2 sm:grid-cols-5">{(claim.photos ?? []).map((p, i) => <li key={p.id}><Thumb path={p.path} n={i + 1} onOpen={setBig} /></li>)}</ul>
      <Modal open={!!big} onClose={() => setBig(null)} title={t('return.photos.title')} wide>
        {big && <img src={big} alt={t('return.photos.alt', { n: 1 })} className="mx-auto max-h-[75vh] w-auto max-w-full rounded-2xl object-contain" />}
      </Modal>
    </div>
  );
}

/** What a step says under its label: the team's words, how the item comes back, or the refund amount and how it goes back. */
function stepDetail(step: ClaimStep, claim: ReturnClaim, t: (k: string, v?: Record<string, string | number>) => string): string | null {
  if (step.key === 'branch') return [claim.branch ? t('return.step.branch_detail', { branch: claim.branch.name }) : null, step.detail].filter(Boolean).join(' ') || null;
  if (step.key === 'refunded' && claim.refund) {
    return t(claim.refund.status === 'processed' ? 'return.step.refund_done' : 'return.step.refund_wait', { amount: claim.refund.amount.formatted, how: t(refundHowKey(claim.refund.method)) });
  }
  return step.detail;
}

function ClaimTimeline({ claim }: { claim: ReturnClaim }) {
  const { t, date } = useI18n();
  return (
    <ol className="relative" aria-label={t('return.claim.timeline')} data-testid="claim-timeline">
      {claim.timeline.map((s, i) => {
        const last = i === claim.timeline.length - 1;
        const detail = stepDetail(s, claim, t);
        const tone = s.state === 'done' ? 'bg-brass text-paper' : s.state === 'current' ? 'border-2 border-brass bg-paper text-brass-deep' : s.state === 'stopped' ? 'bg-ink-soft text-paper' : 'border border-line bg-paper text-muted';
        return (
          <li key={s.key} data-step={s.key} data-state={s.state} className="relative flex gap-3 pb-4 last:pb-0" aria-current={s.state === 'current' ? 'step' : undefined}>
            {!last && <span aria-hidden className={cx('absolute start-[11px] top-6 bottom-0 w-px', s.state === 'done' ? 'bg-brass/50' : 'bg-line')} />}
            <span aria-hidden className={cx('relative z-10 mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full', tone)}>
              {s.state === 'done' ? <Check size={14} strokeWidth={3} /> : s.state === 'stopped' ? <X size={14} strokeWidth={3} /> : <span className={cx('h-1.5 w-1.5 rounded-full bg-current', s.state === 'current' && 'motion-safe:animate-pulse')} />}
            </span>
            <div className="min-w-0 flex-1">
              <p className={cx('text-[15px] leading-snug', s.state === 'upcoming' ? 'text-muted' : 'font-medium text-ink')}>{t(claimStepKey(s.key))}{s.at && <span className="ms-2 text-xs font-normal text-muted tabular-nums">{day(date, s.at)}</span>}</p>
              {detail && <p className="mt-0.5 text-sm text-ink-soft [overflow-wrap:anywhere]" dir="auto">{detail}</p>}
            </div>
          </li>
        );
      })}
    </ol>
  );
}

/** Photo picker: the chosen files with previews and a remove button; the camera or the gallery, whichever the phone offers. */
function PhotoPicker({ files, onChange, already = 0, required, error }: { files: File[]; onChange: (f: File[]) => void; already?: number; required?: boolean; error?: string }) {
  const { t } = useI18n();
  const input = useRef<HTMLInputElement>(null);
  const urls = useMemo(() => files.map((f) => URL.createObjectURL(f)), [files]);
  useEffect(() => () => urls.forEach((u) => URL.revokeObjectURL(u)), [urls]);
  const room = MAX_CLAIM_PHOTOS - already - files.length;
  return (
    <div>
      <div className="flex items-baseline justify-between gap-3">
        <p className="eyebrow">{t('return.photos.title')}</p>
        <p className="text-xs text-muted tabular-nums">{t('return.photos.count', { n: already + files.length, max: MAX_CLAIM_PHOTOS })}</p>
      </div>
      <p className="mt-0.5 text-xs text-muted">{required ? t('return.photos.required') : t('return.photos.optional')}</p>
      {files.length > 0 && (
        <ul className="mt-3 grid grid-cols-4 gap-2 sm:grid-cols-5">
          {files.map((f, i) => (
            <li key={`${f.name}-${f.size}-${i}`} className="relative">
              <img src={urls[i]} alt={t('return.photos.alt', { n: already + i + 1 })} className="aspect-square w-full rounded-2xl border border-line object-cover" />
              <button type="button" onClick={() => onChange(files.filter((_, j) => j !== i))} aria-label={t('return.photos.remove')}
                className="absolute -end-1.5 -top-1.5 flex h-8 w-8 items-center justify-center rounded-full bg-ink text-paper shadow-soft focus-visible:outline-2 focus-visible:outline-brass"><X size={15} /></button>
            </li>
          ))}
        </ul>
      )}
      <input ref={input} type="file" accept={PHOTO_MIMES.join(',')} multiple hidden data-testid="claim-photo-input"
        onChange={(e) => { const picked = Array.from(e.target.files ?? []); e.target.value = ''; if (picked.length) onChange([...files, ...picked].slice(0, Math.max(files.length, MAX_CLAIM_PHOTOS - already))); }} />
      {room > 0 && <Button type="button" variant="outline" size="sm" className="mt-3" onClick={() => input.current?.click()}><Camera size={16} />{files.length || already ? t('return.photos.add_more') : t('return.photos.add')}</Button>}
      {error && <p role="alert" className="mt-2 text-sm text-danger">{error}</p>}
    </div>
  );
}

/** Photos and a note added while the claim still waits for the team. */
function AddEvidence({ claim, orderId }: { claim: ReturnClaim; orderId: string }) {
  const { t } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const [files, setFiles] = useState<File[]>([]);
  const [note, setNote] = useState('');
  const [err, setErr] = useState('');
  const problem = photoProblem(files, claim.photos?.length ?? 0);
  const send = useMutation({
    mutationFn: () => {
      const form = new FormData();
      files.forEach((f) => form.append('photos[]', f));
      if (note.trim()) form.append('reason', note.trim());
      return api(`/returns/${claim.id}/evidence`, { form });
    },
    onSuccess: () => { setFiles([]); setNote(''); setErr(''); toast.push(t('return.claim.added')); void qc.invalidateQueries({ queryKey: ['order', orderId] }); },
    onError: (e) => setErr(e instanceof ApiError && (e.status === 0 || e.code === 'session_refresh_unavailable') ? e.message : t(e instanceof ApiError ? returnFailureKey(e.status, e.code, e.errors) : 'error.generic')),
  });
  const ready = (files.length > 0 || note.trim().length > 0) && !problem;
  return (
    <form onSubmit={(e) => { e.preventDefault(); setErr(''); if (ready && !send.isPending) send.mutate(); }} className="space-y-3 rounded-2xl bg-sand/50 p-4" data-testid="claim-add">
      <p className="text-sm font-semibold">{t('return.claim.add')}</p>
      <p className="text-xs text-muted">{t('return.claim.add_hint')}</p>
      {err && <p role="alert" className="text-sm text-danger">{err}</p>}
      <PhotoPicker files={files} onChange={setFiles} already={claim.photos?.length ?? 0} error={problem ? t(problem) : undefined} />
      <Textarea label={t('return.claim.your_note')} value={note} onChange={(e) => setNote(e.target.value)} maxLength={MAX_CLAIM_NOTE} className="[&_textarea]:min-h-20" />
      <Button type="submit" size="md" block loading={send.isPending} disabled={!ready}>{t('return.claim.add_submit')}</Button>
    </form>
  );
}

function ClaimCard({ claim, orderId }: { claim: ReturnClaim; orderId: string }) {
  const { t, date } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const [confirm, setConfirm] = useState(false);
  const open = claimIsOpen(claim);
  const cancel = async () => {
    await post(`/returns/${claim.id}/cancel`);
    toast.push(t('return.claim.cancelled'));
    await qc.invalidateQueries({ queryKey: ['order', orderId] });
    setConfirm(false);
  };
  return (
    <section className="space-y-4 rounded-3xl border border-line bg-paper p-5 shadow-soft" data-testid="return-status" data-status={claim.status}>
      <header className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <h3 className="font-display text-2xl leading-tight">{t('return.claim.title', { n: claim.number })}</h3>
          <p className="mt-0.5 text-xs text-muted">{t('return.claim.sent_on', { d: day(date, claim.requested_at) })} · {claim.kind ? t(`return.type.${claim.kind}`) : ''}</p>
        </div>
        <Badge tone={returnTone(claim.status) === 'warning' ? 'warning' : returnTone(claim.status) === 'success' ? 'success' : 'brass'}>{claim.status_label}</Badge>
      </header>
      {!!claim.items?.length && <p className="text-sm text-ink-soft">{claim.items.map((i) => `${i.quantity} × ${i.name}`).join(' · ')}</p>}
      {claim.timeline?.length ? <ClaimTimeline claim={claim} /> : <p className="text-sm">{t(returnStatusKey(claim.status), { d: day(date, claim.requested_at), e: day(date, claim.decided_at ?? claim.requested_at) })}</p>}
      {claim.status === 'rejected' && <p className="text-sm text-muted">{t('return.status.rejected_help')}</p>}
      {claim.note && <div><p className="eyebrow mb-1">{t('return.claim.your_note')}</p><p className="rounded-2xl bg-sand/60 p-3 text-sm [overflow-wrap:anywhere]" dir="auto">“{claim.note}”</p></div>}
      {claim.messages?.map((m) => <p key={m.at + m.text} className="rounded-2xl bg-sand/60 p-3 text-sm [overflow-wrap:anywhere]" dir="auto">“{m.text}”</p>)}
      <ClaimPhotos claim={claim} />
      {open && claim.can_add_evidence && <AddEvidence claim={claim} orderId={orderId} />}
      {open && claim.can_cancel && <Button type="button" variant="ghost" className="text-danger hover:bg-danger/10" onClick={() => setConfirm(true)}>{t('return.claim.cancel')}</Button>}
      <ConfirmDialog open={confirm} onClose={() => setConfirm(false)} title={t('return.claim.cancel')} body={t('return.claim.cancel_confirm')} confirmLabel={t('return.claim.cancel')} cancelLabel={t('return.claim.cancel_keep')} onConfirm={cancel} />
    </section>
  );
}

/** "You can report a problem until …": the last day the claim window is open for the pieces still reportable. */
export function ReturnWindowNote({ o }: { o: OrderDetail }) {
  const { t, date } = useI18n();
  if (!o.can_return || !o.return_window?.until) return null;
  return <p className="text-center text-xs text-muted" data-testid="return-window">{t('return.window_until', { d: day(date, o.return_window.until) })}</p>;
}

/** Every claim of the order, newest first, each with where it stands. Shown above the order's parcels. */
export function ReturnStatusCard({ o }: { o: OrderDetail }) {
  const claims = o.returns ?? (o.return_request ? [o.return_request] : []);
  if (!claims.length) return null;
  return <div className="space-y-4">{claims.map((c) => <ClaimCard key={c.id} claim={c} orderId={o.id} />)}</div>;
}

/** "Report a problem or return": what happened, which pieces, photos (needed for damage and a wrong item) and a short note. The server checks the window and what was handed over. */
export function ReturnForm({ o, onDone, onCancel }: { o: OrderDetail; onDone: () => void; onCancel: () => void }) {
  const { t, date } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const key = useRef(uuid());
  const rows = o.items.map((i) => ({ item: i, max: returnableOf(i) })).filter((r) => r.max > 0);
  const [qty, setQty] = useState<Record<string, number>>({});
  const [type, setType] = useState<ClaimType>('damaged');
  const [files, setFiles] = useState<File[]>([]);
  const [note, setNote] = useState('');
  const [err, setErr] = useState('');
  const picked = Object.entries(qty).filter(([, n]) => n > 0);
  const needsPhoto = claimNeedsPhoto(type);
  const problem = photoProblem(files);
  const ready = picked.length > 0 && !problem && (!needsPhoto || files.length > 0);
  const send = useMutation({
    mutationFn: () => {
      const form = new FormData();
      picked.forEach(([id, n], i) => { form.append(`items[${i}][order_item_id]`, id); form.append(`items[${i}][quantity]`, String(n)); });
      form.append('reason_code', type);
      if (note.trim()) form.append('reason', note.trim());
      files.forEach((f) => form.append('photos[]', f));
      return api(`/orders/${o.id}/returns`, { form, headers: { 'Idempotency-Key': key.current } });
    },
    onSuccess: () => { toast.push(t('return.sent')); void qc.invalidateQueries({ queryKey: ['order', o.id] }); onDone(); },
    onError: (e) => setErr(e instanceof ApiError && (e.status === 0 || e.code === 'session_refresh_unavailable') ? e.message : t(e instanceof ApiError ? returnFailureKey(e.status, e.code, e.errors) : 'error.generic')),
  });
  return (
    <form onSubmit={(e) => { e.preventDefault(); setErr(''); if (ready && !send.isPending) send.mutate(); }} className="flex min-h-full flex-col">
      <div className="flex-1 space-y-6 p-6">
        <p className="text-sm text-muted">{t('return.intro')}</p>
        {o.return_window?.until && <p className="text-xs font-medium text-brass-deep" data-testid="return-window">{t('return.window_until', { d: day(date, o.return_window.until) })}</p>}
        {err && <p role="alert" className="rounded-2xl border border-danger/30 bg-danger/8 px-4 py-3 text-sm text-danger">{err}</p>}
        {rows.length === 0 ? <p className="rounded-2xl bg-sand/60 p-5 text-center text-muted">{t('return.none')}</p> : (
          <>
            <fieldset>
              <legend className="eyebrow mb-2">{t('return.type.label')}</legend>
              <div className="grid gap-2" role="radiogroup">
                {CLAIM_TYPES.map((c) => (
                  <label key={c.code} className="block cursor-pointer">
                    <input type="radio" name="claim-type" value={c.code} checked={type === c.code} onChange={() => setType(c.code)} className="peer sr-only" />
                    <span className="block min-h-11 rounded-2xl border border-line bg-paper px-4 py-3 transition peer-checked:border-brass peer-checked:bg-brass-tint/50 peer-focus-visible:outline-2 peer-focus-visible:outline-brass">
                      <span className="block text-[15px] font-medium">{t(`return.type.${c.code}`)}</span>
                      <span className="mt-0.5 block text-xs text-muted">{t(`return.type.${c.code}.hint`)}</span>
                    </span>
                  </label>
                ))}
              </div>
            </fieldset>
            <ul className="divide-y divide-line rounded-3xl border border-line bg-paper px-4">
              {rows.map(({ item, max }) => (
                <li key={item.id} className="flex items-center gap-3 py-3">
                  <div className="w-14 shrink-0"><Pic src={item.image_url} alt="" ratio="1/1" className="rounded-xl" /></div>
                  <div className="min-w-0 flex-1"><p className="line-clamp-2 text-[15px] font-medium leading-snug">{item.name}</p>{item.variant_name && <p className="text-xs text-muted">{item.variant_name}</p>}<p className="text-xs text-muted">{t('return.of', { n: max })}</p></div>
                  <QtyStepper value={qty[item.id] ?? 0} min={0} max={max} onChange={(n) => setQty({ ...qty, [item.id]: n })} />
                </li>
              ))}
            </ul>
            <PhotoPicker files={files} onChange={setFiles} required={needsPhoto} error={problem ? t(problem) : undefined} />
            <Textarea label={t('return.note')} value={note} onChange={(e) => setNote(e.target.value)} maxLength={MAX_CLAIM_NOTE} hint={t('return.note.hint')} className="[&_textarea]:min-h-24" />
          </>
        )}
      </div>
      <div className="sticky bottom-0 border-t border-line bg-paper px-6 pt-4 pb-[max(16px,env(safe-area-inset-bottom))]">
        <Button type="submit" size="lg" block loading={send.isPending} disabled={!ready}>{picked.length ? t('return.submit_n', { n: picked.reduce((s, [, n]) => s + n, 0) }) : t('return.submit')}</Button>
        {needsPhoto && picked.length > 0 && files.length === 0 && <p className="mt-2 text-center text-xs text-muted">{t('return.photos.required')}</p>}
        <Button type="button" variant="ghost" block className="mt-1.5" onClick={onCancel}>{t('action.cancel')}</Button>
      </div>
    </form>
  );
}

