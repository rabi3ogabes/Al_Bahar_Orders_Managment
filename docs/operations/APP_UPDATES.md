# Forcing an app update (Google Play / App Store)

Dashboard → **Organization → App updates** (`/admin/app-updates`). One card per app (customer, driver, branch staff) and per store (Android, iPhone).

1. Publish the new version in the store. The **build number** is the number after "+" in the version (`1.0.0+15` → 15).
2. Type the newest version and build, check the store link (Google Play links are pre-filled from the app id; add the App Store link once the iPhone app exists), optionally write a message in English and Arabic, and **Save**.
   People on older builds now see a small, dismissible "A new version is available" card.
3. Press **Force update** (it asks first). Everyone on a build below the newest build is covered by a full-screen "Update required" page whose only button opens the store.
   The server also answers HTTP 426 to every API call from those builds, so the block cannot be bypassed. **Stop forcing** lifts it at once.
   You can also set "Must update below build" yourself, e.g. to force only builds below 14 while 15 is the newest.

How it works: apps send `X-App`, `X-App-Platform`, `X-App-Build` on every request and ask `GET /api/v1/app-update?app=&platform=&build=` at start-up,
when they come to the foreground and every 30 minutes. Builds older than this feature (they send no headers) cannot be forced; they update normally from the store.
**Publish the new version in the store before pressing Force update**, otherwise people are sent to a store page with nothing new.
