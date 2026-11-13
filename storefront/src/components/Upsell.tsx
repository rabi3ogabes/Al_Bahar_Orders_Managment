import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowRight, Plus } from 'lucide-react';
import { get, post } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useCart } from '../lib/cart';
import { useI18n } from '../lib/i18n';
import { useToast } from '../lib/toast';
import type { ProductCard } from '../lib/types';
import { Rail } from './Rail';
import { Pic, PriceTag, Skeleton } from './ui';

/** Admin-chosen suggestions: cards with a one-tap add (signed in, single variant) or a link to the product. Hidden when empty. */
export function UpsellStrip({ title, items, loading = false, id }: { title: string; items: ProductCard[]; loading?: boolean; id: string }) {
  const { t, dir } = useI18n();
  const { user } = useAuth();
  const cart = useCart();
  const qc = useQueryClient();
  const toast = useToast();
  const [busy, setBusy] = useState<string | null>(null);

  if (loading) {
    return (
      <section className="mt-12" aria-hidden>
        <Skeleton className="mb-6 h-8 w-56" />
        <div className="flex gap-4 overflow-hidden">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-64 w-[44vw] shrink-0 sm:w-[26vw] md:w-[19vw] lg:w-[16%]" />)}</div>
      </section>
    );
  }
  if (items.length === 0) return null;

  const add = async (p: ProductCard) => {
    setBusy(p.id);
    try {
      await post('/cart/items', { variant_id: p.variant_id, quantity: 1 });
      await qc.invalidateQueries({ queryKey: ['cart'] });
      cart.openDrawer();
    } catch { toast.push(t('error.generic'), 'error'); } finally { setBusy(null); }
  };

  return (
    <section className="mt-12" aria-labelledby={id}>
      <h2 id={id} className="mb-6 text-3xl md:text-4xl">{title}</h2>
      <Rail itemWidth="w-[44vw] sm:w-[26vw] md:w-[19vw] lg:w-[16%]">
        {items.map((p) => {
          const quick = !!user && !!p.variant_id && !p.has_variants && p.in_stock && p.purchasable;
          return (
            <div key={p.id} className="relative rounded-2xl border border-line bg-paper p-3">
              <Link to={`/products/${p.slug}`} className="block">
                <Pic src={p.image?.thumb_url} alt={p.image?.alt ?? p.name} ratio="1/1" className="rounded-xl" />
                <p className="mt-2 line-clamp-2 min-h-[2.5rem] text-sm font-medium">{p.name}</p>
              </Link>
              <div className="mt-1 flex items-center justify-between gap-2">
                <PriceTag price={p.price} size="sm" />
                {quick ? (
                  <button type="button" onClick={() => add(p)} disabled={busy === p.id} aria-label={`${t('product.add_to_bag')}: ${p.name}`} title={t('product.add_to_bag')}
                    className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-primary text-bg transition-colors hover:bg-primary-soft disabled:opacity-40"><Plus size={18} /></button>
                ) : (
                  <Link to={`/products/${p.slug}`} aria-label={`${t('upsell.view')}: ${p.name}`} title={t('upsell.view')}
                    className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full border border-line text-ink transition-colors hover:bg-primary hover:text-bg"><ArrowRight size={18} className={dir === 'rtl' ? 'rotate-180' : ''} /></Link>
                )}
              </div>
            </div>
          );
        })}
      </Rail>
    </section>
  );
}

/** Cart page strip: suggestions for what is in the bag, from the server (signed-in customers only). */
export function CartSuggestions() {
  const { t } = useI18n();
  const { user } = useAuth();
  const cart = useCart();
  const ids = cart.lines.map((l) => l.variantId).sort().join(',');
  const q = useQuery({ queryKey: ['cart-suggestions', user?.id, ids], enabled: !!user, placeholderData: (prev) => prev, queryFn: async () => (await get<{ data: ProductCard[] }>('/cart/suggestions')).data });
  if (!user) return null;
  return <UpsellStrip id="cart-upsell" title={t('upsell.cart')} items={q.data ?? []} loading={q.isLoading} />;
}
