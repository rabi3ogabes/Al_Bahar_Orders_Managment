# Web store: side windows

Owner request: "use the window open on the right/left side instead of opening a page (like opening the cart), so it is better than opening a page and then pressing Back."
Rules: [`docs/guides/SIDE_WINDOWS.md`](../guides/SIDE_WINDOWS.md). Code: `storefront/src/components/SideDrawer.tsx` (the one window), `Dialogs.tsx` (centred ones),
`lib/overlay.ts`, `lib/useCloseOnBack.ts`, `lib/useUrlOverlay.ts` (Back, URL).

## 1. The cart panel ("Add to cart")

Tapping **Add to cart** anywhere (product page, cards, quick view, "you may also need" rail, buy again) never navigates. It opens the cart panel over the page: right side in English, **left side in Arabic**, bottom sheet on phones (< 768 px).

| Requirement | How |
|---|---|
| Stays open until dismissed, no timer | No `setTimeout` closes or hides anything. The "Added to cart" confirmation lives until the panel is opened another way; Undo stays until the panel closes. |
| "Added to cart" + photo, name, price, quantity − / + | Green confirmation row, then the added line in a tinted card (photo, name, option, unit price, quantity, line total). Strings in EN and AR (the word is "cart"; the old "bag" wording is gone from the EN file). |
| Quantity − at 1 | **Removes the line and offers Undo** (the − shows a bin at 1). Consistent everywhere in the panel. |
| Live subtotal | Footer subtotal follows every tap before the server answers. |
| Exactly two buttons | **Checkout** and **Keep shopping**. (Header has a ✕ and an "open full cart" icon link, not buttons in the footer.) |
| Keep shopping | Closes the panel only: same URL, no reload, same scroll position, focus back on the Add button (`useOpener` + `cart.opener`). |
| Checkout | Same route and guards as the cart page: signed in, nothing unavailable; a guest gets the sign-in window first, then goes on. Pending cart changes reach the server first (`cart.flush()`). |
| Scrim, Esc, Back, swipe | Scrim click, Esc, browser/phone Back (closes only this panel, one history entry) and swipe down on phones all close it. |
| Header badge immediately | Optimistic cache write (`lib/cart.tsx`, pure maths in `lib/cartMath.ts`). Requests go out in order; the last answer replaces the optimistic data. A failure refetches the real cart (rollback) and shows a toast. |
| Same variant again = quantity + 1 | Client: `addGuestRow` / `cartAdd` merge by variant id. Server: `CartService::addTo` adds to the existing `cart_items` row under `lockForUpdate`, and `cart_items_cart_id_product_variant_id_unique` makes a duplicate line impossible. Verified on the server with a signed-in cart (below). |
| Other lines | Shown below the added item (compact), then the suggestions rail (signed-in customers). |
| Dark, reduced motion, focus trap, 44 px, no layout shift | Theme tokens; global reduced-motion rule; Radix focus trap and scroll lock (scrollbar compensated, measured: 0 px shift); every control in the panel is 44 px or more. |
| `/cart` page | Unchanged and working (also linked from the panel header). |

## 2. Audit: what opens in a window now

Ladder (docs/guides): tooltip, popover/sheet menu, **side window** (details, forms, filters, carts), centred modal (decisions), full page (checkout, big shareable content).

