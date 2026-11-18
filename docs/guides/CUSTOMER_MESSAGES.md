# Sending messages to customers (notification and WhatsApp)

Dashboard → **Customers** → tick customers (or open one) → **Send message**. Every send is saved in **Marketing → Customer messages** and in the customer's own **Messages** section.

## How a send works

1. **Choose the customers.** One customer (the row button, or the button on the customer's page), several ticked customers, or **everyone matching the filters** ("Select all N customers matching…"). Filters you can use: search, status, marketing consent, *order left in the cart*, *not messaged in the last 24 hours / 3 / 7 / 30 days / never messaged*.
2. **Choose a template**: *Forgotten order reminder*, *Special offer for you*, any template you added under Marketing → Customer journeys → Message templates, or write your own.
3. **Choose how to send**: **Notification** (the app's inbox plus a push to each phone) and/or **WhatsApp** (through the n8n automation; the option is greyed out until n8n is connected in Settings → Integrations).
4. **Customise**: title and message in English and Arabic (each customer gets the language of their app), an optional longer WhatsApp text, and the buttons that insert placeholders. A live preview shows exactly what the customer will see.
5. **Attach a personal coupon (optional, needs marketing rights)**: every customer gets their own one-time code under the offer you pick; put `{{coupon}}` and `{{expires}}` in the text.
6. **Send now or schedule** (in Qatar time, up to 60 days ahead). Sending to more than one customer asks you to confirm first.
7. **Read the result.** The window does not just say "sent": for one customer it says what happened on the phone side (see below), and for several it counts them (sent, not sent, failed) and says how many pushes went to a phone and how many reached the app inbox only. If something is still being delivered it updates by itself.

If **Send** cannot go out yet, the reason is written in an amber note right next to the button (never only far down the window), and pressing the button takes you to the field to fix. The usual ones: a title or message is missing in one of the two languages, the customer has not agreed to marketing messages, or **the customer was messaged recently** (quiet time, 24 hours by default). For one customer the note has a **Send anyway** button for that last one; for several customers the quiet time always stays. **"Send anyway" is recorded in the audit log and can be used 3 times per customer per hour**; after that the note says so and the message has to wait. Pressing Send twice (or again after a timeout) never sends twice.

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

## A message did not arrive: what to check

Go in this order. Each step says what you will see and what to do.

1. **Was it sent at all?** Open **Marketing → Customer messages** (or the customer's **Messages** section). If there is no row for your send, nothing went out: the window was still open with an amber note next to the **Send** button. Read it. *Add a title (Arabic)* → write both languages. *Messaged recently* → press **Send anyway** (one customer) or wait for the quiet time. *Has not agreed to marketing messages* / *Turned this channel off* → the customer has to agree or switch it back on in the app; nothing can override that.
2. **What did the window say after Send?**
   | You saw | It means | What to do |
   |---|---|---|
   | **Sent to Hamad's phone** | Google accepted the push for the phone and the message is also in the app inbox | If the phone shows nothing: it is offline, in *Do not disturb* / battery saver, or notifications are off for the app (phone settings → Apps → Al Bahar → Notifications) |
   | **Saved in the app inbox only**: notifications are switched off on the phone | The phone refuses to show a banner | Ask the customer to allow notifications in the app (Settings → Notifications) or in the phone settings |
   | **Saved in the app inbox only**: no phone registered | The customer has not signed in to the app on a phone (or signed out) | Ask them to open the app and sign in |
   | **Saved in the app inbox only**: the customer switched push off | They turned off push for offers and news in the app | They can switch it on again in the app: Notification settings |
   | **Saved in the app inbox only**: Google refused the push | The phone's registration is no longer valid (app reinstalled, signed out) or the Firebase key is wrong. The error word is shown | The phone registers again when the customer opens the app. If many customers show the same error, check **Organization → Push notifications → Test connection** |
   | **Saved in the app inbox only**: push is not set up | Firebase is not switched on for the platform | Organization → Push notifications |
   | **Not sent** | A rule stopped it (the reason is written) | See "Who is skipped" below |
   | **Saved in the app inbox** (a spinner) | The inbox message is saved and the push to the phone is on its way; the window updates by itself | Wait a second or two. |
   | **Sent by WhatsApp** (or *by Notification*) | One channel went out and the other did not (the reason is written under it) | Nothing, unless you need the other channel: fix its reason (for example the customer has not opted in) |
   | **Taking longer than usual** | The push (or WhatsApp) is waiting for the background worker (the `queue` service). The inbox message is already saved | Check the worker is running (see For developers). The message goes out when it runs |
3. **Customers → the customer → Messages** shows every message to that customer with the same notes (*Reached 1 phone, notifications off on 1*, *No phone registered*, *Google refused the push*, *Customer switched push off*).
4. **The push shows in the notification shade, but not as a pop-up.** Messages from the team use the *Offers and news* channel on Android, which is quiet by design (sound and lock screen, no pop-up banner). The customer can change that for the channel in the phone settings. With the app open, the app shows its own banner.
5. **Test it end to end on your own phone.** Sign in to the customer app with a customer account that has agreed to marketing messages, allow notifications, then from the dashboard: Customers → that customer → **Send message** → **Write your own** → both languages → **Send** (use **Send anyway** if that customer was messaged in the last 24 hours). Expected: **Saved in the app inbox** at once, then within a second or two **Sent to <name>'s phone**, and the banner on the phone within seconds (lock the phone or leave the app to see it). To test only the push channel itself, with none of the customer rules (consent, quiet time) in the way, use **Organization → Push notifications → Send a test to a phone** (the *Latest phones* list shows the phone that registered last), or `php artisan push:test <phone number>` on the server.

### الرسالة لم تصل: ما الذي تفحصه

1. افتح **التسويق ← رسائل العملاء**: إن لم يكن هناك سطر لإرسالك فلم يخرج شيء، وسبب ذلك مكتوب في إشعار كهرماني بجانب زر **إرسال** (عنوان أو نص ناقص بإحدى اللغتين، أو أن العميل لم يوافق على الرسائل، أو أنه وصلته رسالة مؤخراً). لعميل واحد اضغط **أرسل رغم ذلك** (يُسجَّل في سجل التدقيق، ويُسمح به ٣ مرات لكل عميل في الساعة). الضغط مرتين على إرسال لا يرسل مرتين.
2. بعد الإرسال تقول النافذة ما حدث فعلاً: **أُرسلت إلى هاتف …** (قبلت Google الإشعار)، أو **حُفظت في صندوق التطبيق فقط** مع السبب (الإشعارات متوقفة على الهاتف، لا يوجد هاتف مسجّل، أوقف العميل الإشعارات الفورية، رفضت Google الإشعار، أو الإشعارات الفورية غير مفعّلة)، أو **لم تُرسل** مع القاعدة التي أوقفتها.
3. إن قالت **أُرسلت إلى هاتف …** ولم يظهر شيء: الهاتف غير متصل أو في وضع عدم الإزعاج أو الإشعارات متوقفة للتطبيق. رسائل الفريق تصل عبر قناة «العروض والأخبار» الهادئة في أندرويد: تظهر في شريط الإشعارات وشاشة القفل بدون نافذة منبثقة.
4. للتجربة على هاتفك: سجّل الدخول إلى تطبيق العميل بحساب وافق على الرسائل التسويقية، واسمح بالإشعارات، ثم أرسل من لوحة التحكم (العملاء ← العميل ← **إرسال رسالة**) واكتب اللغتين.

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
- Delivery: nothing slow runs inside the request of the person who pressed Send. For **one customer** `CustomerMessaging::dispatchBatch($batch, inbox: true)` first saves the in-app inbox message right there (`MessageDelivery::saveInbox`: database only; the row stays `queued` and remembers it in `meta.inbox_id`), so the window says *Saved in the app inbox* at once. The push and WhatsApp are always the job's (`SendCustomerMessageJob`, queue `notifications`, worker command `queue:work redis --queue=default,notifications,exports`), which reuses that inbox entry instead of making a second one, so a slow Google (10 s timeout per phone, one retry) or n8n can never hold the request or lead to a second click. If saving the inbox fails, the job simply does everything. The result window polls `MessageComposer::refreshResult` every 2 seconds for about 90 seconds and follows the job to *Sent to <name>'s phone* (only when Google really accepted the push), then says it is taking long. `messaging:dispatch-due` (every minute) starts scheduled sends and re-queues a send whose jobs were lost after `15 + recipients / per-minute` minutes.
- A push Google answers with a temporary error (busy, unreachable) is tried a second time after 0.4 s.
- **Pressing Send twice sends once.** The window makes a send key when it opens (`MessageComposer::$sendKey`); `CustomerMessaging::send()` remembers the send for 10 minutes under that key and the staff member, so a double click, or a second try after a timeout, returns the first send: no second batch, push or inbox entry, and nothing is counted again against the limits. Different text under the same key, or a window opened again, is a new send. The buttons also ignore a press while the first is still going.
- **"Send anyway"** (one customer only, the quiet time skipped) is written to the audit entry of the send (`customer_messages.sent`, `override_cooldown: true`, `recipient`: the customer) and limited to **3 per customer per hour** whoever sends (`CustomerMessaging::OVERRIDE_MAX`, `RateLimiter` key `customer-messages:override:<customer>`); it only counts when the quiet time was really in the way. After that the amber note says so (no button) and the send is refused with a plain message (HTTP 429 from the service).
- Phones: `user_devices.notification_permission` (`granted`, `denied`, `not_asked`, null = not reported) and `notification_permission_at`. `App\Domain\Notifications\Services\DevicePushReach` turns them into `{devices, can_show, off, not_asked, unknown}` for a customer or for a whole page of customers with one grouped query. A notification row's `meta` is `{in_app, devices, pushed, silent, rejected, error?, push_tried, push_skip?}`: `pushed` = phones Google accepted it for (as always), `silent` = how many of those said notifications are off or not asked, `rejected` = phones Google refused (`error` = the first FCM error word, e.g. UNREGISTERED), `push_tried` = false when no push was attempted, and then `push_skip` says why (`customer` = push switched off by the customer, `not_set_up` = Firebase off) so the history never claims "no phone" for that. `DevicePushReach::deliveryNote()` (history rows) and `deliveryOutcome()` (the result window) read these. Words: `lang/{en,ar}/messaging_phones.php`.
- n8n payload for a message sent by hand: `docs/integrations/n8n/README.md` (event `direct.whatsapp`; the same workflow serves journeys). The delivery report endpoint accepts ids of both kinds.
- Tests: `tests/Feature/Messaging`, `tests/Feature/Admin/MessageComposerTest.php`, `MessageHistoryTest.php`, `CustomerTimelineTest.php`, `MessagingPhonesTest.php` (the phone lines, warnings and notes), and `tests/Feature/Push/DeviceNotificationStateTest.php` (the stored answer, the delivery numbers, the helper).
