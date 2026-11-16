import { useCallback, useEffect, useRef, useState, type KeyboardEvent } from 'react';
import { Link } from 'react-router-dom';
import { ChevronLeft, ChevronRight, Pause, Play } from 'lucide-react';
import { useI18n } from '../lib/i18n';
import { useMedia } from '../lib/hooks';
import { bannerTarget } from '../lib/bannerLink';
import { aspectSize, indexFromScroll, normaliseSlider, scrollLeftFor, stepForKey, wrapIndex } from '../lib/sliderCore';
import type { HomeSection, HomeSlide } from '../lib/types';

/** True while the element is on screen (autoplay should not run for a slider nobody can see). */
function useOnScreen(ref: React.RefObject<Element | null>): boolean {
  const [on, setOn] = useState(true);
  useEffect(() => {
    const el = ref.current;
    if (!el || typeof IntersectionObserver === 'undefined') return;
    const io = new IntersectionObserver(([e]) => setOn(e.isIntersecting), { threshold: 0.25 });
    io.observe(el);
    return () => io.disconnect();
  }, [ref]);
  return on;
}

function useTabVisible(): boolean {
  const [v, setV] = useState(() => typeof document === 'undefined' || document.visibilityState !== 'hidden');
  useEffect(() => {
    const fn = () => setV(document.visibilityState !== 'hidden');
    document.addEventListener('visibilitychange', fn);
    return () => document.removeEventListener('visibilitychange', fn);
  }, []);
  return v;
}

/**
 * A home slider (a `banners` section): the admin chose its place on the home page, its shape, whether it slides on its own, the dots, titles and
 * rounded corners. Swipe on touch, arrows, dots, ←/→, and a pause button; no motion at all when the visitor asked for reduced motion; mirrors in
 * right-to-left. Slides with a link are links (inside the store, or to an https address in a new tab); slides without one are plain pictures.
 * The box has its aspect ratio from the first paint, so the page never jumps when a picture arrives.
 */
