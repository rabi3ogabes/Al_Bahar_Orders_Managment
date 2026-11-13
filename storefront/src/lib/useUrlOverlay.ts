import { useCallback, useEffect, useRef } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { URL_MARK, openedByUs, readParam, withParam, withoutParam } from './overlay';

/**
 * A side window whose open state lives in the URL (`?order=<id>`, `?quick=<slug>`): reload, share and notification links
 * open it, and the history entry that opened it is the one Back removes. Opening pushes one entry (switching to another
 * record replaces it); closing steps back when we pushed it, otherwise (deep link) just drops the parameter in place.
 */
export function useUrlOverlay(key: string) {
  const loc = useLocation();
  const navigate = useNavigate();
  const value = readParam(loc.search, key);
  const live = useRef({ loc, value });
  live.current = { loc, value };

  const open = useCallback((id: string) => {
    const { loc: l, value: v } = live.current;
    navigate({ pathname: l.pathname, search: withParam(l.search, key, id), hash: l.hash }, { replace: !!v, state: { [URL_MARK]: key } });
  }, [navigate, key]);

  const close = useCallback(() => {
    const { loc: l, value: v } = live.current;
    if (!v) return;
    if (openedByUs(l.state, key)) navigate(-1);
    else navigate({ pathname: l.pathname, search: withoutParam(l.search, key), hash: l.hash }, { replace: true });
  }, [navigate, key]);

  return { value, open, close };
}

/** Keeps the last non-empty value, so a window can finish its exit animation after the URL parameter is already gone. */
export function useLastValue<T>(value: T | null): T | null {
  const last = useRef<T | null>(value);
  useEffect(() => { if (value) last.current = value; });
  return value ?? last.current;
}
