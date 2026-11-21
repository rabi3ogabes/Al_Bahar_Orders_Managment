# Printed picking list and delivery note

Two sheets of paper for every branch part (roadmap 2.10). The **picking list** is for the person who gathers the goods in the branch: the lines come in the
order of the shelves, so one walk through the store fills the order. The **delivery note** goes with the goods in the driver's hands: who receives them, where,
what is in the parcel, how much cash to collect, and a line for the customer's signature. This page is the guide for the owner and the branch manager (what
you see, where the switches are) and, at the end, the design note (what was decided, why, what every number means).

Arabic and English everywhere. Every label on the paper is printed in both languages, the customer's language first. The Arabic wording was written for this
release and has not been read by a native speaker: please have it read once (`backend/lang/ar/print_docs.php`, the part under `doc` is what is printed).

| What | Where you find it | Default |
|---|---|---|
| **Picking list / Delivery note of one part** | **Sales > Orders**: open an order (page or side window), on every branch part: two buttons *Picking list* and *Delivery note* (a pickup part has only the picking list). Also a small icon on the **Deliveries** board (picking list on parts waiting for dispatch, delivery note on every open delivery) and on the **Pickup** counter (parts still being prepared). | on |
| **Print for the branch** (many parts, one PDF) | The **Print** button at the top of **Orders**, **Deliveries** and **Pickup**: *everything waiting to be prepared*, or *the deliveries of one day and one driver* | on |
| **Paper size** | **Settings > Printing**: *Paper of the picking list*, *Paper of the delivery note* | **A4** both |
| **Prices on a paid order's note** | **Settings > Printing**: *Show prices on the delivery note of paid orders* | **off** |
| **Bin (shelf) of a product at a branch** | **Inventory > Stock**: the edit window of a row has the field *Bin* (for example `A-03-2`) | empty |

Everything opens as a PDF **in a new tab** (nothing is stored, nothing is sent to anyone): print it from there. One page per part; a part with many lines
flows onto more pages and repeats its number at the top of each page.

---

## 1. The picking list

