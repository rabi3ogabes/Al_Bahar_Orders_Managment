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
