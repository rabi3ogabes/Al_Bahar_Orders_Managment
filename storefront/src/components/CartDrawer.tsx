import { useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { ArrowRight, Plus, ShoppingBag, Trash2, Undo2 } from 'lucide-react';
import { get } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useCart, type Line } from '../lib/cart';
import { useI18n } from '../lib/i18n';
import { cx } from '../lib/format';
import type { ProductCard } from '../lib/types';
import { useAuthModal } from './AuthModal';
import { DrawerLink, useDrawerNavigate } from './drawerLink';
import { SideDrawer } from './SideDrawer';
import { Alert, Button, Pic, PriceTag, QtyStepper, Skeleton } from './ui';

/** The mini-cart: items, quantity, remove with Undo, suggestions and the pinned checkout button, without leaving the page. */
export default function CartDrawer() {
  const { t } = useI18n();
  const cart = useCart();
  const title = `${t('cart.title')}${cart.count ? ` (${cart.count})` : ''}`;
  return (
    <SideDrawer open={cart.drawer} onClose={cart.closeDrawer} title={title} description={t('cart.items')} footer={cart.lines.length ? <CartFooter /> : null}>
      <CartBody />
    </SideDrawer>
  );
}

function CartFooter() {
  const { t } = useI18n();
  const cart = useCart();
  const { user } = useAuth();
  const authModal = useAuthModal();
  const go = useDrawerNavigate();
  const blocked = cart.lines.some((l) => !l.available);
  const checkout = () => (user ? go('/checkout') : authModal.open({ reason: t('auth.reason_checkout'), onDone: () => go('/checkout') }));
  return (
    <div>
      <div className="mb-1 flex items-baseline justify-between"><span className="text-sm text-muted">{t('cart.subtotal')}</span><span className="text-xl font-semibold tabular-nums">{cart.subtotal.formatted}</span></div>
      <p className="mb-3 text-xs text-muted">{t('cart.shipping_note')}</p>
      <Button size="lg" block disabled={blocked} onClick={checkout}>{t('cart.checkout')} · {cart.subtotal.formatted}</Button>
      {blocked && <p role="alert" className="mt-2 text-center text-xs text-danger">{t('cart.fix_items')}</p>}
      <DrawerLink to="/cart" className="mt-1.5 flex h-11 w-full items-center justify-center rounded-full text-sm font-medium text-ink transition-colors hover:bg-sand">{t('cart.view')}</DrawerLink>
    </div>
  );
}

function CartBody() {
  const { t } = useI18n();
  const cart = useCart();
  const go = useDrawerNavigate();
  const [removed, setRemoved] = useState<Line | null>(null);
  const timer = useRef<number>();
  useEffect(() => () => window.clearTimeout(timer.current), []);

  const remove = async (l: Line) => {
    setRemoved(l);
    window.clearTimeout(timer.current);
    timer.current = window.setTimeout(() => setRemoved(null), 8000);
    await cart.remove(l);
  };
  const undo = async () => {
    const l = removed;
    if (!l) return;
    setRemoved(null);
    window.clearTimeout(timer.current);
    await cart.restore(l);
  };

  if (cart.loading) return <div className="space-y-4 p-6"><Skeleton className="h-28" /><Skeleton className="h-28" /></div>;
  const empty = cart.lines.length === 0;
  return (
    <div className="flex min-h-full flex-col">
      {cart.cart?.warnings?.map((w, i) => <div key={i} className="px-6 pt-4"><Alert tone="warning">{w.message ?? w.code}</Alert></div>)}
      {empty ? (
        <div className="flex flex-1 flex-col items-center justify-center px-8 py-12 text-center">
          <ShoppingBag size={44} strokeWidth={1.2} className="text-brass" />
          <p className="mt-4 font-display text-2xl">{t('cart.empty')}</p>
          <p className="mt-1 text-sm text-muted">{t('cart.empty_text')}</p>
          <Button className="mt-6" onClick={() => go('/shop')}>{t('cart.continue')}</Button>
        </div>
      ) : (
        <ul className="divide-y divide-line px-6" aria-label={t('cart.items')}>
          {cart.lines.map((l) => <CartLine key={l.key} l={l} fresh={cart.lastAdded === l.variantId} onRemove={() => void remove(l)} />)}
        </ul>
      )}
      {removed && (
        <div role="status" className="sticky bottom-3 z-10 mx-6 mt-3 flex items-center justify-between gap-3 rounded-2xl bg-ink px-4 py-2 text-sm text-bg shadow-soft">
          <span className="min-w-0 truncate">{t('cart.removed', { name: removed.name })}</span>
          <button type="button" onClick={() => void undo()} className="flex h-11 shrink-0 items-center gap-1.5 rounded-full px-3 font-semibold text-accent hover:underline"><Undo2 size={15} />{t('action.undo')}</button>
        </div>
      )}
      <Suggestions />
    </div>
  );
}

