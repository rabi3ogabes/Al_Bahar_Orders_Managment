# Web store QA log

Method: local backend (own DB, `php artisan serve`, broadcasting off) + the built storefront, driven by headless Chromium at 390 / 768 / 1440, EN and AR, light and dark, classic and fresh layouts. Every fix re-checked in the browser; `npx tsc --noEmit` and `npm run build` are clean.

## Bugs fixed
| Area | Found | Sev | Fix | Status |
|---|---|---|---|---|
| sanitizeHtml (product/CMS HTML) | Children of unknown tags were never sanitised (`<foo><img onerror=..>` kept `onerror`), `java<TAB>script:` links survived, script/style text leaked | High (XSS) | Rewrote `lib/sanitize.ts`: recurse into unwrapped nodes, drop script/style/svg/iframe with content, URL allow-list after stripping control chars. Proved old vs new in browser | Fixed |
| AuthModal forgot password | "Forgot password" sent a login code; the reset step was unreachable | High | `resetting` mode sends `password_reset`, reset title, resend + back on reset step; phone prefilled without +974 | Fixed |
| AuthModal / address / OTP | Arabic-Indic digits were stripped, so Arabic keyboards could not sign in | Med | `latinDigits()` in phone, OTP code, address numbers | Fixed |
| AuthModal | Double submit on Enter, no maxlength/trim, `+` in the middle of a number | Low | busy guard, maxLength, trim, safer `normalizePhone` | Fixed |
| Referral | Invite card showed "undefined points" / empty code (API contract changed) and `?ref=` links were ignored | High | `ReferralCard` uses the new payload and hides when off; `?ref=` captured and sent on sign-up (invite field only when programme is on) | Fixed |
| Header nav (classic) | Every category link looked active on /shop | Low | active state from `?category` | Fixed |
| Overlays | Search / branch picker / cart drawer stayed open after browser Back | Med | closed on route change (classic and fresh header) | Fixed |
| Dialogs | No focus trap, focus not returned to opener | Med | `useFocusTrap` in Modal, Drawer, Search | Fixed |
| Shop price filter | Clearing the chip left old numbers in the inputs; min>max gave empty list | Low | keyed form, swap min/max | Fixed |
| Order page | Guest saw "We hit a snag"; someone else's order (403) too; no h1 | Med | sign-in prompt, 403 = not found, retry button, h1 | Fixed |
| Product page | Qty/photo index kept after switching to a variant with less stock/fewer photos; `</script>` in a name could break JSON-LD | Med/Low | clamp effects, escape `<` | Fixed |
| Product card quick add / wishlist | Double error toast; wishlist errors were unhandled promise rejections | Low | single toast, try/catch + toast, guard re-entry | Fixed |
| Checkout | Step numbers 1,3,4; first day often fully booked; no way to remove a coupon; duplicate coupon messages; notes not trimmed; points above new max | Med/Low | sequential numbers, default to first day with free slot, remove-code link, clamp, trim | Fixed |
| Contrast | Footer headings, footer logo caption, referral/loyalty/auction-band eyebrows were 1.5:1 on maroon; dark-mode primary buttons 3.97:1, success/danger/warning < 4.5 | Med | `text-on-primary/80` on primary bands; adjusted dark tokens and light warning/success | Fixed |
| Offline | Network failure showed the word "network" | Low | plain EN/AR sentence | Fixed |
| First paint | Dark / Arabic visitors saw a light English flash | Low | inline pre-paint script in `index.html` | Fixed |

## Checked, no bug
All routes at 3 widths: no horizontal overflow, no console errors (only expected 404/401 for bad slugs). Bad product/page/auction slugs show the not-found state. Address form: required, 422 field error, double-click makes one POST. Checkout place-order double click: one POST, redirect to order, notes saved. RTL layout on home, shop, product, checkout, account. Fresh layout renders in light/dark.

## Not fixed / owner decisions
- Footer newsletter form (classic) has no API: it only shows a "thanks" toast and discards the email. Needs an endpoint or removal.
- No "delete my account" entry on the web profile (API `me/deletion-request` exists); App Store / Play policy needs it for apps, recommended for web too.
- Referral reward text comes from the API in the user's saved locale, not the UI language (backend `ReferralService::summary`).
- Order placement returns 500 if the broadcast server (Reverb) is down, although the order is saved (seen only because Reverb was not running in the sandbox; consider making broadcasts non-fatal).
- Design preview (`?preview_design=`) is kept in memory only, so a full reload leaves preview (by design).
