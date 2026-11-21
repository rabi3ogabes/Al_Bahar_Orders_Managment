# Delivery date promise, paid assembly, guest cart and sharing

Four features that help a customer decide before they pay (roadmap 3.1, 3.2, 3.5, 3.7). This page is both the owner's guide (what you see, where the
switches are) and the design note (what was decided, why, and what it costs). The API is in [API_CONTRACT.md](../api/API_CONTRACT.md) (section 7b for the
date promise and assembly, section 7 for the cart, section 8 for orders).

| Feature | Customer sees | Where you control it | Default |
|---|---|---|---|
| **Delivery or pickup date promise** | "Delivery tomorrow, 4 pm to 6 pm" on the product page, the cart and the checkout; one date per parcel when an order splits | **Settings > Checkout & fees**: *Show the delivery date before payment*, *Same-day order cut-off*; on each product: *Extra lead time (days)* | **On**, cut-off **15:00**, lead time **0** |
| **Assembly and installation** | A tick "Add assembly and installation (QAR 150 each)" on products that offer it; the fee as its own line in the cart, the checkout, the order and the invoice | **Settings > Checkout & fees**: *Offer paid assembly and installation*, *Extra days when assembly is chosen*; on each product (card *Assembly and installation*): *Assembly fee per unit (QAR)* | **On**, extra days **0**, no product has a fee until you type one |
| **Guest cart in the app** | The app opens without signing in: browse, add to cart, see the cart. Sign-in is asked only at checkout | Nothing to set | Always on |
| **Share on WhatsApp** | A "Share on WhatsApp" button on a product (web and app) and on the cart (web and app) | Nothing to set | Always on |

Arabic wording of all four was written for this release and has not been reviewed by a native speaker: please have it read before it goes to customers
(the dashboard words are in `backend/lang/ar/org_admin.php`, `catalog_admin.php` and `sales_admin.php`; the customer words in `promise.php` and `checkout.php`;
the app words in `mobile/lib/l10n/app_ar.arb`; the web words in `storefront/src/locales/ar.ts`).

## 1. Delivery and pickup date promise (3.1)

### What the customer sees
- **Product page and quick view:** under the price, one line for the chosen option and quantity: "Delivery tomorrow, 4 pm - 6 pm" or "Pick up today from 4:15 pm".
  It follows the customer's showroom (or the best one) until an address is known, then the address's delivery area. Until there is an address the line adds
  "The exact date depends on your address."
- **Cart:** one sentence for the whole basket. When the basket splits across showrooms there is one row per parcel and an "All delivered by ..." line.
- **Checkout:** the same sentence above the date picker, and the picker never offers a day or time earlier than the promise.
- **Not in stock, branch closed, nothing free:** the honest line "We will confirm your delivery date" (or "your pick-up time"). It is a calm note, never an
  error, and the customer can still buy. A date is never invented.

### Where the date comes from (the only rules, in order)
1. **Honest first.** A parcel that is not in stock at its showroom, or a showroom that is closed for now, gets no date.
2. **Lead time.** Each product has *Extra lead time (days)* (made to order, default 0). A delivery with assembly adds the dashboard's *Extra days when assembly is
   chosen*. The earliest date is today plus the longest of them.
3. **A showroom that publishes slots** (delivery or pickup): the date is the first slot with a free place from the earliest date on. It is read through the
   very same service the checkout date picker reads, so the promise can never name a day or time the picker cannot offer (a test keeps the two equal).
4. **A showroom without slots, delivery:** the earliest open day. Today only when the order is placed before the *Same-day order cut-off* and the showroom is
   still open long enough for the drive; otherwise the next open day, skipping closed days and holidays.
5. **A showroom without slots, pickup:** now plus the showroom's preparation time inside an opening window (rounded up to 15 minutes), else the next opening.
All times are Qatar time and all sentences come from the server in the customer's language, so the web store and both apps always say the same thing.

### What staff see
Nothing new to do. The date is a promise built from existing data (stock, hours, holidays, slots). The order keeps the slot the customer chose as before.

### Switching it off
*Show the delivery date before payment* off: no date anywhere, checkout works exactly as before. The endpoints answer `enabled: false` and the clients draw nothing.

### Decisions and trade-offs
- **No date is not an error.** A promise that blocked buying would cost sales; "We will confirm" keeps the sale and the branch phones the customer.
- **Slot showrooms use their real first free slot,** not an estimate, because the customer will pick from the same list a minute later.
- **Cut-off default 15:00** (a showroom without slots). Change it in the dashboard; each showroom with slots follows its own slot booking cut-off instead.
- **Answers are cached for 30 seconds** per identical question and language (and public product answers for a minute at the CDN). Stock and slots do not move
  faster than that, and the placed order always re-checks the real slot (error `slot_too_soon` if a chosen slot is earlier than the promise allows).
- **Rate limited** (`delivery-promise` limiter) because it is public.

## 2. Paid assembly and installation (3.5)

### What the customer sees
- A product that has an assembly fee shows the tick **Add assembly and installation (QAR 150 each)** on its page, quick view and cart line.
- The cart, quote, order and invoice show **Assembly and installation** as its own line, inside the total. A pickup cart shows the tick greyed out: assembly
  is done at the door, so it is delivery only and charged only on delivery.
- If the customer changes the quantity, the fee follows the units. If they switch the basket to pickup, the fee is not charged.

### What staff and drivers see
- **Order page (dashboard):** an *Assembly and installation* total row and a badge on each line that has it.
- **Branch and dispatch lists:** the line shows "Assembly ordered".
- **Driver app:** the stop's item list says assembly was bought and the job asks the driver to assemble. (The driver does not tick it off in the app yet, see
  section 6.)