function CartLine({ l, fresh, onRemove }: { l: Line; fresh: boolean; onRemove: () => void }) {
  const { t, locale } = useI18n();
  const cart = useCart();
  const ref = useRef<HTMLLIElement>(null);
  useEffect(() => { if (fresh) ref.current?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }, [fresh]);
  return (
    <li ref={ref} className={cx('flex gap-4 py-5', fresh && 'live-flash')} aria-label={l.name}>
      <DrawerLink to={`/products/${l.slug}`} className="w-24 shrink-0" aria-label={l.name}><Pic src={l.image} alt={l.name} ratio="1/1" className="rounded-2xl" label={l.brand ?? undefined} /></DrawerLink>
      <div className="min-w-0 flex-1">
        <DrawerLink to={`/products/${l.slug}`} className="line-clamp-2 font-display text-xl leading-tight hover:text-brass-deep">{l.name}</DrawerLink>
        {l.variantName && <p className="text-[13px] text-muted">{l.variantName}</p>}
        {l.priceChanged && <p className="mt-1 text-[12px] font-medium text-warning">{t('cart.price_changed')}</p>}
        {l.issue && <p className="mt-1 text-[12px] font-medium text-danger">{t(`cart.issue.${l.issue}`)}</p>}
        <p className="mt-1 text-[13px] text-muted tabular-nums" lang={locale}>{l.unit.formatted}</p>
        <div className="mt-2 flex items-center justify-between gap-2">
          <QtyStepper value={l.qty} max={l.max} min={1} onChange={(n) => void cart.setQty(l, n)} />
          <span className="text-[15px] font-semibold tabular-nums" lang={locale}>{l.total.formatted}</span>
        </div>
        <button type="button" onClick={onRemove} className="-ms-2 mt-1 flex h-11 items-center gap-1.5 rounded-full px-2 text-[13px] text-muted transition-colors hover:text-danger"><Trash2 size={15} />{t('cart.remove')}</button>
      </div>
    </li>
  );
}

/** "You may also need": what the server suggests for this bag (signed-in customers), one tap to add. */
function Suggestions() {
  const { t } = useI18n();
  const { user } = useAuth();
  const cart = useCart();
  const ids = cart.lines.map((l) => l.variantId).sort().join(',');
  const q = useQuery({ queryKey: ['cart-suggestions', user?.id, ids], enabled: !!user && cart.lines.length > 0, placeholderData: (p) => p, queryFn: async () => (await get<{ data: ProductCard[] }>('/cart/suggestions')).data });
  const items = (q.data ?? []).filter((p) => !cart.lines.some((l) => l.variantId === p.variant_id)).slice(0, 8);
  if (!user || items.length === 0) return null;
  return (
    <section className="mt-auto border-t border-line px-6 pt-5 pb-6" aria-labelledby="mini-up">
      <h3 id="mini-up" className="mb-3 font-display text-xl">{t('upsell.cart')}</h3>
      <ul className="-mx-6 flex snap-x gap-3 overflow-x-auto px-6 pb-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        {items.map((p) => <Suggestion key={p.id} p={p} />)}
      </ul>
    </section>
  );
}
function Suggestion({ p }: { p: ProductCard }) {
  const { t, dir } = useI18n();
  const cart = useCart();
  const [busy, setBusy] = useState(false);
  const quick = !!p.variant_id && !p.has_variants && p.in_stock && p.purchasable;
  const add = async () => { setBusy(true); try { await cart.quickAdd(p); } catch { /* the cart showed the reason */ } finally { setBusy(false); } };
  return (
    <li className="w-40 shrink-0 snap-start rounded-2xl border border-line bg-paper p-2.5">
      <DrawerLink to={`/products/${p.slug}`} className="block" aria-label={p.name}>
        <Pic src={p.image?.thumb_url} alt="" ratio="1/1" className="rounded-xl" />
        <p className="mt-2 line-clamp-2 min-h-[2.5rem] text-[13px] font-medium leading-snug">{p.name}</p>
      </DrawerLink>
      <div className="mt-1 flex items-center justify-between gap-1">
        <PriceTag price={p.price} size="sm" />
        {quick
          ? <button type="button" disabled={busy} onClick={() => void add()} aria-label={`${t('product.add_to_bag')}: ${p.name}`} title={t('product.add_to_bag')} className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-primary text-on-primary transition-colors hover:bg-primary-soft disabled:opacity-40"><Plus size={18} /></button>
          : <DrawerLink to={`/products/${p.slug}`} aria-label={`${t('upsell.view')}: ${p.name}`} title={t('upsell.view')} className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-line text-ink transition-colors hover:bg-primary hover:text-on-primary"><ArrowRight size={18} className={dir === 'rtl' ? 'rotate-180' : ''} /></DrawerLink>}
      </div>
    </li>
  );
}
