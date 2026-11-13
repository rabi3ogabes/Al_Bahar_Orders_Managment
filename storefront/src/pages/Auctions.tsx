import { useEffect, useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Bell, BellOff, Gavel, ShieldCheck, Timer, Trophy, Zap } from 'lucide-react';
import { ApiError, del, get, post } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useI18n } from '../lib/i18n';
import { useToast } from '../lib/toast';
import { useDocTitle, useTick } from '../lib/hooks';
import { cx, toNum } from '../lib/format';
import type { Auction, AuctionDetail, Lot, Money, Paged } from '../lib/types';
import { useAuthModal } from '../components/AuthModal';
import { Alert, Badge, Breadcrumbs, Button, Checkbox, EmptyState, Input, Pic, Skeleton } from '../components/ui';
import { ConfirmDialog } from '../components/Dialogs';

function useCountdown(endIso: string | undefined, serverIso: string | undefined) {
  useTick(1000);
  // Compare against the server's clock, not the visitor's.
  const offset = useMemo(() => (serverIso ? new Date(serverIso).getTime() - Date.now() : 0), [serverIso]);
  if (!endIso) return { ms: 0, text: '--:--:--', urgent: false };
  const ms = Math.max(0, new Date(endIso).getTime() - (Date.now() + offset));
  const d = Math.floor(ms / 864e5), h = Math.floor((ms % 864e5) / 36e5), m = Math.floor((ms % 36e5) / 6e4), s = Math.floor((ms % 6e4) / 1000);
  const p = (n: number) => String(n).padStart(2, '0');
  return { ms, text: `${d > 0 ? `${d}d ` : ''}${p(h)}:${p(m)}:${p(s)}`, urgent: ms > 0 && ms < 60_000 };
}

function Countdown({ end, server, className }: { end: string; server?: string; className?: string }) {
  const { t } = useI18n();
  const c = useCountdown(end, server);
  return <span className={cx('tabular-nums', c.urgent && 'text-danger', className)} dir="ltr">{c.ms === 0 ? t('auction.ended') : c.text}</span>;
}

/* ───────────── List ───────────── */
export function AuctionList() {
  const { t, date } = useI18n();
  useDocTitle(t('nav.auctions'));
  const q = useQuery({ queryKey: ['auctions'], queryFn: async () => get<Paged<Auction>>('/auctions', { per_page: 30 }), refetchInterval: 30_000 });
  const live = q.data?.data.filter((a) => a.status === 'live') ?? [];
  const rest = q.data?.data.filter((a) => a.status !== 'live') ?? [];
  return (
    <div className="wrap py-8 md:py-12">
      <p className="eyebrow">{t('auction.eyebrow')}</p>
      <h1 className="mt-2 text-[clamp(40px,6vw,76px)] leading-[0.98]">{t('nav.auctions')}</h1>
      <p className="mt-4 max-w-xl text-ink-soft">{t('auction.intro')}</p>
      {q.isLoading && <div className="mt-10 grid gap-6 md:grid-cols-2"><Skeleton className="h-72" /><Skeleton className="h-72" /></div>}
      {q.data && q.data.data.length === 0 && <EmptyState icon={<Gavel size={28} />} title={t('auction.none')} text={t('auction.none_text')} />}
      {live.length > 0 && <h2 className="mb-5 mt-12 flex items-center gap-3 text-3xl"><span className="h-2.5 w-2.5 animate-pulse rounded-full bg-danger" />{t('auction.live_now')}</h2>}
      <div className="grid gap-6 md:grid-cols-2">{live.map((a) => <AuctionCard key={a.id} a={a} />)}</div>
      {rest.length > 0 && <h2 className="mb-5 mt-14 text-3xl">{t('auction.upcoming')}</h2>}
      <div className="grid gap-6 md:grid-cols-2">{rest.map((a) => <AuctionCard key={a.id} a={a} sub={t('auction.starts', { d: date(a.starts_at) })} />)}</div>
    </div>
  );
}
function AuctionCard({ a, sub }: { a: Auction; sub?: string }) {
  const { t } = useI18n();
  const live = a.status === 'live';
  return (
    <Link to={`/auctions/${a.id}`} className="group block overflow-hidden rounded-[28px] border border-line bg-paper transition-shadow hover:shadow-soft">
      <Pic src={a.cover_image_url} alt={a.title} ratio="16/9" label={a.title} />
      <div className="p-6">
        <div className="mb-2 flex items-center gap-2"><Badge tone={live ? 'danger' : 'brass'}>{live ? t('auction.live') : t('auction.scheduled')}</Badge><span className="text-xs text-muted">{a.reference}</span></div>
        <h3 className="text-3xl leading-tight">{a.title}</h3>
        <p className="mt-2 flex items-center gap-4 text-sm text-muted"><span>{t('auction.lots', { n: a.lots_count })}</span>{live ? <span className="flex items-center gap-1.5"><Timer size={14} /><Countdown end={a.ends_at} /></span> : <span>{sub}</span>}</p>
      </div>
    </Link>
  );
}

