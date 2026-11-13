# Customer app — side windows instead of pages

Owner request: secondary flows open in a window beside the page (like opening the cart), not as a new page that needs Back.
Rules and the overlay ladder: `docs/guides/SIDE_WINDOWS.md`. The one helper: `mobile/lib/core/widgets/side_panel.dart`
(`showSidePanel`): a tall bottom sheet on phones (< 720 px), an END-side panel from 720 px (right in English, **left in
Arabic**). Both are routes, so the system Back button closes only the top window.

## How the app decides (the ladder, run in order)

| Question | Answer | Used for |
|---|---|---|
| A hint on an icon? | Tooltip | quick-view eye, edit pencil, close |
| A decision ("Remove this item?", "Discard changes?") | Centred dialog, Cancel / safe button focused | `confirmAction`, `confirmDiscard` |
| A record, a short form, a picker, a short list, a cart, filters? | **Window** (`showSidePanel`) | everything marked "window" below |
| A map, a payment, a checkout, onboarding, sign-in, a printable or shareable document with its own URL? | **Full screen / page**, always with a visible exit | see "Stays a screen" |
| A second thing needed while a window is open? | **A step inside the same window** (`PanelStep`, back arrow), or close this window and open the next on the page behind. Never a window on a window | cancel / return / password / address form / branch details / notification text |

## Flow by flow

