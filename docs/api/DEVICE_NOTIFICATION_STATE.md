# Phone notification permission: ask early, report it, show it in the dashboard

## Why
On Android 13+ a push that reaches the phone is hidden until the person allows notifications. The customer app asked only after the first
order, so a customer without orders got pushes accepted by FCM (`pushed 1 of 1 devices`) that were never shown (the phone reported
`permission_not_asked`). The dashboard said "sent", which was true and useless.

## App (customer app; driver and staff apps already ask at once)
* After a signed-in customer first reaches Home, wait about 3 seconds (no other window open, not during onboarding or sign-in), then run the
  existing friendly sheet (`promptForNotificationsIfNeeded`): "Stay updated on your orders and offers", Turn on / Not now. The pure rule
  `notificationPromptFor` keeps governing the frequency (not now = wait 14 days, three times at most; settings variant twice, 30 days apart).
* Profile > Notification settings shows a status card at the top when notifications are off or not asked: "Notifications are off on this phone"
  with a button that runs the same prompt (or opens the phone settings when the system dialog is gone). Hidden when on.
* The phone reports its state: `POST /api/v1/devices` gets an optional field `notification_permission`: `granted | denied | not_asked`
  (omitted or unknown = unknown). It is sent with every registration, again after the prompt result, and on app resume when the state
  differs from the last one sent. It never blocks registration; a failure is ignored.

## Server
* `user_devices.notification_permission` (nullable string, check in the allowed set) and `notification_permission_at` (timestamptz). Updated
  by the same upsert as the token. Old app versions never send it (null = unknown). Both the customer and the driver / staff device endpoints accept it.
* Message delivery meta (`customer_messages.meta`) gets `silent`: how many of the phones reached have notifications off or not asked. `pushed`
  keeps meaning "Google accepted it". Also `rejected` (phones Google refused) with the first error word in `error`, and `push_skip` (`customer` = the customer switched push off, `not_set_up` = Firebase off) when `push_tried` is false, so the dashboard's result window and history can say what really happened instead of a bare "sent" (see [CUSTOMER_MESSAGES.md](../guides/CUSTOMER_MESSAGES.md)).

## Dashboard (Customers page, messages)
* In the single-customer message window and the customer timeline: a "Phones" line per customer: "1 phone · notifications on", or
  "1 phone · notifications off: a push will not show, the inbox message still arrives", "not asked yet", "no phone registered".
  When no phone can show a push, the Send button's notification option shows this warning in plain words (it still sends: the in-app inbox works).
* Message history rows: when `silent > 0` show "Reached N phone(s), notifications off on M". When `devices = 0` show "No phone registered".
* Bulk sends: the preview counts "can show a push" separately from "has a phone".
