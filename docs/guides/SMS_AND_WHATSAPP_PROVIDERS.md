# SMS and WhatsApp providers

Roadmap 6.3. Dashboard: **Organization → SMS & WhatsApp**.

## What the owner sees
- **Connection**: SMS and WhatsApp each say Connected (which provider, or "server settings") or **Not connected**. Nothing is ever silent.
- **Choose a provider**: SMS: Twilio, Unifonic, Infobip, or "Other provider (JSON gateway)". WhatsApp: Twilio. Each preset shows only the fields it needs, with a plain hint (where to find it in the provider's console), a fake example, and a link. Keys are **sealed** (encrypted with the app key like the other provider keys), write-only, and never sent back to the page ("Saved. Type a new one to replace it").
- **Send a test message**: type a Qatar number, press Send test. The provider's own answer shows (status, error code, reason). **Check status** asks the provider again; **I got it** records that the message arrived on a phone.
- **Test sign-in codes**: a red box while they are on: *anyone can sign in as any customer with the fixed test code*. The same warning shows on the owner's home page. Once a provider is saved and a test was marked "I got it" (after the last save), the owner can press **Turn off test sign-in codes**, which asks for a fresh two-factor code and is audit-logged (`security.test_codes_off`). From then the server ignores `OTP_TEST_CODE` even if it is still in the server settings; you can remove it there. In an emergency (real codes do not arrive) the owner can turn them back on the same way (`security.test_codes_on`), as long as `OTP_TEST_CODE` is still set.

## Today's behaviour is unchanged until the owner acts
No provider saved: sign-in works exactly as before (the server's `SMS_DRIVER`, and the fixed code while `OTP_TEST_CODE` is set). Saving a provider does **not** switch the test codes off by itself; only the owner's button does. The old rule stays: with `SMS_DRIVER=http` the test code is already ignored.

## Presets (exact requests)
| Provider | Request |
|---|---|
| Twilio SMS | POST `api.twilio.com/2010-04-01/Accounts/{SID}/Messages.json`, basic auth SID:token, form `To`, `From` (or `MessagingServiceSid` when the sender starts with `MG`), `Body` |
| Twilio WhatsApp | same, `To=whatsapp:+974…`, `From=whatsapp:+<your number>` |
| Unifonic | POST `api.unifonic.com/rest/SMS/messages`, form `AppSid`, `SenderID`, `Body`, `Recipient` (digits), `responseType=JSON`; status GET `…/GetMessageIDStatus` |
| Infobip | POST `https://{base}/sms/2/text/advanced`, header `Authorization: App {key}`, JSON `messages[0].{from,destinations[0].to,text}`; status GET `/sms/2/logs?messageId=` (the base must end in `.infobip.com`) |
| JSON gateway | POST your https address, one header with the key, JSON with three field names (defaults `to`, `message`, `sender`) |

## Sending
Timeout 10 s (5 s to connect). **One retry** only for a connection failure or a 5xx answer, never for a refusal. A failed sign-in code delivery is recorded (provider, masked number, the provider's error code and a cleaned reason) **without the code or the message**; the customer sees the usual "We could not send the SMS". The same provider sends notification SMS; WhatsApp notifications and customer messages use Twilio when it is chosen, else the Meta keys from the server as before.

## Design note
- **Settings keys**: `messaging.sms_provider`, `messaging.whatsapp_provider`, `messaging.<provider>_<field>` (secrets sealed), `messaging.configured_at` (when the page was last saved), `otp.test_codes_disabled`. Never environment variables.
- **Proof rule**: a test only counts if it is a *test* message, to the same provider, **marked received by an admin**, and created after the last save. Provider delivery status alone is not enough because some providers (and the JSON gateway) give no delivery report; "I got it" on a real phone is the proof.
- **Log hygiene**: the message text is never stored; provider error text has digit runs of 4+ replaced by `••••` (so a code or number echoed back is not kept) and is cut to 200 characters. Only failures and tests are stored for sign-in codes (no row per successful code, to keep the table small).
- **Safety**: the JSON gateway address must be a public https address (no localhost, private or link-local IPs); checked on save and on every send.
- **Not done**: delivery-report webhooks (status is read by asking the provider); Infobip WhatsApp and WhatsApp templates (Twilio WhatsApp sends free text, which only works inside the 24-hour window or with the sandbox, so approved templates are a later step); a SMS provider does not yet get a nightly health ping.
