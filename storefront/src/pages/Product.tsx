import { UpsellStrip } from '../components/Upsell';
import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Bell, Check, ChevronDown, Heart, MapPin, PackageCheck, Ruler, Share2, ShieldCheck, Truck, Wrench } from 'lucide-react';
import { ApiError, get, post } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useBranch } from '../lib/branch';
import { useCart } from '../lib/cart';
import { useI18n } from '../lib/i18n';
import { useToast } from '../lib/toast';
import { useWishlist } from '../lib/wishlist';
import { useDocTitle } from '../lib/hooks';
import { sanitizeHtml } from '../lib/sanitize';
import { cx } from '../lib/format';
import type { Paged, ProductDetail, Review, Variant } from '../lib/types';
import { useAuthModal } from '../components/AuthModal';
import { Rail } from '../components/Rail';
import { ProductCard } from '../components/ProductCard';
import { Badge, Breadcrumbs, Button, EmptyState, Pic, PriceTag, QtyStepper, Rating, Skeleton, Textarea, Input } from '../components/ui';

function Accordion({ title, children, open: o = false }: { title: string; children: React.ReactNode; open?: boolean }) {
  const [open, setOpen] = useState(o);
  return (
    <div className="border-b border-line">
      <button onClick={() => setOpen(!open)} aria-expanded={open} className="flex w-full items-center justify-between py-5 text-start font-display text-2xl">{title}<ChevronDown size={20} className={cx('transition-transform', open && 'rotate-180')} /></button>
      {open && <div className="fade pb-6 text-ink-soft">{children}</div>}
    </div>
  );
}

function Reviews({ product }: { product: ProductDetail }) {
  const { t, date, num } = useI18n();
  const { user } = useAuth();
  const authModal = useAuthModal();
  const toast = useToast();
  const qc = useQueryClient();
  const [writing, setWriting] = useState(false);
  const [rating, setRating] = useState(5);
  const [title, setTitle] = useState('');
  const [body, setBody] = useState('');
  const list = useQuery({ queryKey: ['reviews', product.id], queryFn: async () => get<Paged<Review>>(`/products/${product.id}/reviews`, { per_page: 20 }) });
  const submit = useMutation({
    mutationFn: () => post(`/products/${product.id}/reviews`, { rating, title: title || undefined, body: body || undefined }),
    onSuccess: () => { toast.push(t('reviews.thanks')); setWriting(false); setTitle(''); setBody(''); void qc.invalidateQueries({ queryKey: ['reviews', product.id] }); },
    onError: (e) => toast.push(e instanceof ApiError ? e.message : t('error.generic'), 'error'),
  });
  return (
    <div>
      <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div className="flex items-center gap-4">
          <span className="font-display text-6xl leading-none">{product.rating.count ? product.rating.average.toFixed(1) : '—'}</span>
          <div><Rating value={product.rating.average} size={18} /><p className="mt-1 text-sm text-muted">{t('reviews.count', { n: num(product.rating.count) })}</p></div>
        </div>
        <Button variant="outline" onClick={() => (user ? setWriting(!writing) : authModal.open({ reason: t('auth.reason_review') }))}>{t('reviews.write')}</Button>
      </div>
      {writing && (
        <form onSubmit={(e) => { e.preventDefault(); submit.mutate(); }} className="rise mb-8 space-y-4 rounded-3xl border border-line bg-paper p-5">
          <div role="radiogroup" aria-label={t('reviews.rating')} className="flex gap-1">
            {[1, 2, 3, 4, 5].map((n) => <button key={n} type="button" role="radio" aria-checked={rating === n} aria-label={`${n}`} onClick={() => setRating(n)} className="p-1"><svg width="30" height="30" viewBox="0 0 24 24" className={n <= rating ? 'fill-brass text-brass' : 'fill-transparent text-line'} stroke="currentColor" strokeWidth="1.5"><path d="m12 2 3 6.9 7.5.7-5.7 5 1.7 7.4L12 18l-6.5 4 1.7-7.4-5.7-5L9 8.9z" /></svg></button>)}
          </div>
          <Input label={t('reviews.title')} value={title} onChange={(e) => setTitle(e.target.value)} maxLength={120} />
          <Textarea label={t('reviews.body')} value={body} onChange={(e) => setBody(e.target.value)} maxLength={3000} />
          <Button type="submit" loading={submit.isPending}>{t('reviews.submit')}</Button>
        </form>
      )}
      {list.isLoading && <Skeleton className="h-24" />}
      {list.data?.data.length === 0 && <p className="rounded-2xl bg-sand/60 p-5 text-center text-muted">{t('reviews.none')}</p>}
      <ul className="divide-y divide-line">
        {list.data?.data.map((r) => (
          <li key={r.id} className="py-5">
            <div className="flex items-center justify-between"><Rating value={r.rating} /><span className="text-xs text-muted">{date(r.created_at, { dateStyle: 'medium' })}</span></div>
            {r.title && <p className="mt-2 font-semibold">{r.title}</p>}
            {r.body && <p className="mt-1 text-ink-soft">{r.body}</p>}
            <p className="mt-2 flex items-center gap-2 text-xs text-muted">{r.author}{r.verified_purchase && <Badge tone="success"><Check size={11} className="me-1" />{t('reviews.verified')}</Badge>}</p>
          </li>
        ))}
      </ul>
    </div>
  );
}

