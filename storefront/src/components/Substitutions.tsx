import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { ArrowRightLeft, Check } from 'lucide-react';
import { ApiError, post } from '../lib/api';
import { useI18n } from '../lib/i18n';
import { useToast } from '../lib/toast';
import { useNow } from '../lib/useNow';
import { answerFailureKey, minutesLeft, outcomeKey, priceNoteKey, waitingOf, type SubstitutionLine, type SubstitutionRequest } from '../lib/substitutions';
import type { OrderDetail } from '../lib/types';
import { Button, Pic } from './ui';

/** Substitution consent on the order: "We do not have X. Replace it with Y?" with Accept or "No, remove it and refund me", a live time left, and, once answered, what happened. */

function Side({ label, line }: { label: string; line: SubstitutionLine | null }) {
  const { t } = useI18n();
  if (!line) return null;
  return (
    <div className="flex min-w-0 flex-1 items-center gap-3">
      <div className="w-14 shrink-0"><Pic src={line.image_url} alt="" ratio="1/1" className="rounded-xl" /></div>
      <div className="min-w-0">
        <p className="eyebrow">{label}</p>
        <p className="line-clamp-2 text-[15px] font-medium leading-snug [overflow-wrap:anywhere]">{line.name}</p>
        {line.variant_name && <p className="text-xs text-muted">{line.variant_name}</p>}
        <p className="text-xs text-muted tabular-nums">{t('subst.qty', { n: line.quantity })} · {line.total.formatted}</p>
      </div>
    </div>
  );
}

function Question({ r, orderId }: { r: SubstitutionRequest; orderId: string }) {
  const { t } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const now = useNow();
  const [err, setErr] = useState('');
  const left = minutesLeft(r.expires_at, now);
  const answer = useMutation({
    mutationFn: (a: 'accept' | 'decline') => post(`/orders/${orderId}/substitutions/${r.id}`, { answer: a }),
    onSuccess: (_d, a) => { setErr(''); toast.push(t(a === 'accept' ? 'subst.accepted_toast' : 'subst.declined_toast')); void qc.invalidateQueries({ queryKey: ['order', orderId] }); },
    onError: (e) => {
      setErr(e instanceof ApiError && (e.status === 0 || e.code === 'session_refresh_unavailable') ? e.message : t(e instanceof ApiError ? answerFailureKey(e.status, e.code) : 'error.generic'));
      void qc.invalidateQueries({ queryKey: ['order', orderId] }); // the question may have moved on: show what it is now
    },
  });
  const item = r.original?.name ?? '';
  const note = t(priceNoteKey(r.price_difference), { amount: r.price_difference.formatted.replace('-', '') });
  return (
    <section className="space-y-4 rounded-3xl border border-brass/40 bg-brass-tint/50 p-5 shadow-soft" data-testid="substitution-question" aria-labelledby={`subst-${r.id}`}>
      <div className="flex items-start gap-3">
        <span aria-hidden className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brass text-paper"><ArrowRightLeft size={18} /></span>
        <div className="min-w-0">
          <h3 id={`subst-${r.id}`} className="font-display text-2xl leading-tight">{t('subst.title')}</h3>
          <p className="mt-1 text-[15px] [overflow-wrap:anywhere]">{t('subst.ask', { item, replacement: r.replacement?.name ?? '' })}</p>
        </div>
      </div>
      <div className="flex flex-col gap-3 rounded-2xl bg-paper p-3 sm:flex-row sm:items-center">
        <Side label={t('subst.you_ordered')} line={r.original} />
        <ArrowRightLeft aria-hidden size={16} className="mx-auto shrink-0 text-muted rtl:-scale-x-100" />
        <Side label={t('subst.replacement')} line={r.replacement} />
      </div>
      <p className="text-sm text-ink-soft">{t('subst.price_line', { note })}</p>
      <p className="text-xs text-muted" role="status" data-testid="substitution-clock">{left > 0 ? t('subst.time_left', { n: left }) : t('subst.time_up')}</p>
      <p className="text-xs text-muted">{t(r.fallback === 'keep' ? 'subst.fallback_keep' : 'subst.fallback_remove')}</p>
      {err && <p role="alert" className="rounded-2xl border border-danger/30 bg-danger/8 px-4 py-3 text-sm text-danger">{err}</p>}
      <div className="grid gap-2.5">
        <Button size="lg" block loading={answer.isPending && answer.variables === 'accept'} disabled={answer.isPending || !r.can_answer} onClick={() => answer.mutate('accept')}><Check size={18} />{t('subst.accept')}</Button>
        <Button size="lg" variant="outline" block loading={answer.isPending && answer.variables === 'decline'} disabled={answer.isPending || !r.can_answer} onClick={() => answer.mutate('decline')}>{t('subst.decline')}</Button>
      </div>
    </section>
  );
}

/** The open questions first (the most urgent on top), then a calm line for each one that was answered or settled. */
export function SubstitutionCards({ o }: { o: OrderDetail }) {
  const { t } = useI18n();
  const all = o.substitution_requests ?? [];
  const waiting = waitingOf(all);
  const done = all.filter((r) => outcomeKey(r.status));
  if (!waiting.length && !done.length) return null;
  return (
    <div className="space-y-4">
      {waiting.map((r) => <Question key={r.id} r={r} orderId={o.id} />)}
      {done.length > 0 && (
        <ul className="space-y-1.5 rounded-2xl bg-sand/50 p-4 text-sm text-ink-soft" aria-label={t('subst.history')} data-testid="substitution-history">
          {done.map((r) => <li key={r.id}><span className="font-medium">{r.original?.name} → {r.replacement?.name}</span> · {t(outcomeKey(r.status) as string)}</li>)}
        </ul>
      )}
    </div>
  );
}
