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
