# Deals: countdown offers (dashboard guide)

A **deal** puts a special price and a countdown on one product or many, with a limited quantity, so customers order now.
It shows in the customer app and the web store as a live widget (`dd:hh:mm:ss`), on the product cards and on the product page.
You find it under **Marketing > Deals** (Arabic: **التسويق > العروض**). The technical contract is [../api/DEALS.md](../api/DEALS.md).

Deals are not the same as **Promotions & coupons**: a promotion is a pricing rule (a code, 10 % off the basket); a deal is a customer-facing offer with a
countdown. Both keep working. Units that get a deal price are **never discounted twice**: promotions and coupon item discounts do not apply to them
(a free-delivery code still works, because it only waives delivery).

## The list

Every deal is a card with its name, a coloured **phase** badge, a **countdown that ticks**, the products, units sold (with a bar), orders and the savings
given so far.

| Phase | Meaning |
|---|---|
| **Live** | switched on, inside its time, and at least one product still has units |
| **Sold out** | inside its time but every product with a quantity is sold out (it stays on the public list for 15 minutes so people see why, then disappears) |
| **Scheduled** | switched on, waiting for its start time |
| **Paused** | switched off; customers do not see it |
| **Ended** | past its end time, or you pressed **End now** |

Filters at the top (kept in the address, so Back and sharing work): All, Live, Scheduled, Paused, Ended.

Row actions: the **switch** pauses or resumes at once (a "Saved" message confirms it, no Save button); **Edit**; **Duplicate** (a switched-off copy with
nothing sold and a fresh countdown of the same length); **End now** (asks first; the countdown stops for everyone, orders already placed keep their deal
prices); **Delete** (only for a deal nobody ordered from).

## The editor (six short parts, in a side window)

It opens beside the list (a bottom sheet on a phone), and **Save stays off until you change something**. Closing with unsaved changes asks first.

1. **Name and time.** English and Arabic name and a short line. The **end time** is required; quick buttons set it to +1 h, +6 h, +24 h, +3 days or the end of
   today. The **start** is optional: empty means "the moment the deal is switched on". All times are Qatar time.
