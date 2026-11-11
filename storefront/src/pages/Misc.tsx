import { useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Clock, Mail, MapPin, Navigation, Phone, SearchX } from 'lucide-react';
import { ApiError, get } from '../lib/api';
import { useBranch } from '../lib/branch';
import { useI18n } from '../lib/i18n';
import { useDocTitle } from '../lib/hooks';
import { sanitizeHtml } from '../lib/sanitize';
import { cx, mapsHref, telHref } from '../lib/format';
import type { BranchHours, CmsPage } from '../lib/types';
import { Badge, Button, EmptyState, LinkButton, Skeleton } from '../components/ui';

const DAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

function Hours({ id }: { id: string }) {
  const { t } = useI18n();
  const q = useQuery({ queryKey: ['hours', id], queryFn: async () => (await get<{ data: BranchHours }>(`/branches/${id}/hours`)).data, staleTime: 10 * 60_000 });
  if (!q.data) return <Skeleton className="mt-4 h-24" />;
  const today = new Date().getDay();
  return (
    <ul className="mt-4 space-y-1 text-sm">
      {[...q.data.weekly].sort((a, b) => a.day_of_week - b.day_of_week).map((d) => (
        <li key={d.day_of_week} className={cx('flex justify-between rounded-lg px-2 py-1', d.day_of_week === today && 'bg-brass-tint/60 font-semibold')}>
          <span>{t(`day.${DAYS[d.day_of_week]}`)}</span>
          <span className="tabular-nums" dir="ltr">{d.is_closed ? t('branch.closed') : `${d.opens_at} – ${d.closes_at}`}</span>
        </li>
      ))}
    </ul>
  );
}

export function Showrooms() {
  const { t, date } = useI18n();
  const { branches, loading, branchId, select } = useBranch();
  useDocTitle(t('nav.showrooms'));
  return (
    <div className="wrap py-8 md:py-12">
      <p className="eyebrow">{t('showrooms.eyebrow')}</p>
      <h1 className="mt-2 text-[clamp(40px,6vw,76px)] leading-[0.98]">{t('nav.showrooms')}</h1>
      <p className="mt-4 max-w-xl text-ink-soft">{t('showrooms.intro')}</p>
      <div className="mt-10 grid gap-6 md:grid-cols-2">
        {loading && <><Skeleton className="h-72" /><Skeleton className="h-72" /></>}
        {branches.map((b) => (
          <article key={b.id} className="rounded-[28px] border border-line bg-paper p-6 sm:p-7">
            <div className="flex items-start justify-between gap-3">
              <h2 className="text-3xl leading-tight">{b.name}</h2>
              <Badge tone={b.opening.is_open ? 'success' : 'sand'}>{b.opening.is_open ? t('branch.open') : t('branch.closed')}</Badge>
            </div>
            {b.opening.is_open && b.opening.closes_at && <p className="mt-1 text-sm text-muted">{t('branch.closes_at', { d: date(b.opening.closes_at, { timeStyle: 'short', timeZone: 'Asia/Qatar' }) })}</p>}
            {!b.opening.is_open && b.opening.next_open_at && <p className="mt-1 text-sm text-muted">{t('branch.opens_at', { d: date(b.opening.next_open_at, { weekday: 'short', timeStyle: 'short', timeZone: 'Asia/Qatar' }) })}</p>}
            <ul className="mt-5 space-y-2.5 text-sm text-ink-soft">
              {b.address && <li className="flex gap-3"><MapPin size={17} className="mt-0.5 shrink-0 text-brass" />{b.address}</li>}
              {b.phone && <li><a href={telHref(b.phone)} className="flex gap-3 hover:text-brass-deep"><Phone size={17} className="mt-0.5 shrink-0 text-brass" /><bdi>{b.phone}</bdi></a></li>}
              {b.email && <li><a href={`mailto:${b.email}`} className="flex gap-3 hover:text-brass-deep"><Mail size={17} className="mt-0.5 shrink-0 text-brass" />{b.email}</a></li>}
            </ul>
            <div className="mt-4 flex flex-wrap gap-2">{b.pickup_enabled && <Badge tone="brass">{t('checkout.pickup')}</Badge>}{b.delivery_enabled && <Badge tone="brass">{t('checkout.delivery')}</Badge>}{b.pay_at_pickup_enabled && <Badge>{t('pay.pickup')}</Badge>}</div>
            <details className="mt-4 group"><summary className="flex cursor-pointer list-none items-center gap-2 text-sm font-semibold"><Clock size={16} className="text-brass" />{t('branch.hours')}</summary><Hours id={b.id} /></details>
            <div className="mt-6 flex flex-wrap gap-2">
              {mapsHref(b.latitude, b.longitude, b.name) && <a href={mapsHref(b.latitude, b.longitude, b.name)} target="_blank" rel="noreferrer" className="inline-flex h-10 items-center gap-2 rounded-full border border-line px-5 text-sm font-medium hover:border-primary"><Navigation size={15} />{t('order.directions')}</a>}
              <Button size="sm" variant={branchId === b.id ? 'primary' : 'outline'} className="h-10" onClick={() => select(b.id)}>{branchId === b.id ? t('branch.selected') : t('branch.shop_here')}</Button>
            </div>
          </article>
        ))}
      </div>
    </div>
  );
}

export function CmsPageView() {
  const { slug = '' } = useParams();
  const { t, date } = useI18n();
  const q = useQuery({ queryKey: ['page', slug], queryFn: async () => (await get<{ data: CmsPage }>(`/pages/${slug}`)).data, retry: (n, e) => !(e instanceof ApiError && e.status === 404) && n < 2 });
  useDocTitle(q.data?.title);
  if (q.isError) return <NotFound />;
  if (!q.data) return <div className="wrap max-w-3xl py-16"><Skeleton className="h-12 w-2/3" /><Skeleton className="mt-6 h-64" /></div>;
  return (
    <article className="wrap max-w-3xl py-12 md:py-16">
      <h1 className="text-[clamp(40px,6vw,68px)] leading-none">{q.data.title}</h1>
      <p className="mt-3 text-xs text-muted">{t('page.updated', { d: date(q.data.updated_at, { dateStyle: 'medium' }) })}</p>
      <div className="prose-store mt-8 text-[16px] leading-relaxed" dangerouslySetInnerHTML={{ __html: sanitizeHtml(q.data.body) }} />
    </article>
  );
}

export function NotFound() {
  const { t } = useI18n();
  useDocTitle(t('notfound.title'));
  return <div className="wrap py-24"><EmptyState icon={<SearchX size={30} />} title={t('notfound.title')} text={t('notfound.text')} action={<div className="flex gap-3"><LinkButton to="/">{t('nav.home')}</LinkButton><LinkButton to="/shop" variant="outline">{t('nav.shop')}</LinkButton></div>} /></div>;
}