| Flow | Before | After | Why |
|---|---|---|---|
| Product page → Add to cart | snackbar "View cart" → tab | **"Added to your cart" window**: the line(s) just added highlighted, quantity steppers, remove, "You may also need", subtotal, pinned **Proceed to checkout** + **View cart** | keep your place; check out in two taps; the Cart tab stays a tab |
| Product cards: "+" for products with colours/sizes | pushed the product page | **Quick view window** (picture, price, options, quantity, pinned **Add to cart**, "View full details") | choose and add without leaving the list |
| Product cards (list, search, home, wishlist, related) | tap = page only | + visible **eye** button and **long-press** = quick view; tap still opens the page | discoverable, keyboard/screen-reader reachable (tooltip "Quick view: name") |
| Buy again / reorder "View cart" | snackbar → cart tab | opens the cart window | same as above |
| Orders list → an order | pushed `/orders/:id` | **Order window**: status, live timeline, shipments with items, address, totals, pinned next action (Pay now / Track order / Pickup pass / Order again), header **Open full page** | read and act, then Back lands on the list |
| Order window: live updates | pull to refresh | `order.updated` ping refreshes the open window (status, timeline, pinned action) | realtime kept |
| Order window: Cancel order, Request return | bottom sheet | **a step in the same window** (back arrow, reason, pinned destructive / primary button, "Discard changes?" when text or items were entered) | one window at a time |
| Order window: Review, Contact support, Reorder | pushed pages | close the order window and open the review window / new-ticket window / cart window on the page behind | one window at a time; a clear hand-over |
| Home live-order card, notifications about an order, a ticket's "Related order", My auctions → View order | pushed the order page | the same order window (`openLocation`) | consistent |
| Search / category / list: Filters + Sort | bottom sheet, "Show results" applied blind | **Filters window** with sort, price, stock/sale, attributes, brands, a **live count** ("Show 24 results" pinned), Clear all, discard guard | answer "how many?" before leaving |
| Addresses: add / edit | pushed pages | **Address window** (Save pinned; "Discard changes?"); the **map pin picker stays a full-screen route** (✕ in the app bar, "Confirm location") and returns its pin to the window | map = full screen; the form = window |
| Checkout / auction-win checkout: Change address | pushed `/addresses?pick=1` | **Address picker window** (list, tap to choose, pinned **Add address** → the form as a step) | choose without losing the checkout |
| Branch picker (account row, cart/product "Change", checkout pickup, branch pill) | pushed `/branches?pick=1` | **Branch window** (All / Nearby / Map tabs; a branch's details as a step; choosing closes it and confirms on the page) | frequent, quick choice |
| Product page: Check other branches, See all reviews, Ask a question, Auction terms, single text inputs (bid max, password) | bottom sheets | windows (same helper; forms guard against losing typed text) | one mechanism |
| Write a review | pushed page | **Review window** (rating required, photos, Submit pinned) | form beside the product |
| Profile edit (account hub) | pushed page | **Profile window**; **Change password is a step in it**; the avatar pencil now has a name and a 48 dp target | form, no new page |
| Notification preferences (account / settings / notifications list) | pushed page | **Preferences window**; every switch saves at once and says **Saved** (inside the window) | toggles save instantly |
| Support: New ticket, a ticket's thread | pushed pages | **New-ticket window**; once sent the **conversation window** opens in its place (thread + reply box pinned) | form and conversation beside the list |
| Notifications (home bell, account row) | pushed page | **Notifications window**; an item about an order/ticket opens that record's window in its place; an item with no destination shows its **full text** as a step (before: it re-opened the same page) | detail, not a loop |
| Wishlist, Loyalty & referral (account hub) | pushed pages | windows | read/act and stay on the hub |
| Terms, FAQ, Returns, Contact, Auction terms (login, settings, support, auctions, home banners) | pushed pages | **Content window** | reading terms never costs a half-filled sign-in form |

## Stays a screen (on purpose) — each has a visible exit

| Screen | Why | Exit |
|---|---|---|
| Checkout, auction win checkout, payment status | a multi-step money flow | app-bar back arrow; payment status has explicit "View order" / "Continue shopping" |
| Order tracking (live map) | a map | app-bar / Fresh-header back arrow |
| Map pin picker | a map | ✕ (full-screen dialog), "Confirm location" |
| Sign-in, OTP, register, password, language, onboarding, splash | entry flows | "Continue as guest", back arrows, "Skip" |
| Product page, auction and lot pages, category / list / search results | destinations with their own URL, long, shareable | back arrow |
| Orders, addresses, support, my auctions lists | the destination of an account row; their records open in windows | back arrow |
| `/orders/:id`, `/products/:id`… via link, push notification, "Open full page" | deep links keep working | back arrow |
| Delete account, settings, invite, pickup pass (QR) | account-level / printable screens | back arrow |
| Image zoom viewer | media viewer | back arrow |

## Behaviour every window now has (`side_panel.dart`)

- Phone sheet (92 % max, content-sized, drag handle) / tablet end panel (440 px, the page stays visible); Arabic slides from the left.
- Close by ✕, scrim, Esc and system Back; Back closes only the top window. Focus is kept inside; Esc goes through the pop check.
- Forms (`isForm`): swipe-down is off (a drag bypasses `PopScope`); ✕, scrim, Back and Esc ask **"Discard changes?"** when the form is dirty (Keep editing is focused).
- The keyboard lifts the pinned footer. `SidePanelLayout` = scroll area + pinned primary action (also used by the full-page versions of the same forms).
- Messages: a snackbar would hide behind a window, so `showInfoSnack` / `showErrorSnack` inside a window show a **toast under the window header**; confirmations after a window closes are shown on the page behind.
- Reduced motion: fade instead of slide. Logical edges only (`EdgeInsetsDirectional`, `AlignmentDirectional`).
- `showAppBottomSheet` (older name) now calls `showSidePanel`, so every remaining sheet is the same window.

## Tests

- `test/widgets/side_panel_test.dart` — helper: sheet on 390, end panel on 1024, left in Arabic, pinned footer, Back closes only the top one, scrim / Esc / ✕, toast inside the window, keyboard, dirty-form guard (focused safe button), swipe lock, steps.
- `test/qa/side_windows_test.dart` — the real app on a fake backend, phone and tablet, English and Arabic: orders (sheet / panel / left in Arabic / deep link / Open full page / live update / cancel and return steps / dirty note / Home card), cart window (added, View cart, Checkout, steppers, tablet, Arabic), quick view (eye, long-press, add, guest, full details), filters (live count, apply, dirty guard, tablet), address window (dirty guard, Save, confirmation), map pin picker (✕, confirm returns to the window), profile (+ password step), notification preferences, support (new ticket → thread, thread), review, branch picker, wishlist, loyalty, notifications (text step, order hand-over), checkout stays a page.

## Not verified here

No device or emulator was available: all of the above is covered by widget tests only. Still to look at on a real phone and a tablet: the feel of the slide / drag, the keyboard on the pinned footers, the app footer staying visible under a window (it lives outside the navigator and was left untouched), TalkBack / VoiceOver reading order of the windows, and the map tiles inside the branch window.
