# Notification controls (switch any notification on or off)

Dashboard → **Content → Notification controls** (`/admin/notification-rules`). The admin decides, per notification and per channel, what the
platform sends. Nothing changes until you change something: every notification starts as the platform ships it.

- Open the page: permission **notifications.view**. Change anything: **notifications.edit** (checked again on every switch, on the server).
- Every switch **saves at once** (there is no Save button), shows a toast with **Undo**, and is written to the audit log
  (`notifications.rule_changed`, with the old and the new state and who did it).
- Switching a notification **off** means customers stop hearing about that step. **The order itself is never affected.**
- The message **texts** stay on the page next to it: *Content → Notification templates* (the **Edit message** link of every row opens it for that event).

## What you see

A list grouped as **Orders · Payments · Auctions · Stock and prices · Support · Referrals · Campaigns and reminders · Account and security ·
Staff and driver alerts**. Each row has a friendly name and one line saying *when it is sent* (English and Arabic), then:

| Control | Meaning |
|---|---|
| **Send** (master switch) | Off: nothing at all is sent or stored for this notification, on any channel. The channel switches grey out and keep their state. |
| **Push · In-app · SMS · WhatsApp · Email** | One switch per channel. In-app is the entry in the app's notification inbox. |
| **Live card** | Only on the six order events that drive the live order card (see below). |
| **Changed / Off** badges | *Changed* = differs from the default. *Off* = the master switch is off. |
| **Reset to default** | Per row (and **Reset all to default**, after a confirmation). Defaults come back exactly as shipped. |
| **Last 30 days: N sent** | Push, SMS, WhatsApp and e-mail messages delivered (from the delivery log, one grouped query, cached 5 minutes). The in-app inbox is not counted. |
| **Warning chips** | When switched off: payment link, payment failed, refund, order items changed ("customers may not know..."), campaigns ("will not go out while this is off"), and a live card whose last step is off ("stays on its last step until it expires"). |

Filters: a search box (finds names, descriptions and event keys, in English and Arabic), group chips (several at once, kept in the URL), and a live
result count. On a phone each row is a card and every channel switch carries its own label; in Arabic the page mirrors.

**Channels that are not set up on the server** are shown greyed out with *not set up yet*, and the strip at the top says which ones and links to
where they are set up (Push page for push and the live card; Settings for the others). Your choices are kept and apply as soon as the channel works.
What each one needs (see `NotificationChannelStatus`):

| Channel | Set up when |
|---|---|
| Push | Firebase switched on with its server key (Organization → Push notifications) |
| Live card | Firebase (Android) **or** the Apple key (iPhone Live Activity), same page |
| SMS | `SMS_DRIVER=http` and `SMS_HTTP_URL` in the server environment |
| WhatsApp | `WHATSAPP_PHONE_NUMBER_ID` and `WHATSAPP_ACCESS_TOKEN` (Cloud API) in the server environment |
| Email | a real mailer (`MAIL_MAILER` is not `log` or `array`) |
| In-app | always |

## How it works

`notification_rules` holds one row per event the admin **changed** (`event`, `is_enabled`, `channels` as `{channel: bool}`, `updated_by`). No row means
the platform default, so a fresh install behaves exactly like before. Only deviations are stored: a switch put back to its default is removed, and an event with
nothing left to change loses its row. `App\Domain\Notifications\Services\NotificationRules` is the one place that answers "may this be sent?":

| Method | Use |
|---|---|
| `enabled($event)` | the master switch |
| `channelEnabled($event, $channel)` | the state the page shows (master on and the channel's override, or its default) |
| `allows($event, $channel)` | for sends the caller already decided to make: true unless the event or that channel is switched off |
| `effectiveChannels($event, $channels, $allowAdditions)` | the channels to send on, from the caller's list |
| `set / reset / resetAll` | write (audited, cache flushed) |
| `matrix()` | everything the page needs |

Rules are cached as one entry (5 minutes) under a key that carries a **version number, bumped on every write** (and once more after the surrounding transaction commits), so a switch applies at once on every server and a reader that started before the change can only store the old rules under the old key, which nobody reads any more (`NotificationRules::cacheKey()`).

**A rule can only remove a send, or add a channel to an event sent with its default channels.** When the code names its own channels (a campaign's
channel list, the payment link, a staff cart reminder), the rule can only take some of them away; it never adds one. Consent and the customer's
own channel preferences (`NotificationService::allowed`) are applied **after** the rules, so a rule never sends something a customer opted out of or never agreed to
(marketing needs consent whatever the rules say).

### The live order card

The card (Android promoted notification, iPhone Live Activity) follows the order's stage, not a catalog event, so each step is tied to the event that owns it
(`NotificationCatalog::CARD_PHASE_EVENTS`):

| Card step | Event that controls it |
|---|---|
| Placed | `order.created` |
| Preparing | `order.confirmed` ("order accepted") |
| On the way | `order.out_for_delivery` |
| Ready for pickup | `order.ready_for_pickup` |
| Delivered / Collected | `order.completed` |
| Cancelled | `order.cancelled` |
| Delivery unsuccessful | none: always shown, a failed delivery needs the customer's attention |

- **Event off (master)**: the message **and** the card step stop. The card is skipped, not delayed: it stays on the step before and moves on with the next step that is on.
  Nothing is recorded for the skipped step, so switching it back on sends the card at the next change.
- **Only the "Live card" switch off**: the card skips that step, but the message stays, and the **classic push takes the card's place** (while the card is on, the classic push is
  left out so the phone does not ring twice; see `NotificationService::liveCardCovers`).
