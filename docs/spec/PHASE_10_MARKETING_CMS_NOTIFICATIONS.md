# Phase 10 — Marketing, CMS, Loyalty, Notifications and Customer Support

## Objective
Implement configurable marketing tools, customer engagement, content management and communication channels.

## Promotions and coupons
Support:
- Percentage discount.
- Fixed discount.
- Product-specific.
- Category-specific.
- Branch-specific.
- Customer-group-specific.
- First-order discount.
- Minimum basket value.
- Maximum discount.
- Usage limit overall and per customer.
- Start/end date.
- Free delivery.
- Flash sale.
- Bundle discount.
- Buy One Get One.
- Referral campaign.

Define stacking/priority rules. Backend is authoritative for eligibility and calculation. Record coupon usage against orders.

## Loyalty and referrals
- Customer points account.
- Earn rules.
- Redemption rules.
- Expiry.
- Reversal on refund/cancellation.
- Referral codes.
- Referral eligibility and fraud controls.
- VIP tiers if enabled.
All points changes must have ledger entries.

## CMS and homepage builder
Laravel admin screens:
- Homepage sections.
- Section order.
- Product/category selection.
- Banners for mobile and web.
- Arabic/English content.
- Start/end schedule.
- Publish/unpublish.
- CMS pages: About, Contact, FAQ, Terms, Privacy, Returns, Auction terms.
- Preview before publish.

## Notifications
Channels:
- Firebase push.
- SMS provider.
- WhatsApp Business provider.
- Email.
- In-app notification center.

Events:
- OTP.
- Order created/confirmed.
- Payment success/failure.
- Processing/ready/pickup/delivery.
- Cancellation/refund.
- Auction start/reminder/outbid/win/loss/payment deadline.
- Back in stock.
- Price drop.
- Marketing campaigns.

Requirements:
- Arabic/English templates.
- Channel preferences.
- Marketing consent and opt-out.
- Queue sending.
- Retry with backoff.
- Provider response logging with sensitive data redacted.
- Delivery status.
- Deep links to relevant app screens.
- Segmentation by branch, purchase history, category, customer group and auction participation.

## Abandoned cart
- Configurable inactivity period.
- Consent-aware messaging.
- Do not send repeated campaigns beyond frequency limits.
- Revalidate price and stock when customer returns.
- Track campaign conversion.

## Reviews and support
- Verified purchase reviews.
- Photo uploads.
- Moderation.
- Report review.
- Product Q&A.
- Support tickets.
- Ticket priorities/statuses.
- Customer and staff messages.
- Assignment to support agents.
- Internal notes.
- Response-time reporting.

## Database tables
- promotions
- promotion_products
- promotion_categories
- promotion_branches
- coupons
- coupon_usage
- loyalty_accounts
- loyalty_transactions
- referrals
- banners
- homepage_sections
- homepage_section_items
- cms_pages
- cms_page_translations
- notification_templates
- notifications
- notification_logs
- notification_preferences
- support_tickets
- support_messages
- reviews
- review_media

## Tests
- Coupon eligibility and limits.
- Discount stacking.
- Refund reversals.
- Loyalty ledger integrity.
- Marketing opt-out.
- Notification retry.
- Arabic/English templates.
- Scheduled banner publishing.
- Support permissions.

## Acceptance criteria
- Admin can control homepage content without deployment.
- Coupons/promotions are calculated server-side.
- Notifications are queued and logged.
- Customer preferences and consent are respected.
- Loyalty and referral ledgers are auditable.
