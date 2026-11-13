import { useState } from 'react';
import { Link, NavLink, Navigate, Outlet, useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Bell, Gift, Heart, LogOut, MapPin, MessageCircle, Package, Pencil, Plus, Trash2, User as UserIcon, Star, Copy, Gavel } from 'lucide-react';
import { ApiError, del, get, post, put } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useI18n } from '../lib/i18n';
import { useToast } from '../lib/toast';
import { useWishlist } from '../lib/wishlist';
import { useDocTitle } from '../lib/hooks';
import { cx } from '../lib/format';
import type { Address, Auction, Loyalty, Lot, OrderSummary, Paged, Referral, Ticket } from '../lib/types';
import { AddressForm } from '../components/AddressForm';
import { useAuthModal } from '../components/AuthModal';
import { ProductCard, CardGrid } from '../components/ProductCard';
import { Alert, Badge, Button, Checkbox, EmptyState, Input, LinkButton, Modal, Pic, Select, Skeleton, Spinner, Textarea } from '../components/ui';
import { statusTone } from './Order';

const NAV = [
  { to: '/account', end: true, icon: UserIcon, key: 'account.overview' }, { to: '/account/orders', icon: Package, key: 'account.orders' },
  { to: '/account/addresses', icon: MapPin, key: 'account.addresses' }, { to: '/account/wishlist', icon: Heart, key: 'wishlist.title' },
  { to: '/account/auctions', icon: Gavel, key: 'account.auctions' }, { to: '/account/loyalty', icon: Gift, key: 'account.loyalty' },
  { to: '/account/notifications', icon: Bell, key: 'account.notifications' }, { to: '/account/support', icon: MessageCircle, key: 'account.support' }, { to: '/account/profile', icon: Pencil, key: 'account.profile' },
] as const;

export function AccountLayout() {
  const { t } = useI18n();
  const { user, loading, logout } = useAuth();
  const authModal = useAuthModal();
  const nav = useNavigate();
  useDocTitle(t('account.title'));
  if (loading) return <div className="wrap py-20"><Spinner /></div>;
  if (!user) return <div className="wrap py-16"><EmptyState icon={<UserIcon size={28} />} title={t('account.signin_title')} text={t('account.signin_text')} action={<Button size="lg" onClick={() => authModal.open()}>{t('auth.sign_in')}</Button>} /></div>;
  return (
    <div className="wrap py-8 md:py-12">
      <div className="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div><p className="eyebrow">{t('account.welcome')}</p><h1 className="text-[clamp(34px,5vw,56px)] leading-none">{user.name}</h1></div>
        <Button variant="ghost" onClick={async () => { await logout(); nav('/'); }}><LogOut size={16} />{t('account.signout')}</Button>
      </div>
      <div className="grid gap-8 lg:grid-cols-[250px_minmax(0,1fr)]">
        <nav aria-label={t('account.title')} className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-2 lg:mx-0 lg:flex-col lg:gap-1 lg:overflow-visible lg:px-0">
          {NAV.map(({ to, icon: I, key, ...r }) => (
            <NavLink key={to} to={to} end={'end' in r} className={({ isActive }) => cx('flex shrink-0 items-center gap-3 rounded-2xl px-4 py-3 text-sm font-medium transition-colors', isActive ? 'bg-primary text-bg' : 'text-ink-soft hover:bg-sand')}><I size={17} />{t(key)}</NavLink>
          ))}
        </nav>
        <div className="min-w-0"><Outlet /></div>
      </div>
    </div>
  );
}

