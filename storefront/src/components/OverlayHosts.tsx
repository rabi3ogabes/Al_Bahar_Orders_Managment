import { lazy, useEffect, useRef } from 'react';
import { useCart } from '../lib/cart';
import { isOrderId, isSlug } from '../lib/overlay';
import { useLastValue, useUrlOverlay } from '../lib/useUrlOverlay';
import { LazyHost } from './Lazy';

const OrderImpl = lazy(() => import('./OrderDrawer'));
const QuickImpl = lazy(() => import('./QuickView'));
/** Warm the quick view's code when a product card is touched. */
export const preloadQuick = () => { void import('./QuickView'); };
export const preloadOrder = () => { void import('./OrderDrawer'); };

/**
 * The side windows that live in the URL: `?order=<id>` (an order) and `?quick=<slug>` (a product quick view). Mounted once
 * in the layout, so a reload, a shared link or a notification link opens the window on any page, and Back closes it.
 */
export function OverlayHosts() {
  const cart = useCart();
  const order = useUrlOverlay('order');
  const quick = useUrlOverlay('quick');
  const orderId = isOrderId(order.value) ? order.value : null;
  const slug = isSlug(quick.value) ? quick.value : null;
  const lastOrder = useLastValue(orderId);
  const lastSlug = useLastValue(slug);

  // "Add to bag" in the quick view: close it first, then show the bag with the new line (never a window opened from a window); the cart panel keeps the "Added to cart" confirmation.
  const wantBag = useRef(false);
  useEffect(() => { if (!slug && wantBag.current) { wantBag.current = false; cart.reveal(); } }, [slug]); // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <>
      <LazyHost when={!!orderId}>{lastOrder && <OrderImpl id={lastOrder} open={!!orderId} onClose={order.close} />}</LazyHost>
      <LazyHost when={!!slug}>{lastSlug && <QuickImpl slug={lastSlug} open={!!slug} onClose={quick.close} onAdded={() => { wantBag.current = true; quick.close(); }} />}</LazyHost>
    </>
  );
}
