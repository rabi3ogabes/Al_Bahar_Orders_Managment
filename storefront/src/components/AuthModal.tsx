import { createContext, lazy, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from 'react';
import { useAuth } from '../lib/auth';
import { LazyHost } from './Lazy';

export { captureReferral, normalizePhone } from '../lib/authHelpers';

interface Ctx { open: (opts?: { reason?: string; onDone?: () => void }) => void; close: () => void }
const C = createContext<Ctx>({ open: () => {}, close: () => {} });
export const useAuthModal = () => useContext(C);

// The sign-in window (and the dialog library behind it) is fetched the first time someone needs it.
const loadFlow = () => import('./AuthFlow');
const AuthFlow = lazy(loadFlow);

export function AuthModalProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<{ reason?: string; onDone?: () => void } | null>(null);
  const { user } = useAuth();
  const done = useRef<(() => void) | undefined>();
  const open = useCallback((o?: { reason?: string; onDone?: () => void }) => { done.current = o?.onDone; setState(o ?? {}); }, []);
  const close = useCallback(() => setState(null), []);
  // Signed in → run the action that needed an account, then get out of the way.
  useEffect(() => { if (user && state) { const fn = done.current; done.current = undefined; setState(null); fn?.(); } }, [user, state]);
  return (
    <C.Provider value={{ open, close }}>
      {children}
      <LazyHost when={!!state}>{state && <AuthFlow reason={state.reason} onClose={close} />}</LazyHost>
    </C.Provider>
  );
}

