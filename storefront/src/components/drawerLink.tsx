import { createContext, useCallback, useContext } from 'react';
import { Link, useNavigate, type LinkProps, type To } from 'react-router-dom';

/** What a side window tells the links inside it (kept out of SideDrawer.tsx so the first-paint bundle can use it). */
export interface DrawerContext { urlBacked: boolean; close: () => void }
export const DrawerCtx = createContext<DrawerContext>({ urlBacked: false, close: () => {} });

/** A link inside a window. It replaces the window's history entry (so Back from the destination skips the window), except
 *  for URL windows where the destination simply follows the window in history. */
export function DrawerLink({ to, onClick, ...p }: LinkProps) {
  const { urlBacked, close } = useContext(DrawerCtx);
  return <Link to={to} replace={!urlBacked} onClick={(e) => { onClick?.(e); if (!urlBacked && !e.defaultPrevented) close(); }} {...p} />;
}
/** Programmatic version of `DrawerLink`: closes the window and goes there. */
export function useDrawerNavigate() {
  const { urlBacked, close } = useContext(DrawerCtx);
  const navigate = useNavigate();
  return useCallback((to: To) => { navigate(to, { replace: !urlBacked }); if (!urlBacked) close(); }, [navigate, urlBacked, close]);
}
