# n8n + WhatsApp for customer journeys and messages sent by hand

Al Bahar decides **who** gets **which** message and **when** (Dashboard → Marketing → Customer journeys for automatic flows, and Customers → Send message for messages the team sends by hand, see `docs/guides/CUSTOMER_MESSAGES.md`). n8n only **delivers** the WhatsApp message and reports back. **One workflow serves both**: the two kinds of message have the same shape.

## Set up (10 minutes)

1. In n8n: **Import from file** → `whatsapp-journeys.workflow.json`.
2. Open the **Send WhatsApp** node, pick your WhatsApp Business credential and set your *Phone number ID*.
3. In n8n set the environment variable `BAHAR_N8N_SECRET` to a long random text (16+ characters). Allow `$env` access and the `crypto` module in Code nodes (`NODE_FUNCTION_ALLOW_BUILTIN=crypto`, `N8N_BLOCK_ENV_ACCESS_IN_NODE=false`).
4. Activate the workflow and copy the **Production URL** of the *Webhook* node.
5. In Al Bahar: **Settings → Integrations**: turn on *Send WhatsApp through n8n*, paste the URL and the **same secret**. Save.
6. Dashboard → Customer journeys → Message templates → open a template → **Send a test** with WhatsApp ticked.

## What n8n receives (POST, JSON)

| Field | Meaning |
|---|---|
| `event` | `journey.whatsapp` (automatic journey) or `direct.whatsapp` (sent by hand from the customers pages) |
| `delivery_id` | Id to send back in the delivery report |
| `journey`, `step`, `template` | Journeys only: which flow, which step, which template |
| `batch_id`, `template`, `kind` | Sent by hand only: the send it belongs to, the template name, `reminder` / `offer` / `custom` |
| `locale` | `ar` or `en` — the customer's app language (text is already translated) |
| `to` | Customer phone in international format (`+974…`) |
| `customer` | `{ id, name }` (first name) |
| `message` | Final text with the name, coupon and expiry already filled in |
| `coupon` | `{ code, expires }` or `null` |
| `callback_url` | Where to report the result |

Headers: `X-Bahar-Timestamp` and `X-Bahar-Signature` = `sha256=` + HMAC-SHA256 of `"<timestamp>.<raw body>"` with the shared secret. Reject anything that does not verify or is older than 5 minutes (the workflow does).

## What n8n reports back (POST `callback_url`, same signature scheme)

```json
{ "delivery_id": "…", "status": "delivered | failed | sent", "external_id": "wamid…", "reason": "optional" }
```

The Activity tab (journeys) and the Customer messages history (sent by hand) then show *Delivered* or *Failed* with the reason. The report is optional for the first status: Al Bahar already marks a message *Sent* when n8n answers `2xx` to the webhook, and the report upgrades it to *Delivered* (or marks it *Failed*). A late *sent* never overwrites *delivered*.

## Rules enforced by Al Bahar (not by n8n)

- Messages sent by hand: the team's rights, marketing consent, WhatsApp opt-in, a phone number, a quiet period between two messages (Settings → Notifications), and a bulk limit are all checked by Al Bahar before n8n is called, and again at the moment of sending. A bulk send hands messages to n8n at most *WhatsApp messages per minute* (Settings → Notifications) so the number is not flagged.

- Only customers with marketing consent are messaged; WhatsApp only when the customer opted in to WhatsApp.
- Quiet hours and a per-customer daily limit per journey.
- A step is skipped, never sent late (cart steps expire after 48 hours, birthday steps after 18 hours).
- Each (customer, step, channel) is sent once; the scheduler can run twice without double-sending.
- Sending stops when the customer orders or returns to the cart.
