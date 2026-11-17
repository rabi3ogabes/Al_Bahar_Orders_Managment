# Al Bahar storefront

Customer web store (React 18 + TypeScript + Vite + Tailwind 4) on the public `/api/v1`. English and Arabic (RTL), light and dark.

```bash
npm ci
npm run dev        # http://localhost:5173, proxies /api to http://127.0.0.1:8000
npm run build      # type-checks, then builds to dist/ (served at /store/ — see docker/php/Dockerfile)
```

Laravel serves `public/store/index.html` for every storefront URL (`StorefrontController`); the SPA router renders the page.
Admin, `/api`, `/webhooks`, `/payments` and `/docs` are never captured. Customers sign in with a phone OTP (needs an SMS gateway) or a password.
Online payment hands off to SADAD and returns to `/orders/{id}` (`payments/return.blade.php`).

## Side windows

Quick views, the bag, an order, filters and edit forms open in a window beside the page (right in English, left in Arabic; a tall
bottom sheet with swipe-down on phones) instead of a new page, so one Back closes the window. Rules: `docs/guides/SIDE_WINDOWS.md`;
what became a window and why: `docs/qa/SIDE_WINDOWS_STORE.md`.

- `components/SideDrawer.tsx` is the one window (Radix Dialog for focus trap, scroll lock and Esc; vaul for the phone swipe).
  `components/Dialogs.tsx` holds the centred ones: `ConfirmDialog` (decisions) and `Modal` (sign-in).
- Every window is its own lazily loaded chunk (`components/windows.tsx`, `shell.tsx`, `OverlayHosts.tsx`), so none of it is in the first-paint bundle.
- `lib/useCloseOnBack.ts` gives one history entry per open window; `lib/useUrlOverlay.ts` keeps `?order=<id>` and `?quick=<slug>` in the URL
  (reload, share and notification links open the window). The pure logic is in `lib/overlay.ts` (tested in `tests/overlay.test.mjs`).
- Pages that push notifications and emails link to (`/orders/:id`, `/products/:slug`, `/cart`) keep working as full pages.

## Deals (countdown offers)

Contract: `docs/api/DEALS.md`. `/deals` (live deals as widgets with their products, an "Upcoming" list), `/deals/:id`, a "Deals" link with a live dot in both headers,
a home strip, the deal strip on the product page, a badge and deal price on cards and the quick view, deal wording in the cart and checkout (with the `409 deal_changed`
banner) and on a placed order.

- `lib/deals.ts` is the pure part (types, countdown maths against the server clock, deal price, limit / stock / quote-line wording in both languages); `lib/useDeals.ts` fetches
  (`useDeals()`: every 60 s while the tab is visible, on focus, and when a deal starts or ends); `lib/useNow.ts` is the one shared one-second clock.
- `components/DealWidget.tsx` (+ `dealWidget.css`) is the one widget: five styles, three shapes, three timer looks; it paints only the server's resolved `design.colors`.
  `DealBits.tsx` holds the pieces inside other pages (price, product strip, card badge, cart / checkout notes); `DealsStrip.tsx` the deal + product grid used by the deals page and home.
- Every field is optional: a server without deals sends no `deal` and nothing changes.

## Tests

```bash
npm test                                   # fast unit tests (node --test): overlay history/URL logic, shop filters, cart maths, web push, deals
STORE_URL=http://127.0.0.1:4173 node tests/e2e/cart-panel.e2e.mjs   # browser check of the cart panel (needs the built store + API, and Playwright)
```

`tests/e2e/cart-panel.e2e.mjs` runs at 375 and 1280 px in English and Arabic: Add to cart opens the panel without navigating, the badge moves at once,
adding the same item again makes quantity 2 (one line), Keep shopping keeps the scroll position and focus, outside click / Esc / Back close it, Checkout goes on.
Set `E2E_PHONE` and `E2E_PASSWORD` to run it signed in as well (server cart).

`tests/e2e/deals.e2e.mjs` checks the deals feature in a browser with every API response mocked (only the built store is served; Chromium needed): the five widget styles in English
and Arabic, light and dark, at 320 / 375 / 768 / 1280 px (no overflow, no console errors, server colours, ticking clock, reduced motion), the deals and deal pages, the home strip,
the product strip, cart, checkout with `409 deal_changed`, and a placed order. See the header of the file for the command.