### Rules of the money (server is the only source)
- **A flat fee per unit** (set per product, QAR). No discount and no tax is applied to it: it is a service price the customer sees in full.
- **Frozen on the order line** when the order is placed (`assembly_fee`, `assembly_total`), so a later price change never alters a placed order.
- **Inside the quote hash** only when selected, so older quotes and orders are unchanged and a changed choice returns the usual 409 `quote_changed`.
- **Split orders:** the fee goes to the parcel that carries the line. Cash on delivery and the payment split use the same largest-remainder share as the
  goods, weighted by line total plus assembly.
- **Refunds and adjustments:** the fee follows the units. Reducing, removing, replacing or cancelling units gives back fee times the units (never more than
  the line still owes); a cancelled parcel gives back its own assembly. `Assembly::refundShare` is the one helper to ask "what assembly goes back with N units".
- **Lead time:** a delivery with assembly adds the *Extra days when assembly is chosen* to the promised date (and the date picker's earliest day).
- **Turning the switch off** hides the tick and refuses new choices (422 `assembly_not_offered`); orders already placed keep what was bought.

## 3. Guest cart in the app (3.2)

- The app opens without signing in. A guest adds products, changes quantity, removes, picks assembly and sees the cart, all kept on the phone
  (`shared_preferences`, survives closing the app).
- **Totals as a guest** are at normal prices. Deals, coupons and the delivery fee are applied by the server after sign-in; the cart says so in one line.
- **At checkout** a guest is asked to sign in or register and is returned to checkout, not to the product page.
- **After sign-in** the guest cart is merged into the account cart: a product in both keeps the higher quantity (never the sum), assembly stays if either
  side chose it, products that are unavailable are dropped with a short note ("Your cart changed: ..."). The guest cart is then emptied.
- A guest's phone cart never reaches the server before sign-in; `POST /cart/promise` takes the lines in its body so the date promise still works.
- The web store already had a guest cart; it only gained the assembly tick and the date promise.

## 4. Share on WhatsApp (3.7)

- **Web:** a *Share on WhatsApp* button on a product and on the cart opens `https://wa.me/?text=...` (no phone number; the customer picks who). The text is the
  name, the price and the product's plain link.
- **App:** the same button, WhatsApp first. If WhatsApp is not installed the customer is told in a sentence and the system share sheet opens with the same text,
  so sharing never dead-ends. The page's share icon still opens the system sheet directly.
- **The link is a plain product address** (`https://albahar.qa/products/<slug>`): no query string, nothing about the person who shares.
- **The preview card** (picture, title, price line) is built by the server into the page's `<head>` (Open Graph and Twitter tags), because WhatsApp never runs
  JavaScript. Tests keep the card right: title, description, one absolute picture, the same address everywhere. The card is in English by the existing design of the store's page tags.

## 5. What was added

**Migration** `2026_11_21_120000_add_assembly_and_lead_time` (with a working `down()`): `products.assembly_fee`, `products.lead_time_days`,
`cart_items.assembly`, `order_items.assembly_fee` / `assembly_total`, `orders.assembly_fee_total`, with check constraints.

**Endpoints** (all in [API_CONTRACT.md](../api/API_CONTRACT.md) section 7b): `GET /products/{id}/delivery-promise`, `POST /cart/promise`, `GET /cart/promise`.
New fields: `assembly` on `POST` and `PATCH /cart/items`, `items[].assembly` and `assembly_total` on the cart, `lines[].assembly`, `totals.assembly_fee`,
`fulfillments[].assembly_fee` / `promise` / `earliest_date` and `promise` on the quote, `items[].assembly` and `totals.assembly_fee` on an order,
`assembly_service_available` and `lead_time_days` on the product, `assembly_ordered` on staff and driver rows.

**Dashboard settings** (Settings > Checkout & fees, English and Arabic labels and hints): `checkout.promise_enabled`, `checkout.same_day_cutoff`,
`checkout.assembly_enabled`, `checkout.assembly_extra_days`. Per product: *Assembly fee per unit (QAR)* and *Extra lead time (days)* in the product editor.

## 6. Not done, and why

- **"Assembly done" confirmation by the driver.** The driver sees that assembly was bought, but cannot tick it done in the app. That is a new step in the
  delivery proof flow (photo, signature, handover) and belongs with the driver app's next change.
- **Assembly revenue is not a separate line in the sales reports.** It is inside the order total and shown on every order and invoice. A report column
  needs a rollup change in the reports module.
- **The guest app cart shows normal prices.** Deals and coupons are decided by the server and need the customer's account; they apply at checkout.
- **The return screen's suggested refund** does not yet include the assembly fee (the returns feature was being rewritten by another change at the same
  time). Use `Assembly::refundShare($item, $units)` there.

## 7. Choices made for the owner (change any of them)

| Choice | Default | Why |
|---|---|---|
| Same-day cut-off for a showroom without slots | 15:00 Qatar time | Leaves time to prepare and drive before closing |
| Assembly is delivery only | Yes | The installer is at the door; a pickup customer collects the goods |
| Tax on the assembly fee | Not applied | A flat service price shown in full; ask the developer to add it if your accountant needs VAT on it |
| Assembly extra days | 0 | Nothing changes until you decide assembly takes longer |
| Merging a guest cart with an account cart | Higher quantity wins | Adding would double what the customer already chose on another device |
| Promise cache | 30 seconds | Stock and slots do not move faster; protects the database |