/* ───────────── Overview ───────────── */
export function Overview() {
  const { t, num } = useI18n();
  const { user } = useAuth();
  const orders = useQuery({ queryKey: ['orders', 1], queryFn: async () => get<Paged<OrderSummary>>('/orders', { per_page: 3 }) });
  const loy = useQuery({ queryKey: ['loyalty'], queryFn: async () => (await get<{ data: Loyalty }>('/me/loyalty')).data });
  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-3">
        <Stat label={t('account.orders')} value={orders.data ? num(orders.data.meta.total) : '…'} to="/account/orders" />
        <Stat label={t('loyalty.balance')} value={loy.data ? num(loy.data.balance) : '…'} sub={loy.data?.value.formatted} to="/account/loyalty" tone="brass" />
        <Stat label={t('loyalty.tier')} value={loy.data ? t(`tier.${loy.data.tier}`) : '…'} to="/account/loyalty" />
      </div>
      <section className="rounded-[28px] border border-line bg-paper p-6">
        <div className="mb-4 flex items-center justify-between"><h2 className="text-2xl">{t('account.recent')}</h2><Link to="/account/orders" className="text-sm text-brass-deep hover:underline">{t('action.view_all')}</Link></div>
        {orders.isLoading ? <Skeleton className="h-24" /> : orders.data?.data.length ? <OrderList items={orders.data.data} /> : <p className="py-6 text-center text-muted">{t('orders.none')}</p>}
      </section>
      {user && <ReferralCard />}
    </div>
  );
}
function Stat({ label, value, sub, to, tone }: { label: string; value: string; sub?: string; to: string; tone?: 'brass' }) {
  return <Link to={to} className={cx('rounded-[24px] border p-5 transition-shadow hover:shadow-soft', tone === 'brass' ? 'border-brass/40 bg-brass-tint/60' : 'border-line bg-paper')}><p className="eyebrow">{label}</p><p className="mt-2 font-display text-5xl leading-none">{value}</p>{sub && <p className="mt-1 text-sm text-muted">{sub}</p>}</Link>;
}
function ReferralCard() {
  const { t } = useI18n();
  const toast = useToast();
  const ref = useQuery({ queryKey: ['referral'], queryFn: async () => (await get<{ data: Referral }>('/me/referral')).data });
  const r = ref.data;
  // The programme is switched off in the dashboard (the API then returns only `enabled: false`).
  if (!r || !r.enabled || !r.code) return null;
  const copy = async () => {
    try { await navigator.clipboard.writeText(r.share_url); toast.push(t('product.link_copied')); } catch { toast.push(r.share_url, 'info'); }
  };
  return (
    <section className="rounded-[28px] bg-primary p-6 text-bg">
      <p className="eyebrow !text-brass">{t('referral.eyebrow')}</p>
      <h2 className="mt-1 text-3xl">{t('referral.title')}</h2>
      {r.referrer_reward?.text && <p className="mt-2 text-bg/80">{r.referrer_reward.text}</p>}
      <div className="mt-4 flex flex-wrap items-center gap-3">
        <code className="rounded-full bg-bg/10 px-5 py-2.5 text-lg tracking-widest" dir="ltr">{r.code}</code>
        <Button variant="brass" size="sm" onClick={copy}><Copy size={14} />{t('referral.copy')}</Button>
        <span className="text-sm text-bg/70">{t('referral.count', { n: r.referred_count })}</span>
      </div>
    </section>
  );
}

/* ───────────── Orders ───────────── */
function OrderList({ items }: { items: OrderSummary[] }) {
  const { t, date } = useI18n();
  return (
    <ul className="divide-y divide-line">
      {items.map((o) => (
        <li key={o.id}>
          <Link to={`/orders/${o.id}`} className="flex items-center gap-4 py-4 transition-colors hover:bg-sand/40 sm:px-2">
            <div className="w-16 shrink-0"><Pic src={o.thumbnail_url} alt={o.number} ratio="1/1" className="rounded-2xl" /></div>
            <div className="min-w-0 flex-1"><p className="font-display text-2xl leading-none">{o.number}</p><p className="mt-1 text-sm text-muted">{date(o.placed_at, { dateStyle: 'medium' })} · {t('orders.items', { n: o.items_count })}</p></div>
            <div className="text-end"><p className="font-semibold tabular-nums">{o.grand_total.formatted}</p><Badge tone={statusTone(o.status)}>{o.status_label}</Badge></div>
          </Link>
        </li>
      ))}
    </ul>
  );
}
export function Orders() {
  const { t } = useI18n();
  const [page, setPage] = useState(1);
  const q = useQuery({ queryKey: ['orders', page], queryFn: async () => get<Paged<OrderSummary>>('/orders', { page, per_page: 10 }), placeholderData: (p) => p });
  return (
    <section className="rounded-[28px] border border-line bg-paper p-6">
      <h2 className="mb-2 text-3xl">{t('account.orders')}</h2>
      {q.isLoading ? <Skeleton className="mt-4 h-40" /> : q.data?.data.length ? (
        <>
          <OrderList items={q.data.data} />
          {q.data.meta.last_page > 1 && <div className="mt-4 flex justify-center gap-3"><Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('action.prev')}</Button><Button variant="outline" size="sm" disabled={page >= q.data.meta.last_page} onClick={() => setPage(page + 1)}>{t('action.next')}</Button></div>}
        </>
      ) : <EmptyState icon={<Package size={28} />} title={t('orders.none')} text={t('orders.none_text')} action={<LinkButton to="/shop">{t('cart.continue')}</LinkButton>} />}
    </section>
  );
}

