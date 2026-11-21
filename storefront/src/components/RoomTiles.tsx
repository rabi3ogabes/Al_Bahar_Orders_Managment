import { Link } from 'react-router-dom';
import { useI18n } from '../lib/i18n';
import { cx } from '../lib/format';
import { roomColumns, roomHref, roomSrcSet, visibleRooms, type Room } from '../lib/roomsCore';
import { Pic, SectionHead } from './ui';

/**
 * "Shop by room": one large tile per room (picture, name, how many products), each leading to the shop filtered to that room. Used by both home designs
 * (`fresh` picks the card style and spacing of the fresh one). The pictures load as they come into view; a room with none shows the store's placeholder.
 */
export function RoomTiles({ items, title, fresh = false }: { items: Room[]; title?: string | null; fresh?: boolean }) {
  const { t, num } = useI18n();
  const rooms = visibleRooms(items);
  if (!rooms.length) return null;
  return (
    <section className={cx('wrap', fresh ? 'fx-sec' : 'mt-24')} aria-label={title || t('rooms.title')}>
      <SectionHead eyebrow={t('rooms.eyebrow')} title={title || t('rooms.title')} link={{ to: '/shop', label: t('action.view_all') }} />
      <ul className={cx('grid grid-cols-2 gap-3 sm:gap-5 md:grid-cols-3', roomColumns(rooms.length))}>
        {rooms.map((r, i) => (
          <li key={r.id} className={cx(rooms.length % 2 === 1 && i === rooms.length - 1 && 'col-span-2 md:col-span-1')}>
            <Link to={roomHref(r)} className={cx('group relative block overflow-hidden', fresh ? 'fx-card fx-hover rounded-[var(--r-card)]' : 'rounded-[24px]')} aria-label={`${r.name}, ${r.products_count === 1 ? t('rooms.count_one') : t('rooms.count', { n: num(r.products_count) })}`}>
              <Pic src={r.image_url} srcSet={roomSrcSet(r)} sizes="(min-width: 1024px) 20vw, (min-width: 768px) 33vw, 50vw" alt="" ratio="4/5" label={r.name} />
              <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-ink/75 via-ink/25 to-transparent p-4 pt-16 text-white">
                <span className="block font-display text-2xl leading-tight">{r.name}</span>
                <span className="mt-0.5 block text-[13px] text-white/80">{r.products_count === 1 ? t('rooms.count_one') : t('rooms.count', { n: num(r.products_count) })}</span>
              </div>
            </Link>
          </li>
        ))}
      </ul>
    </section>
  );
}
