import { useRef } from 'react';

/**
 * Radix returns focus to a `Dialog.Trigger`; our windows are opened from state or the URL, so there is none.
 * Remember what had focus when the window opened (the button that was tapped) and give it back on close.
 */
export function useOpener(prefer?: () => HTMLElement | null) {
  const el = useRef<HTMLElement | null>(null);
  return {
    remember: () => { el.current = document.activeElement instanceof HTMLElement && document.activeElement !== document.body ? document.activeElement : null; },
    restore: (e: Event) => {
      e.preventDefault();
      // The element the caller says should get focus (the Add button), else whatever had focus when the window opened.
      const t = [prefer?.(), el.current].find((x) => x?.isConnected);
      el.current = null;
      t?.focus({ preventScroll: true });
    },
  };
}
