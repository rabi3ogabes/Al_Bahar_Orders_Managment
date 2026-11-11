# Push notifications (customer, driver, staff apps)

The server sends pushes by itself once Firebase is connected; until then it skips them silently.

## What you provide (one time)
1. In the Firebase console create a project and add three Android apps with these package ids:
   `qa.albahar.customer`, `qa.albahar.driver`, `qa.albahar.staff`.
2. Download each app's `google-services.json` and commit them (they hold no secrets) as
   `mobile/android/app/src/<flavor>/google-services.json` — customer: `src/main`-level flavors `customerProd`/`customerStaging`/`customerDev`
   (put the same file in each flavor folder you build), driver: `driverProd`, staff: `staffProd`.
3. Project settings → Service accounts → generate a private key (JSON). Never commit it. Upload it to the server and set
   `FCM_PROJECT_ID` and `FCM_CREDENTIALS_PATH` in the app's environment (panel → Docker app → Environment), then restart.

## What each app shows
- Customer: one live "order progress" card per order (step bar Placed → Preparing → On the way → Delivered), updated in place.
- Driver: "New delivery" when a job is assigned. Staff: "Order ready, needs a driver" and "Delivery failed".
- Each app has its own small notification icon (bag / pin / storefront) and the Qatar maroon accent.
