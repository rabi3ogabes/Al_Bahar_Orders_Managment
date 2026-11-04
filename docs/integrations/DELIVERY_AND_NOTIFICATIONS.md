# Delivery providers & notification channels

## Third-party couriers
Couriers are integrated through `App\Domain\Fulfillment\Contracts\DeliveryProviderAdapter`
(`quote`, `createBooking`, `cancelBooking`, `track`, `parseWebhook`) and registered in `DeliveryProviderRegistry`.
Each provider row (`delivery_providers`) stores encrypted credentials, an encrypted webhook secret and a capability map;
callers check capabilities before using optional operations. No courier was selected yet, so **only the mock adapter
ships** (`driver = mock`, refuses to run in production) and is used by tests (timeouts, retries, signed idempotent
webhooks, out-of-order safety).

To add a courier: implement the interface in `app/Domain/Fulfillment/Providers/<Name>.php`, map its statuses to
`DeliveryStatus`, register it in `DeliveryProviderRegistry`, create the provider row in the admin, and point the courier
webhook to `POST /webhooks/delivery/{code}`.

## Notification channels
| Channel | Implementation | Required configuration |
|---|---|---|
| In-app | `notifications` table, `/api/v1/me/notifications` | — |
| Push | FCM HTTP v1 (`FcmClient`, service-account JWT) | `FCM_PROJECT_ID`, `FCM_CREDENTIALS_PATH` (file outside repo) |
| SMS | `SmsSender` contract: `null` (default, never logs bodies) or generic `http` gateway | `SMS_DRIVER=http`, `SMS_HTTP_*` |
| WhatsApp | WhatsApp Business Cloud API | `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_ACCESS_TOKEN`; marketing templates must be pre-approved |
| Email | Laravel Mail | `MAIL_*` |

All external sends are queued (`notifications` queue) with 5 attempts and exponential backoff (10s, 1m, 5m, 15m),
logged in `notification_logs` with masked recipients and redacted responses. Marketing requires consent
(`users.marketing_consent_at`) and honours per-channel/category opt-outs. Templates: `notification_templates`
(event × channel × locale) override the defaults in `lang/{en,ar}/notifications.php`.
