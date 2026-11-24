import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { ShoppingBag, Trash2, ShieldCheck, Truck, ArrowRight, ArrowLeft, MessageCircle } from 'lucide-react';
import { useCart } from '../lib/cart';
import { useAuth } from '../lib/auth';
import { useBranch } from '../lib/branch';
import { useBasketPromise } from '../lib/usePromise';
import { basketShareText, whatsappShareHref } from '../lib/shareBasket';
import { useI18n } from '../lib/i18n';
import { useDocTitle } from '../lib/hooks';
import { Alert, Button, EmptyState, Pic, QtyStepper, Skeleton } from '../components/ui';
import { useAuthModal } from '../components/AuthModal';
import { CartSuggestions } from '../components/Upsell';
import { DeliveryFeeRow } from '../components/DeliveryFeeRow';
import { CartDealNote, CartDealsHint } from '../components/DealBits';
import { PromiseNote } from '../components/PromiseNote';
import { AssemblyChoice } from '../components/AssemblyChoice';
import { positiveMoney } from '../lib/deals';

export default function CartPage() {
  const { t, dir } = useI18n();
  const cart = useCart();
  const { user } = useAuth();
  const authModal = useAuthModal();
  const nav = useNavigate();
  const Arrow = dir === 'rtl' ? ArrowLeft : ArrowRight;
  const [busy, setBusy] = useState(false);
  const { branchId } = useBranch();
  // When will I get it? One date per parcel and an overall "all by", for a guest's basket and an account's alike (assembly adds its days).
  const promise = useBasketPromise({ lines: cart.lines.map((l) => ({ variantId: l.variantId, qty: l.qty, assembly: !!l.assembly?.selected })), type: 'delivery', branchId });
  useDocTitle(t('cart.title'));

  if (cart.loading) return <div className="wrap py-12"><Skeleton className="h-10 w-60" /><Skeleton className="mt-8 h-40" /></div>;
  if (!cart.lines.length) {
    return <div className="wrap py-16"><EmptyState heading="h1" icon={<ShoppingBag size={30} />} title={t('cart.empty')} text={t('cart.empty_text')} action={<Button size="lg" onClick={() => nav('/shop')}>{t('cart.continue')}</Button>} /></div>;
  }
  const blocked = cart.lines.some((l) => !l.available);
  const dealSavings = positiveMoney(cart.cart?.deal_savings);
  // Pending changes reach the server first; a second tap while that happens does nothing (the same as the cart panel).
  const go = async () => {
    if (busy) return;
    setBusy(true);
    try { await cart.flush(); } finally { setBusy(false); }
    if (user) nav('/checkout'); else authModal.open({ reason: t('auth.reason_checkout'), onDone: () => nav('/checkout') });
  };

  return (
    <div className="wrap py-8 md:py-12">
      <h1 className="text-[clamp(38px,5vw,60px)] leading-none">{t('cart.title')} <span className="text-muted">({cart.count})</span></h1>
      <div className="mt-10 grid grid-cols-[minmax(0,1fr)] gap-10 lg:grid-cols-[minmax(0,1fr)_380px]">
        <section aria-label={t('cart.items')}>
          {cart.cart?.warnings?.map((w, i) => <div key={i} className="mb-4"><Alert tone="warning">{w.message ?? w.code}</Alert></div>)}
          <ul className="divide-y divide-line border-y border-line">
            {cart.lines.map((l) => (
              <li key={l.key} className="flex gap-4 py-6 sm:gap-5">
                <Link to={`/products/${l.slug}`} className="w-24 shrink-0 min-[400px]:w-28 sm:w-36"><Pic src={l.image} alt={l.name} ratio="1/1" className="rounded-3xl" label={l.brand ?? undefined} /></Link>
                <div className="flex min-w-0 flex-1 flex-col">
                  {/* On a phone the price drops under the name instead of squeezing it into one word per line. */}
                  <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
                    <div className="min-w-0 flex-1 basis-36">
                      {l.brand && <p className="eyebrow !text-[10px] text-muted">{l.brand}</p>}
                      <Link to={`/products/${l.slug}`} className="font-display text-2xl leading-tight hover:text-brass-deep">{l.name}</Link>
                      {l.variantName && <p className="text-sm text-muted">{l.variantName}</p>}
                    </div>
                    <p className="shrink-0 text-lg font-semibold tabular-nums">{l.total.formatted}</p>
                  </div>
                  {l.priceChanged && <p className="mt-1 text-xs font-medium text-warning">{t('cart.price_changed')}</p>}
                  {l.issue && <p className="mt-1 text-xs font-medium text-danger">{t(`cart.issue.${l.issue}`)}</p>}
                  <p className="mt-1 text-sm text-muted">{l.unit.formatted} × {l.qty}</p>
                  <CartDealNote deal={l.deal} qty={l.qty} className="mt-2.5" />
                  {l.assembly && <AssemblyChoice fee={l.assembly.fee} checked={l.assembly.selected} total={l.assembly.amount} onChange={(on) => void cart.setAssembly(l, on)} className="mt-3" />}
                  <div className="mt-auto flex flex-wrap items-center justify-between gap-x-3 gap-y-2 pt-4">
                    <QtyStepper value={l.qty} max={l.max} onChange={(n) => cart.setQty(l, n)} />
                    <button onClick={() => cart.remove(l)} className="flex items-center gap-1.5 text-sm text-muted hover:text-danger"><Trash2 size={15} />{t('cart.remove')}</button>
                  </div>
                </div>
              </li>
            ))}
          </ul>
          <Link to="/shop" className="mt-6 inline-flex items-center gap-2 text-sm font-medium text-ink-soft hover:text-ink">{dir === 'rtl' ? <ArrowRight size={16} /> : <ArrowLeft size={16} />}{t('cart.continue')}</Link>
        </section>
        <aside className="h-fit rounded-[28px] border border-line bg-paper p-6 lg:sticky lg:top-32">
          <h2 className="text-3xl">{t('checkout.summary')}</h2>
          <dl className="mt-5 space-y-3 text-sm">
            <div className="flex justify-between"><dt className="text-muted">{t('cart.subtotal')}</dt><dd className="font-semibold tabular-nums">{cart.subtotal.formatted}</dd></div>
            {dealSavings && <div className="flex justify-between"><dt className="text-muted">{t('deal.savings')}</dt><dd className="font-semibold tabular-nums text-success">− {dealSavings.formatted}</dd></div>}
            {cart.assemblyTotal && <div className="flex justify-between" data-testid="cart-assembly-row"><dt className="text-muted">{t('cart.assembly')}</dt><dd className="font-semibold tabular-nums">+ {cart.assemblyTotal.formatted}</dd></div>}
            <DeliveryFeeRow />
          </dl>
          <PromiseNote promise={promise.data} loading={promise.isLoading} className="mt-4" />
          <CartDealsHint lines={cart.lines} className="mt-4" />
          <Button size="lg" block className="mt-6" disabled={blocked} loading={busy} onClick={() => void go()}>{t('cart.checkout')}<Arrow size={18} /></Button>
          {blocked && <p className="mt-2 text-center text-xs text-danger">{t('cart.fix_items')}</p>}
          <a href={whatsappShareHref(basketShareText(cart.lines.map((l) => ({ name: l.name, variantName: l.variantName, qty: l.qty, slug: l.slug })), { intro: t('share.basket_intro'), total: t('pay.none.share_total', { total: cart.subtotal.formatted }) }, window.location.origin))}
            target="_blank" rel="noopener noreferrer" data-testid="share-whatsapp-basket" className="mt-3 flex min-h-11 items-center justify-center gap-2 rounded-full text-sm font-medium text-ink-soft hover:text-ink"><MessageCircle size={17} strokeWidth={1.7} aria-hidden="true" />{t('share.whatsapp_basket')}</a>
          <ul className="mt-6 space-y-2 text-[13px] text-muted">
            <li className="flex gap-2"><ShieldCheck size={16} className="shrink-0 text-brass" />{t('checkout.secure')}</li>
            <li className="flex gap-2"><Truck size={16} className="shrink-0 text-brass" />{t('cart.shipping_note')}</li>
          </ul>
        </aside>
      </div>
      <CartSuggestions />
    </div>
  );
}
