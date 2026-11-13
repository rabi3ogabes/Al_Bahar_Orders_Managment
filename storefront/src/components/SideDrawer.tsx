import { useCallback, useRef, useState, type ReactNode } from 'react';
import * as Dialog from '@radix-ui/react-dialog';
import { Drawer as Sheet } from 'vaul';
import { X } from 'lucide-react';
import { useI18n } from '../lib/i18n';
import { useMedia } from '../lib/hooks';
import { useCloseOnBack } from '../lib/useCloseOnBack';
import { useOpener } from '../lib/useOpener';
import { cx } from '../lib/format';
import { ConfirmDialog } from './Dialogs';
import { DrawerCtx } from './drawerLink';

/**
 * THE side window. End side (right in English, left in Arabic) on tablets and desktops, a tall bottom sheet with a drag
 * handle and swipe-down on phones. Radix Dialog gives the focus trap, scroll lock and Esc; vaul gives the swipe on phones.
 * Back, Esc, the scrim and ✕ each close exactly this window (see `useCloseOnBack`). Rules: docs/guides/SIDE_WINDOWS.md.
 */
export interface SideDrawerProps {
  open: boolean
  /** The person asked to close (✕, Esc, scrim, swipe or Back). Set your state / URL here. */
  onClose: () => void
  title: string
  /** Read out by screen readers after the title. */
  description?: string
  children: ReactNode
  /** Pinned at the bottom: the one primary action, named for what it does. */
  footer?: ReactNode
  /** Next to the ✕, e.g. an "Open full page" link. */
  headerAction?: ReactNode
  size?: 'sm' | 'md' | 'lg'
  side?: 'end' | 'start'
  /** `sheet` (default) on phones, or keep the side panel (navigation menu). */
  phone?: 'sheet' | 'side'
  /** Unsaved input: closing asks "Discard changes?" first. */
  dirty?: boolean
  /** The open state lives in the URL (`?order=`): Back already closes it, so no history entry is added here. */
  urlBacked?: boolean
  /** Where focus goes on close, if not the element that had it on open (the Add button of the cart panel). */
  returnFocus?: () => HTMLElement | null
  className?: string
}

// Narrower than the screen on phones too, so a strip of the page (the scrim) stays tappable.
const WIDTH = { sm: 'sm:w-[400px]', md: 'sm:w-[460px]', lg: 'sm:w-[560px]' } as const;

export function SideDrawer({ open, onClose, title, description, children, footer, headerAction, size = 'md', side = 'end', phone = 'sheet', dirty = false, urlBacked = false, returnFocus, className }: SideDrawerProps) {
  const { t } = useI18n();
  const isPhone = useMedia('(max-width: 767px)') && phone === 'sheet';
  const [ask, setAsk] = useState(false);
  const [rearm, setRearm] = useState(0);
  const panel = useRef<HTMLDivElement>(null);
  const opener = useOpener(returnFocus);

  const request = useCallback(() => { if (dirty) setAsk(true); else onClose(); }, [dirty, onClose]);
  // Back with unsaved input: stay open, ask, and put the history entry back for the next Back.
  useCloseOnBack(open && !urlBacked, () => { if (dirty) { setAsk(true); setRearm((n) => n + 1); } else onClose(); }, rearm);

  const onOpenAutoFocus = (e: Event) => {
    opener.remember();
    e.preventDefault();
    const box = panel.current;
    // First field when there is one (not on touch screens: the keyboard would cover the window), otherwise the window itself.
    const field = window.matchMedia('(pointer: fine)').matches ? box?.querySelector<HTMLElement>('[data-autofocus],input:not([type=hidden]):not([disabled]),textarea,select') : null;
    (field ?? box)?.focus({ preventScroll: true });
  };

  const head = (
    <div className="flex shrink-0 items-center gap-2 border-b border-line ps-6 pe-3 pt-4 pb-3 max-md:pt-2">
      {isPhone ? <Sheet.Title className="min-w-0 flex-1 truncate text-2xl font-medium">{title}</Sheet.Title> : <Dialog.Title className="min-w-0 flex-1 truncate text-2xl font-medium">{title}</Dialog.Title>}
      {headerAction}
      {isPhone
        ? <Sheet.Close aria-label={t('action.close')} title={t('action.close')} className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-ink transition-colors hover:bg-sand"><X size={20} /></Sheet.Close>
        : <Dialog.Close aria-label={t('action.close')} title={t('action.close')} className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-ink transition-colors hover:bg-sand"><X size={20} /></Dialog.Close>}
    </div>
  );
  const body = <div className="ov-body min-h-0 flex-1 overflow-y-auto overscroll-contain">{children}</div>;
  const foot = footer ? <div className="shrink-0 border-t border-line bg-paper px-6 pt-4 pb-[max(16px,env(safe-area-inset-bottom))]">{footer}</div> : null;
  const sr = description ? (isPhone ? <Sheet.Description className="sr-only">{description}</Sheet.Description> : <Dialog.Description className="sr-only">{description}</Dialog.Description>) : null;

  return (
    <DrawerCtx.Provider value={{ urlBacked, close: request }}>
      {isPhone ? (
        <Sheet.Root open={open} onOpenChange={(o) => { if (!o) request(); }}>
          <Sheet.Portal>
            <Sheet.Overlay className="ov-scrim fixed inset-0 z-[80] bg-ink/45 backdrop-blur-[2px]" />
            <Sheet.Content ref={panel} tabIndex={-1} onOpenAutoFocus={onOpenAutoFocus} onCloseAutoFocus={opener.restore} {...(description ? {} : { 'aria-describedby': undefined })}
              className={cx('fixed inset-x-0 bottom-0 z-[80] flex max-h-[92dvh] min-h-[40dvh] flex-col rounded-t-[28px] bg-bg shadow-soft outline-none', className)}>
              <Sheet.Handle className="!mt-2.5 !mb-1 !h-1.5 !w-11 shrink-0 !bg-line" />
              {head}{sr}{body}{foot}
            </Sheet.Content>
          </Sheet.Portal>
        </Sheet.Root>
      ) : (
        <Dialog.Root open={open} onOpenChange={(o) => { if (!o) request(); }}>
          <Dialog.Portal>
            <Dialog.Overlay className="ov-scrim fixed inset-0 z-[80] bg-ink/45 backdrop-blur-[2px]" />
            <Dialog.Content ref={panel} tabIndex={-1} data-side={side} onOpenAutoFocus={onOpenAutoFocus} onCloseAutoFocus={opener.restore} {...(description ? {} : { 'aria-describedby': undefined })}
              className={cx('ov-panel fixed inset-y-0 z-[80] flex w-[calc(100vw-48px)] flex-col bg-bg shadow-soft outline-none', side === 'end' ? 'end-0 sm:rounded-s-[28px]' : 'start-0 sm:rounded-e-[28px]', WIDTH[size], className)}>
              {head}{sr}{body}{foot}
            </Dialog.Content>
          </Dialog.Portal>
        </Dialog.Root>
      )}
      <ConfirmDialog open={ask} onClose={() => setAsk(false)} title={t('discard.title')} body={t('discard.body')} confirmLabel={t('discard.confirm')} cancelLabel={t('discard.keep')}
        onConfirm={() => { setAsk(false); onClose(); }} />
    </DrawerCtx.Provider>
  );
}