/* ───────────── Addresses ───────────── */
export function Addresses() {
  const { t } = useI18n();
  const qc = useQueryClient();
  const toast = useToast();
  const [edit, setEdit] = useState<Address | 'new' | null>(null);
  const q = useQuery({ queryKey: ['addresses'], queryFn: async () => (await get<{ data: Address[] }>('/me/addresses')).data });
  const remove = useMutation({ mutationFn: (id: string) => del(`/me/addresses/${id}`), onSuccess: () => qc.invalidateQueries({ queryKey: ['addresses'] }), onError: (e) => toast.push(e instanceof ApiError ? e.message : t('error.generic'), 'error') });
  const makeDefault = useMutation({ mutationFn: (id: string) => post(`/me/addresses/${id}/default`, {}), onSuccess: () => qc.invalidateQueries({ queryKey: ['addresses'] }) });
  return (
    <section className="rounded-[28px] border border-line bg-paper p-6">
      <div className="mb-5 flex items-center justify-between"><h2 className="text-3xl">{t('account.addresses')}</h2><Button size="sm" onClick={() => setEdit('new')}><Plus size={15} />{t('address.add')}</Button></div>
      {q.isLoading ? <Skeleton className="h-32" /> : q.data?.length ? (
        <ul className="grid gap-4 sm:grid-cols-2">
          {q.data.map((a) => (
            <li key={a.id} className={cx('rounded-3xl border p-5', a.is_default ? 'border-brass' : 'border-line')}>
              <div className="flex items-center justify-between"><b>{a.label || a.recipient_name}</b>{a.is_default && <Badge tone="brass">{t('address.default')}</Badge>}</div>
              <p className="mt-2 text-sm text-ink-soft">{a.summary}{a.area ? `, ${a.area}` : ''}</p>
              <p className="text-sm text-muted">{a.recipient_name} · <bdi>{a.recipient_phone}</bdi></p>
              <div className="mt-4 flex flex-wrap gap-2">
                <Button size="sm" variant="outline" onClick={() => setEdit(a)}><Pencil size={13} />{t('action.edit')}</Button>
                {!a.is_default && <Button size="sm" variant="ghost" onClick={() => makeDefault.mutate(a.id)}>{t('address.make_default')}</Button>}
                <Button size="sm" variant="ghost" className="text-danger" onClick={() => confirm(t('address.delete_confirm')) && remove.mutate(a.id)}><Trash2 size={13} />{t('action.delete')}</Button>
              </div>
            </li>
          ))}
        </ul>
      ) : <EmptyState icon={<MapPin size={28} />} title={t('address.none')} text={t('address.none_text')} />}
      <Modal open={!!edit} onClose={() => setEdit(null)} title={edit === 'new' ? t('address.add') : t('address.edit')} wide>
        {edit && <AddressForm initial={edit === 'new' ? undefined : edit} onCancel={() => setEdit(null)} onSaved={() => { setEdit(null); void qc.invalidateQueries({ queryKey: ['addresses'] }); }} />}
      </Modal>
    </section>
  );
}

