# Scan to add (customer app) and barcode labels (dashboard)

A customer points the phone at a product barcode or QR code and the product goes into the cart. The shop prints its own labels
from the dashboard.

## What was built

| Part | Where |
|---|---|
| Lookup API `GET /api/v1/products/by-code/{code}` | `backend/app/Http/Controllers/Api/V1/Catalog/CatalogController.php` (`byCode`), `app/Domain/Catalog/Services/ProductCodeResolver.php`, `ScanResultPresenter.php`, route in `routes/api_public.php` |
| Index migration | `database/migrations/2026_11_14_300000_add_code_lookup_indexes_to_products.php` |
| Scanner screen, route `/scan` | `mobile/lib/features/scan/**`, route in `lib/core/router/app_router.dart` |
| Entry points | Home search bar (scan icon), Search field (scan icon when empty), Cart tab ("Scan to add" card, also when the cart is empty), `albahar://scan` deep link, footer destination `scan` |
| Barcode field rules | `app/Rules/ProductBarcode.php`, `ProductService::barcodeTaken`, product editor and variant editor |
| Label sheet | `GET /admin/products/labels` (`Admin\ProductLabelController`, view `documents/product-labels.blade.php`), pure-PHP `app/Support/Code128.php` |

## The endpoint

`GET /api/v1/products/by-code/{code}[?branch_id=uuid]`. Public (no sign-in), throttled by the `product-scan` limiter (signed-in
shoppers 120/min each, guests 60/min per address; 429 `too_many_requests`). Send the scanned text URL-encoded as one path segment.

What `{code}` may be, tried in this order: variant barcode, product barcode, variant SKU, product SKU (all exact, case-insensitive,
trimmed, Arabic digits accepted); then the same number written as UPC-A / EAN-13 / EAN-8 / GTIN-14 (leading zeros); then a store link
`https://host/[…/]products/{slug or id}`, `albahar://products/{id or slug}`, a bare UUID or a slug (English or Arabic).

Answer `200 {data}`: the product card (same as the list) plus

| Field | Meaning |
|---|---|
| `variant_id`, `variant_name`, `variant_sku` | the exact variant to add; null while `needs_choice` |
| `price` | the price of that variant (not the product's "from" price) |
| `in_stock` | at least one unit available (at the branch, or in any open branch without `branch_id`) |
| `purchasable` | sold in this branch context (has a price), independent of stock |
| `max_quantity` | `min(units available, per-line cart limit)`; 0 when it cannot be added; null while `needs_choice` |
| `needs_choice` | a product-level code of a product with several variants |
| `matched_by` | `variant_barcode`, `product_barcode`, `variant_sku`, `product_sku`, `url`, `deep_link`, `id`, `slug` |

`404 {code: "product_not_found"}` for anything else: unknown code, draft / inactive / archived product, inactive variant, product hidden at
the given branch, a link to something that is not a product, over-long or hostile input. Invalid UTF-8 in the path is a 400 from the framework.

## How the app behaves

* Full-screen camera, dimmed overlay with a rounded window, torch button (hidden when the camera has none), always-visible close button,
  "Enter a code manually" window (also the way in without a camera).
* A found product is added with the cart's own optimistic logic (`CartController.addItem`): the badge moves at once, the same variant raises
  the existing line, a refusal puts the cart back. No navigation.
* The same code is ignored for 2 s after it was last seen (holding the phone on one barcode is one unit; moving away for 2 s and scanning
  again adds another). A typed code always counts. One lookup at a time.
* Result card: photo, name, price, − / +, Undo (back to the quantity before that scan), running "N items · subtotal", **Keep scanning**
  (default) and **View cart**.
* Problems in plain words, one action each: not found (Search by name), out of stock, not sold at your branch, cart already holds all that is
  available, offline (Try again, nothing queued), several options (opens the existing option window), guest (Sign in), server message.
* No camera permission: explanation + Open settings; returning from Settings starts the camera again. No camera: explanation + typing + search.
* Accessibility: labelled controls, 48 dp targets, text scale 1.3 tested, Arabic/RTL tested, dark mode, reduced motion (no slide or size animation),
  the added product is announced.

The footer: `scan` is registered as an optional footer destination (parent: Cart) and the scanner hides the footer. The dashboard's footer
allow-list (`AppFooterService::ITEMS`) was **not** extended, so the owner cannot pick it in Appearance > App footer yet (a few lines:
add `scan` and icon `qr`, plus the `footer_admin` words).

## Printing labels

Dashboard > Products: tick products, **Print labels** (or the printer icon on a row) opens an A4 sheet in a new tab. One label per active
variant: name (dashboard language, English, Arabic or both), option name, price, Code 128 barcode and the code in text. The code printed is
the variant's barcode, else (single-variant product) the product's barcode, else the SKU, so the app resolves it to exactly that variant.
Choose copies (1–20), size (65 / 24 / 10 per sheet, common sticker-sheet sizes), language and price on/off, then Print: A4, 100 %, no margins.
Labels whose code a barcode cannot hold (non-ASCII) print the code only and say so; codes too long for the chosen size get a warning.
Needs `catalog.view`; the selection checkboxes need `catalog.edit` (like the other bulk actions). Up to 100 products per sheet.

The barcode field (product and variant) now has helper text, accepts letters, digits, `.` `_` `-` only, and must be unique across products
and variants, compared without regard to case.

## Testing

* Backend: `ScanByCodeTest` (50), `ProductLabelsTest`, `Code128Test` (49 together). Code 128 output was also decoded back with an independent
  decoder (zxing-cpp) for every printable character, every digit pair and every set switch.
* Flutter: `test/features/scan/` 55 tests (controller 23, screen and entry points 32) with a fake camera.

## Trying it on a phone (not verifiable in the sandbox)

1. Build a debug app (`flutter run --flavor customerDev`), sign in, open Home, tap the scan icon in the search bar.
2. Accept the camera permission. Hold a product barcode inside the frame: a card slides up and the cart badge moves.
3. Check: torch toggle, haptic on success, holding steady adds one unit, moving away and back adds another, Undo, Keep scanning, View cart.
4. Deny the permission (Settings > camera off) and reopen: the explanation and Open settings appear; turn it on and return: the camera starts.
5. Print a label sheet from the dashboard and scan it with the app (also a small 38 mm label, to check the bars are readable).

On the live server: run `php artisan migrate` (adds four small indexes); nothing else is needed.