/* ───────────── Auction detail ───────────── */
export function AuctionPage() {
  const { id = '' } = useParams();
  const { t } = useI18n();
  const { user } = useAuth();
  const authModal = useAuthModal();
  const toast = useToast();
  const qc = useQueryClient();
  const [reg, setReg] = useState(false);
  const [terms, setTerms] = useState(false);
  const q = useQuery({ queryKey: ['auction', id], queryFn: async () => (await get<{ data: AuctionDetail }>(`/auctions/${id}`)).data, refetchInterval: 10_000 });
  const a = q.data;
  useDocTitle(a?.title);
  const register = useMutation({
    mutationFn: () => post(`/auctions/${id}/register`, { accept_terms: true }),
    onSuccess: () => { toast.push(t('auction.registered')); setReg(false); setTerms(false); void qc.invalidateQueries({ queryKey: ['auction', id] }); },
  });
  if (q.isError) return <div className="wrap py-20"><EmptyState title={t('auction.not_found')} /></div>;
  if (!a) return <div className="wrap py-12"><Skeleton className="h-96" /></div>;
  const registered = !!a.my_registration;
  return (
    <div className="wrap py-8 md:py-12">
      <Breadcrumbs items={[{ to: '/', label: t('nav.home') }, { to: '/auctions', label: t('nav.auctions') }, { label: a.title }]} />
      <div className="mt-6 grid items-end gap-6 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
        <div>
          <Badge tone={a.status === 'live' ? 'danger' : 'brass'}>{a.status === 'live' ? t('auction.live') : t('auction.scheduled')}</Badge>
          <h1 className="mt-3 text-[clamp(38px,5.5vw,72px)] leading-[1]">{a.title}</h1>
          {a.description && <p className="mt-4 max-w-2xl text-ink-soft">{a.description}</p>}
        </div>
        <div className="rounded-[28px] border border-line bg-paper p-6">
          <p className="eyebrow">{a.status === 'live' ? t('auction.ends_in') : t('auction.starts_in')}</p>
          <p className="font-display text-5xl"><Countdown end={a.status === 'live' ? a.ends_at : a.starts_at} server={a.server_time} /></p>
          {a.requires_registration && (registered ? <p className="mt-3 flex items-center gap-2 text-sm text-success"><ShieldCheck size={16} />{t('auction.you_registered')}</p>
            : <Button className="mt-4" block onClick={() => (user ? setReg(true) : authModal.open({ reason: t('auction.reason_register') }))}>{t('auction.register')}{a.deposit ? ` · ${t('auction.deposit', { v: a.deposit.formatted })}` : ''}</Button>)}
        </div>
      </div>

      <div className="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        {a.lots.map((l) => <LotCard key={l.id} l={l} server={a.server_time} />)}
      </div>

      {a.terms && <section className="mt-14 rounded-[28px] border border-line bg-paper p-6"><h2 className="text-2xl">{t('auction.terms')}</h2><p className="mt-2 whitespace-pre-line text-sm text-ink-soft">{a.terms}</p>
        <ul className="mt-4 flex flex-wrap gap-2 text-xs"><Badge>{t('auction.pay_within', { h: a.winner_payment_hours })}</Badge>{a.pickup_allowed && <Badge>{t('checkout.pickup')}</Badge>}{a.delivery_allowed && <Badge>{t('checkout.delivery')}</Badge>}{!a.cod_allowed && <Badge>{t('auction.no_cod')}</Badge>}</ul></section>}

      <ConfirmDialog open={reg} onClose={() => setReg(false)} destructive={false} title={t('auction.register_q')} confirmLabel={t('auction.confirm_register')} confirmDisabled={!terms}
        onConfirm={async () => { await register.mutateAsync(); }}>
        <p className="mb-4 max-h-48 overflow-y-auto whitespace-pre-line text-sm text-ink-soft">{a.terms ?? t('auction.terms_default')}</p>
        <Checkbox checked={terms} onChange={setTerms}>{t('auction.accept_terms')}</Checkbox>
      </ConfirmDialog>
    </div>
  );
}
function LotCard({ l, server }: { l: Lot; server: string }) {
  const { t } = useI18n();
  const open = l.status === 'open';
  return (
    <Link to={`/auctions/lots/${l.id}`} className="group block overflow-hidden rounded-[24px] border border-line bg-paper transition-shadow hover:shadow-soft">
      <Pic src={l.image_url} alt={l.title} ratio="4/3" label={`#${l.lot_number}`} />
      <div className="p-5">
        <div className="flex items-center justify-between"><span className="eyebrow">{t('auction.lot', { n: l.lot_number })}</span>{open ? <Badge tone="danger">{t('auction.live')}</Badge> : <Badge>{t(`lot.${l.status}`)}</Badge>}</div>
        <h3 className="mt-2 text-2xl leading-tight">{l.title}</h3>
        <div className="mt-3 flex items-end justify-between"><div><p className="text-xs text-muted">{t('auction.current')}</p><p className="text-xl font-semibold tabular-nums">{l.current_price.formatted}</p></div>
          {open && <span className="flex items-center gap-1.5 text-sm"><Timer size={14} /><Countdown end={l.ends_at} server={server} /></span>}</div>
      </div>
    </Link>
  );
}

