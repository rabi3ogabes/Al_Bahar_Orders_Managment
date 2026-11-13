import { useEffect, useRef } from 'react';
import { OverlayStack } from './overlay';

let stack: OverlayStack | null = null;
/** One stack for the whole page, wired to the real history and a single popstate listener. */
function overlays(): OverlayStack {
  if (!stack) {
    const s = new OverlayStack(window.history);
    window.addEventListener('popstate', () => s.handlePop());
    stack = s;
  }
  return stack;
}

/**
 * While `open`, the browser / phone Back button closes this window (and only the top-most one): one history entry per
 * open window. Closing by ✕, Esc or the scrim takes the entry back out. StrictMode-safe (the entry is pushed one tick
 * after opening). `rearm` re-pushes the entry after Back was answered with a "Discard changes?" question.
 */
export function useCloseOnBack(open: boolean, onClose: () => void, rearm = 0): void {
  const close = useRef(onClose);
  close.current = onClose;
  useEffect(() => {
    if (!open) return;
    const s = overlays();
    const entry = s.register(() => close.current());
    const t = window.setTimeout(() => s.push(entry), 0);
    return () => { window.clearTimeout(t); s.release(entry); };
  }, [open, rearm]);
}
