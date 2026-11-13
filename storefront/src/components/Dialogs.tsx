import { useEffect, useRef, useState, type ReactNode } from 'react';
import * as Dialog from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import { ApiError } from '../lib/api';
import { useI18n } from '../lib/i18n';
import { useCloseOnBack } from '../lib/useCloseOnBack';
import { useOpener } from '../lib/useOpener';
import { cx } from '../lib/format';
import { Button } from './ui';

/**
 * Centred windows. `ConfirmDialog` is for decisions: a question as the title, the consequence as the text, a verb on the
 * button, Cancel focused, never closed by a stray click on the scrim. `Modal` holds a short form (the sign-in flow).
 */
export function ConfirmDialog({ open, onClose, title, body, confirmLabel, cancelLabel, onConfirm, destructive = true, confirmDisabled, children }: {
  open: boolean; onClose: () => void; title: string; body?: string; confirmLabel: string; cancelLabel?: string; confirmDisabled?: boolean
  onConfirm: () => void | Promise<void>; destructive?: boolean; children?: ReactNode
}) {
  const { t } = useI18n();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const cancel = useRef<HTMLButtonElement>(null);
  const opener = useOpener();
  const live = useRef(busy);
  live.current = busy;
  useEffect(() => { if (open) { setBusy(false); setError(''); } }, [open]);
  useCloseOnBack(open, () => { if (!live.current) onClose(); });

  const confirm = async () => {
    if (busy) return;
    setBusy(true); setError('');
    try { await onConfirm(); } catch (e) { setError(e instanceof ApiError ? e.message : t('error.generic')); } finally { setBusy(false); }
  };
  return (
    <Dialog.Root open={open} onOpenChange={(o) => { if (!o && !busy) onClose(); }}>
      <Dialog.Portal>
        <Dialog.Overlay className="ov-scrim fixed inset-0 z-[90] bg-ink/50 backdrop-blur-[2px]" />
        <Dialog.Content role="alertdialog" {...(body ? {} : { 'aria-describedby': undefined })}
          onOpenAutoFocus={(e) => { opener.remember(); e.preventDefault(); cancel.current?.focus({ preventScroll: true }); }} onCloseAutoFocus={opener.restore}
          onPointerDownOutside={(e) => { if (destructive) e.preventDefault(); }}
          className="ov-modal fixed inset-0 z-[90] m-auto h-fit max-h-[90dvh] w-[min(440px,calc(100vw-32px))] overflow-y-auto rounded-[28px] bg-bg p-6 shadow-soft outline-none">
          <Dialog.Title className="font-display text-[26px] leading-tight">{title}</Dialog.Title>
          {body && <Dialog.Description className="mt-2 text-sm leading-relaxed text-muted">{body}</Dialog.Description>}
          {children && <div className="mt-4">{children}</div>}
          {error && <p role="alert" className="mt-3 text-sm text-danger">{error}</p>}
          <div className="mt-6 flex flex-col-reverse gap-2.5 sm:flex-row sm:justify-end">
            <Button ref={cancel} variant="outline" disabled={busy} onClick={onClose}>{cancelLabel ?? t('action.cancel')}</Button>
            <Button variant={destructive ? 'danger' : 'primary'} loading={busy} disabled={confirmDisabled} onClick={() => void confirm()}>{confirmLabel}</Button>
          </div>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}

export function Modal({ open, onClose, title, children, wide, sheetOnPhone = true }: { open: boolean; onClose: () => void; title: string; children: ReactNode; wide?: boolean; sheetOnPhone?: boolean }) {
  const { t } = useI18n();
  const opener = useOpener();
  const box = useRef<HTMLDivElement>(null);
  useCloseOnBack(open, onClose);
  // The first field gets focus (marked data-autofocus), not the ✕ that comes first in the DOM.
  const focusIn = (e: Event) => { opener.remember(); e.preventDefault(); (box.current?.querySelector<HTMLElement>('[data-autofocus]') ?? box.current)?.focus({ preventScroll: true }); };
  return (
    <Dialog.Root open={open} onOpenChange={(o) => { if (!o) onClose(); }}>
      <Dialog.Portal>
        <Dialog.Overlay className="ov-scrim fixed inset-0 z-[90] bg-ink/50 backdrop-blur-[2px]" />
        <Dialog.Content ref={box} tabIndex={-1} aria-describedby={undefined} onOpenAutoFocus={focusIn} onCloseAutoFocus={opener.restore}
          className={cx('ov-modal fixed z-[90] max-h-[92dvh] overflow-y-auto bg-bg p-6 shadow-soft outline-none sm:inset-0 sm:m-auto sm:h-fit sm:rounded-[28px] sm:p-8',
            sheetOnPhone ? 'inset-x-0 bottom-0 rounded-t-[28px]' : 'inset-0 m-auto h-fit rounded-[28px]', wide ? 'sm:w-[min(672px,calc(100vw-32px))]' : 'sm:w-[min(448px,calc(100vw-32px))]')}>
          <div className="mb-5 flex items-start justify-between gap-4">
            <Dialog.Title className="font-display text-3xl leading-tight">{title}</Dialog.Title>
            <Dialog.Close aria-label={t('action.close')} title={t('action.close')} className="-me-2 -mt-2 flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-ink transition-colors hover:bg-sand"><X size={20} /></Dialog.Close>
          </div>
          {children}
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