For one branch part (one branch's parcel of an order). What the picker sees, top to bottom:

- **The part number** in big type (`AB1234-1`) and a **barcode of it**. The part number is a code the dashboard search already understands (see section 6).
- Branch, order number and when it was placed, the **delivery time** (the slot the customer chose; for a pickup the *pickup time*), delivery or pickup.
- **Badges**, only when true: *Assembly requested*, *Two people* (an oversized line, or a parcel heavier than the limit in Settings > Operations clock), and
  *Waiting for the customer* (see below). A split order says *Part 2 of 3*; the other parts are named only to a person who may see their branch.
- **Notes**: what the customer wrote and the branch's own notes on the order or the part. The customer's name, phone and address are **not** printed on the
  picking list (the paper stays in the store).
- **The lines**, each with a tick box: **bin**, product name (the customer's language first, the other muted below), variant (size, colour), SKU and the
  **quantity** in big type. Lines the branch removed or swapped out are not listed: only what has to come off the shelf. A line the branch took fewer of says
  *Reduced from 2*.
- Totals (lines, units, weight when the catalogue knows it), and *Picked by / Checked by / Time* lines to sign.

### The order of the lines (the walk)
Lines are sorted **by bin, the way a person reads a shelf code**: `A-2` comes before `A-10`, capitals and small letters count the same. Lines whose bin is not
set come **last** and say *No bin*; the foot of the page says how many. Lines in the same bin follow each other by name, then SKU, so the same part always
prints in the same order. Set the bins in **Inventory > Stock** (one bin per product per branch).

### A replacement the customer has not answered yet
When the branch replaced an item and asked the customer (docs/guides/RETURNS_AND_SUBSTITUTIONS.md), the replacement line is printed with an amber note
*Replacement: the customer has not answered yet (until 15:40)*, what it is *instead of*, and a banner *Keep preparing*. If the customer says no, the line
is taken out again and the next print no longer shows it.

---

## 2. The delivery note

Only for **delivery** parts (a pickup part has no delivery note: the customer collects it with the pass). Top to bottom:

- The part number and its barcode, the order number, *Part 2 of 3* when the order is split.
- **Deliver to**: the recipient's name and phone (the person on the address, else the customer), and who ordered it when that is someone else.
- The **delivery time**, the **full address** (zone, street, building, unit, area, city), the **landmark**, the **delivery instructions** (the gate code, the floor:
  what the customer typed), the customer's note, and the **map link** as text when the address has a pin (no live map on paper).
- Badges *Assembly requested* and *Two people*.
- **The items** with quantities (and SKU). **Prices only when the order is cash on delivery**, or when the switch in Settings > Printing is on.
- **What the driver collects**, in big type (see below).
- **Received by / Signature / Date** lines, and *Problem with this delivery? Call* with the **branch phone** (the customer care phone from Settings > General
  when the branch has none).

### The money box
| The order is | The box says |
|---|---|
| Cash on delivery, something still due | **Collect cash QAR x** in big type, with the order total and *Already paid* when part of it was collected before |
| Cash on delivery, one of several parcels | the same, and *This parcel's share of the cash, of 2 parcels*: each driver collects his own share and the shares add up to what is due |
| Cash on delivery, nothing due any more | *Nothing to collect: already settled* |
| Paid online (SADAD) | *Paid: nothing to collect* |
| Online, payment not confirmed | a red *Payment not confirmed: check with the branch before handing over* |

The amount is the delivery's own cash amount (the same number the driver's app shows). When no delivery exists yet it is worked out the same way, from the
payment and the parts that are still to be delivered.

---

## 3. Printing many at once

**Print** at the top of Orders, Deliveries and Pickup opens a side window. Choose:

- **Picking lists, everything waiting to be prepared**: parts that are *accepted*, *pending* or *being prepared*, oldest promise first. Unpaid online orders are
  not in it (they are not waiting for you yet). On the Deliveries screen only delivery parts, on the Pickup screen only pickup parts.
- **Delivery notes, deliveries of one day**: pick the **day** (today by default) and a **driver** (*all drivers*, *no driver yet*, or one name with how many
  deliveries he has). The pages come in the order the driver meets them. **Today also carries what is still open from earlier days and what has no time**
  (the same as the driver's "My day").
- A **branch** choice when you work for more than one.

The window says how many parts that is and opens the PDF. **A PDF holds at most 50 parts** (about 7 seconds of work); a longer list prints the first 50, oldest
first, and the window says so: choose one branch or one driver to print the rest. A batch with no part prints nothing and logs nothing.

---

## 4. Who printed it, and how many times

Every printed part writes **one entry to the audit log** (*Picking list printed* / *Delivery note printed*) with who, when, which part, the **copy number** and
the paper. On the part (order page and side window) each document shows **how often it was printed**: *Printed 2 times, last by Noor at 14:05*, and a second
delivery note is highlighted in amber *Printed before: send only one copy with the goods*. On the paper itself a second copy carries a red **REPRINT, copy 2**
stamp, so a duplicate note at the door is never a surprise. The counter is read from the audit log, so it can never disagree with it.

Nothing is logged when the request is refused, the part does not exist, the batch is empty, or the PDF could not be made.

## 5. Who may print

Exactly the people who may **view the order at that branch** (the `orders.view` permission, branch by branch): branch managers, order managers, sales,
pickup, customer support, accountants, auditors, admins. A delivery driver account (no order access) cannot; the dispatcher prints for him. A part of another branch
is refused as a whole (403) and nothing is logged; a branch-limited user's batches only ever cover their own branches. The delivery note carries the
recipient's name, phone and address, so it is **never emailed or sent anywhere**, the PDF is never cached (`no-store`) and every print is audited.

## 6. The barcode

The barcode is **Code 128** of the **part number** (`AB1234-1`), drawn by the same support class as the product labels. A barcode scanner that types into the
screen (a "keyboard" scanner) types the number; put the cursor in the dashboard's global search (Ctrl/Cmd + K), scan, and the order opens: the search
reads `AB1234-1` as order `1234`. The pickup pass (the customer's QR code) is **not** printed on these sheets: it is the key to the goods and stays with the customer.

---

## Not in this release
- **Staff app (Flutter):** left alone. The sheets are web pages behind the dashboard sign-in; the app talks to the token API, and opening a PDF from there needs
  a signed link that does not exist yet. Staff on a phone open the dashboard in the browser, where the buttons work.
- **Fragile goods:** the catalogue has no "fragile" flag, so the sheets cannot show one. *Two people* comes from the oversized flag and the weight limit.
- **One walking list across several parts** (all parts of a batch merged into one route through the store): the batch is one page per part, as asked.

---

# Design note

**Decisions**
- **No new table.** The bin already existed (`branch_inventory.bin_location`, editable in Inventory > Stock), and the print history **is** the audit log
  (`order.picking_list_printed` / `order.delivery_note_printed`, subject = the part, `metadata.copy`). One record, no second counter to drift. Reads use the
  `audit_logs (auditable_type, auditable_id)` index and are bounded by the parts on the screen (at most 50).
- **The barcode carries the part number, not the pickup token.** The token is a bearer secret that releases the goods; a printed sheet in a store or a van must
  not hold it. The part number is unique per branch part and is what dashboard search already resolves.
- **A named part you may not see refuses the whole request.** A rule-based batch ("waiting to be prepared") silently stays inside your branches, like every
  list in the dashboard. A branch never learns of another branch's parcel from its own paper (other parts are named only to a viewer who may see them).
- **Customer personal data is on the delivery note only.** The picking list has no name, phone or address. Internal staff notes are on the picking list only,
  never on the delivery note (the customer may read it).
- **Prices on the note: cash on delivery always, paid orders only on request** (default off), because the driver collects money and the customer checks what
  it is made of, while a paid order's note is a packing slip.
- **Arabic on paper.** The PDF engine cannot join or wrap Arabic, so every Arabic text goes through the shaper **one line at a time** after being cut into
  lines in reading order (otherwise a long address would print its end on the first line). A number followed by an Arabic comma keeps its digits readable
  (`850،` not `058`). Arabic fonts are the DejaVu set the invoice already embeds.
- **A PDF holds at most 50 parts.** The engine needs about 0.13 s a page (measured: 100 pages = 13 s). 50 stays well inside a web request on any host.
- **Paper per document, not per user.** One setting each, A4 default, A5 for the half sheet.

**What the numbers mean**
- *Parts waiting to be prepared* = branch parts in `pending`, `accepted` or `preparing` whose order is not `pending_payment`, inside your branches (and the chosen branch / kind).
- *Deliveries of a day* = deliveries still open (`pending`, `assigned`, `accepted`, `picked_up`, `out_for_delivery`, `rescheduled`) whose delivery time falls on that Qatar day; for today also those with no time and those of earlier days that are still open.
- *Printed N times* = the number of audit entries of that document for that part; the next copy is N + 1.
- *Collect cash* = the open delivery's `cod_amount` (the part's share of what is still due, kept by the cash on delivery split); without a delivery, the same share computed from the payment and the parts still to deliver.
- *Already paid* = the order's `paid_total` (what drivers or the gateway already captured).
- *Weight* = sum of unit weight times quantity for lines whose weight is known; *Two people* = an oversized line, or a weight above `dispatch.two_person_weight_kg` (the driver card's rule).

**Settings added** (all in Settings > Printing, group `printing`): `printing.picking_paper` (a4|a5, default a4), `printing.note_paper` (a4|a5, default a4),
`printing.note_prices` (bool, default off).
**Routes added** (all `orders.view`, GET, throttled): `admin.print.picking.part`, `admin.print.note.part`, `admin.print.picking.batch`, `admin.print.note.batch`.
**No migration, no scheduled job, no API endpoint.**
