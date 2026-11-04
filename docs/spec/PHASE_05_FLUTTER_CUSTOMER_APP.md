# Phase 05 — Flutter Customer Applications (Android and iOS)

## Objective
Build the customer-facing Flutter application for Android and iOS. It must connect to the Laravel REST API; no production business logic or authoritative pricing/stock calculations may live only in Flutter.

## Technology and structure
- Flutter stable channel.
- Dart null safety.
- Feature-first architecture.
- Repository/service layer.
- State management: choose Riverpod or Bloc and use consistently.
- API client with interceptors, refresh handling and typed models.
- Secure token storage using platform secure storage.
- Localization using Flutter localization tooling.
- Arabic RTL and English LTR.
- Firebase Cloud Messaging.
- Deep links for products, orders and auctions.
- Build flavors for development, staging and production.

## Required application sections
1. Splash and onboarding.
2. Language selection.
3. Registration/login/OTP.
4. Home.
5. Categories.
6. Search.
7. Product listing.
8. Product details.
9. Branch selection.
10. Cart.
11. Checkout.
12. Orders.
13. Order tracking.
14. Wishlist.
15. Auctions.
16. Notifications.
17. Profile and addresses.
18. Reviews and support.
19. Settings, privacy and account deletion.

## Home screen
Render sections returned by the Laravel home API:
- banners
- category shortcuts
- featured products
- new arrivals
- best sellers
- promotions
- brands
- upcoming auctions
- live auctions
- recommended products

Do not hard-code homepage section order or content.

## Catalog and product experience
- Category navigation and nested categories.
- Search Arabic/English.
- Filters and sorting.
- Product image gallery and zoom.
- Variants and specifications.
- Effective branch price.
- Branch availability.
- Delivery/pickup availability.
- Reviews and ratings.
- Related products.
- Wishlist.
- Share product.
- Loading, empty, error and offline states.

## Branch selection
- Manual branch selection.
- Nearby branch discovery with permission.
- Branch details, hours and location.
- Selected preferred branch.
- Show stock and effective price for selected branch.
- Do not change an existing order's assigned branch when preferred branch changes.

## Cart
- Add/update/remove.
- Variant selection.
- Persist cart across sessions.
- Handle unavailable items and price changes.
- Show server-calculated totals.
- Revalidate cart before checkout.
- Do not trust client-supplied price or discount.

## Account
- Qatar phone OTP.
- Password login if enabled.
- Profile editing.
- Saved addresses with zone, street, building, unit, area, landmark and map pin.
- Order history.
- Wishlist.
- Notifications.
- Language preference.
- Support.
- Privacy controls.
- Account deletion request.

## UX requirements
- Premium, clean home/living retail visual identity.
- Responsive layouts for phones and tablets.
- Accessible touch targets.
- Image optimization and lazy loading.
- Skeleton loading.
- Clear error messages.
- Confirm destructive actions.
- Consistent design system and reusable components.
- Currency displayed in QAR.
- Dates displayed in Qatar local time.
- RTL layout must be genuine, not just right-aligned text.

## API integration
Use only documented `/api/v1` endpoints. Create typed request/response models. Handle:
- 401 and refresh.
- 403.
- 404.
- 422 validation.
- 429 rate limiting.
- 5xx errors.
- network timeout/offline.
Never expose tokens or sensitive values in logs.

## Push notifications
- Register FCM token.
- Update token on refresh.
- Handle foreground/background/terminated states.
- Deep-link to relevant order, product or auction.
- Respect notification preferences.

## Tests
- Widget tests for reusable UI.
- Unit tests for repositories and state.
- Authentication flows.
- Localization and RTL.
- Cart and checkout state.
- Branch switching.
- Deep links.
- Notification navigation.
- Integration smoke tests on Android and iOS.

## Acceptance criteria
- Android and iOS builds run.
- Customer can register/login.
- Arabic and English work throughout.
- Customer can browse products and branches.
- Cart and wishlist work with API.
- Profile and addresses persist.
- Push notification registration works.
- No hard-coded production catalog or price data.
