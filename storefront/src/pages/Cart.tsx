import { Link, useNavigate } from 'react-router-dom';
import { ShoppingBag, Trash2, ShieldCheck, Truck, ArrowRight, ArrowLeft } from 'lucide-react';
import { useCart } from '../lib/cart';
import { useAuth } from '../lib/auth';
import { useI18n } from '../lib/i18n';
import { useDocTitle } from '../lib/hooks';
import { Alert, Button, EmptyState, Pic, QtyStepper, Skeleton } from '../components/ui';
import { useAuthModal } from '../components/AuthModal';
import { CartSuggestions } from '../components/Upsell';

export default function CartPage() {
  const { t, dir } = useI18n();
  const cart = useCart();
  const { user } = useAuth();
  const authModal = useAuthModal();
  const nav = useNavigate();
  const Arrow = dir === 'rtl' ? ArrowLeft : ArrowRight;
  useDocTitle(t('cart.title'));

  if (cart.loading) return <div className="wrap py-12"><Skeleton className="h-10 w-60" /><Skeleton className="mt-8 h-40" /></div>;
  if (!cart.lines.length) {
    return <div className="wrap py-16"><EmptyState icon={<ShoppingBag size={30} />} title={t('cart.empty')} text={t('cart.empty_text')} action={<Button size="lg" onClick={() => nav('/shop')}>{t('cart.continue')}</Button>} /></div>;
  }
  const blocked = cart.lines.some((l) => !l.available);
  const go = () => (user ? nav('/checkout') : authModal.open({ reason: t('auth.reason_checkout'), onDone: () => nav('/checkout') }));

  return (
    <div className="wrap py-8 md:py-12">
      <h1 className="text-[clamp(38px,5vw,60px)] leading-none">{t('cart.title')} <span className="text-muted">({cart.count})</span></h1>
      <div className="mt-10 grid gap-10 lg:grid-cols-[minmax(0,1fr)_380px]">
        <section aria-label={t('cart.items')}>
          {cart.cart?.warnings?.map((w, i) => <div key={i} className="mb-4"><Alert tone="warning">{w.message ?? w.code}</Alert></div>)}
          <ul className="divide-y divide-line border-y border-line">
            {cart.lines.map((l) => (
              <li key={l.key} className="flex gap-5 py-6">
                <Link to={`/products/${l.slug}`} className="w-28 shrink-0 sm:w-36"><Pic src={l.image} alt={l.name} ratio="1/1" className="rounded-3xl" label={l.brand ?? undefined} /></Link>
                <div className="flex min-w-0 flex-1 flex-col">
                  <div className="flex items-start justify-between gap-4">
                    <div className="min-w-0">
                      {l.brand && <p className="eyebrow !text-[10px] text-muted">{l.brand}</p>}
                      <Link to={`/products/${l.slug}`} className="font-display text-2xl leading-tight hover:text-brass-deep">{l.name}</Link>
                      {l.variantName && <p className="text-sm text-muted">{l.variantName}</p>}
                    </div>
                    <p className="shrink-0 text-lg font-semibold tabular-nums">{l.total.formatted}</p>
                  </div>
                  {l.priceChanged && <p className="mt-1 text-xs font-medium text-warning">{t('cart.price_changed')}</p>}
                  {l.issue && <p className="mt-1 text-xs font-medium text-danger">{t(`cart.issue.${l.issue}`)}</p>}
                  <p className="mt-1 text-sm text-muted">{l.unit.formatted} × {l.qty}</p>
                  <div className="mt-auto flex items-center justify-between pt-4">
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
            <div className="flex justify-between"><dt className="text-muted">{t('checkout.delivery_fee')}</dt><dd className="text-muted">{t('cart.at_checkout')}</dd></div>
          </dl>
          <Button size="lg" block className="mt-6" disabled={blocked} onClick={go}>{t('cart.checkout')}<Arrow size={18} /></Button>
          {blocked && <p className="mt-2 text-center text-xs text-danger">{t('cart.fix_items')}</p>}
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
