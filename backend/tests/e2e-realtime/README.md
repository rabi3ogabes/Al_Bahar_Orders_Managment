# Real-time end-to-end run

Needs: Postgres with the demo seed (`php artisan migrate --force && php artisan db:seed --force`), Redis, then in four shells with the same
environment (own DB, `QUEUE_CONNECTION=redis`, `BROADCAST_CONNECTION=reverb`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_APP_ID`):
`php artisan serve --port=8071`, `php artisan reverb:start --port=8081`, `php artisan queue:work redis --queue=default,notifications,exports`.

    BASE=http://127.0.0.1:8071 WS_HOST=127.0.0.1 WS_PORT=8081 WS_KEY=<REVERB_APP_KEY> OUT=/tmp/rt.json node tests/e2e-realtime/run.mjs

`pusher-js` is taken from `storefront/node_modules` (run `npm ci` there once). It places four orders and drives them through every step as the
customer, the West Bay manager and the West Bay driver, times every message, and checks the contract and the audience rules.
`MODE=legacy SETTLE=3600` skips the new-contract checks (used for the "before" numbers).
