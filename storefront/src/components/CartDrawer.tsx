import { useContext, useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { ArrowRight, CheckCircle2, ExternalLink, Minus, Plus, ShoppingBag, Trash2, Undo2 } from 'lucide-react';
import { get } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useCart, type Line } from '../lib/cart';
import { useI18n } from '../lib/i18n';
import { cx } from '../lib/format';
import type { ProductCard } from '../lib/types';
import { useAuthModal } from './AuthModal';
import { DrawerCtx, DrawerLink, useDrawerNavigate } from './drawerLink';
import { SideDrawer } from './SideDrawer';
import { Alert, Button, Pic, PriceTag, Skeleton } from './ui';

/**
 * The cart panel. "Add to cart" anywhere opens it over the current page (end side drawer, a bottom sheet on phones) and it
 * stays until it is dismissed: no timers. It confirms the add, shows the added item with a quantity control, the live
 * subtotal, and exactly two actions: Checkout and Keep shopping. The header cart icon opens the same panel without the confirmation.
 */
export default function CartDrawer() {
  const { t } = useI18n();
  const cart = useCart();
  const title = `${t('cart.title')}${cart.count ? ` (${cart.count})` : ''}`;
  return (
    <SideDrawer open={cart.drawer} onClose={cart.closeDrawer} returnFocus={() => cart.opener.current} title={title} description={t('cart.items')} footer={<CartFooter />}
      headerAction={<DrawerLink to="/cart" aria-label={t('cart.view_full')} title={t('cart.view_full')} className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-ink transition-colors hover:bg-sand"><ExternalLink size={19} /></DrawerLink>}>
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
  const { close } = useContext(DrawerCtx);
  const [busy, setBusy] = useState(false);
  const blocked = cart.lines.some((l) => !l.available);
  // Same guards as the cart page: signed in (else the sign-in window first), nothing unavailable. Pending cart changes reach the server first.
  const checkout = async () => {
    if (busy) return;
    setBusy(true);
    try { await cart.flush(); } finally { setBusy(false); }
    if (user) go('/checkout'); else authModal.open({ reason: t('auth.reason_checkout'), onDone: () => go('/checkout') });
  };
  return (
    <div>
      <div className="mb-1 flex items-baseline justify-between"><span className="text-sm text-muted">{t('cart.subtotal')}</span><span className="text-xl font-semibold tabular-nums" aria-live="polite" data-testid="cart-subtotal">{cart.subtotal.formatted}</span></div>
      <p className="mb-3 text-xs text-muted">{t('cart.shipping_note')}</p>
      <Button size="lg" block loading={busy} disabled={blocked || cart.lines.length === 0} onClick={() => void checkout()}>{t('cart.checkout')}</Button>
      {blocked && <p role="alert" className="mt-2 text-center text-xs text-danger">{t('cart.fix_items')}</p>}
      <Button size="lg" variant="outline" block className="mt-2" onClick={close}>{t('cart.keep')}</Button>
    </div>
  );
}

function CartBody() {
  const { t } = useI18n();
  const cart = useCart();
  // The line that was just removed with the quantity control. It stays here, with Undo, until the panel closes: no timer.
  const [removed, setRemoved] = useState<Line | null>(null);
  useEffect(() => { if (!cart.drawer) setRemoved(null); }, [cart.drawer]);

  const remove = (l: Line) => { setRemoved(l); void cart.remove(l); };
  const undo = () => { const l = removed; if (!l) return; setRemoved(null); void cart.restore(l); };

  if (cart.loading) return <div className="space-y-4 p-6"><Skeleton className="h-28" /><Skeleton className="h-28" /></div>;
  const added = cart.lines.find((l) => l.variantId === cart.lastAdded) ?? null;
  const others = added ? cart.lines.filter((l) => l !== added) : cart.lines;
  return (
    <div className="flex min-h-full flex-col">
      {cart.cart?.warnings?.map((w, i) => <div key={i} className="px-6 pt-4"><Alert tone="warning">{w.message ?? w.code}</Alert></div>)}
      {added && (
        <>
          <div role="status" className="mx-6 mt-5 flex items-center gap-2.5 rounded-2xl bg-success/10 px-4 py-3 text-success">
            <CheckCircle2 size={20} className="shrink-0" /><span className="font-semibold">{t('cart.added')}</span>
            <span className="sr-only">{t('cart.qty_now', { name: added.name, n: added.qty })}</span>
          </div>
          <ul className="px-6 pt-3" aria-label={t('cart.added')}><CartLine l={added} size="focus" onRemove={remove} /></ul>
        </>
      )}
      {cart.lines.length === 0 && (
        <div className="flex flex-1 flex-col items-center justify-center px-8 py-12 text-center">
          <ShoppingBag size={44} strokeWidth={1.2} className="text-brass" />
          <p className="mt-4 font-display text-2xl">{t('cart.empty')}</p>
          <p className="mt-1 text-sm text-muted">{t('cart.empty_text')}</p>
        </div>
      )}
      {others.length > 0 && (
        <section className="px-6 pt-5" aria-labelledby={added ? 'cart-also' : undefined} aria-label={added ? undefined : t('cart.items')}>
          {added && <h3 id="cart-also" className="eyebrow mb-1">{t('cart.also')}</h3>}
          <ul className="divide-y divide-line">{others.map((l) => <CartLine key={l.key} l={l} size={added ? 'compact' : 'regular'} onRemove={remove} />)}</ul>
        </section>
      )}
      {removed && (
        <div role="status" className="sticky bottom-3 z-10 mx-6 mt-4 flex items-center justify-between gap-3 rounded-2xl bg-ink px-4 py-2 text-sm text-bg shadow-soft">
          <span className="min-w-0 truncate">{t('cart.removed', { name: removed.name })}</span>
          <button type="button" onClick={undo} className="flex h-11 shrink-0 items-center gap-1.5 rounded-full px-3 font-semibold text-accent hover:underline"><Undo2 size={15} />{t('action.undo')}</button>
        </div>
      )}
      <Suggestions />
    </div>
  );
}

/** − at 1 removes the line (with Undo), so the minus shows a bin there. 44 px targets. */
function PanelQty({ l, onRemove }: { l: Line; onRemove: (l: Line) => void }) {
  const { t } = useI18n();
  const cart = useCart();
  const last = l.qty <= 1;
  return (
    <div role="group" aria-label={`${t('qty')}: ${l.name}`} className="inline-flex h-11 items-center rounded-full border border-line bg-paper">
      <button type="button" onClick={() => (last ? onRemove(l) : void cart.setQty(l, l.qty - 1))} aria-label={last ? t('cart.remove_item', { name: l.name }) : t('qty.less')} title={last ? t('cart.remove') : t('qty.less')}
        className={cx('flex h-11 w-11 items-center justify-center rounded-full transition-colors', last && 'text-danger')}>{last ? <Trash2 size={16} /> : <Minus size={16} />}</button>
      <span className="min-w-8 text-center text-sm font-semibold tabular-nums" aria-live="polite" data-testid="line-qty">{l.qty}</span>
      <button type="button" aria-label={t('qty.more')} disabled={l.qty >= l.max} onClick={() => void cart.setQty(l, l.qty + 1)} className="flex h-11 w-11 items-center justify-center rounded-full transition-colors disabled:opacity-30"><Plus size={16} /></button>
    </div>
  );
}

function CartLine({ l, size, onRemove }: { l: Line; size: 'focus' | 'regular' | 'compact'; onRemove: (l: Line) => void }) {
  const { t, locale } = useI18n();
  const focus = size === 'focus';
  return (
    <li data-testid="cart-line" data-variant={l.variantId} className={cx('flex gap-4', focus ? 'rounded-3xl border border-brass/40 bg-brass-tint/40 p-4' : size === 'regular' ? 'py-5' : 'py-4')} aria-label={l.name}>
      <DrawerLink to={`/products/${l.slug}`} className={cx('shrink-0', focus || size === 'regular' ? 'w-24' : 'w-16')} aria-label={l.name}><Pic src={l.image} alt={l.name} ratio="1/1" className={focus ? 'rounded-2xl' : 'rounded-xl'} label={focus ? (l.brand ?? undefined) : undefined} /></DrawerLink>
      <div className="min-w-0 flex-1">
        <DrawerLink to={`/products/${l.slug}`} className={cx('line-clamp-2 font-display leading-tight hover:text-brass-deep', focus ? 'text-2xl' : 'text-xl')}>{l.name}</DrawerLink>
        {l.variantName && <p className="text-[13px] text-muted">{l.variantName}</p>}
        {l.priceChanged && <p className="mt-1 text-[12px] font-medium text-warning">{t('cart.price_changed')}</p>}
        {l.issue && <p className="mt-1 text-[12px] font-medium text-danger">{t(`cart.issue.${l.issue}`)}</p>}
        <p className="mt-0.5 text-[14px] text-ink-soft tabular-nums" lang={locale}>{l.unit.formatted}</p>
        <div className="mt-2 flex items-center justify-between gap-2">
          <PanelQty l={l} onRemove={onRemove} />
          <span className="text-[15px] font-semibold tabular-nums" lang={locale}>{l.total.formatted}</span>
        </div>
      </div>
    </li>
  );
}

/** "You may also need": what the server suggests for this cart (signed-in customers), one tap to add. */
function Suggestions() {
  const { t } = useI18n();
  const { user } = useAuth();
  const cart = useCart();
  const ids = cart.lines.map((l) => l.variantId).sort().join(',');
  const q = useQuery({ queryKey: ['cart-suggestions', user?.id, ids], enabled: !!user && cart.lines.length > 0 && !cart.adding, placeholderData: (p) => p, queryFn: async () => (await get<{ data: ProductCard[] }>('/cart/suggestions')).data });
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
  const quick = !!p.variant_id && !p.has_variants && p.in_stock && p.purchasable;
  return (
    <li className="w-40 shrink-0 snap-start rounded-2xl border border-line bg-paper p-2.5">
      <DrawerLink to={`/products/${p.slug}`} className="block" aria-label={p.name}>
        <Pic src={p.image?.thumb_url} alt="" ratio="1/1" className="rounded-xl" />
        <p className="mt-2 line-clamp-2 min-h-[2.5rem] text-[13px] font-medium leading-snug">{p.name}</p>
      </DrawerLink>
      <div className="mt-1 flex items-center justify-between gap-1">
        <PriceTag price={p.price} size="sm" />
        {quick
          ? <button type="button" onClick={() => void cart.quickAdd(p)} aria-label={`${t('product.add_to_cart')}: ${p.name}`} title={t('product.add_to_cart')} className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-primary text-on-primary transition-colors hover:bg-primary-soft"><Plus size={18} /></button>
          : <DrawerLink to={`/products/${p.slug}`} aria-label={`${t('upsell.view')}: ${p.name}`} title={t('upsell.view')} className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-line text-ink transition-colors hover:bg-primary hover:text-on-primary"><ArrowRight size={18} className={dir === 'rtl' ? 'rotate-180' : ''} /></DrawerLink>}
      </div>
    </li>
  );
}
