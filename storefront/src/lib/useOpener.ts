import { useRef } from 'react';

/**
 * Radix returns focus to a `Dialog.Trigger`; our windows are opened from state or the URL, so there is none.
 * Remember what had focus when the window opened (the button that was tapped) and give it back on close.
 */
export function useOpener() {
  const el = useRef<HTMLElement | null>(null);
  return {
    remember: () => { el.current = document.activeElement instanceof HTMLElement && document.activeElement !== document.body ? document.activeElement : null; },
    restore: (e: Event) => {
      e.preventDefault();
      const t = el.current;
      el.current = null;
      if (t?.isConnected) t.focus({ preventScroll: true });
    },
  };
}
