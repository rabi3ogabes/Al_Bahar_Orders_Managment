import { useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import * as Dialog from '@radix-ui/react-dialog';
import { Search, Sparkles, X } from 'lucide-react';
import { get } from '../lib/api';
import { useBranch } from '../lib/branch';
import { useI18n } from '../lib/i18n';
import { useDebounce } from '../lib/hooks';
import { useCloseOnBack } from '../lib/useCloseOnBack';
import type { ProductCard } from '../lib/types';
import { IconButton, Pic, PriceTag, Spinner } from './ui';

interface Suggest { products: ProductCard[]; categories: { id: string; slug: string; name: string }[]; brands: { id: string; slug: string; name: string }[]; did_you_mean: string | null }

/** Search: a panel dropping from the top. Same rules as every window: Esc, scrim, ✕ and Back close it, focus is trapped and returned. */
export default function SearchOverlay({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { t } = useI18n();
  const nav = useNavigate();
  const [q, setQ] = useState('');
  const dq = useDebounce(q.trim(), 250);
  const input = useRef<HTMLInputElement>(null);
  const { branchId } = useBranch();
  useCloseOnBack(open, onClose);
  const res = useQuery({
    queryKey: ['suggest', dq, branchId], enabled: open && dq.length >= 2,
    queryFn: async () => (await get<{ data: Suggest }>('/search/suggest', { q: dq, branch_id: branchId })).data,
  });
  const products = res.data?.products ?? [];
  // Leaving through a link closes the window first, then the router moves on (replace: the search leaves no history entry).
  const go = (e?: React.FormEvent) => { e?.preventDefault(); if (q.trim()) { onClose(); nav(`/shop?q=${encodeURIComponent(q.trim())}`, { replace: true }); } };
  const leave = () => onClose();
  return (
    <Dialog.Root open={open} onOpenChange={(o) => { if (!o) { onClose(); setQ(''); } }}>
      <Dialog.Portal>
        <Dialog.Overlay className="ov-scrim fixed inset-0 z-[85] bg-ink/45 backdrop-blur-[2px]" />
        <Dialog.Content aria-describedby={undefined} onOpenAutoFocus={(e) => { e.preventDefault(); input.current?.focus(); }}
          className="ov-top fixed inset-x-0 top-0 z-[85] mx-auto max-h-[100dvh] max-w-3xl overflow-y-auto overscroll-contain rounded-b-[28px] bg-bg p-5 shadow-soft outline-none sm:top-6 sm:rounded-[28px] sm:p-7">
          <Dialog.Title className="sr-only">{t('search.title')}</Dialog.Title>
          <form onSubmit={go} className="flex items-center gap-3 border-b border-primary pb-3">
            <Search size={22} className="text-muted" />
            <input ref={input} value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('search.placeholder')} aria-label={t('search.title')} enterKeyHint="search" className="min-w-0 flex-1 bg-transparent font-display text-[26px] outline-none placeholder:text-muted/60" />
            <Dialog.Close asChild><IconButton label={t('action.close')} type="button"><X size={20} /></IconButton></Dialog.Close>
          </form>
          <div className="mt-5 min-h-24" aria-live="polite">
            {res.isFetching && <div className="flex justify-center py-6"><Spinner /></div>}
            {res.data?.did_you_mean && (
              <button onClick={() => setQ(res.data!.did_you_mean!)} className="mb-3 flex min-h-11 items-center gap-2 rounded-full bg-brass-tint px-4 py-2 text-sm font-medium text-brass-deep hover:underline"><Sparkles size={15} />{t('search.did_you_mean', { q: res.data.did_you_mean })}</button>
            )}
            {res.data && (res.data.categories.length > 0 || res.data.brands.length > 0) && (
              <div className="mb-3 flex flex-wrap gap-2">
                {res.data.categories.map((c) => <Link key={c.id} to={`/shop?category=${c.slug}`} replace onClick={leave} className="flex min-h-11 items-center rounded-full border border-line bg-paper px-3.5 text-sm hover:border-primary">{c.name}</Link>)}
                {res.data.brands.map((b) => <Link key={b.id} to={`/shop?brand=${b.slug}`} replace onClick={leave} className="flex min-h-11 items-center rounded-full border border-line bg-paper px-3.5 text-sm text-muted hover:border-primary">{b.name}</Link>)}
              </div>
            )}
            {!res.isFetching && dq.length >= 2 && res.data && products.length === 0 && !res.data.did_you_mean && <p className="py-6 text-center text-muted">{t('search.none', { q: dq })}</p>}
            {!res.isFetching && products.length > 0 && (
              <ul className="divide-y divide-line">
                {products.map((p) => (
                  <li key={p.id}>
                    <Link to={`/products/${p.slug}`} replace onClick={leave} className="flex items-center gap-4 py-3 hover:opacity-80">
                      <Pic src={p.image?.thumb_url} alt={p.name} ratio="1/1" className="h-16 w-16 shrink-0 rounded-xl" />
                      <div className="min-w-0 flex-1"><p className="truncate font-display text-xl">{p.name}</p><p className="text-xs text-muted">{p.brand?.name}</p></div>
                      <PriceTag price={p.price} size="sm" />
                    </Link>
                  </li>
                ))}
                <li className="pt-3"><button onClick={() => go()} className="flex min-h-11 items-center text-sm font-medium text-brass-deep hover:underline">{t('search.all', { q: dq })}</button></li>
              </ul>
            )}
          </div>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