/* ───────────── Wishlist ───────────── */
export function Wishlist() {
  const { t } = useI18n();
  const wl = useWishlist();
  return (
    <section className="rounded-[28px] border border-line bg-paper p-6">
      <h2 className="mb-6 text-3xl">{t('wishlist.title')}</h2>
      {wl.loading ? <Skeleton className="h-40" /> : wl.items.length ? <CardGrid cols={3}>{wl.items.map((i) => <ProductCard key={i.id} p={i.product!} />)}</CardGrid>
        : <EmptyState icon={<Heart size={28} />} title={t('wishlist.empty')} text={t('wishlist.empty_text')} action={<LinkButton to="/shop">{t('cart.continue')}</LinkButton>} />}
    </section>
  );
}

/* ───────────── Loyalty ───────────── */
export function LoyaltyPage() {
  const { t, num, date } = useI18n();
  const q = useQuery({ queryKey: ['loyalty'], queryFn: async () => (await get<{ data: Loyalty }>('/me/loyalty')).data });
  if (!q.data) return <Skeleton className="h-64" />;
  const l = q.data;
  return (
    <div className="space-y-6">
      <section className="relative overflow-hidden rounded-[28px] bg-primary p-8 text-bg">
        <Star className="absolute -end-6 -top-6 text-brass/20" size={160} strokeWidth={1} />
        <p className="eyebrow !text-brass">{t('loyalty.tier')}: {t(`tier.${l.tier}`)}</p>
        <p className="mt-3 font-display text-7xl leading-none">{num(l.balance)}</p>
        <p className="mt-2 text-bg/70">{t('loyalty.worth', { v: l.value.formatted })}</p>
      </section>
      <section className="rounded-[28px] border border-line bg-paper p-6">
        <h2 className="mb-4 text-2xl">{t('loyalty.history')}</h2>
        {l.transactions.length === 0 ? <p className="text-muted">{t('loyalty.none')}</p> : (
          <ul className="divide-y divide-line">{l.transactions.map((x, i) => (
            <li key={i} className="flex items-center justify-between gap-4 py-3.5"><div><p className="font-medium">{x.reason || t(`loyalty.type.${x.type}`)}</p><p className="text-xs text-muted">{date(x.created_at, { dateStyle: 'medium' })}{x.expires_at ? ` · ${t('loyalty.expires', { d: date(x.expires_at, { dateStyle: 'medium' }) })}` : ''}</p></div>
              <span className={cx('font-semibold tabular-nums', x.points >= 0 ? 'text-success' : 'text-danger')}>{x.points > 0 ? '+' : ''}{num(x.points)}</span></li>
          ))}</ul>
        )}
      </section>
    </div>
  );
}

/* ───────────── Notifications ───────────── */
export function Notifications() {
  const { t, date } = useI18n();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: ['notifications'], queryFn: async () => get<{ data: { id: string; title: string; body: string; read_at: string | null; created_at: string }[] }>('/me/notifications', { per_page: 30 }) });
  const readAll = useMutation({ mutationFn: () => post('/me/notifications/read-all', {}), onSuccess: () => qc.invalidateQueries({ queryKey: ['notifications'] }) });
  return (
    <section className="rounded-[28px] border border-line bg-paper p-6">
      <div className="mb-4 flex items-center justify-between"><h2 className="text-3xl">{t('account.notifications')}</h2>{q.data?.data.some((n) => !n.read_at) && <Button size="sm" variant="ghost" onClick={() => readAll.mutate()}>{t('notifications.read_all')}</Button>}</div>
      {q.isLoading ? <Skeleton className="h-32" /> : q.data?.data.length ? (
        <ul className="divide-y divide-line">{q.data.data.map((n) => <li key={n.id} className="flex gap-3 py-4"><span className={cx('mt-2 h-2 w-2 shrink-0 rounded-full', n.read_at ? 'bg-transparent' : 'bg-brass')} /><div><p className="font-medium">{n.title}</p><p className="text-sm text-ink-soft">{n.body}</p><p className="mt-1 text-xs text-muted">{date(n.created_at)}</p></div></li>)}</ul>
      ) : <EmptyState icon={<Bell size={28} />} title={t('notifications.none')} />}
    </section>
  );
}