2. **Products.** Search by name or code and tap to add. For each product: a **deal price** (required for a fixed-price deal, optional "own price" otherwise),
   the **quantity** for this deal (empty = unlimited) and its own **per order** / **per customer** limits (empty = the deal's limit). A product that already
   sold units cannot be removed, and its quantity cannot go below what is sold.
3. **Price rule.** *Percent off* (1 to 95), *Amount off* (per unit, in QAR) or *Fixed price* (the price you type on each product). The box "The customer
   pays" shows the first product with its normal price struck through and the deal price, so you see the result before saving. A variant that already costs
   less than a fixed deal price simply gets no deal.
4. **Limits.** *Per order*: how many units of one product get the deal price in one order. *Per customer*: how many over all of one account's orders in this
   deal. Units over a limit are **never refused**: the customer pays the normal price for them, and the cart says it plainly ("Deal price on 2 of 3 ·
   limit 2 per order").
5. **Look.** Style (Classic, Bold, Minimal, Luxe, Ribbon), colours (Brand, Gold, Night, Emerald, Rose or Custom), corners, countdown look (boxes, big digits,
   words), which parts to show (days, hours, minutes, seconds; at least two; days only appear while 24 hours or more are left), urgency (below this many
   minutes the digits take the highlight colour and pulse once a second; people who asked for less motion see no pulse), icon, the words above the timer and
   on the button in English and Arabic, "Only n left" bar, the "-30%" badge and an optional picture behind the words. On the right (above on a phone) a
   **live preview** counts down like the real widget: switch **EN / ع** and the light or dark page to check every case. The server fills in the colours, so
   the shop and the app always draw exactly what you see. Custom colours that are hard to read are warned about, and refused when they are nearly invisible.
6. **Show on home and switched on.** These two tick boxes wait for Save.

A product can be in **only one running deal at a time**. If the times overlap, Save says which deal it clashes with and when it runs. A deal you keep
switched off (a draft) may overlap; the clash is checked when you switch it on.

## Worked examples

### 1. "50 units, 2 per order"

A sofa promotion: 50 sofas at 30 % off, no customer may take more than 2 at that price in one order.

1. Marketing > Deals > **New deal**. Name "Sofa Week", Arabic "أسبوع الأرائك". Press **+3 days** for the end time.
2. Part 2: search "Oslo", tap the sofa, type **50** in *Quantity*.
3. Part 3: *Percent off*, **30**. The box shows `QAR 3,000.00` struck through and `QAR 2,100.00`.
4. Part 4: *Per order* **2**. Leave *Per customer* empty.
5. Part 5: pick a look; Part 6: keep **Show on home** and **Switched on**. **Create deal**.

What happens: a customer with 3 sofas in the cart pays the deal price on 2 and the normal price on the third. When 50 units have been taken the deal shows
"Sold out" for 15 minutes and then leaves the list. A cancelled or unpaid order gives its units back automatically.

### 2. "1 per customer account for the whole weekend"

A lamp that every customer may buy once at the deal price, over several orders.

1. New deal "Weekend Lamp", **Starts** Friday 18:00, **Ends** Sunday 23:59 (type the times in Qatar time).
2. Part 2: add the lamp, leave the quantity empty (unlimited stock for the deal).
3. Part 3: *Fixed price*, type **QAR 99** as the deal price on the lamp. (A lamp variant that already costs QAR 80 gets no deal.)
4. Part 4: *Per customer* **1**.

What happens: from Friday 18:00 every account gets the deal price on its first lamp only. If a customer cancels that order the allowance comes back. A second
lamp, in the same order or a later one, costs the normal price. The list shows the deal as *Scheduled* with a countdown to its start until Friday 18:00.

### 3. "One product, 24-hour countdown"

A flash offer on one dining table with a big countdown and a sense of urgency.

1. New deal "Flash table". Press **+24 h**.
2. Part 2: add the table, *Quantity* **10**.
3. Part 3: *Amount off*, **QAR 400**.
4. Part 5: *Bold* style, *Night* colours, countdown **big digits**, urgency **120** (the last two hours pulse), keep the stock bar on.

What happens: the widget counts down from 24:00:00 (days are hidden because less than 24 hours remain). In the last two hours the digits turn to the highlight colour and pulse. When
you want it finished earlier, **End now**: orders already placed keep their deal price.

## What customers and staff should know

* **One price for the whole order.** The price the customer confirms is the price they pay. If a deal ends, sells out or reaches a limit while they are in
  checkout, the order is not placed: the app says "A deal changed, your total was updated" and they confirm the new total.
* **Staff-made orders and auctions do not get deal prices** (the dashboard's *New order*, the branch app). This is on purpose; it can be changed later.
* **Cancelling and returning give units back.** A cancelled order, an unpaid online order that expired, a delivery that failed and was then cancelled,
  a delivered parcel marked **Returned** (its goods are back on the shelf), a fully refunded order, or an item taken off by the branch (out of stock) gives
  its deal units back to the deal and to the customer's own allowance. Doing it twice gives them back once. A parcel that is only delivered keeps its units.
* **Put a limit when you set a quantity.** Units are taken when the order is placed, even before it is paid, so with a quantity but no limit per order and
  none per customer, one account could take every unit, cancel and do it again. The editor warns you (and the list shows a "No buyer limit" chip) but never
  blocks you: set a **per customer** limit to close it.
* **Typing mistakes are caught.** A deal price below 5 % of the normal price is refused (it names the product and the lowest price allowed), and prices
  accept plain numbers with up to 2 decimals only.
* **Prices on the invoice.** Each order line remembers the deal it got (name, units, saving per unit) and the order lists the deal as a discount, so
  invoices and revised invoices stay right even after the deal changes or ends.
* **Who can do what.** Marketing: *view* sees the page; *create* makes and duplicates; *edit* changes, pauses, resumes and ends; *delete* deletes. A
  read-only role (for example the auditor) can look but never change. Every change is written to the audit log (`deals.created`, `deals.updated`,
  `deals.paused`, `deals.resumed`, `deals.ended`, `deals.deleted`).

## The middle button of the app menu

**Appearance > App footer** has a **Middle button** choice: *None*, *Auctions* or *Deals*. Choosing one switches that item on, and the app draws it as a
raised round button in the exact middle of the bar, with the other items sharing both sides. Its icon and word are the ones you set for the item in the
list above (a flame for Deals by default). A Deals button shows a small live dot while a deal is running. The menu keeps 3 to 5 items with Home on; a
middle button whose item is switched off or removed goes back to None on its own. Older app builds ignore the choice and show the item in its normal place.

## If something looks wrong

| You see | Why | What to do |
|---|---|---|
| Save says a product is "already in the deal ..." | another switched-on deal has it at the same time | change one of the two times, or remove the product from one |
| "This deal was ended" when editing | **End now** is final | **Duplicate** the deal and switch the copy on |
| A product shows no deal price | its normal price is already below the fixed deal price, or the deal is paused / ended / not started | check part 3 and the phase badge |
| A customer got the normal price on some units | a limit (per order, per customer or what is left) applied; the cart and quote say which | nothing, this is the rule |
| Delete is missing | the deal has orders | **End now** instead |
