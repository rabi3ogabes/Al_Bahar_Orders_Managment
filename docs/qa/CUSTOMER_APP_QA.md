# Customer app QA log

Scope: `mobile/lib/**` customer code (everything except `lib/ops/**`). Run on branch `claude/optimistic-euler-cffll1`.
Result: `flutter analyze` has no issues in customer code, 44 new tests pass (21 screen-matrix, 23 form), and the full suite passes for every customer file
(the only failures in the full run are in `test/ops/**`, owned by the driver/staff QA agent).

## How it was tested

| Layer | File | What it proves |
|---|---|---|
| Screen matrix | `mobile/test/qa/customer_screens_matrix_test.dart` | Boots the real app and visits all 38 customer routes at 390x844. Data, empty, error, loading and offline API modes; EN/AR, light/dark; text x1.3 (x1.6 for the long-text pass). Any overflow, assertion or parse exception fails the test. |
| Long text | same file | Long product names, addresses, titles and status labels (EN and AR) at text x1.6: wrap or ellipsize, never overflow. |
| Guest | same file | Guest browsing and protected routes redirect. |
| Accessibility | same file | `androidTapTargetGuideline` (48dp) and `labeledTapTargetGuideline` on every route (4 mode/locale/theme combinations). |
| Forms | `mobile/test/qa/customer_forms_test.dart` | Validation EN+AR, 422 field errors next to the field, 429 wait, offline, double-tap protection, trimming, +974 formatting, Arabic digits, OTP resend timer, discard-changes prompt, request bodies. |
| Fixtures | `customer_fixtures.dart`, `test_fonts.dart` | Fake backend and the real app fonts. Without the fonts the test engine draws every glyph as a full-width square and invents overflow (the first sweep reported 40 overflows, almost all false). Rows that only overflowed under that placeholder font were still hardened with `Flexible`/ellipsis and are marked "hardened" below. |

## Screens and forms

Legend: R = code reviewed, M = in screen matrix, F = form test, A = accessibility sweep.

| Screen / form | Checks | Bugs found | Status |
|---|---|---|---|
| Splash, language, onboarding | R M | none | OK |
| Phone sign-in | R M F A | Enter key could submit twice; error stayed after retyping | Fixed |
| OTP | R F | Enter key could verify twice | Fixed |
| Register | R F | No discard prompt, no max lengths, password rule (letters + numbers) not checked, no next/done actions, Enter could submit twice | Fixed |
| Password sign-in | R F | Phone error was shown under the password field; no max length; double submit | Fixed |
| Password reset | R F | No resend timer, no way to change number, weak password only rejected by the server, no formatters | Fixed |
| Home | R M | Auction card row hardened for long countdowns; 36dp buy-again button | Fixed |
| Categories, category, product list, search | R M A | Filter/sort toolbar hardened for large text; sort target was 44dp; search clear button lagged 400 ms | Fixed |
| Product detail, variants, colours, upsell | R M F | Question sheet: no length check (min 5), silently closed on empty | Fixed |
| Reviews (write) | R F | No discard prompt, double submit, no keyboard type | Fixed |
| Cart | R M F A | Cart image tap had no semantics label; coupon could submit twice; no input limits | Fixed |
| Checkout | R M | Delivery/pickup toggle was 44dp; checkout stepper hardened for large text; provider used after dispose | Fixed |
| Payment return | R | none | OK |
| Orders list / detail | R M | Status chips overflowed with long labels (3 places, found by the long-text pass); orders tab was 40dp; cancel details unlimited; return reason dropdown not expanded | Fixed |
| Order tracking, pickup pass | R M | none | OK |
| Wishlist, notifications, notification prefs | R M A | none | OK |
| Account | R M | Logout button was a light-only pink in dark mode | Fixed |
| Edit profile | R F | No validation, no discard prompt, password sheet closed silently on a short password, controllers disposed during the sheet close animation | Fixed |
| Addresses list and form | R F | Infinite skeleton when loading failed with a non-API error; 422 on pin/latitude was hidden; Arabic digits dropped; no input limits or actions; no discard prompt; map controller leaked | Fixed |
| Support list, new ticket, thread | R F | No discard prompt; thread did not scroll to the newest message; reply had no limit; `return` category unlabelled; dropdown overflowed at large text; reply could write to a disposed controller | Fixed |
| Delete account | R M | Reason had no limit; double submit | Fixed |
| Auctions list, detail, lot | R M F | Rows hardened for long countdowns; Arabic digits could not be typed in a bid; paste with 3 decimals was rejected; auto-bid sheet closed silently on a bad value; confirm dialog could open twice | Fixed |
| My auctions, win checkout | R M | `setState` after await without `mounted` | Fixed |
| Invite friends | R M | Friends list crashed layout (ListTile trailing consumed the tile) | Fixed |
| Branches, branch detail | R M | none | OK |
| CMS pages, settings | R M | none | OK |
| App update gate, offline states, deep links | R (existing tests) | none | OK |
| Dio client | R | Refresh is single-flight, token in secure storage, only a definitive 401/403/422 on refresh ends the session, timeouts 15 s / 30 s. No automatic retries exist, so unsafe requests are never replayed except the one token-refresh replay, which keeps its idempotency key. | OK |

