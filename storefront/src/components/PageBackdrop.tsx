import { useState, type CSSProperties } from 'react';
import skylineSrc from '../assets/qatar-skyline.svg?url';
import { cssUrl } from '../lib/backdropCore';
import { useBackdrop } from '../lib/brand';

/**
 * The page background set from the dashboard (docs/api/PAGE_BACKDROP.md): the Doha skyline in one colour, the admin's own
 * picture, or nothing (the page colour alone is applied in lib/brand.tsx). One fixed layer behind every page, rendered once by
 * the Layout inside an `isolate` wrapper, so header, cards and footer keep their own opaque backgrounds and text never sits
 * on the picture. It takes no input, is hidden from assistive technology, does not animate and is not printed.
 */
export function PageBackdrop() {
  const b = useBackdrop();
  // An own picture that fails to load simply disappears: no broken-image mark behind the page.
  const [failed, setFailed] = useState<string | null>(null);
  if (b.picture === 'skyline') {
    // Tint: the dashboard's colour, else var(--primary), which is already the dark-mode primary in dark mode.
    const vars = { '--sky-url': cssUrl(skylineSrc), '--sky-o': b.opacity, ...(b.tint ? { '--sky-tint': b.tint } : {}) } as CSSProperties;
    return <div aria-hidden="true" className="page-backdrop" data-backdrop="skyline"><div className="page-sky" style={vars} /></div>;
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
