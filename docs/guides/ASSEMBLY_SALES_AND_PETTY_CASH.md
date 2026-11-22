# Assembly in the sales reports, petty cash and the Z-report PDF

Roadmap items 5.5 and 5.6. Dashboard only: no app, storefront or staff API screen changed. Everything is in English and Arabic.

---

## 1. Assembly in the sales reports (5.5)

### What the owner sees

**Reports > Sales > Assembly sales** (needs the Reports right, like every sales report). One row per branch for the chosen dates:

| Column | Meaning |
|---|---|
| Assembly jobs | Branch parts of orders (one visit to a customer's door each) that still have at least one assembly unit left after returns |
| Units | Units that carry paid assembly and are still on the order |
| Units returned | Units whose goods came back |
| Gross | The assembly fee of those units |
| Refunded | The assembly fee of the returned units (their share goes back to the customer) |
| Net | Gross minus refunded |

**Reports > Sales > Sales summary** gets five more rows below "Average order value": assembly jobs, assembly units, assembly revenue (gross), assembly refunded, assembly revenue (net). Net sales keeps its meaning (items only).

**Filter "Orders with assembly"** (a switch in the toolbar of every sales report, and `assembly_only=1` on the staff API `GET /admin/reports/{report}` and on exports): keeps only the branch parts that have paid assembly. It applies to Sales summary, by branch, product, category, brand, payment method, fulfillment and Orders by status. It is remembered in the address (`?assemblyOnly=1`), so a link shares it. The CSV export carries the same filter; the "Assembly sales" report is always about assembly, so the switch is not shown there.

A branch manager sees only their own branch's assembly (the same branch scope as every report); another branch's assembly is never in their numbers.

### Definitions (what each number means)

- **Which orders**: the same as every sales figure (`SalesBasis`): placed in the period (shop time, Qatar), not cancelled, not waiting for the online payment. The branch is the branch of the order **line**, as in the other sales reports.
- **Units** = `quantity - quantity_cancelled` of each line with an assembly fee. A unit an adjustment removed has already left `quantity`.
- **Gross** = fee per unit x units, never more than the line still owes (`assembly_total`).
- **Refunded** = fee per unit x `quantity_returned`, never more than the line's gross: the same share `Assembly::refundShare` puts into a suggested return refund.
- **Net** = gross - refunded.
- **Job** = one branch part of an order. A part whose every unit came back is not a job any more.
- **Orders with assembly (the filter)** = judged per branch part: a split order where only branch A sold furniture with assembly counts for A and not for B. Under the filter, "Net sales" and "Refunds" of the summary are narrowed to those orders too (a refund of another order is not taken off).

### Design note

- **Attribution to the order's day.** Assembly refunded is attributed to the day the order was placed, like the sale it belongs to. A return received next month therefore lowers last month's net, and a past period can change when a later return arrives. The alternative (dating the refund by the return) would need a date per returned unit that the order line does not keep.
- **Net sales is not changed.** Net sales counts item lines only, so it never contained assembly. A return refund that includes the assembly share is still taken off net sales in full (the refund is one amount without a split), so for a return of an assembled item the two reports overlap by that share. Not changed here to keep every existing figure as it was; the assembly rows are separate and exact.
- **Daily close and owner pack** do not carry assembly yet: they will read the number through the shared definition (roadmap 5.8) when it lands. The Assembly sales report is the place today.
- **No new index.** The queries are bounded by the indexed `orders.placed_at` range and join lines by `order_items.order_id`; the "has assembly" test is an `EXISTS` on `order_items.order_fulfillment_id`, which already has an index (`order_items_order_fulfillment_id_idx`).

---

## 2. Petty cash (5.6a)

### What each person sees

**The cashier** (Finance > Cash, the Shift panel): an open drawer card has a second button, **Pay out cash**, under Close shift, and a line "Petty cash paid out" with the amount. The window shows the cash the drawer should hold, then:

- **Amount** (QAR, up to two decimals),
- **What was it for?** a short list (Supplies, Transport and fuel, Small repair, Cleaning, Refreshments, Other) and **Details** (required for "Other", up to 300 characters),
- **Receipt photo** (optional, JPG/PNG/WebP up to 5 MB, kept private),
- **Approved by**: appears as soon as the typed amount is **above** the limit (see below).

Pay out records it and keeps the window open on the list **Paid out in this shift** (newest first): time, reason, who paid, who approved, the amount, a link to the receipt and a **Reverse** button while the shift is open. Reverse asks for a reason on the same row.

**The Z-report** (print, and the PDF) has its own block **Petty cash paid out** with every payout (a reversed one is struck through and marked REVERSED with who and why) and the total, and the cash working has the line "- petty cash paid out".

**Settings > Cash**: "A petty-cash payout needs an approver above" (default QAR 100.00, "above" is strict: exactly QAR 100.00 needs nobody).

### The rules (all on the server)

- Who: `payments.edit` at the branch (the same right as opening and closing the shift). A cashier of another branch gets 403; someone who may only look sees no button.
- Only while the shift is open. A payout takes the shift row lock first (the lock receiving cash, banking and closing already take), so it is either inside the shift and its Z-report or refused; it can never appear after the close.
- It **lowers the expected cash** of the shift: expected = float + hand-ins received - banked - cash refunds - petty payouts not reversed. The count at closing is compared with the lowered figure.
- **A payout cannot be larger than the cash the drawer should hold** (refused with the amount the drawer holds). Exactly the drawer's amount is allowed and takes it to zero.
- **Above the limit** a named approver is required: an active person other than the cashier who holds `payments.override` at the branch (administrators, super admins and accountants do; the branch manager role does not, see "Owner decisions"). The approver is chosen from a list in the window.
- **A double tap is harmless**: the window carries a key; the same key answers with the payout that exists. A second window is a second payout.
- **Reverse** (void): reason of 3 to 300 characters, only while the shift is open, by anyone who may run the drawer; a payout somebody approved can only be reversed by someone who may approve. The line stays (who, when, why) and stops counting. Reversing twice is one reversal.
- Audit log: `cash_shift.payout_recorded` (amount, reason, note, approver, drawer after) and `cash_shift.payout_voided`.
- **Privacy**: staff names and amounts only; the receipt photo is on the private disk (never the public one) and opens only through `GET /admin/cash/payouts/{payout}/receipt` for someone who may see that branch's payments (404 otherwise).

### Numbers

| Number | Definition |
|---|---|
| Petty cash paid out | Sum of the payouts of the shift that were not reversed; the count beside it is their number |
| Expected cash | Opening float + hand-ins received - banked - cash refunds - petty cash paid out |
| Drawer after (audit) | Expected cash after this payout |

---

## 3. The Z-report as a PDF (5.6b)

Finance > Cash > **Shifts** tab: a **PDF** link beside **Z-report** on every closed shift, and a **Download PDF** button in the window shown when a shift is closed. The route is `GET /admin/cash/shifts/{shift}/z-report.pdf` (optional `?lang=en|ar`: which language leads).

- Made the way the picking list and the delivery note are made (dompdf, labels in both languages, Arabic joined by the same `PrintText` shaper), A4, one or two pages. File name `z-report-Z-12-DOH.pdf`, downloaded as an attachment, never cached.
- Same content as the print: branch, cashier and closer, times, the cash working, hand-ins, bank deposits, petty cash, the count by note, the difference with its reason and review, signature lines. **Staff names and amounts only.**
- **ORIGINAL / COPY rule kept**: the PDF and the print use the same counter. The first of the two to be opened is the ORIGINAL, every later one (PDF or print) is COPY 1, COPY 2 ... Each is in the audit log (`cash_shift.z_printed`). The PDF is rendered first and counted after, so a PDF that fails to render uses up no original.
- 404 for an open shift, a shift of another branch (to a viewer without that branch) and for anyone without payments rights (403).

### Design note

- **Approver is named, not signed in.** The cashier names a colleague who holds `payments.override`; the colleague does not sign in on the same screen. The control is that the name must be a real eligible person (checked on the server), it is in the audit log and on the Z-report, and a payout someone approved can only be reversed by someone who may approve. A two-step "request, then approve on the manager's own device" flow was judged heavier than the owner asked for; it can be added later on the same table.
- **Over-the-drawer is refused, not allowed.** A negative expected cash has no meaning. The safe default; a cashier who really holds more than the system expects fixes it at closing as a difference.
- **Receipt is optional**, as asked; the Z-report shows no receipt link (it is a printed document); the photo is for the manager in the dashboard.
- **Not built**: several tills per branch, a petty cash float separate from the drawer, categories reports across shifts.

## Owner decisions

1. Branch managers do not hold `payments.override` by default, so an approver above QAR 100 is an accountant or an administrator (by phone). To let a manager approve, give them a role that has the right (not offered in the Branch employees checklist), or raise the limit in Settings > Cash.
2. The default limit (QAR 100) and the list of reasons are choices of this release.