/* ───────────── Support ───────────── */
export function Support() {
  const { t, date } = useI18n();
  const qc = useQueryClient();
  const toast = useToast();
  const [open, setOpen] = useState(false);
  const [subject, setSubject] = useState('');
  const [message, setMessage] = useState('');
  const [category, setCategory] = useState('general');
  const [err, setErr] = useState<ApiError | null>(null);
  const q = useQuery({ queryKey: ['tickets'], queryFn: async () => (await get<{ data: Ticket[] }>('/me/support/tickets')).data });
  const create = useMutation({
    mutationFn: () => post('/me/support/tickets', { subject, message, category }),
    onSuccess: () => { toast.push(t('support.sent')); setOpen(false); setSubject(''); setMessage(''); void qc.invalidateQueries({ queryKey: ['tickets'] }); },
    onError: (e) => setErr(e instanceof ApiError ? e : null),
  });
  return (
    <section className="rounded-[28px] border border-line bg-paper p-6">
      <div className="mb-5 flex items-center justify-between"><h2 className="text-3xl">{t('account.support')}</h2><Button size="sm" onClick={() => { setErr(null); setOpen(true); }}><Plus size={15} />{t('support.new')}</Button></div>
      {q.isLoading ? <Skeleton className="h-24" /> : q.data?.length ? (
        <ul className="divide-y divide-line">{q.data.map((x) => <li key={x.id} className="flex items-center justify-between gap-3 py-4"><div><p className="font-medium">{String(x.subject ?? x.id)}</p><p className="text-xs text-muted">{date(x.created_at)}</p></div><Badge tone={statusTone(x.status)}>{x.status}</Badge></li>)}</ul>
      ) : <EmptyState icon={<MessageCircle size={28} />} title={t('support.none')} text={t('support.none_text')} />}
      <Modal open={open} onClose={() => setOpen(false)} title={t('support.new')}>
        <form onSubmit={(e) => { e.preventDefault(); setErr(null); create.mutate(); }} className="space-y-4">
          {err && !Object.keys(err.errors).length && <Alert tone="danger">{err.message}</Alert>}
          <Select label={t('support.category')} value={category} onChange={(e) => setCategory(e.target.value)}>{['general', 'order', 'payment', 'delivery', 'product', 'returns'].map((c) => <option key={c} value={c}>{t(`support.cat.${c}`)}</option>)}</Select>
          <Input label={t('support.subject')} required value={subject} onChange={(e) => setSubject(e.target.value)} maxLength={160} error={err?.field('subject')} />
          <Textarea label={t('support.message')} required value={message} onChange={(e) => setMessage(e.target.value)} maxLength={4000} error={err?.field('message')} />
          <Button type="submit" loading={create.isPending}>{t('support.send')}</Button>
        </form>
      </Modal>
    </section>
  );
}