function Questions({ productId }: { productId: string }) {
  const { t } = useI18n();
  const { user } = useAuth();
  const authModal = useAuthModal();
  const toast = useToast();
  const [q, setQ] = useState('');
  const list = useQuery({ queryKey: ['questions', productId], queryFn: async () => (await get<{ data: { id: string; question: string; answer: string | null }[] }>(`/products/${productId}/questions`)).data });
  const ask = useMutation({
    mutationFn: () => post(`/products/${productId}/questions`, { question: q }),
    onSuccess: () => { toast.push(t('qa.thanks')); setQ(''); },
    onError: (e) => toast.push(e instanceof ApiError ? e.message : t('error.generic'), 'error'),
  });
  return (
    <div>
      <ul className="mb-6 space-y-5">
        {list.data?.map((x) => <li key={x.id}><p className="font-semibold">{x.question}</p>{x.answer && <p className="mt-1 border-s-2 border-brass ps-4 text-ink-soft">{x.answer}</p>}</li>)}
        {list.data?.length === 0 && <li className="text-muted">{t('qa.none')}</li>}
      </ul>
      <form onSubmit={(e) => { e.preventDefault(); if (!user) return authModal.open({ reason: t('auth.reason_review') }); ask.mutate(); }} className="flex flex-col gap-3 sm:flex-row sm:items-end">
        <Input className="flex-1" label={t('qa.ask')} value={q} onChange={(e) => setQ(e.target.value)} minLength={5} maxLength={1000} required />
        <Button type="submit" variant="outline" loading={ask.isPending}>{t('qa.send')}</Button>
      </form>
    </div>
  );
}

