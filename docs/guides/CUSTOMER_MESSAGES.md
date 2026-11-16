# Sending messages to customers (notification and WhatsApp)

Dashboard → **Customers** → tick customers (or open one) → **Send message**. Every send is saved in **Marketing → Customer messages** and in the customer's own **Messages** section.

## How a send works

1. **Choose the customers.** One customer (the row button, or the button on the customer's page), several ticked customers, or **everyone matching the filters** ("Select all N customers matching…"). Filters you can use: search, status, marketing consent, *order left in the cart*, *not messaged in the last 24 hours / 3 / 7 / 30 days / never messaged*.
2. **Choose a template**: *Forgotten order reminder*, *Special offer for you*, any template you added under Marketing → Customer journeys → Message templates, or write your own.
3. **Choose how to send**: **Notification** (the app's inbox plus a push to each phone) and/or **WhatsApp** (through the n8n automation; the option is greyed out until n8n is connected in Settings → Integrations).
4. **Customise**: title and message in English and Arabic (each customer gets the language of their app), an optional longer WhatsApp text, and the buttons that insert placeholders. A live preview shows exactly what the customer will see.
5. **Attach a personal coupon (optional, needs marketing rights)**: every customer gets their own one-time code under the offer you pick; put `{{coupon}}` and `{{expires}}` in the text.
6. **Send now or schedule** (in Qatar time, up to 60 days ahead). Sending to more than one customer asks you to confirm first.

## Phones: can the push be seen?

"Sent" for a notification means the message is in the customer's app inbox and Google accepted the push for each phone. It does **not** always mean the customer sees the push: on Android 13 and newer a phone hides every notification until the person allows them, and a customer who was never asked (or said no) gets nothing on the lock screen, although Google reports the push as accepted. The inbox message still arrives, so the customer finds it the next time they open the app.

Each phone reports which of these it is, and the dashboard shows it so nobody has to guess:

- **Message window (one customer)** has a **Phones** line under the customer's name:

  | Line | Meaning |
  |---|---|
  | 1 phone · notifications on | A push shows on it (with several phones: "3 phones · a push shows on 1 of them") |
  | 1 phone · notifications off: a push will not show, the inbox message still arrives | The customer turned notifications off |
  | 1 phone · not asked yet: a push will not show until the customer allows notifications, the inbox message still arrives | The app has not asked yet (Android 13+) |
  | 1 phone · notification setting not reported yet | An older app version that does not tell us. The push is sent and may well show, so there is no warning |
  | No phone registered | The customer never signed in to the app on a phone, or signed out |

  When no phone can show a push (no phone, notifications off, or not asked yet), the **Notification** option in step 2 also shows a warning that says so and how to fix it: ask the customer to allow notifications in the app (**Settings → Notifications**). The message **is still sent**: the inbox entry always arrives, and the phones are still pushed in case the setting changes.
- **Sending to several customers**: under "Who will get it", *Push on phones* counts, among the customers who will get the notification, how many can see a push, how many have notifications off or not asked yet, how many have no phone and how many have not reported yet (nothing is shown when push is not set up, or when only WhatsApp is chosen).
- **Customer's Messages section and the history of a send**: a message that reached phones where notifications are off says *Reached 2 phones, notifications off on 1*, and one that reached only the inbox because the customer has no phone says *No phone registered*. Nothing extra is shown in the normal case.

Where it comes from: the customer, driver and staff apps send `notification_permission` (`granted`, `denied` or `not_asked`) with the push token ([API contract](../api/API_CONTRACT.md), [DEVICE_NOTIFICATION_STATE.md](../api/DEVICE_NOTIFICATION_STATE.md)). A phone that never reports counts as "not reported", never as "off".

## Placeholders

`{{name}}` first name · `{{coupon}}` coupon code · `{{expires}}` coupon expiry · `{{items}}` number of items in the cart · `{{first_item}}` first item in the cart · `{{app_link}}` store link. Anything else is rejected before sending, so a typo never reaches a customer as an empty gap.

## Who is skipped (and why you can see it)

Nobody is ever messaged against these rules. Skipped customers are saved in the history with the reason:

| Reason | Meaning |
|---|---|
| Has not agreed to marketing messages | Marketing consent is required for every message from this feature |
| Turned this channel off | The customer switched off marketing notifications |
| Has not opted in to WhatsApp | WhatsApp marketing needs an explicit opt-in |
| No phone number | WhatsApp needs a phone |
| Already messaged recently | A message (sent by hand **or** by an automatic journey) went out within the quiet period (default 24 hours, Settings → Notifications). When you message **one** customer you can tick *Send anyway*; a bulk send always keeps the guard |
| No order left in the cart | An *order reminder* only goes to customers who really have items in their cart |
| Account is not active | Suspended or deactivated customers |

Consent and the quiet period are checked again at the moment of sending, so a scheduled message never reaches someone who withdrew their consent in the meantime. The automatic journeys step aside for a customer your team just messaged.

## History, "sent before X time", retry and cancel

Marketing → **Customer messages** lists every send, newest first: when (relative and exact), template, channels, who it went to, how many were sent / delivered / failed / skipped, and who sent it. Filter by kind, channel, status, team member and **date range** (today, 7 / 30 / 90 days, or a custom range, and "sent before …" to find older sends). Open a send to see the exact texts, every customer's result and reason, **Retry failed** and **Cancel** (a scheduled send, or the part of a running one that has not gone out yet; what already went out stays sent).

## Settings and rights

- Settings → Notifications: *Quiet time between messages to one customer* (0 turns the guard off), *WhatsApp messages per minute*, *Most customers in one send*.
- Rights: **notifications → create** to send, **marketing → create** to attach a coupon, **notifications → view** to see the history (company-wide rights see everyone's sends, others see their own). Branch-scoped staff can only message customers who ordered from their branches.
- Every send, schedule, cancel and retry is written to the audit log.

## For developers

- Code: `app/Domain/Messaging` (services: `CustomerMessaging` is the one entry point, `Eligibility`, `MessageContent`, `MessageVariables`, `MessageDelivery`, `ContactHistory`, `CustomerFilter`), job `SendCustomerMessageJob` (queue `notifications`, one job per customer so a customer's channels share one coupon), scheduler command `messaging:dispatch-due` (every minute: starts due scheduled sends, re-queues sends whose jobs were lost).
- Tables: `customer_message_batches` (one per send, texts as sent, counters) and `customer_messages` (one row per customer × channel with its own status, reason, provider id). The partial index `customer_messages_last_contact` powers "last message" on the customers list.
- Phones: `user_devices.notification_permission` (`granted`, `denied`, `not_asked`, null = not reported) and `notification_permission_at`. `App\Domain\Notifications\Services\DevicePushReach` turns them into `{devices, can_show, off, not_asked, unknown}` for a customer or for a whole page of customers with one grouped query. A notification row's `meta` is `{in_app, devices, pushed, silent, push_tried}`: `pushed` = phones Google accepted it for (as always), `silent` = how many of those said notifications are off or not asked, `push_tried` = false when no push was attempted (the customer takes no push, or push is not set up) so the history never claims "no phone" for that. Words: `lang/{en,ar}/messaging_phones.php`.
- n8n payload for a message sent by hand: `docs/integrations/n8n/README.md` (event `direct.whatsapp`; the same workflow serves journeys). The delivery report endpoint accepts ids of both kinds.
- Tests: `tests/Feature/Messaging`, `tests/Feature/Admin/MessageComposerTest.php`, `MessageHistoryTest.php`, `CustomerTimelineTest.php`, `MessagingPhonesTest.php` (the phone lines, warnings and notes), and `tests/Feature/Push/DeviceNotificationStateTest.php` (the stored answer, the delivery numbers, the helper).
