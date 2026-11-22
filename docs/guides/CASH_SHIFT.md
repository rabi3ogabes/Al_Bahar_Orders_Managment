# Cash shift, Z-report and hand-in by selected collections

Roadmap item 2.11. Everything is on the **Cash** page of the dashboard (Finance > Cash collections). No new page, no app change: the driver and staff apps are untouched.

## What each person sees

**The cashier of a branch** opens Finance > Cash. On top is the **Shift panel**: one card for their branch.

- Drawer shut: "Open shift". A window asks for the float, counted **by note** (QAR 500, 200, 100, 50, 10, 5, 1) and the coins as one amount. The total is added up by the server as it is typed. It also says what the last shift closed with, so the float can be compared.
- Drawer open: the card shows **the cash that should be in the drawer right now**, how long it has been open, and the lines behind it (float, hand-ins received, banked, cash refunds). "Close shift" opens the end-of-day window.
- Close shift: the window shows the **expected cash** with its working, then the cashier counts the drawer by note and coins. The difference appears as it is typed. Above a small limit a **reason** is required, above a bigger one a **second person** must also review it. When it is closed the window says "Z-1" and offers **Print Z-report**.

**The branch manager / accountant** also uses the **Shifts** tab: every shift of the branches they may see (filter by status, branch and date; open shifts and shifts waiting for review are always listed) with what was counted against what was expected, the Z-report of each closed shift (it also holds the float), and the **Review** button for a large difference. If the branch is set to "block", the Receive cash window says so and offers to open a shift.

**The owner** gets "Needs attention" lines (Needs attention page, home card and the menu number): a drawer open for too long, and cash received on a finished day while no shift was open. See below.

**Receiving a driver's cash** (To hand in tab > Receive cash) now lists the driver's collections, **oldest first, with the order number of each**. All are ticked to start with, so the usual "whole balance" hand-in is still one count and one tap. Untick what is not being handed over today, or **Clear** and tick only some; "Select all" brings them back. The expected amount is the running total of the ticked ones and the counted amount is compared with exactly those. A driver with 15 collections (QAR 3,935.50) is one window, one count, one tap. The receipt keeps what was ticked: in **Hand-ins & deposits** each row shows how many collections it settled, and **Details** lists them (order number, time, amount), so a variance can be explained per receipt. Collections left unticked stay in "To hand in".

## A day in the life

1. The cashier opens the shift with the counted float.
2. Hand-ins received (from drivers and from the pickup counter) are stamped with the shift. Bank deposits made while the shift is open are stamped too, and so is a cash refund marked as paid out while it is open.
3. At the end of the day the cashier closes the shift with the count. The server freezes the Z-report figures, gives it the next Z number of the branch, and, for a large difference, leaves it for a second person.
4. The second person (someone with `payments.override` who neither opened nor closed the shift) reviews it from the Shifts tab and signs it off with a few words. The same words and rules as a hand-in variance.

## The numbers: what each one means

| Number | Definition |
|---|---|
| **Opening float** | The notes and coins counted when the shift was opened (notes x value + coins). |
| **Hand-ins received** | The cash the receiver **counted** in each hand-in stamped with this shift (not what the collector was supposed to hand over). |
| ... from drivers / from the pickup counter | The amount **collected** on the collections of those hand-ins, by type (cash on delivery / pay at pickup). |
| ... differences found when counting hand-ins | Counted minus collected, over all those hand-ins. The three lines always add up to the hand-ins received. |
| **Banked** | The counted amount of the hand-ins deposited at the bank while this shift was open. |
| **Cash refunds paid** | Refunds paid out in cash (method "manual cash", marked paid out) while this shift was open at a branch of that order, by someone who may receive cash there. |
| **Expected cash** | Opening float + hand-ins received - banked - cash refunds paid - petty cash paid out. To the dirham: all money is whole dirham arithmetic, never decimals of a float. |
| **Counted** | Notes by value + coins, as counted at closing. |
| **Variance** | Counted - expected. Negative = short. |
| **Z number** | Z-1, Z-2 ... per branch, given at closing in one atomic step. A number is never reused, a failed closing does not use one. |

## Settings (Settings > Cash, English and Arabic)

| Setting | Default | What it does |
|---|---|---|
| Cash received while no shift is open | Accept it and add it to the next shift opened | `attach`: a hand-in is accepted while the drawer is shut and is added to the next shift opened at that branch, back to the previous close. `block`: it is refused with "Open a cash shift first" until a shift is open. |
| A reason is needed above | QAR 1.00 | A difference above this needs a written reason (the same message as a hand-in difference). 0 means every difference. |
| A second person must review above | QAR 50.00 | A difference above this also needs a second person (`payments.override`, not the opener or the closer). Never lower than the reason limit. |
| A shift open longer than this needs attention | 16 hours | Shows in Needs attention: amber after this long, red after one and a half times. |

## Permissions and privacy

