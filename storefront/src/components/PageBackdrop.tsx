import { useState, type CSSProperties } from 'react';
import { SCENES, cssUrl } from '../lib/backdropCore';
import { useBackdrop } from '../lib/brand';

/**
 * Every built-in artwork as a file address, keyed by its file name. `?no-inline` makes Vite emit each one as its own file (even the
 * small ones) instead of a data address inside the main script, and the browser only requests the one the page actually draws.
 */
const ART_URLS: Record<string, string> = Object.fromEntries(
  Object.entries(import.meta.glob<string>('../assets/qatar-*.svg', { query: '?url&no-inline', import: 'default', eager: true }))
    .map(([path, url]) => [path.slice(path.lastIndexOf('/') + 1), url]),
);

/**
 * The page background set from the dashboard (docs/api/PAGE_BACKDROP.md): one of the built-in Qatar artworks in one colour, the
 * admin's own picture, or nothing (the page colour alone is applied in lib/brand.tsx). One fixed layer behind every page, rendered
 * once by the Layout inside an `isolate` wrapper, so header, cards and footer keep their own opaque backgrounds and text never sits
 * on the picture. It takes no input, is hidden from assistive technology, does not animate and is not printed.
 */
export function PageBackdrop() {
  const b = useBackdrop();
  // An own picture that fails to load simply disappears: no broken-image mark behind the page.
  const [failed, setFailed] = useState<string | null>(null);
  if (b.picture === 'skyline') {
    const art = SCENES[b.scene];
    const src = ART_URLS[art.file];
    if (!src) return null;
    // Tint: the dashboard's colour, else var(--primary), which is already the dark-mode primary in dark mode.
    const vars = { '--sky-url': cssUrl(src), '--sky-o': b.opacity, ...(b.tint ? { '--sky-tint': b.tint } : {}) } as CSSProperties;
    return (
      <div aria-hidden="true" className="page-backdrop" data-backdrop="skyline" data-scene={b.scene}>
        <div className={art.tile ? 'page-sky page-sky-tile' : 'page-sky'} style={vars} />
      </div>
    );
  }
  if (b.picture === 'image' && b.imageUrl && failed !== b.imageUrl) {
    const url = b.imageUrl;
    return (
      <div aria-hidden="true" className="page-backdrop" data-backdrop="image">
        <img src={url} alt="" decoding="async" draggable={false} onError={() => setFailed(url)} className={b.fit === 'cover' ? 'page-img-cover' : 'page-img-bottom'} style={{ opacity: b.opacity }} />
      </div>
    );
  }
  return null;
}
