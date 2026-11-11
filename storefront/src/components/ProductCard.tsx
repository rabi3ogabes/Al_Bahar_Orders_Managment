import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Heart, ShoppingBag } from 'lucide-react';
import { useI18n } from '../lib/i18n';
import { useWishlist } from '../lib/wishlist';
import { useCart } from '../lib/cart';
import { useToast } from '../lib/toast';
import { get } from '../lib/api';
import { cx } from '../lib/format';
import type { ProductCard as P, ProductDetail } from '../lib/types';
import { Badge, PriceTag, Pic, Rating } from './ui';

const BADGE_TONE: Record<string, 'brass' | 'ink' | 'danger' | 'success'> = { new: 'ink', sale: 'danger', bestseller: 'brass', featured: 'brass', limited: 'danger' };

export function ProductCard({ p, priority }: { p: P; priority?: boolean }) {
  const { t } = useI18n();
  const wl = useWishlist();
  const cart = useCart();
  const toast = useToast();
  const [busy, setBusy] = useState(false);
  const liked = wl.has(p.id);
  const badges = [...(p.price.discount_percent > 0 && !p.badges.includes('sale') ? ['sale'] : []), ...p.badges].filter((b) => BADGE_TONE[b]).slice(0, 2);

  const quickAdd = async (e: React.MouseEvent) => {
    e.preventDefault();
    setBusy(true);
    try {
      const d = (await get<{ data: ProductDetail }>(`/products/${p.slug}`)).data;
      const v = d.variants.find((x) => x.id === d.default_variant_id) ?? d.variants[0];
      if (!v) throw new Error('no variant');
      await cart.add({ id: p.id, slug: p.slug, name: p.name, brand: p.brand, image: p.image?.thumb_url }, v, 1);
    } catch { toast.push(t('error.generic'), 'error'); } finally { setBusy(false); }
  };

  return (
    <article className="group relative">
      <Link to={`/products/${p.slug}`} className="block" aria-label={p.name}>
        <div className="relative overflow-hidden rounded-[20px]">
          <Pic src={p.image?.thumb_url} alt={p.image?.alt ?? p.name} label={p.brand?.name} eager={priority} />
          <div className="absolute start-3 top-3 flex flex-col items-start gap-1.5">
            {badges.map((b) => <Badge key={b} tone={BADGE_TONE[b]}>{t(`badge.${b}`)}</Badge>)}
          </div>
          {!p.in_stock && <div className="absolute inset-0 flex items-end bg-bg/55 p-3"><Badge tone="sand">{t('product.out_of_stock')}</Badge></div>}
          {p.purchasable && !p.has_variants && p.in_stock && (
            <button onClick={quickAdd} disabled={busy} aria-label={t('product.add_to_bag')}
              className="absolute bottom-3 end-3 flex h-11 w-11 translate-y-2 items-center justify-center rounded-full bg-primary text-bg opacity-0 shadow-soft transition-all duration-300 hover:bg-brass-deep focus-visible:translate-y-0 focus-visible:opacity-100 group-hover:translate-y-0 group-hover:opacity-100 max-md:translate-y-0 max-md:opacity-100">
              <ShoppingBag size={18} className={busy ? 'animate-pulse' : ''} />
            </button>
          )}
        </div>
        <div className="px-1 pt-3.5">
          {p.brand && <p className="eyebrow !text-[10px] !tracking-[0.16em] text-muted">{p.brand.name}</p>}
          <h3 className="mt-0.5 line-clamp-2 min-h-[2.6em] text-[19px] leading-[1.25]">{p.name}</h3>
          <div className="mt-1.5 flex items-center justify-between gap-2">
            <PriceTag price={p.price} size="sm" />
          </div>
          {p.rating.count > 0 && <div className="mt-1"><Rating value={p.rating.average} count={p.rating.count} size={12} /></div>}
        </div>
      </Link>
      <button onClick={() => wl.toggle(p.id)} aria-pressed={liked} aria-label={liked ? t('wishlist.remove') : t('wishlist.add')}
        className="absolute end-3 top-3 flex h-10 w-10 items-center justify-center rounded-full bg-paper/90 text-ink shadow-soft backdrop-blur transition-transform hover:scale-105">
        <Heart size={18} className={cx(liked && 'fill-danger text-danger')} />
      </button>
    </article>
  );
}

export function CardGrid({ children, cols = 4 }: { children: React.ReactNode; cols?: 3 | 4 }) {
  return <div className={cx('grid grid-cols-2 gap-x-4 gap-y-9 md:gap-x-6', cols === 4 ? 'lg:grid-cols-4' : 'lg:grid-cols-3')}>{children}</div>;
}
export function CardSkeletons({ n = 8 }: { n?: number }) {
  return (
    <CardGrid>
      {Array.from({ length: n }).map((_, i) => (
        <div key={i}><div className="skeleton aspect-[4/5] rounded-[20px]" /><div className="skeleton mt-4 h-3 w-1/3" /><div className="skeleton mt-2 h-5 w-4/5" /><div className="skeleton mt-2 h-4 w-1/3" /></div>
      ))}
    </CardGrid>
  );
}