| Flow | Before | After | Why |
|---|---|---|---|
| Cart (header icon, Add to cart anywhere) | Side drawer built by hand, "Added" only a badge | Cart panel (section 1), lazy chunk | The owner's example; keeps the page and scroll |
| Order from the orders list / overview | `<Link>` to `/orders/:id` page | **Order window** `?order=<id>`: live status (socket flash and polling kept), timeline, items, totals, pickup pass, invoice, reorder (pinned), return, cancel | A secondary look at a record; deep link and the full page keep working (`Open full page`) |
| Product card | Link to the product page only | **Quick view** `?quick=<slug>`: photos, price, options, quantity, Add to cart, "View full details" | Choose without leaving the list |
| Shop filters and sort on phones/tablets | Hand-built drawer applying every tap | **Filter window** with a draft: pinned button shows the live count ("Show 24 pieces"), applies on tap, Reset in the header | Airbnb-style; Back and ✕ discard the draft |
| Addresses add / edit | Modal (page content centred) | Side window, pinned "Save address", Discard-changes guard | Form over a list |
| Address in checkout | Modal | Same window | Keeps the multi-step checkout page |
| Delete address | `window.confirm` | Centred decision: question title, verb button, Cancel focused | A decision, not a browser dialog |
| Profile and password | Page `/account/profile` | Side window from the account menu (page still answers at the URL) | Edit without leaving the section |
| Support request | Modal | Side window with pinned "Send request", dirty guard | Form |
| Write a review | Inline form that pushed the page down | Side window, dirty guard | Form |
| Return request | None on the web | Step inside the order window (and a window on the order page); pieces, reason, pinned button | The API existed; never a window opened from a window, so the step replaces the content |
| Cancel order | Modal with fields | Centred decision with reason inside | Decision |
| Showroom picker | Modal | Side window | List of choices |
| Mobile menu | Hand-built drawer | Side window from the start edge (kept as a side panel on phones) | Same component, same Back and focus rules |
| Search | Hand-built overlay without Back | Top panel on the same dialog library (Esc, scrim, ✕, Back, focus return) | Same rules for every window |
| Sign-in | Modal | **Stays a modal** (centred, bottom-aligned on phones), now lazy and with focus return | The brief |
| Auction register | Modal with terms | Centred decision with the terms and a checkbox | Decision with a consequence |

### Stays a page, on purpose

Checkout (multi-step; now has a visible "Back to cart" exit), the cart page, the product page, order page (notifications and emails link there), shop results, account sections (orders, addresses, wishlist, loyalty, notifications, support list), showrooms, auctions and lots (live pages), CMS pages. Sign-in stays a modal.

## 3. How it works

- **One window.** `SideDrawer`: Radix Dialog (focus trap, scroll lock, Esc, aria) on tablets and desktops; vaul on phones for the swipe-down sheet with a drag handle (max 92 dvh). End side (`inset-inline-end`, slides from the left in Arabic); logical properties only.
- **Back.** `useCloseOnBack` pushes one history entry per open window; Back closes the top window only. Closing by ✕, Esc or scrim takes the entry back out, so nothing is left behind. A link inside a window **replaces** the window's entry (Back from the destination skips the window). Logic is in `lib/overlay.ts` (class `OverlayStack`) with a fake history in `tests/overlay.test.mjs`.
- **URL windows.** `?order=` and `?quick=` are the history entry themselves (`useUrlOverlay`): opening pushes one, closing steps back, a deep link closes in place. Reload, share and notification links open them on any page. The layout no longer scrolls to the top when only these params change.
- **Dirty forms.** `dirty` on `SideDrawer`: Esc / scrim / ✕ / Back / swipe ask "Discard changes?" (Keep editing focused); the history entry is re-armed.
- **Decisions.** `ConfirmDialog`: question title, consequence text, verb button, Cancel focused, scrim does not close a destructive one.
- **Lazy.** Every window is its own chunk (`LazyHost`), warmed on hover/focus/touch and the cart on idle. First-paint bundle:

| | before | after |
|---|---|---|
| `index-*.js` | 382.79 kB (118.05 kB gzip) | 378.80 kB (118.67 kB gzip) at the last build; the dialog library is not in it |
| `index-*.css` | 73.69 kB (13.48 kB gzip) | 77.4 kB (14.1 kB gzip) |
| new shared chunk (Radix Dialog + vaul) | none | `SideDrawer` 32 kB (10 kB gzip), loaded on first open |

## 4. Bugs found and fixed on the way

- **Arabic phones (375 px): the whole page was 11 px wider than the screen** (footer grid column forced wider by the newsletter pill), so fixed sheets were clipped. `grid-cols-[minmax(0,1fr)]` on both footers.
- **/checkout at 375 px overflowed horizontally** (the 14-day slot row widened the page). Same fix on the checkout grid. A crawl of 12 routes in EN/AR, classic/fresh now shows no horizontal overflow.
- Radix returns focus only to a `Dialog.Trigger`; ours open from state or the URL, so focus was lost. `useOpener` remembers the tapped element and gives it back.
- `autoFocus` on the sign-in input stopped Radix from recording where focus came from; the first field is now focused through `data-autofocus`.
- The old windows had no Back handling, their own focus trap code and unlabelled scroll lock; all gone (`Drawer`, `Modal`, `useFocusTrap` removed from `ui.tsx`).

## 5. Not verified or left

See the end of this file for the run log and open items.
