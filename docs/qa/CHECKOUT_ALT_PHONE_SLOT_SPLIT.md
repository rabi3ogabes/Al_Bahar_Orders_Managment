# Checkout: earliest time, alternative phone number, split-shipment switch

Three changes to checkout, in the customer app, the web store, the API and the driver / branch staff apps.

## A. The earliest bookable time is chosen for the customer

- **Rule (one pure function per client, unit-tested):** `mobile/lib/features/checkout/domain/slot_selection.dart` (`earliestBookableSlot`, `decideSlot`) and `storefront/src/lib/slotPick.ts`. Tests: `mobile/test/features/slot_selection_test.dart`, `storefront/tests/slot-pick.test.mjs`.
- Once the times load (one span request, today + 13 days, `only_available` on the app), the soonest day and the earliest start with a free place is selected. Delivery and pickup behave the same.
- **A time that can still be booked is never replaced**, whoever chose it (an earlier time that opens up is offered, not forced).
- **Chosen again** when: the customer changes address, showroom or Delivery/Pickup; the chosen time fills up; its cutoff passes while the screen is open (checked every 30 s and when the app returns); the server refuses it (that time is remembered as refused and skipped). The screen then says "That time just became unavailable, so we chose the next earliest."
- The API now sends `bookable_until` (ISO-8601 +03:00, the slot's start minus its cutoff) on every slot row so a screen left open can tell by itself. Older servers omit it: the client then only refuses times that have already started.
- **Qatar time:** "today" is computed in Asia/Qatar (UTC+3), never the phone's or browser's date. The web store used the browser's date before; just after midnight in Doha that could be a day behind and the API answered 422. Covered by tests at 00:10 and 23:59 Doha time with a UTC clock.
- UI: a calm "Earliest available" mark on the chosen time and a hint line (one tap on another time changes it); the place-order bar shows the booked time; nothing open shows a plain empty state with "Check again" and "Choose pickup / delivery instead".
- Guard: at most 4 automatic choices per context, so a misbehaving server can never loop the screen.

## B. Admin can hide the "allow split shipments" switch

- Setting `checkout.show_split_shipment` (bool), **Dashboard → Settings → Checkout & fees**, English and Arabic label and help text. **Default: on** (`config/platform.php`).
- The quote answers `split_choice`. It is true only when the setting is on AND more than one active branch holds at least one unit of the cart (`FulfillmentAllocator` returns `stocked_branches`). The apps show the switch only then; the web store never had it.
- **With one active branch the switch is hidden** whatever the setting says.
- Setting off: the server ignores any `allow_split` the client sends (apps still showing the switch, old apps) and never splits; the order comes from one branch or cannot be placed (`insufficient_stock`). Applies to delivery, pickup and staff-assisted orders.
- Tests: `backend/tests/Feature/Commerce/SplitShipmentSettingTest.php` (setting on / off / unset x one / many branches x client sends true / false / nothing, pickup, placing, the settings screen, permissions).

## C. Alternative phone number (delivery orders)

- Field under the delivery address (app and web): prefilled from the saved number; otherwise type one or tick "I don't have another number". The place-order button waits and the bar says why. Errors appear only after the customer leaves the field or has typed a whole number. Typing unticks, ticking empties.
- Validation (`App\Support\AlternatePhone`, same rule in `QatarPhone`/`altPhone.ts`): Qatar mobile (8 digits starting 3/5/6/7, `+974`, `00974`, Arabic digits), different from the account number. Plain-words errors in English and Arabic.
- API: `POST /checkout/quote|orders` accept `alternate_phone` and `no_alternate_phone`. A current client always sends both once answered. **An older client sends neither and is not held to the rule**; the number saved on the profile (if any) is used for it. Pickup ignores the fields.
- Stored: `users.alternate_phone` (profile; `PATCH /me`, empty or null clears it) and `orders.alternate_phone` (snapshot). A number typed at checkout becomes the saved one (not when staff place the order). Ticking "none" leaves the saved one alone; it is not sent to the driver.
- **Who sees it:** the driver of that delivery (`GET /admin/dispatch/driver/jobs`, `GET /admin/me/deliveries`, web driver console), staff of the order's branch (`GET /admin/dispatch/queue`, `GET /admin/orders/{id}`, the admin order page). The driver and staff apps show "Alternative number" and a second **Call alternative number** button (48 dp, number always left-to-right, RTL-safe); the driver's job card has a compact "Alternative" button.
- **Never in:** customer order payloads, the live order card, notifications and push, invoices, raw model dumps (`alternate_phone` is `$hidden` on `User` and `Order`), or audit and logs (`Redactor` masks the key).
- **Deleting an account:** `AlternatePhone::erase()` clears the profile and every order snapshot of that person; the deletion request calls it immediately. The repo has no job that processes the 14-day deletion yet: whatever anonymises accounts must call `AlternatePhone::erase($user)`.
- Tests: `backend/tests/Feature/Commerce/AlternatePhoneTest.php`, `mobile/test/features/checkout_alt_phone_split_test.dart`, `mobile/test/ops/alternate_phone_model_test.dart`, `storefront/tests/alt-phone.test.mjs`.

## Decisions

- "I don't have another number" is asked on every delivery order that has no saved number (literal reading of the request); it is not remembered.
- The switch counts a branch if it holds at least one unit of the cart ("could fulfil part of it"), because that is when splitting matters.
- Older apps keep working: the new fields are optional everywhere.

## How to test

- Backend: `DB_DATABASE=albahar_checkout php artisan test tests/Feature/Commerce` (own database).
- App: `cd mobile && flutter analyze && flutter test test/features/slot_selection_test.dart test/features/checkout_auto_slot_test.dart test/features/checkout_alt_phone_split_test.dart test/features/delivery_slots_test.dart test/ops`.
- Store: `cd storefront && npx tsc --noEmit && npm run build && npm test`.

## Not verified

No real device (calls, keyboards, RTL on a phone) and no Android SDK. The web checkout was type-checked, built and its rules unit-tested, but not driven in a browser (Playwright) at 375 / 1280 px in this pass.
