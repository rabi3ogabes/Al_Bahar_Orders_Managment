import { useRef, type ReactNode } from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useI18n } from '../lib/i18n';

/** Horizontal scroll-snap rail with keyboard-friendly arrow buttons (mirrors in RTL). */
export function Rail({ children, itemWidth = 'w-[62vw] sm:w-[34vw] md:w-[26vw] lg:w-[22%]' }: { children: ReactNode[]; itemWidth?: string }) {
  const ref = useRef<HTMLDivElement>(null);
  const { t, dir } = useI18n();
  const by = (d: 1 | -1) => ref.current?.scrollBy({ left: d * (ref.current.clientWidth * 0.85) * (dir === 'rtl' ? -1 : 1), behavior: 'smooth' });
  return (
    <div className="group/rail relative">
      <div ref={ref} className="-mx-4 flex snap-x snap-mandatory gap-4 overflow-x-auto scroll-smooth px-4 pb-2 [scrollbar-width:none] sm:-mx-10 sm:px-10 md:gap-6 [&::-webkit-scrollbar]:hidden">
        {children.map((c, i) => <div key={i} className={`${itemWidth} shrink-0 snap-start`}>{c}</div>)}
      </div>
      <button aria-label={t('action.prev')} onClick={() => by(-1)} className="absolute start-1 top-[34%] hidden h-12 w-12 items-center justify-center rounded-full bg-paper text-ink shadow-soft transition-opacity hover:bg-ink hover:text-bg md:flex md:opacity-0 md:group-hover/rail:opacity-100"><ChevronLeft size={20} className="rtl:rotate-180" /></button>
      <button aria-label={t('action.next')} onClick={() => by(1)} className="absolute end-1 top-[34%] hidden h-12 w-12 items-center justify-center rounded-full bg-paper text-ink shadow-soft transition-opacity hover:bg-ink hover:text-bg md:flex md:opacity-0 md:group-hover/rail:opacity-100"><ChevronRight size={20} className="rtl:rotate-180" /></button>
    </div>
  );
}