- **Only "Push" off, card on**: the card still moves; the classic push was never sent next to it anyway.
- A final step switched off (delivered, collected, cancelled) leaves the card on its last step until it expires (the page warns about it).
- The app can still draw a card for a skipped step when the customer opens it, because it reads the same card from `GET /api/v1/orders/active-progress`: that is the app pulling, not the server sending.

### Locked, and what is not locked

- **`otp` (sign-in codes) can never be switched off**, in the page or in the service (`set()` throws, and a stray row is ignored): without it nobody could log in.
  It is sent straight through the SMS gateway (`OtpService`), not through `NotificationService`.
- Nothing else is locked. **Payment link, payment failed, refund processed and order items changed** can be switched off, and the page shows a warning chip while they are.
  Reason: they are transactional but the platform can work without them (staff can copy a link; the order page still shows the state), and the admin owns the decision.
- **Payment link switched off**: the staff action "Send link" refuses with a clear message instead of reporting a link as sent.
- **Campaign messages switched off**: a campaign stays as it is (a scheduled one waits, nothing is marked *sent*), **Send** shows an error, and it goes out normally when you switch it back on.
  **A scheduled campaign is not sent late.** The scheduler (`marketing:send-scheduled`, every minute) sends a scheduled campaign only if it came due less than **24 hours** ago
  (`CampaignService::SCHEDULE_GRACE_HOURS`). One that came due earlier (campaign messages were off for a day, the scheduler was down) is **not sent**: it ends as *Cancelled* with the note
  "scheduled for … but could not go out within a day" (`marketing_campaigns.status_note = missed_when_due`, audited as `marketing.campaign_missed`), so a days-old offer never reaches customers when you
  switch campaigns back on. A campaign that came due less than a day ago still goes out then. Create it again if you still want it.
- **A campaign is marked *sent* only when something went out.** `recipients_count` counts the customers reached on at least one channel (not everyone in the audience). If the master switch is on but every
  channel of the campaign is off (or nobody can be reached, or the audience is empty), nothing is sent, the status is **unchanged** and **Send** tells the admin ("Nothing went out ... not marked as sent").
- A message already **queued** when you switch it off is dropped at the moment of sending (`SendNotificationJob` checks again) and logged as *skipped · switched_off*.

### Sent only when you send them (not switched here)

- **Customer journeys** (Marketing → Customer journeys): each journey has its own on/off switch.
- **Customer messages** (Customers → Send message, history under Marketing → Customer messages): you write and send them.
- Test sends from the Push page, the journey tester and the campaign **Test** button are admin tests.

## Inventory: everything the system sends to a person

Found with `grep -rn "->notify(\|NotificationService\|PushNotifier\|FcmClient\|WhatsAppClient\|N8nClient\|LiveActivityPusher\|OrderProgress\|sendPush\|Mail::" backend/app`.
"Gate" is how the controls apply.

### Customer notifications (`NotificationCatalog::EVENTS`)

All go through `NotificationService::notify` (the gate: `NotificationRules::enabled` + `effectiveChannels`, then consent and preferences). Channels are the platform defaults.

