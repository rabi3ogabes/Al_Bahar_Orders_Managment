import { useEffect, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Bell, Check, ExternalLink, Heart, PackageX } from 'lucide-react';
import { ApiError, get, post } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useBranch } from '../lib/branch';
import { useCart } from '../lib/cart';
import { useI18n } from '../lib/i18n';
import { useToast } from '../lib/toast';
import { useWishlist } from '../lib/wishlist';
import { cx, money } from '../lib/format';
import type { ProductDetail } from '../lib/types';
import { AttributePicker, useVariantPicker } from './AttributePicker';
import { useAuthModal } from './AuthModal';
import { DrawerLink } from './drawerLink';
import { SideDrawer } from './SideDrawer';
import { Badge, Button, EmptyState, Pic, PriceTag, QtyStepper, Rating, Skeleton } from './ui';

/**
 * Quick view (`?quick=<slug>`): photos, price, options, quantity and "Add to bag" without leaving the list.
 * The product page stays one link away and keeps working on its own.
 */
export default function QuickView({ slug, open, onClose, onAdded }: { slug: string; open: boolean; onClose: () => void; onAdded: () => void }) {
  const { t, locale } = useI18n();
  const { branchId } = useBranch();
  const { user } = useAuth();
  const authModal = useAuthModal();
  const cart = useCart();
  const wl = useWishlist();
  const toast = useToast();
  const q = useQuery({ queryKey: ['product', slug, branchId], enabled: open, queryFn: async () => (await get<{ data: ProductDetail }>(`/products/${slug}`, { branch_id: branchId })).data, retry: (n, e) => !(e instanceof ApiError && e.status === 404) && n < 2 });
  const p = q.data;
  const picker = useVariantPicker(p);
  const { variant } = picker;
  const [qty, setQty] = useState(1);
  const [img, setImg] = useState(0);
  const [adding, setAdding] = useState(false);
  useEffect(() => { setQty(1); setImg(0); }, [p?.id]);

  const images = useMemo(() => {
    if (!p) return [];
    const own = p.images.filter((i) => !i.variant_id || i.variant_id === variant?.id);
    return own.length ? own : p.images;
  }, [p, variant?.id]);
  const stockMax = Math.max(1, Math.min(20, variant?.available_quantity || 20));
  useEffect(() => { setQty((n) => Math.min(n, stockMax)); }, [stockMax]);
  useEffect(() => { setImg((i) => (i < images.length ? i : 0)); }, [images.length]);

  const price = variant?.price ?? p?.price;
  const canBuy = !!p && !!variant && variant.in_stock && variant.available_in_branch && p.purchasable;
  const low = canBuy && variant!.available_quantity > 0 && variant!.available_quantity <= 5;
  const liked = !!p && wl.has(p.id);

  const add = async () => {
    if (!p || !variant) return;
    setAdding(true);
    try {
      await cart.add({ id: p.id, slug: p.slug, name: p.name, brand: p.brand, image: images[0]?.thumb_url ?? p.image?.thumb_url }, variant, qty, { open: false });
      onAdded();
    } catch { /* the cart already showed the reason */ } finally { setAdding(false); }
  };
  const notify = async () => {
    if (!user) return authModal.open({ reason: t('product.notify_reason') });
    if (!variant) return;
    try { await post('/me/stock-alerts', { variant_id: variant.id, type: 'back_in_stock', branch_id: branchId || undefined }); toast.push(t('product.notify_ok')); } catch (e) { toast.push(e instanceof ApiError ? e.message : t('error.generic'), 'error'); }
  };

  const total = price ? money(parseFloat(price.final.amount) * qty, price.final.currency, locale) : '';
  const footer = p ? (
    <Button size="lg" block disabled={!canBuy} loading={adding} onClick={() => void add()}>
      {!variant ? t('product.choose_options') : canBuy ? <>{t('product.add_to_bag')} · <span className="tabular-nums" lang={locale}>{total}</span></> : t('product.out_of_stock')}
    </Button>
  ) : null;
  const notFound = q.isError && q.error instanceof ApiError && q.error.status === 404;

  return (
    <SideDrawer open={open} onClose={onClose} urlBacked title={p?.name ?? t('quick.title')} footer={footer} description={p?.short_description ?? undefined}
      headerAction={<DrawerLink to={`/products/${slug}`} aria-label={t('quick.full')} title={t('quick.full')} className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-ink transition-colors hover:bg-sand"><ExternalLink size={19} /></DrawerLink>}>
      {q.isError ? <EmptyState icon={<PackageX size={28} />} title={notFound ? t('product.not_found') : t('error.title')} text={notFound ? t('product.not_found_text') : t('error.generic')} action={!notFound ? <Button variant="outline" onClick={() => void q.refetch()}>{t('action.retry')}</Button> : undefined} />
        : !p || !price ? <div className="space-y-4 p-6"><Skeleton className="aspect-square rounded-3xl" /><Skeleton className="h-8 w-2/3" /><Skeleton className="h-6 w-1/3" /><Skeleton className="h-24" /></div>
        : (
          <div className="p-6">
            <div className="relative overflow-hidden rounded-3xl">
              <Pic src={images[img]?.url} alt={images[img]?.alt ?? p.name} ratio="1/1" label={p.brand?.name} eager />
              <div className="absolute start-3 top-3 flex flex-col gap-1.5">{p.badges.slice(0, 2).map((b) => <Badge key={b} tone={b === 'new' ? 'ink' : 'brass'}>{t(`badge.${b}`)}</Badge>)}</div>
              <button type="button" onClick={() => wl.toggle(p.id)} aria-pressed={liked} aria-label={liked ? t('wishlist.remove') : t('wishlist.add')} className="absolute end-3 top-3 flex h-11 w-11 items-center justify-center rounded-full bg-paper/90 text-ink shadow-soft backdrop-blur"><Heart size={19} className={cx(liked && 'fill-danger text-danger')} /></button>
            </div>
            {images.length > 1 && (
              <ul className="mt-3 flex gap-2.5 overflow-x-auto pb-1" aria-label={t('product.gallery')}>
                {images.map((im, i) => <li key={im.url + i}><button type="button" onClick={() => setImg(i)} aria-label={`${i + 1}`} aria-current={i === img} className={cx('block w-16 overflow-hidden rounded-xl ring-offset-2 ring-offset-bg transition-all', i === img ? 'ring-2 ring-ink' : 'opacity-70 hover:opacity-100')}><Pic src={im.thumb_url} alt="" ratio="1/1" /></button></li>)}
              </ul>
            )}
            {p.brand && <p className="eyebrow mt-5">{p.brand.name}</p>}
            <div className="mt-2 flex flex-wrap items-center gap-3">{p.rating.count > 0 && <Rating value={p.rating.average} count={p.rating.count} />}<span className="text-xs text-muted">SKU {variant?.sku ?? p.sku}</span></div>
            <div className="mt-3"><PriceTag price={price} size="lg" /></div>
            {p.short_description && <p className="mt-4 leading-relaxed text-ink-soft">{p.short_description}</p>}
            <AttributePicker p={p} picker={picker} />
            <div className="mt-6 min-h-6 text-sm" aria-live="polite">
              {!variant ? <span className="text-muted">{t('product.choose_options')}</span>
                : canBuy ? <span className="flex items-center gap-2 text-success"><Check size={16} />{low ? t('product.low_stock', { n: variant.available_quantity }) : t('product.in_stock')}</span>
                : <span className="text-danger">{t('product.out_of_stock')}</span>}
            </div>
            <div className="mt-4 flex items-center gap-3"><QtyStepper value={qty} onChange={setQty} max={stockMax} />{variant && !canBuy && <button type="button" onClick={() => void notify()} className="flex min-h-11 items-center gap-2 text-sm font-medium text-brass-deep hover:underline"><Bell size={15} />{t('product.notify')}</button>}</div>
            <DrawerLink to={`/products/${slug}`} className="mt-6 flex min-h-11 items-center justify-center rounded-full border border-line text-sm font-medium text-ink transition-colors hover:border-primary">{t('quick.full')}</DrawerLink>
          </div>
        )}
    </SideDrawer>
  );
}
