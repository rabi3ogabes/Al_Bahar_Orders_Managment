import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Plus } from 'lucide-react';
import { get } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useBranch } from '../lib/branch';
import { useCart } from '../lib/cart';
import { useI18n } from '../lib/i18n';
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
  const q = useQuery({ queryKey: ['buy-again', user?.id, branchId], enabled: !!user, queryFn: async () => (await get<{ data: Row[] }>('/me/buy-again', { branch_id: branchId })).data });
  const rows = q.data ?? [];
  if (!user || rows.length === 0) return null;

  const add = (r: Row) => { void cart.quickAdd(r.product, r.last_quantity, r.variant_id).catch(() => undefined); };

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
              <button onClick={() => add(r)} disabled={!r.product.in_stock} aria-label={t('buyagain.add', { n: r.last_quantity })} title={t('buyagain.add', { n: r.last_quantity })}
                className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary text-bg transition-colors hover:bg-primary-soft disabled:opacity-40"><Plus size={18} /></button>
            </div>
          </div>
        ))}
      </Rail>
    </section>
  );
}
