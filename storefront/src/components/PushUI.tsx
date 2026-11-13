import { useState, type ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import { Bell, BellOff, BellRing, Loader2, Share, SquarePlus, X } from 'lucide-react';
import { useI18n } from '../lib/i18n';
import { useToast } from '../lib/toast';
import { usePushPrompt, useWebPush, type EnableResult } from '../lib/webpush';
import { helpOrder, type HelpFamily, type PushState } from '../lib/webpush-core';
import { cx } from '../lib/format';
import { Button, Skeleton } from './ui';

const IOS_HINT_KEY = 'albahar.push.ios_hint';
const readFlag = (k: string) => { try { return localStorage.getItem(k) === '1'; } catch { return false; } };
const writeFlag = (k: string) => { try { localStorage.setItem(k, '1'); } catch { /* private mode: it just comes back next visit */ } };

/** The toast that follows an Enable tap. Nothing for a closed browser prompt (the customer chose nothing) or a failure (shown inline). */
function useEnableFeedback() {
  const { t } = useI18n();
  const toast = useToast();
  return (r: EnableResult) => {
    if (r === 'granted') toast.push(t('push.toast_on'));
    else if (r === 'denied') toast.push(t('push.toast_denied'), 'info');
  };
}

const stepBadge = 'flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brass-tint text-xs font-semibold text-brass-deep';

/* ───────────── iPhone / iPad: add to Home Screen ───────────── */

/** Safari's bottom bar with the Share button called out. Drawn here (no image to download), follows the theme and honours reduced motion. */
function IosArt({ label }: { label: string }) {
  return (
    <svg role="img" aria-label={label} viewBox="0 0 200 150" className="mx-auto h-auto w-full max-w-[260px] text-ink-soft">
      <rect x="38" y="4" width="124" height="142" rx="18" fill="var(--paper)" stroke="currentColor" strokeWidth="2" />
      <rect x="48" y="16" width="104" height="9" rx="4.5" fill="var(--sand)" />
      <rect x="48" y="32" width="64" height="6" rx="3" fill="var(--sand-deep)" />
      <rect x="48" y="44" width="104" height="38" rx="6" fill="var(--sand)" />
      <rect x="48" y="90" width="88" height="5" rx="2.5" fill="var(--sand-deep)" />
      <rect x="48" y="100" width="56" height="5" rx="2.5" fill="var(--sand-deep)" />
      <path d="M40 114h120v20a12 12 0 0 1 -12 12h-96a12 12 0 0 1 -12 -12z" fill="var(--sand)" />
      <g stroke="currentColor" strokeWidth="1.6" fill="none" strokeLinecap="round" strokeLinejoin="round" opacity=".55">
        <path d="M60 130l-4 -4 4 -4" /><path d="M76 122l4 4 -4 4" />
        <path d="M118 124.5h5a1.5 1.5 0 0 1 1.5 1.5v8a1.5 1.5 0 0 1 -1.5 1.5h-5a1.5 1.5 0 0 1 -1.5 -1.5v-8a1.5 1.5 0 0 1 1.5 -1.5z" />
        <rect x="135" y="123" width="10" height="12" rx="2" />
      </g>
      <circle cx="98" cy="129" r="12" fill="var(--primary)" opacity=".16" className="live-dot" />
      <circle cx="98" cy="129" r="12" fill="none" stroke="var(--primary)" strokeWidth="1.6" />
      <g stroke="var(--primary)" strokeWidth="1.7" fill="none" strokeLinecap="round" strokeLinejoin="round">
        <path d="M94 129v5a1.4 1.4 0 0 0 1.4 1.4h5.2a1.4 1.4 0 0 0 1.4 -1.4v-5" /><path d="M98 133v-9M95 127l3 -3 3 3" />
      </g>
    </svg>
  );
}

function IosGuide() {
  const { t } = useI18n();
  const [art, setArt] = useState(() => !readFlag(IOS_HINT_KEY));
  const steps: { icon: ReactNode; key: string }[] = [
    { icon: <Share size={16} aria-hidden />, key: 'push.ios.1' },
    { icon: <SquarePlus size={16} aria-hidden />, key: 'push.ios.2' },
    { icon: <BellRing size={16} aria-hidden />, key: 'push.ios.3' },
  ];
  return (
    <div className="mt-5 rounded-2xl bg-sand/60 p-4">
      <p className="font-semibold">{t('push.ios.title')}</p>
      {art && (
        <div className="relative mt-3 rounded-2xl bg-paper px-3 py-3">
          <button type="button" aria-label={t('push.ios.hide')} title={t('push.ios.hide')} onClick={() => { writeFlag(IOS_HINT_KEY); setArt(false); }}
            className="absolute end-1 top-1 inline-flex h-11 w-11 items-center justify-center rounded-full text-muted hover:bg-sand"><X size={16} /></button>
          <IosArt label={t('push.ios.art')} />
        </div>
      )}
      <ol className="mt-4 space-y-3 text-sm text-ink-soft">
        {steps.map((s, i) => (
          <li key={s.key} className="flex items-start gap-3">
            <span className={stepBadge}>{i + 1}</span>
            <span className="flex-1 pt-0.5">{t(s.key)}</span>
            <span className="mt-0.5 shrink-0 text-brass-deep">{s.icon}</span>
          </li>
        ))}
      </ol>
    </div>
  );
}

/* ───────────── Blocked in the browser ───────────── */

function Steps({ family }: { family: HelpFamily }) {
  const { t } = useI18n();
  return (
    <ol className="mt-3 space-y-2.5 text-sm text-ink-soft">
      {[1, 2, 3].map((n) => <li key={n} className="flex items-start gap-3"><span className={stepBadge}>{n}</span><span className="flex-1 pt-0.5">{t(`push.denied.${family}.${n}`)}</span></li>)}
    </ol>
  );
}

function DeniedHelp({ family }: { family: HelpFamily }) {
  const { t } = useI18n();
  const [own, ...others] = helpOrder(family);
  return (
    <div className="mt-5 rounded-2xl bg-sand/60 p-4">
      <h3 className="!font-sans text-[15px] font-semibold">{t('push.denied.title')} <span className="font-normal text-muted">· {t(`push.fam.${own}`)}</span></h3>
      <Steps family={own} />
      <details className="mt-3">
        <summary className="flex min-h-11 cursor-pointer items-center text-sm font-medium text-brass-deep">{t('push.denied.others')}</summary>
        <div className="space-y-4 pb-1">
          {others.map((f) => <div key={f}><p className="mt-2 text-sm font-semibold">{t(`push.fam.${f}`)}</p><Steps family={f} /></div>)}
        </div>
      </details>
    </div>
  );
}

/* ───────────── Account → Notifications ───────────── */

function Switch({ on, busy, disabled, label, describedBy, onToggle }: { on: boolean; busy: boolean; disabled: boolean; label: string; describedBy: string; onToggle: () => void }) {
  const { t } = useI18n();
  return (
    <button type="button" role="switch" aria-checked={on} aria-describedby={describedBy} disabled={disabled || busy} onClick={onToggle}
      className="mt-5 flex min-h-[52px] w-full items-center justify-between gap-4 rounded-2xl border border-line bg-bg px-4 py-2 text-start transition-colors hover:border-primary disabled:cursor-not-allowed disabled:opacity-60">
      <span className="text-[15px] font-medium">{label}</span>
      <span className="flex items-center gap-3">
        <span aria-hidden="true" className="flex min-w-8 justify-end text-sm text-muted">{busy ? <Loader2 size={16} className="animate-spin" /> : on ? t('push.on') : t('push.off')}</span>
        <span aria-hidden="true" className={cx('relative inline-block h-8 w-14 shrink-0 rounded-full transition-colors', on ? 'bg-primary' : 'bg-muted')}>
          <span className={cx('absolute top-1 h-6 w-6 rounded-full bg-white shadow transition-[inset-inline-start]', on ? 'start-7' : 'start-1')} />
        </span>
      </span>
    </button>
  );
}

const HEAD_ICON: Record<PushState, typeof Bell> = { granted: BellRing, default: Bell, error: Bell, ios_needs_install: Bell, denied: BellOff, unsupported: BellOff, disabled_by_admin: BellOff };

/** "Browser notifications" card at the top of Account → Notifications: one control, plain words for every state. */
export function PushSettingsCard() {
  const { t } = useI18n();
  const toast = useToast();
  const feedback = useEnableFeedback();
  const push = useWebPush();
  if (!push.ready) return <Skeleton className="h-40 !rounded-[28px]" />;
  const { state, busy, error } = push;
  const Icon = HEAD_ICON[state];
  const on = state === 'granted';
  const switchShown = state === 'default' || state === 'granted' || state === 'error' || state === 'denied';
  const toggle = async () => {
    if (on) { if (await push.disable()) toast.push(t('push.toast_off')); } else feedback(await push.enable());
  };
  const help = state === 'error' && error ? t(`push.err.${error}`) : t(`push.help.${state}`);
  return (
    <section aria-labelledby="push-title" className="rounded-[28px] border border-line bg-paper p-6">
      <div className="flex items-start gap-4">
        <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-brass-tint text-brass-deep"><Icon size={22} aria-hidden /></span>
        <div className="min-w-0 flex-1">
          <h2 id="push-title" className="text-2xl leading-tight">{t('push.title')}</h2>
          <p id="push-help" className={cx('mt-1 text-sm', state === 'error' ? 'text-danger' : 'text-ink-soft')} role={state === 'error' ? 'alert' : undefined}>{help}</p>
        </div>
      </div>
      {switchShown && <Switch on={on} busy={busy} disabled={state === 'denied'} label={t('push.switch')} describedBy="push-help" onToggle={() => void toggle()} />}
      {state === 'error' && <div className="mt-3"><Button variant="outline" size="md" loading={busy} onClick={() => void toggle()}>{t('action.retry')}</Button></div>}
      {state === 'denied' && <DeniedHelp family={push.family} />}
      {state === 'ios_needs_install' && <IosGuide />}
    </section>
  );
}

/* ───────────── The soft prompt ───────────── */

/**
 * A small card, never a browser popup: it appears a moment after the page (after an order, and on the orders list) and only the
 * Enable button asks the browser. "Not now" hides it for 14 days. Customers who cannot act on it (blocked, unsupported, switched
 * off by the store, or guests) never see it. On iPhone/iPad in a normal tab the button shows how to install instead.
 */
export function PushPrompt({ className }: { className?: string }) {
  const { t } = useI18n();
  const nav = useNavigate();
  const feedback = useEnableFeedback();
  const p = usePushPrompt();
  const [tried, setTried] = useState(false);
  // A failed attempt moves the state to 'error', which would hide the card with the problem unexplained: keep it until dismissed.
  const failed = tried && p.state === 'error' && p.error !== null;
  if (!p.show && !failed) return null;
  const ios = p.state === 'ios_needs_install';
  const go = async () => {
    if (ios) { nav('/account/notifications'); return; }
    setTried(true);
    feedback(await p.enable());
  };
  return (
    <section aria-label={t(ios ? 'push.prompt.ios_title' : 'push.prompt.title')} className={cx('rise flex flex-col gap-4 rounded-3xl border border-brass/40 bg-brass-tint/50 p-5 sm:flex-row sm:items-center', className)}>
      <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-paper text-brass-deep"><BellRing size={20} aria-hidden /></span>
      <div className="min-w-0 flex-1">
        <p className="font-semibold">{t(ios ? 'push.prompt.ios_title' : 'push.prompt.title')}</p>
        <p className="mt-0.5 text-sm text-ink-soft">{failed && p.error ? t(`push.err.${p.error}`) : t(ios ? 'push.prompt.ios_text' : 'push.prompt.text')}</p>
      </div>
      <div className="flex shrink-0 gap-2">
        <Button size="md" loading={p.busy} onClick={() => void go()}>{ios ? t('push.prompt.ios_button') : failed ? t('action.retry') : t('push.prompt.enable')}</Button>
        <Button size="md" variant="ghost" onClick={() => { p.dismiss(); setTried(false); }}>{t('push.prompt.later')}</Button>
      </div>
    </section>
  );
}