- Open and close a shift: `payments.edit` at the branch, the same level as receiving a hand-in. Review a large difference: `payments.override` at the branch. Look at the Shift panel, the Shifts tab and the Z-report: `payments.view`, limited to the branches that right covers. A Z-report of another branch answers "not found".
- Every open, close (with the full count by note), review, refund attached and Z-report print is in the audit log (`cash_shift.opened`, `cash_shift.closed`, `cash_shift.variance_reviewed`, `cash_shift.refund_paid`, `cash_shift.z_printed`).
- The Z-report shows **staff names and amounts only**: no customer name, phone, address or order number.

## The Z-report

Open it from the Shifts tab or the "Print Z-report" button after closing. A4 page, English or Arabic (a switch on the page, or `?lang=ar`). Print it, or choose "Save as PDF" in the print window: the browser draws the Arabic correctly. It shows the branch, the cashier and closer, opened and closed times, the float, every hand-in received (who from, how many collections, counted, variance), the cash banked by bank reference, cash refunds, expected, counted by note, the variance with the reason and the review, and two signature lines. The **first** time it is opened is the ORIGINAL; every later time is marked COPY 1, COPY 2 ... with a watermark. Each opening is written to the audit log. Since Wave 5 the same report is also a **PDF download** (a PDF link in the Shifts tab and a Download PDF button after closing) that shares the original / copy counter, and it has a **Petty cash paid out** block: see [ASSEMBLY_SALES_AND_PETTY_CASH.md](ASSEMBLY_SALES_AND_PETTY_CASH.md).

## Needs attention

- **A drawer left open**: a shift open longer than the limit (see Settings). The line names the cashier and goes to the Shifts tab.
- **Cash with no shift open**: for each of the last three finished days, cash received through a hand-in while no shift was open at that moment. A branch that has never opened a shift (or opened its first one after that day) gets no line: it does not use shifts yet. Aggregates only (amount and number of hand-ins).

## Design note: decisions, defaults, trade-offs

- **One open shift per branch.** The drawer is the branch drawer; a partial unique index guarantees it. A different cashier takes over by closing and opening. Several tills in one branch are not modelled.
- **Safest rollout default.** Hand-ins are accepted while no shift is open ("attach"). Blocking would stop a branch receiving cash on the day of the release, and "attach" keeps every existing flow working. A branch can switch to "block" in Settings. In both modes the cash is never lost: while attached it is in the hand-ins list, and the Needs attention line shows the day.
- **Attach, exactly.** Cash received with the drawer shut is added to the **next shift opened at that branch**, back to the previous close. The very first shift of a branch claims nothing from before shifts existed. The opening window says how many hand-ins and how much will be added, and asks the cashier to count the float **without** them, otherwise they would be counted twice.
- **Bank deposits** are never blocked. A deposit made with the drawer shut belongs to no shift: the cash had left before the next count.
- **Expected cash uses what was counted**, not what was supposed to arrive, because the drawer holds what the cashier counted. A short hand-in is already a variance on its own receipt (reviewed by finance as before); the shift does not count it a second time.
- **The cashier's own counter cash.** A pickup collected by the cashier themselves is, as before, handed in to a colleague. Until then it is not part of the expected cash; the card and the closing window say so ("You collected 1 cash payment yourself ... keep it apart from the drawer").
- **Cash refunds** have no branch of their own. They count against the open shift of a branch of the order, by someone who may receive cash there, at the moment they are marked paid out. Bank-transfer and card refunds never touch the drawer.
- **Petty payouts** (Wave 5, [ASSEMBLY_SALES_AND_PETTY_CASH.md](ASSEMBLY_SALES_AND_PETTY_CASH.md)) are in the arithmetic: expected cash = float + hand-ins received - banked - cash refunds - petty cash paid out (not reversed).
- **Reason and review limits** are strict "above": exactly QAR 1.00 or exactly QAR 50.00 need nothing more. The limits are read at closing, so a change applies from the next close.
- **Closing twice.** The same person closing again with the same count gets the closed shift back (a double tap); anything else is refused. The same holds for opening. A close with every box left empty is refused: type 0 where there are none.
- **Locks.** Receiving cash, banking and closing take the shift row first. A hand-in is either in the shift (and in its Z-report) or after it, never in between; a hand-in that slipped in just after opening is still claimed at the closing.
- **Reprints.** The first opening is the original; opening the page again always counts as a copy. A preview before printing therefore uses up the original: print from the button right after closing.
- **API.** The staff API `POST /admin/cash/handovers` already accepted `collection_ids`; it now refuses a list that contains a collection that is gone (already handed in, another collector's or branch's) as one 409 `cash_collection_unavailable`, and in "block" mode answers 422 `cash_shift_required` while no shift is open. The apps need no change for this.
- **Not built.** Several tills per branch; messaging or emailing the Z-report (it is a print and archive document); the branch and driver scorecard (2.12).