## Bugs fixed (file, one line)

- `lib/core/widgets/dirty_form_guard.dart` (new): discard-changes prompt for system back; used on register, reset, address, profile, review, ticket.
- `lib/core/widgets/input_sheets.dart` (new): sheets that own their controllers and show errors (question, auto-bid, password).
- `lib/core/utils/validators.dart` (new): email, strong-password (matches the API rule), ASCII-digit formatter.
- `lib/core/widgets/app_text_field.dart`: localized show/hide password tooltip, autocorrect off for secrets and codes.
- `lib/features/auth/presentation/auth_screens.dart`: busy guards, per-field errors, password rule, max lengths, actions, resend timer and change number on reset.
- `lib/features/profile/presentation/address_form_screen.dart`: infinite skeleton fix, hidden server errors shown, digits, limits, guard, map controller dispose, mounted checks.
- `lib/features/profile/presentation/edit_profile_screen.dart`: validation, guard, password sheet.
- `lib/features/reviews/presentation/write_review_screen.dart`: guard, double submit.
- `lib/features/support/presentation/support_screens.dart`: guard, scroll to newest, limits, expanded dropdown, `return` label.
- `lib/features/catalog/presentation/widgets/review_widgets.dart`: question sheet validation.
- `lib/features/auctions/presentation/lot_screen.dart`: amount formatter, bid double confirm, auto-bid sheet, overflow.
- `lib/features/auctions/presentation/auction_detail_screen.dart`, `lib/features/home/presentation/home_screen.dart`: countdown rows.
- `lib/features/cart/presentation/cart_screen.dart`: image semantics, coupon guard and limits.
- `lib/features/checkout/presentation/checkout_screen.dart`: toggle 48dp and overflow, mounted check.
- `lib/features/orders/presentation/orders_screen.dart`, `order_detail_screen.dart`, `widgets/active_order_card.dart`: chip overflow, 48dp targets, limits.
- `lib/features/catalog/presentation/product_list_screen.dart`, `search_screen.dart`: toolbar overflow, 48dp, limits.
- `lib/features/profile/presentation/invite_friends_screen.dart`: friends row.
- `lib/features/profile/presentation/account_screen.dart`, `lib/core/widgets/product_card.dart`: dark-mode colours.
- `lib/core/widgets/rating.dart`, `lib/core/widgets/common.dart` (checkout steps): large-text overflow.
- `lib/features/settings/presentation/delete_account_screen.dart`, `win_checkout_screen.dart`: limits, mounted checks.
- `lib/l10n/app_en.arb`, `app_ar.arb` (+ generated): new keys (discard prompt, show/hide password, min length, return category, valid amount) and the stronger password hint.

## Not fixed / owner decisions

- Return request always returns the full quantity of each selected line (the API accepts partial quantities). Product decision: add a quantity stepper?
- Coupon codes are sent as typed (trimmed). The keyboard shows capitals but the value is not upper-cased; confirm the API matches case-insensitively.
- The signed-in user's name and phone are cached in plain shared preferences (not secrets, but personal data). Move to secure storage if the privacy policy requires it.
- Notification preferences show the raw channel code for an unknown channel (e.g. `in_app`); the API currently never returns one.
- Integration tests were not run (no emulator).
- `test/ops/**` has failing and flaky tests (location tracker, strings) and unused imports; owned by the driver/staff agent.
- Running several `flutter test` processes at once on this machine can kill test runs with `SIGTERM`; the QA test files carry an explicit 5-minute timeout.
