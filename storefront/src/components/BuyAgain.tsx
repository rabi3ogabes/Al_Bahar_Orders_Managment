import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Plus } from 'lucide-react';
import { get, post } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useBranch } from '../lib/branch';
import { useCart } from '../lib/cart';
import { useI18n } from '../lib/i18n';
import { useToast } from '../lib/toast';
import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import type { ProductCard } from '../lib/types';
import { Rail } from './Rail';
import { Pic, PriceTag, SectionHead } from './ui';

interface Row { product: ProductCard; variant_id: string; last_quantity: number; times_ordered: number }

/** "Buy again": the signed-in customer's own past products, re-added to the bag in one tap. Hidden when there are none. */
export function BuyAgain() {
  const { t } = useI18n();
  const { user } = useAuth();
  const { branchId } = useBranch();
  const cart = useCart();
  const qc = useQueryClient();
  const toast = useToast();
  const [busy, setBusy] = useState<string | null>(null);
  const q = useQuery({ queryKey: ['buy-again', user?.id, branchId], enabled: !!user, queryFn: async () => (await get<{ data: Row[] }>('/me/buy-again', { branch_id: branchId })).data });
  const rows = q.data ?? [];
  if (!user || rows.length === 0) return null;

  const add = async (r: Row) => {
    setBusy(r.variant_id);
    try {
      await post('/cart/items', { variant_id: r.variant_id, quantity: r.last_quantity });
      await qc.invalidateQueries({ queryKey: ['cart'] });
      cart.openDrawer();
    } catch { toast.push(t('error.generic'), 'error'); } finally { setBusy(null); }
  };

  return (
    <section className="wrap mt-16">
      <SectionHead title={t('buyagain.title')} eyebrow={t('buyagain.sub')} />
      <Rail itemWidth="w-[44vw] sm:w-[26vw] md:w-[19vw] lg:w-[16%]">
        {rows.map((r) => (
          <div key={r.variant_id} className="relative rounded-2xl border border-line bg-paper p-3">
            <Link to={`/products/${r.product.slug}`} className="block">
              <Pic src={r.product.image?.thumb_url} alt={r.product.name} ratio="1/1" className="rounded-xl" />
              <p className="mt-2 line-clamp-2 min-h-[2.5rem] text-sm font-medium">{r.product.name}</p>
            </Link>
            <div className="mt-1 flex items-center justify-between gap-2">
              <div><PriceTag price={r.product.price} size="sm" />{r.times_ordered > 1 && <p className="text-[11px] text-muted">{t('buyagain.times', { n: r.times_ordered })}</p>}</div>
              <button onClick={() => add(r)} disabled={busy === r.variant_id || !r.product.in_stock} aria-label={t('buyagain.add', { n: r.last_quantity })} title={t('buyagain.add', { n: r.last_quantity })}
                className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary text-bg transition-colors hover:bg-primary-soft disabled:opacity-40"><Plus size={18} /></button>
            </div>
          </div>
        ))}
      </Rail>
    </section>
  );
}