export default function Product() {
  const { slug = '' } = useParams();
  const { t } = useI18n();
  const nav = useNavigate();
  const { branchId, branches } = useBranch();
  const cart = useCart();
  const wl = useWishlist();
  const toast = useToast();
  const { user } = useAuth();
  const authModal = useAuthModal();

  const q = useQuery({ queryKey: ['product', slug, branchId], queryFn: async () => (await get<{ data: ProductDetail }>(`/products/${slug}`, { branch_id: branchId })).data, retry: (n, e) => !(e instanceof ApiError && e.status === 404) && n < 2 });
  const p = q.data;
  useDocTitle(p?.seo.title ?? p?.name);

  const [sel, setSel] = useState<Record<string, number>>({});
  const [qty, setQty] = useState(1);
  const [img, setImg] = useState(0);
  const [adding, setAdding] = useState(false);

  // Start on the default variant's options.
  useEffect(() => {
    if (!p) return;
    const v = p.variants.find((x) => x.id === p.default_variant_id) ?? p.variants[0];
    const s: Record<string, number> = {};
    if (v) p.attributes.forEach((a) => { const hit = a.values.find((x) => v.attribute_value_ids.includes(x.id)); if (hit) s[a.code] = hit.id; });
    setSel(s); setQty(1); setImg(0);
  }, [p?.id]); // eslint-disable-line react-hooks/exhaustive-deps

  const variant: Variant | undefined = useMemo(() => {
    if (!p) return undefined;
    if (!p.attributes.length) return p.variants.find((x) => x.id === p.default_variant_id) ?? p.variants[0];
    const ids = Object.values(sel);
    return p.variants.find((v) => ids.length === p.attributes.length && ids.every((i) => v.attribute_value_ids.includes(i)));
  }, [p, sel]);

  const optionState = (code: string, valueId: number) => {
    if (!p) return { exists: false, inStock: false };
    const others = Object.entries(sel).filter(([c]) => c !== code).map(([, v]) => v);
    const vs = p.variants.filter((v) => v.attribute_value_ids.includes(valueId) && others.every((o) => v.attribute_value_ids.includes(o)));
    return { exists: vs.length > 0, inStock: vs.some((v) => v.in_stock && v.available_in_branch) };
  };

  const avail = useQuery({ queryKey: ['availability', p?.id], enabled: !!p, queryFn: async () => (await get<{ data: { branches: { branch: { id: string; name: string; pickup_enabled: boolean; delivery_enabled: boolean }; variants: { variant_id: string; available_in_branch: boolean; in_stock: boolean; available: number }[] }[] } }>(`/products/${p!.id}/availability`)).data.branches });

  const images = useMemo(() => {
    if (!p) return [];
    const own = p.images.filter((i) => !i.variant_id || i.variant_id === variant?.id);
    return own.length ? own : p.images;
  }, [p, variant?.id]);

  if (q.isError) {
    const nf = q.error instanceof ApiError && q.error.status === 404;
    return <div className="wrap py-24"><EmptyState title={nf ? t('product.not_found') : t('error.title')} text={nf ? t('product.not_found_text') : t('error.generic')} action={<Button onClick={() => nav('/shop')}>{t('nav.shop')}</Button>} /></div>;
  }
  if (!p) {
    return <div className="wrap grid gap-10 py-10 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]"><Skeleton className="aspect-[4/5] rounded-[28px]" /><div className="space-y-4"><Skeleton className="h-4 w-1/4" /><Skeleton className="h-14 w-4/5" /><Skeleton className="h-8 w-1/3" /><Skeleton className="h-40" /></div></div>;
  }

  const price = variant?.price ?? p.price;
  const canBuy = !!variant && variant.in_stock && variant.available_in_branch && p.purchasable;
  const max = Math.max(1, Math.min(20, variant?.available_quantity || 20));
  const liked = wl.has(p.id);
  const low = canBuy && variant!.available_quantity > 0 && variant!.available_quantity <= 5;
  const productHref = `${location.origin}/products/${p.slug}`;

  const add = async (buyNow = false) => {
    if (!variant) { toast.push(t('product.choose_options'), 'error'); return; }
    setAdding(true);
    try {
      await cart.add({ id: p.id, slug: p.slug, name: p.name, brand: p.brand, image: images[0]?.thumb_url ?? p.image?.thumb_url }, variant, qty);
      if (buyNow) { cart.closeDrawer(); nav('/checkout'); }
    } catch { /* toast shown by cart */ } finally { setAdding(false); }
  };
  const share = async () => {
    try { if (navigator.share) await navigator.share({ title: p.name, url: productHref }); else { await navigator.clipboard.writeText(productHref); toast.push(t('product.link_copied')); } } catch { /* cancelled */ }
  };
  const notify = async () => {
    if (!user) return authModal.open({ reason: t('product.notify_reason') });
    if (!variant) return;
    try { await post('/me/stock-alerts', { variant_id: variant.id, type: 'back_in_stock', branch_id: branchId || undefined }); toast.push(t('product.notify_ok')); } catch (e) { toast.push(e instanceof ApiError ? e.message : t('error.generic'), 'error'); }
  };

  const jsonLd = { '@context': 'https://schema.org', '@type': 'Product', name: p.name, sku: p.sku, brand: p.brand?.name, image: p.images.map((i) => i.url), description: p.short_description, offers: { '@type': 'Offer', priceCurrency: price.final.currency, price: price.final.amount, availability: canBuy ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock', url: productHref } };

  return (
    <div className="wrap pb-28 pt-6 md:pt-8 lg:pb-12">
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(jsonLd) }} />
      <Breadcrumbs items={[{ to: '/', label: t('nav.home') }, { to: '/shop', label: t('nav.shop') }, ...(p.category ? [{ to: `/shop?category=${p.category.slug}`, label: p.category.name }] : []), { label: p.name }]} />

      <div className="mt-6 grid gap-8 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)] lg:gap-14">
        {/* Gallery */}
        <div className="lg:sticky lg:top-32 lg:self-start">
          <div className="group relative overflow-hidden rounded-[28px]">
            <Pic src={images[img]?.url} alt={images[img]?.alt ?? p.name} ratio="4/5" label={p.brand?.name} eager />
            <div className="absolute start-4 top-4 flex flex-col gap-1.5">{p.badges.slice(0, 2).map((b) => <Badge key={b} tone={b === 'new' ? 'ink' : 'brass'}>{t(`badge.${b}`)}</Badge>)}</div>
          </div>
          {images.length > 1 && (
            <ul className="mt-3 flex gap-3 overflow-x-auto pb-1" aria-label={t('product.gallery')}>
              {images.map((im, i) => <li key={im.url + i}><button onClick={() => setImg(i)} aria-label={`${i + 1}`} aria-current={i === img} className={cx('block w-20 overflow-hidden rounded-2xl ring-offset-2 ring-offset-bg transition-all', i === img ? 'ring-2 ring-ink' : 'opacity-70 hover:opacity-100')}><Pic src={im.thumb_url} alt="" ratio="1/1" /></button></li>)}
            </ul>
          )}
        </div>

        {/* Buy box */}
        <div>
          {p.brand && <Link to={`/shop?brand=${p.brand.id}`} className="eyebrow hover:text-ink">{p.brand.name}</Link>}
          <h1 className="mt-2 text-[clamp(34px,4.5vw,56px)] leading-[1.02]">{p.name}</h1>
          <div className="mt-3 flex flex-wrap items-center gap-4">{p.rating.count > 0 && <Rating value={p.rating.average} count={p.rating.count} />}<span className="text-xs text-muted">SKU {variant?.sku ?? p.sku}</span></div>
          <div className="mt-5"><PriceTag price={price} size="lg" /></div>
          {p.short_description && <p className="mt-5 text-[16px] leading-relaxed text-ink-soft">{p.short_description}</p>}

          {p.attributes.map((a) => (
            <fieldset key={a.code} className="mt-7">
              <legend className="mb-2.5 text-sm"><span className="font-semibold">{a.name}</span><span className="text-muted"> · {a.values.find((v) => v.id === sel[a.code])?.name ?? t('product.choose')}</span></legend>
              <div className="flex flex-wrap gap-2.5">
                {a.values.map((v) => {
                  const st = optionState(a.code, v.id);
                  const on = sel[a.code] === v.id;
                  return a.type === 'color' && v.color_hex ? (
                    <button key={v.id} type="button" disabled={!st.exists} aria-label={v.name} aria-pressed={on} title={v.name} onClick={() => setSel({ ...sel, [a.code]: v.id })}
                      className={cx('relative h-11 w-11 rounded-full ring-offset-2 ring-offset-bg transition-all disabled:opacity-30', on ? 'ring-2 ring-ink' : 'ring-1 ring-line hover:ring-brass')} style={{ background: v.color_hex }}>
                      {!st.inStock && st.exists && <span className="absolute inset-0 rounded-full bg-[linear-gradient(135deg,transparent_47%,var(--bg)_47%,var(--bg)_53%,transparent_53%)]" />}
                    </button>
                  ) : (
                    <button key={v.id} type="button" disabled={!st.exists} aria-pressed={on} onClick={() => setSel({ ...sel, [a.code]: v.id })}
                      className={cx('h-11 rounded-full border px-5 text-sm transition-colors disabled:opacity-30', on ? 'border-primary bg-primary text-bg' : 'border-line bg-paper hover:border-brass', st.exists && !st.inStock && !on && 'text-muted line-through')}>{v.name}</button>
                  );
                })}
              </div>
            </fieldset>
          ))}

          <div className="mt-7 min-h-6 text-sm" aria-live="polite">
            {!variant ? <span className="text-muted">{t('product.choose_options')}</span>
              : canBuy ? <span className="flex items-center gap-2 text-success"><Check size={16} />{low ? t('product.low_stock', { n: variant.available_quantity }) : t('product.in_stock')}</span>
              : <span className="text-danger">{t('product.out_of_stock')}</span>}
          </div>

          <div className="mt-5 flex flex-wrap items-center gap-3">
            <QtyStepper value={qty} onChange={setQty} max={max} />
            <Button size="lg" className="min-w-0 flex-1" disabled={!canBuy} loading={adding} onClick={() => add(false)}>{t('product.add_to_bag')}</Button>
            <button onClick={() => wl.toggle(p.id)} aria-pressed={liked} aria-label={liked ? t('wishlist.remove') : t('wishlist.add')} className="flex h-[52px] w-[52px] items-center justify-center rounded-full border border-line bg-paper hover:border-primary"><Heart size={20} className={liked ? 'fill-danger text-danger' : ''} /></button>
          </div>
          <div className="mt-3 flex gap-3">
            <Button variant="brass" size="lg" block disabled={!canBuy} onClick={() => add(true)}>{t('product.buy_now')}</Button>
            <button onClick={share} aria-label={t('product.share')} className="flex h-[52px] w-[52px] shrink-0 items-center justify-center rounded-full border border-line bg-paper hover:border-primary"><Share2 size={19} /></button>
          </div>
          {variant && !canBuy && <button onClick={notify} className="mt-4 flex items-center gap-2 text-sm font-medium text-brass-deep hover:underline"><Bell size={15} />{t('product.notify')}</button>}

          {/* Fulfilment promises */}
          <ul className="mt-8 grid gap-3 rounded-3xl border border-line bg-paper p-5 text-sm">
            <li className="flex gap-3"><Truck size={20} strokeWidth={1.5} className="mt-0.5 shrink-0 text-brass" /><span><b className="font-semibold">{t('promise.delivery')}</b><br /><span className="text-muted">{p.is_oversized ? t('product.oversized') : t('promise.delivery_text')}</span></span></li>
            <li className="flex gap-3"><PackageCheck size={20} strokeWidth={1.5} className="mt-0.5 shrink-0 text-brass" /><span><b className="font-semibold">{t('promise.pickup')}</b><br /><span className="text-muted">{t('promise.pickup_text')}</span></span></li>
            {p.warranty && <li className="flex gap-3"><ShieldCheck size={20} strokeWidth={1.5} className="mt-0.5 shrink-0 text-brass" /><span><b className="font-semibold">{t('product.warranty', { m: p.warranty.duration_months })}</b>{p.warranty.provider && <><br /><span className="text-muted">{p.warranty.provider}</span></>}</span></li>}
            {p.assembly_service_available && <li className="flex gap-3"><Wrench size={20} strokeWidth={1.5} className="mt-0.5 shrink-0 text-brass" /><span><b className="font-semibold">{t('product.assembly')}</b><br /><span className="text-muted">{p.requires_assembly ? t('product.assembly_required') : t('product.assembly_optional')}</span></span></li>}
          </ul>

          {/* Showroom availability */}
          {avail.data && avail.data.length > 0 && (
            <div className="mt-6">
              <h2 className="mb-3 text-xl">{t('product.availability')}</h2>
              <ul className="divide-y divide-line rounded-3xl border border-line bg-paper">
                {avail.data.map((b) => {
                  const row = b.variants.find((x) => x.variant_id === variant?.id) ?? b.variants.find((x) => x.in_stock && x.available_in_branch);
                  const ok = !!row && row.available_in_branch && row.in_stock;
                  return (
                    <li key={b.branch.id} className="flex items-center justify-between gap-3 px-5 py-3.5 text-sm">
                      <span className="flex items-center gap-2.5"><MapPin size={16} className="text-brass" />{b.branch.name}</span>
                      <span className={cx('flex items-center gap-1.5 font-medium', ok ? 'text-success' : 'text-muted')}>{ok ? <><Check size={14} />{t('product.in_stock')}</> : t('product.unavailable_here')}</span>
                    </li>
                  );
                })}
              </ul>
              {!branchId && branches.length > 0 && <p className="mt-2 text-xs text-muted">{t('product.pick_showroom_hint')}</p>}
            </div>
          )}
        </div>
      </div>

      {/* Details */}
      <div className="mt-16 grid gap-x-16 lg:grid-cols-2">
        <div>
          <Accordion title={t('product.description')} open>
            {p.description ? <div className="prose-store" dangerouslySetInnerHTML={{ __html: sanitizeHtml(p.description) }} /> : <p>{p.short_description}</p>}
            {p.material && <p className="mt-3"><b className="text-ink">{t('product.material')}:</b> {p.material}</p>}
          </Accordion>
          {p.specifications.length > 0 && (
            <Accordion title={t('product.specs')}>
              <dl className="divide-y divide-line">{p.specifications.map((s) => <div key={s.label} className="flex justify-between gap-6 py-2.5 text-sm"><dt className="text-muted">{s.label}</dt><dd className="text-end font-medium text-ink">{s.value}</dd></div>)}</dl>
            </Accordion>
          )}
        </div>
        <div>
          {(p.dimensions || p.weight) && (
            <Accordion title={t('product.dimensions')}>
              <div className="flex items-start gap-3 text-sm"><Ruler size={20} className="mt-0.5 text-brass" /><div className="space-y-1">
                {p.dimensions && <p>{t('product.dim_line', { l: p.dimensions.length, w: p.dimensions.width, h: p.dimensions.height, u: p.dimensions.unit })}</p>}
                {p.weight && <p>{t('product.weight')}: {p.weight.value} {p.weight.unit}</p>}
              </div></div>
            </Accordion>
          )}
          {p.warranty?.terms && <Accordion title={t('product.warranty_title')}><p>{p.warranty.terms}</p></Accordion>}
          <Accordion title={t('qa.title')}><Questions productId={p.id} /></Accordion>
        </div>
      </div>

      <section className="mt-16" aria-labelledby="rev"><h2 id="rev" className="mb-6 text-4xl">{t('reviews.title_section')}</h2><Reviews product={p} /></section>

      <UpsellStrip id="set-upsell" title={t('upsell.set')} items={p.upsell ?? []} />

      {p.related.length > 0 && (
        <section className="mt-20">
          <h2 className="mb-8 text-4xl">{t('product.related')}</h2>
          <Rail>{p.related.map((r) => <ProductCard key={r.id} p={r} />)}</Rail>
        </section>
      )}

      {/* Mobile sticky bar */}
      <div className="fixed inset-x-0 bottom-0 z-40 border-t border-line bg-bg/95 px-4 py-3 pb-[max(12px,env(safe-area-inset-bottom))] backdrop-blur lg:hidden">
        <div className="flex items-center gap-3">
          <div className="min-w-0"><p className="truncate text-[13px] text-muted">{p.name}</p><PriceTag price={price} size="sm" /></div>
          <Button className="ms-auto shrink-0" disabled={!canBuy} loading={adding} onClick={() => add(false)}>{t('product.add_to_bag')}</Button>
        </div>
      </div>
    </div>
  );
}