| Event | Page name | Who receives | Channels (default) | Sent from |
|---|---|---|---|---|
| `otp` | Sign-in codes | the person logging in | SMS | `OtpService` → `SmsSender` (not notify). **Locked** |
| `order.created` | Order placed | customer | push, in-app, email + live card | `DomainEventSubscriber::orderPlaced` (OrderPlaced) |
| `order.confirmed` | Order accepted | customer | push, in-app + live card | `orderStatusChanged`, only `pending_payment → confirmed` (online payment confirmed) |
| `order.processing` | Order being prepared | customer | in-app | `orderStatusChanged` → processing |
| `order.ready_for_pickup` | Order ready | customer | push, in-app, SMS + live card | `orderStatusChanged` → ready_for_pickup |
| `order.out_for_delivery` | Out for delivery | customer | push, in-app, SMS + live card | `orderStatusChanged` → out_for_delivery |
| `order.completed` | Delivered or collected | customer | push, in-app + live card | `orderStatusChanged` → completed / partially_cancelled |
| `order.cancelled` | Order cancelled | customer | push, in-app, email + live card | `orderStatusChanged` → cancelled |
| `order.items_changed` | Order items changed | customer | push, in-app | `OrderAdjustmentService::notifyCustomer` |
| `payment.link` | Payment link | customer | push, in-app (+ WhatsApp when set up) | `StaffOrderService::sendPaymentLink` (explicit channels) |
| `payment.succeeded` / `payment.failed` | Payment received / not completed | customer (online payments) | push, in-app | `DomainEventSubscriber::paymentStatusChanged` |
| `refund.processed` | Refund processed | customer | push, in-app, email | `paymentStatusChanged` (refunded / partially_refunded) |
| `auction.starting`, `auction.payment_reminder` | Auction starting / payment reminder | bidder | push, in-app (+ SMS) | **defined, no sender yet** (page says "Not sent yet"; the switch is kept) |
| `auction.outbid` | Outbid | the outbid bidder | push, in-app | `auctionOutbid` |
| `auction.won` / `auction.lost` | Auction won / lost | winner / the other bidders | won: push, in-app, SMS, email; lost: in-app | `auctionWon` |
| `stock.back_in_stock` / `stock.price_drop` | Back in stock / Price drop | customers with an alert (marketing consent) | push, in-app | `backInStock`, `priceChanged` |
| `cart.abandoned` | Cart reminder | customer with a cart left (marketing consent) | push, WhatsApp | `CampaignService::remindAbandonedCarts` (scheduler), staff "Remind" (`remindCart`, explicit channels) |
| `support.reply` | Support reply | customer | push, in-app, email | `SupportService::reply` |
| `referral.rewarded` / `referral.friend_rewarded` | Referral reward | inviter / invited friend | push, in-app | `ReferralService::announce` |
| `campaign` | Campaign messages | the campaign's audience (marketing consent) | the campaign's own channel list | `CampaignService::send` (also gated before it starts) |

### Live order card (customer)

`PushNotifier::refreshOrder` (from `SendRealtimePush` on OrderUpdated / DispatchUpdated, and `RefreshOrderProgressJob` on OrderPlaced / driver moved) builds the
card with `OrderProgress` and sends it to Firebase (`FcmClient::sendProgress`) and to iPhone Live Activities (`LiveActivityPusher` → APNs).
**Gate**: `NotificationRules::allows(<step's event>, 'live_card')` in `PushNotifier::pushProgress`, before anything is sent, so FCM and Live Activities are both covered.
Pulling it (`GET /orders/active-progress`, the order page) is not a send and is not gated.

### Staff and driver alerts (`staff.*`, push only, `PushNotifier::dispatchUpdated`)

| Key | Page name | Who receives | When |
|---|---|---|---|
| `staff.driver_assigned` | New delivery for a driver | the driver | a delivery is assigned to them |
| `staff.order_ready` | Order ready, needs a driver | active employees of the branch | an order part is ready |
| `staff.delivery_failed` | Delivery failed | active employees of the branch | a delivery failed |

A **new order for the branch** is not a push: it reaches the open dashboard and branch app live over the socket (`DispatchUpdated` broadcast), which keeps the screens
current and is not a notification. Likewise `NotificationCreated` (the customer's open app learns about a new inbox entry): it follows the in-app channel.

### Not switched here

`journey.*` messages (`JourneyService`), manual customer messages (`MessageDelivery`, `CustomerMessaging`), WhatsApp/n8n sends of those two, and the admin test sends
(`PushNotificationsPage`, `PushTester`, `JourneyService::sendTest`, `CampaignService::sendTest`).

## Where the gate is

| Send path | Gate |
|---|---|
| `NotificationService::notify` (every customer event above) | `enabled()` first (off: returns `[]`, stores nothing), then `effectiveChannels()`, then consent and preferences |
| Classic push next to the live card | `liveCardCovers` asks `allows($event, 'live_card')`: a card that is off no longer replaces the push |
| `SendNotificationJob` (queued push / SMS / WhatsApp / email) | re-checks `allows()` when it runs |
| Live card (FCM and Live Activity) | `PushNotifier::pushProgress` |
| Driver and branch pushes | `PushNotifier::dispatchUpdated` |
| Campaign send | `CampaignService::send` (and a clear error in `CampaignIndex::sendNow`) |
| Payment link | `StaffOrderService::sendPaymentLink` refuses with a message |

## Tests

`tests/Feature/Notifications/NotificationRulesTest.php`. Run: `cd backend && vendor/bin/pest tests/Feature/Notifications`.