export function BannerSlider({ section, eager = false }: { section: HomeSection; eager?: boolean }) {
  const { t, dir } = useI18n();
  const rtl = dir === 'rtl';
  const settings = normaliseSlider(section.slider);
  const slides = (section.items as HomeSlide[]).filter((s) => !!s.image_url);
  const count = slides.length;
  const { w, h } = aspectSize(settings.aspect);

  const root = useRef<HTMLElement>(null);
  const track = useRef<HTMLDivElement>(null);
  const raf = useRef(0);
  const [active, setActive] = useState(0);
  const [hover, setHover] = useState(false);
  const [focus, setFocus] = useState(false);
  const [touch, setTouch] = useState(false);
  const [userPaused, setUserPaused] = useState(false);
  const touchTimer = useRef(0);
  const reduced = useMedia('(prefers-reduced-motion: reduce)');
  const onScreen = useOnScreen(root);
  const tabVisible = useTabVisible();

  const go = useCallback((i: number) => {
    const el = track.current;
    if (!el || count === 0) return;
    const next = wrapIndex(i, count);
    el.scrollTo({ left: scrollLeftFor(next, el.clientWidth, rtl), behavior: reduced ? 'auto' : 'smooth' });
    setActive(next);
  }, [count, rtl, reduced]);

  // The track scrolls (swipe, arrows, dots): follow it to know which slide is showing.
  const onScroll = () => {
    cancelAnimationFrame(raf.current);
    raf.current = requestAnimationFrame(() => {
      const el = track.current;
      if (el) setActive(indexFromScroll(el.scrollLeft, el.clientWidth, count));
    });
  };
  useEffect(() => () => { cancelAnimationFrame(raf.current); clearTimeout(touchTimer.current); }, []);

  // A new language or window size keeps the same slide in view.
  useEffect(() => {
    const el = track.current;
    if (el) el.scrollTo({ left: scrollLeftFor(active, el.clientWidth, rtl), behavior: 'auto' });
  }, [rtl]); // eslint-free project: only the language switch re-seats the track

  const paused = hover || focus || touch || userPaused;
  const running = settings.autoplay && count > 1 && !reduced;
  // The timer restarts after every slide change (also a manual one), so a slide always gets its full time.
  useEffect(() => {
    if (!running || paused || !onScreen || !tabVisible) return;
    const id = window.setTimeout(() => go(active + 1), settings.interval * 1000);
    return () => window.clearTimeout(id);
  }, [running, paused, onScreen, tabVisible, active, settings.interval, go]);

  const onKey = (e: KeyboardEvent) => {
    const step = stepForKey(e.key, rtl);
    if (step === 0 || count < 2) return;
    e.preventDefault();
    go(active + step);
  };
  const holdTouch = () => { clearTimeout(touchTimer.current); setTouch(true); };
  const releaseTouch = () => { clearTimeout(touchTimer.current); touchTimer.current = window.setTimeout(() => setTouch(false), 4000); };

  if (count === 0) return null;
  const cur = Math.min(active, count - 1); // the list can get shorter while the page is open (another branch, a refresh)
  const heading = section.title?.trim();
  const label = heading || t('slider.region');
  const Prev = rtl ? ChevronRight : ChevronLeft;
  const Next = rtl ? ChevronLeft : ChevronRight;
  const roundedCls = settings.rounded ? 'rounded-[var(--r-big)]' : '';

  return (
    <section ref={root} className="wrap mt-6 sm:mt-8" aria-roledescription="carousel" aria-label={label}
      onMouseEnter={() => setHover(true)} onMouseLeave={() => setHover(false)} onFocusCapture={() => setFocus(true)} onBlurCapture={() => setFocus(false)} onKeyDown={onKey}>
      {heading && (
        <div className="mb-4">
          <h2 className="text-[clamp(22px,3vw,32px)] leading-tight">{heading}</h2>
          {section.subtitle && <p className="mt-1 text-[15px] text-ink-soft">{section.subtitle}</p>}
        </div>
      )}
      <div className={`group/slider relative isolate overflow-hidden bg-sand ${roundedCls}`} style={{ aspectRatio: `${w} / ${h}` }}>
        <div ref={track} onScroll={onScroll} onPointerDown={holdTouch} onPointerUp={releaseTouch} onPointerCancel={releaseTouch}
          aria-live={running && !paused ? 'off' : 'polite'} tabIndex={count > 1 ? 0 : undefined}
          className="flex h-full snap-x snap-mandatory overflow-x-auto overscroll-x-contain [scrollbar-width:none] focus-visible:outline-2 focus-visible:-outline-offset-4 focus-visible:outline-brass [&::-webkit-scrollbar]:hidden">
          {slides.map((s, i) => {
            const target = bannerTarget(s.link);
            const title = s.title?.trim() || '';
            const sub = s.subtitle?.trim() || '';
            const name = title || sub || t('slider.open');
            const here = i === cur;
            const body = (
              <>
                <img src={s.image_url!} alt={target ? '' : title} width={w * 100} height={h * 100} draggable={false} decoding="async"
                  loading={eager && i === 0 ? 'eager' : 'lazy'} {...(eager && i === 0 ? { fetchPriority: 'high' as const } : {})}
                  className="absolute inset-0 h-full w-full select-none object-cover" />
                {settings.show_titles && (title || sub) && (
                  <span className={`absolute inset-x-0 bottom-0 block bg-gradient-to-t from-black/70 via-black/30 to-transparent px-4 pt-12 text-start text-white sm:px-8 ${settings.dots && count > 1 ? 'pb-9' : 'pb-4 sm:pb-6'}`}>
                    {title && <span className="font-display block text-[clamp(20px,3.4vw,38px)] leading-tight">{title}</span>}
                    {sub && <span className="mt-1 block text-[13px] text-white/85 sm:text-[16px]">{sub}</span>}
                  </span>
                )}
              </>
            );
            const cls = 'relative block h-full w-full shrink-0 grow-0 basis-full snap-center focus-visible:outline-2 focus-visible:-outline-offset-4 focus-visible:outline-white';
            return (
              <div key={s.id} role="group" aria-roledescription="slide" aria-label={t('slider.slide_n', { n: i + 1, total: count })} aria-hidden={!here || undefined} className="h-full w-full shrink-0 grow-0 basis-full snap-center">
                {target?.kind === 'internal' ? <Link to={target.to} className={cls} aria-label={name} tabIndex={here ? 0 : -1}>{body}</Link>
                  : target?.kind === 'external' ? <a href={target.href} target="_blank" rel="noopener noreferrer" className={cls} aria-label={name} tabIndex={here ? 0 : -1}>{body}</a>
                  : <div className={cls}>{body}</div>}
              </div>
            );
          })}
        </div>

        {count > 1 && (
          <>
            <button type="button" onClick={() => go(cur - 1)} aria-label={t('slider.prev')}
              className="absolute start-2 top-1/2 z-10 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-paper/90 text-ink shadow-soft transition hover:bg-paper focus-visible:outline-2 sm:start-4 sm:h-11 sm:w-11 md:opacity-0 md:group-hover/slider:opacity-100 md:focus-visible:opacity-100"><Prev size={20} /></button>
            <button type="button" onClick={() => go(cur + 1)} aria-label={t('slider.next')}
              className="absolute end-2 top-1/2 z-10 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-paper/90 text-ink shadow-soft transition hover:bg-paper focus-visible:outline-2 sm:end-4 sm:h-11 sm:w-11 md:opacity-0 md:group-hover/slider:opacity-100 md:focus-visible:opacity-100"><Next size={20} /></button>
            {settings.dots && (
              <div className="absolute inset-x-0 bottom-2 z-10 flex items-center justify-center gap-0.5 sm:bottom-3">
                {slides.map((s, i) => (
                  <button key={s.id} type="button" onClick={() => go(i)} aria-label={t('slider.go_to', { n: i + 1 })} aria-current={i === cur ? 'true' : undefined} className="group/dot flex h-6 items-center px-1">
                    <span className={`block h-1.5 rounded-full bg-white shadow-[0_0_0_1px_rgb(0_0_0/0.25)] transition-all duration-300 ${i === cur ? 'w-6' : 'w-1.5 opacity-70 group-hover/dot:opacity-100'}`} />
                  </button>
                ))}
              </div>
            )}
            {settings.autoplay && !reduced && (
              <button type="button" onClick={() => setUserPaused((p) => !p)} aria-label={userPaused ? t('slider.play') : t('slider.pause')} aria-pressed={userPaused}
                className="absolute end-2 top-2 z-10 flex h-8 w-8 items-center justify-center rounded-full bg-black/45 text-white backdrop-blur transition hover:bg-black/65 focus-visible:outline-2 sm:end-4 sm:top-4">
                {userPaused ? <Play size={14} fill="currentColor" /> : <Pause size={14} fill="currentColor" />}
              </button>
            )}
          </>
        )}
      </div>
    </section>
  );
}