/* ───────────── Profile ───────────── */
export function Profile() {
  const { t } = useI18n();
  const { user, updateProfile, reload } = useAuth();
  const toast = useToast();
  const [name, setName] = useState(user?.name ?? '');
  const [email, setEmail] = useState(user?.email ?? '');
  const [marketing, setMarketing] = useState(user?.marketing_consent ?? false);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<ApiError | null>(null);
  const [pw, setPw] = useState({ current: '', next: '' });
  const [pwErr, setPwErr] = useState<ApiError | null>(null);
  if (!user) return <Navigate to="/account" />;
  const save = async (e: React.FormEvent) => { e.preventDefault(); setBusy(true); setErr(null); try { await updateProfile({ name, email: email || null, marketing_consent: marketing }); toast.push(t('profile.saved')); } catch (x) { setErr(x instanceof ApiError ? x : null); } finally { setBusy(false); } };
  const savePw = async (e: React.FormEvent) => { e.preventDefault(); setPwErr(null); try { await put('/me/password', { current_password: user.has_password ? pw.current : undefined, password: pw.next, password_confirmation: pw.next }); toast.push(t('profile.password_saved')); setPw({ current: '', next: '' }); void reload(); } catch (x) { setPwErr(x instanceof ApiError ? x : null); } };
  return (
    <div className="space-y-6">
      <form onSubmit={save} className="space-y-4 rounded-[28px] border border-line bg-paper p-6">
        <h2 className="text-3xl">{t('account.profile')}</h2>
        {err && !Object.keys(err.errors).length && <Alert tone="danger">{err.message}</Alert>}
        <Input label={t('auth.name')} value={name} onChange={(e) => setName(e.target.value)} required error={err?.field('name')} />
        <Input label={t('auth.phone')} value={user.phone} disabled hint={t('profile.phone_locked')} />
        <Input label={t('auth.email_optional')} type="email" value={email} onChange={(e) => setEmail(e.target.value)} error={err?.field('email')} />
        <Checkbox checked={marketing} onChange={setMarketing}>{t('auth.marketing')}</Checkbox>
        <Button type="submit" loading={busy}>{t('action.save')}</Button>
      </form>
      <form onSubmit={savePw} className="space-y-4 rounded-[28px] border border-line bg-paper p-6">
        <h2 className="text-2xl">{user.has_password ? t('profile.change_password') : t('profile.set_password')}</h2>
        {pwErr && !Object.keys(pwErr.errors).length && <Alert tone="danger">{pwErr.message}</Alert>}
        {user.has_password && <Input label={t('profile.current_password')} type="password" autoComplete="current-password" value={pw.current} onChange={(e) => setPw({ ...pw, current: e.target.value })} error={pwErr?.field('current_password')} />}
        <Input label={t('auth.new_password')} type="password" autoComplete="new-password" value={pw.next} onChange={(e) => setPw({ ...pw, next: e.target.value })} hint={t('auth.password_rule')} error={pwErr?.field('password')} />
        <Button type="submit" variant="outline" disabled={pw.next.length < 8}>{t('action.save')}</Button>
      </form>
    </div>
  );
}

/* ───────────── My auctions ───────────── */
export function MyAuctions() {
  const { t } = useI18n();
  const wins = useQuery({ queryKey: ['wins'], queryFn: async () => (await get<{ data: { id: string; lot: Lot; amount: { formatted: string }; status: string; pay_by?: string }[] }>('/me/auctions/wins')).data });
  const watch = useQuery({ queryKey: ['watch'], queryFn: async () => (await get<{ data: Lot[] }>('/me/auctions/watchlist')).data });
  return (
    <div className="space-y-6">
      <section className="rounded-[28px] border border-line bg-paper p-6"><h2 className="mb-4 text-3xl">{t('auction.my_wins')}</h2>
        {wins.isLoading ? <Skeleton className="h-20" /> : wins.data?.length ? <ul className="divide-y divide-line">{wins.data.map((w) => <li key={w.id} className="flex items-center justify-between py-3"><span className="font-display text-xl">{w.lot?.title}</span><span className="font-semibold">{w.amount?.formatted}</span></li>)}</ul> : <p className="text-muted">{t('auction.no_wins')}</p>}</section>
      <section className="rounded-[28px] border border-line bg-paper p-6"><h2 className="mb-4 text-3xl">{t('auction.watchlist')}</h2>
        {watch.isLoading ? <Skeleton className="h-20" /> : watch.data?.length ? <ul className="divide-y divide-line">{watch.data.map((l) => <li key={l.id}><Link to={`/auctions/lots/${l.id}`} className="flex items-center justify-between py-3 hover:text-brass-deep"><span className="font-display text-xl">{l.title}</span><span className="font-semibold">{l.current_price.formatted}</span></Link></li>)}</ul> : <p className="text-muted">{t('auction.no_watch')}</p>}</section>
    </div>
  );
}
export type { Auction };
export { useParams };
