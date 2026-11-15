# Sending messages to customers (notification and WhatsApp)

Dashboard → **Customers** → tick customers (or open one) → **Send message**. Every send is saved in **Marketing → Customer messages** and in the customer's own **Messages** section.

## How a send works

1. **Choose the customers.** One customer (the row button, or the button on the customer's page), several ticked customers, or **everyone matching the filters** ("Select all N customers matching…"). Filters you can use: search, status, marketing consent, *order left in the cart*, *not messaged in the last 24 hours / 3 / 7 / 30 days / never messaged*.
2. **Choose a template**: *Forgotten order reminder*, *Special offer for you*, any template you added under Marketing → Customer journeys → Message templates, or write your own.
3. **Choose how to send**: **Notification** (the app's inbox plus a push to each phone) and/or **WhatsApp** (through the n8n automation; the option is greyed out until n8n is connected in Settings → Integrations).
4. **Customise**: title and message in English and Arabic (each customer gets the language of their app), an optional longer WhatsApp text, and the buttons that insert placeholders. A live preview shows exactly what the customer will see.
5. **Attach a personal coupon (optional, needs marketing rights)**: every customer gets their own one-time code under the offer you pick; put `{{coupon}}` and `{{expires}}` in the text.
6. **Send now or schedule** (in Qatar time, up to 60 days ahead). Sending to more than one customer asks you to confirm first.

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
- n8n payload for a message sent by hand: `docs/integrations/n8n/README.md` (event `direct.whatsapp`; the same workflow serves journeys). The delivery report endpoint accepts ids of both kinds.
- Tests: `tests/Feature/Messaging`, `tests/Feature/Admin/MessageComposerTest.php`, `MessageHistoryTest.php`, `CustomerTimelineTest.php`.