/* ───────────── Lot page with live bidding ───────────── */
export function LotPage() {
  const { lotId = '' } = useParams();
  const { t, date } = useI18n();
  const { user } = useAuth();
  const authModal = useAuthModal();
  const toast = useToast();
  const qc = useQueryClient();
  const [amount, setAmount] = useState('');
  const [auto, setAuto] = useState('');
  const [err, setErr] = useState('');

  // Server-authoritative state, refreshed every 3 s (the websocket channel is an optional accelerator).
  const lotQ = useQuery({ queryKey: ['lot', lotId, user?.id], queryFn: async () => (await get<{ data: Lot }>(`/auctions/lots/${lotId}`)).data, refetchInterval: 3000 });
  const bidsQ = useQuery({ queryKey: ['bids', lotId, lotQ.data?.last_sequence], enabled: !!lotQ.data, queryFn: async () => (await get<{ data: { bid_number: number; amount: Money; type: string; bidder: string; is_mine: boolean; placed_at: string }[] }>(`/auctions/lots/${lotId}/bids`)).data });
  const l = lotQ.data;
  useDocTitle(l?.title);
  const cd = useCountdown(l?.ends_at, l?.server_time);
  useEffect(() => { if (l && !amount) setAmount(String(toNum(l.min_next_bid))); }, [l?.min_next_bid.amount]); // eslint-disable-line react-hooks/exhaustive-deps

  const refresh = () => { void qc.invalidateQueries({ queryKey: ['lot', lotId] }); void qc.invalidateQueries({ queryKey: ['bids', lotId] }); };
  const bid = useMutation({
    mutationFn: () => post(`/auctions/lots/${lotId}/bids`, { amount: parseFloat(amount) }),
    onSuccess: () => { setErr(''); toast.push(t('auction.bid_ok')); setAmount(''); refresh(); },
    onError: (e) => { setErr(e instanceof ApiError ? e.message : t('error.generic')); refresh(); },
  });
  const setAutoBid = useMutation({
    mutationFn: () => post(`/auctions/lots/${lotId}/auto-bid`, { max_amount: parseFloat(auto) }),
    onSuccess: () => { toast.push(t('auction.auto_ok')); setAuto(''); refresh(); },
    onError: (e) => setErr(e instanceof ApiError ? e.message : t('error.generic')),
  });
  const cancelAuto = useMutation({ mutationFn: () => del(`/auctions/lots/${lotId}/auto-bid`), onSuccess: refresh });
  const watch = useMutation({ mutationFn: () => (l?.is_watching ? del(`/auctions/lots/${lotId}/watch`) : post(`/auctions/lots/${lotId}/watch`, {})), onSuccess: refresh });

  if (lotQ.isError) return <div className="wrap py-20"><EmptyState title={t('auction.not_found')} /></div>;
  if (!l) return <div className="wrap py-12"><Skeleton className="h-96" /></div>;
  const open = l.status === 'open' && cd.ms > 0;
  const needAuth = (fn: () => void) => (user ? fn() : authModal.open({ reason: t('auction.reason_bid') }));
  const leading = l.my_status === 'winning' || l.my_status === 'leading';

  return (
    <div className="wrap py-8 md:py-12">
      <Breadcrumbs items={[{ to: '/auctions', label: t('nav.auctions') }, { to: `/auctions/${l.auction_id}`, label: t('auction.back') }, { label: l.title }]} />
      <div className="mt-6 grid gap-8 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)] lg:gap-14">
        <Pic src={l.image_url} alt={l.title} ratio="4/3" label={`#${l.lot_number}`} className="rounded-[28px]" eager />
        <div>
          <p className="eyebrow">{t('auction.lot', { n: l.lot_number })}</p>
          <h1 className="mt-1 text-[clamp(34px,4.5vw,56px)] leading-[1.02]">{l.title}</h1>
          {l.description && <p className="mt-3 text-ink-soft">{l.description}</p>}

          <div className="mt-6 rounded-[28px] border border-line bg-paper p-6" aria-live="polite">
            <div className="flex items-end justify-between gap-4">
              <div><p className="text-xs uppercase tracking-wider text-muted">{t('auction.current')}</p><p className="font-display text-6xl leading-none tabular-nums">{l.current_price.formatted}</p>
                <p className="mt-1 text-sm text-muted">{t('auction.bids_count', { n: l.bid_count })}{l.reserve_met ? ` · ${t('auction.reserve_met')}` : ` · ${t('auction.reserve_not_met')}`}</p></div>
              <div className="text-end"><p className="text-xs uppercase tracking-wider text-muted">{open ? t('auction.ends_in') : t('auction.status')}</p><p className={cx('font-display text-4xl tabular-nums', cd.urgent && 'text-danger')} dir="ltr">{open ? cd.text : t(`lot.${l.status}`)}</p></div>
            </div>
            {leading && open && <div className="mt-4"><Alert tone="success"><Trophy size={15} className="me-1.5 inline" />{t('auction.you_lead')}</Alert></div>}
            {l.my_status === 'outbid' && open && <div className="mt-4"><Alert tone="warning">{t('auction.outbid')}</Alert></div>}
            {err && <div className="mt-4"><Alert tone="danger">{err}</Alert></div>}

            {open && (
              <div className="mt-5 space-y-4">
                <form onSubmit={(e) => { e.preventDefault(); needAuth(() => bid.mutate()); }} className="flex items-end gap-3">
                  <Input className="flex-1" label={t('auction.your_bid', { min: l.min_next_bid.formatted })} type="number" inputMode="decimal" min={toNum(l.min_next_bid)} step={toNum(l.min_increment) || 1} value={amount} onChange={(e) => setAmount(e.target.value)} prefix="QAR" />
                  <Button type="submit" size="lg" loading={bid.isPending}><Gavel size={18} />{t('auction.bid')}</Button>
                </form>
                <div className="flex flex-wrap gap-2">{[0, 1, 2, 5].map((n) => { const v = toNum(l.min_next_bid) + n * toNum(l.min_increment); return <button key={n} type="button" onClick={() => setAmount(String(v))} className="rounded-full border border-line bg-bg px-3.5 py-1.5 text-[13px] tabular-nums hover:border-brass">{v.toLocaleString('en-US')}</button>; })}</div>
                <details className="rounded-2xl bg-sand/60 p-4">
                  <summary className="flex cursor-pointer items-center gap-2 text-sm font-semibold"><Zap size={15} className="text-brass" />{t('auction.auto')}</summary>
                  <p className="mt-2 text-[13px] text-muted">{t('auction.auto_text')}</p>
                  {l.my_max_auto_bid ? <p className="mt-3 flex items-center justify-between text-sm">{t('auction.auto_active', { v: l.my_max_auto_bid.formatted })}<Button size="sm" variant="ghost" onClick={() => cancelAuto.mutate()}>{t('action.cancel')}</Button></p>
                    : <form onSubmit={(e) => { e.preventDefault(); needAuth(() => setAutoBid.mutate()); }} className="mt-3 flex items-end gap-3"><Input className="flex-1" label={t('auction.auto_max')} type="number" min={toNum(l.min_next_bid)} value={auto} onChange={(e) => setAuto(e.target.value)} prefix="QAR" /><Button type="submit" variant="outline" loading={setAutoBid.isPending}>{t('action.save')}</Button></form>}
                </details>
              </div>
            )}
            {l.buy_now_price && open && <p className="mt-4 text-sm text-muted">{t('auction.buy_now', { v: l.buy_now_price.formatted })}</p>}
            <button onClick={() => needAuth(() => watch.mutate())} className="mt-5 flex items-center gap-2 text-sm font-medium text-ink-soft hover:text-ink">{l.is_watching ? <BellOff size={15} /> : <Bell size={15} />}{l.is_watching ? t('auction.unwatch') : t('auction.watch')}</button>
          </div>
        </div>
      </div>

      <section className="mt-12 rounded-[28px] border border-line bg-paper p-6">
        <h2 className="mb-4 text-3xl">{t('auction.history')}</h2>
        {bidsQ.data?.length ? (
          <ul className="divide-y divide-line">{bidsQ.data.map((b) => (
            <li key={b.bid_number} className={cx('flex items-center justify-between py-3', b.is_mine && 'font-semibold text-brass-deep')}>
              <span>{b.is_mine ? t('auction.you') : b.bidder}{b.type === 'auto' && <Badge tone="sand">{t('auction.auto_tag')}</Badge>}</span>
              <span className="flex items-center gap-4"><span className="text-xs font-normal text-muted">{date(b.placed_at, { timeStyle: 'medium', timeZone: 'Asia/Qatar' })}</span><span className="tabular-nums">{b.amount.formatted}</span></span>
            </li>
          ))}</ul>
        ) : <p className="text-muted">{t('auction.no_bids')}</p>}
      </section>
    </div>
  );
}
