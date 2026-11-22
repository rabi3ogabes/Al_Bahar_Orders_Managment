# Driver and branch apps with no signal (roadmap 5.1)

A driver in a basement car park, a lift lobby or the desert road to Al Khor loses the signal. A branch tablet loses the Wi-Fi. Neither may stop working, and
nothing a driver did may be lost, recorded twice or recorded at the wrong time.

| | Driver app | Branch staff app |
|---|---|---|
| Lists with no signal | the last good jobs, also after the app was closed, with *Last updated 2:05 PM* | the last good counts and order lanes, also after the app was closed, with *You are offline. Showing the list from 2:05 PM.* |
| Steps with no signal | kept on the phone in a visible **outbox**, sent later in the order pressed, at the time pressed | switched off with *Needs a connection* (no queue) |
| Code | `mobile/lib/ops/driver/driver_outbox*.dart`, `mobile/lib/ops/ops_read_cache.dart` | `mobile/lib/ops/staff/staff_offline.dart`, `staff_home.dart` (banner) |
| Server | `POST /admin/deliveries/{id}/{action}` honours `Idempotency-Key` and `acted_at` (`DeliveryStepReplay`, `App\Support\StepTime`); API: [API_CONTRACT.md §13](../api/API_CONTRACT.md) | unchanged |

---

## 1. What the driver sees

**A strip at the top** while the phone has no network: *No connection. Your steps are saved and sent when the signal is back.* with **Try again**.

**The lists stay.** The jobs he had are kept on the phone (also after the app is closed or the phone restarts). When they are the kept ones, a line under the
strips says *Last updated 2:05 PM*. Only what the job card shows is kept: the open jobs, and of today's finished jobs only those the server still shows in full (a
finished job older than a day comes back without the door and the phone numbers and is never kept). Everything kept is wiped when the driver signs out or the
session ends.

**Steps still work.** These are kept and sent later:

| Step | What is kept with it |
|---|---|
| Accept | the time pressed |
| Picked up from branch | the time pressed |
| On my way | the time pressed, where he stood (if the phone knew) |
| Mark delivered | who received it, the proof photo (a copy in the app's private files), the cash collected, where he stood, and that the assembly question was asked |
| Could not deliver | the reason, the note, the photo, where he stood |
| Assembly done / not done | the answer, the reason, the note, the photo |

The job card moves to the new state at once and says **Waiting to send** (the step and *Pressed at 2:05 PM*); the next button is ready (after *Picked up* comes
*On my way*, and it can be pressed too: it waits behind the first). A delivered or failed job stays in **My jobs** with *Waiting to send* until the server has it,
then moves to History. A strip at the top counts them: **2 updates waiting to send** (tap it, or *See updates*). The list shows, in the order pressed: the
order, the step, when it was pressed, and where each stands (*Waiting to send*, *Waits for the update above*, *Not sent*), with **Send now**.

**Never waits:** *I have arrived* (the customer is told "your driver has arrived": a message an hour late would mislead) and *I can't take this* (the branch
must give the job to someone else now). Without signal they show the usual plain sentence (*No connection. Check your signal and try again.*); the delivery form
is still one tap away under *I have arrived*.

**Sending is automatic**: when the network comes back, when the app comes back to the front, after the driver signs in again, and on a timer that waits a little
longer after each failed try (5 s, 10 s, 20 s ... at most 5 minutes). Strictly in the order pressed for each job; one update at a time for the whole phone; never
twice: each update carries a key made when the driver pressed, and every try sends the same key, so a try whose answer was lost is never recorded a second time.

**Positions** are not queued (one every few seconds would pile up for nothing). The newest position is sent the moment the signal is back, with the time the
phone took it. Trade-off: the dashboard's route of that delivery has a gap for the time without signal, then shows where the driver is now.

### A refused update is never silent

When the server says no to a kept update (the job was given to another driver, the order was cancelled, the branch already moved the job on, a photo is
missing, the cash is not what is due ...):

- the banner turns red: **1 update was not accepted**;
- the job card says **Not sent** and why, in plain words (*This order was cancelled. No need to go.*);
- in the list the update shows the same reason and *Discard it, try again, or call the branch.*, with **Discard** (asks first: *It will not be sent. Tell the
  branch what happened at the door.*), **Try again** and **Call the branch** (the branch's own number, sent with each job);
- only that job's later updates wait; the other jobs keep going.

A step refused **while the driver is still in the form** (he has signal and the server answers at once, e.g. the cash is wrong) behaves as before: the form stays
open with the reason, nothing is kept.

**Session ended (401):** everything waits (*Your session ended. Sign in again and it is sent.*) and goes as soon as he signs in again. A different account
signing in on the same phone never sends the first driver's updates: they are dropped.

**Signing out with updates waiting** asks first: *3 updates have not been sent. Send them first?* — **Send first** (sends; if something still cannot go, he stays
signed in and is told), **Sign out anyway** (they are lost: the branch does not see them), **Cancel**.

## 2. What the branch staff app does

The branch app keeps **no queue**: a branch step (accept, start preparing, ready, assign a driver, hand over at the counter, decide a cancellation) must reach the
server at once, because the person is at the counter and the customer or the driver is waiting on the answer. With no connection:

- the bar says *You are offline. Showing the list from 2:05 PM.* (or *Showing the last list.* when the list was read while the app stayed open), with **Try again**;
- the counts and the order lanes are the last good ones, also after the app was closed;
- the action buttons are switched off and named, with *Needs a connection. Try again when the Wi-Fi is back.*;
- when the Wi-Fi is up but the server cannot be reached, the bar says *Could not refresh. Showing the list from 2:05 PM.* (the buttons stay on; a step then says
  why it failed, as before).

## 3. What the dashboard and the customer see

- Every time the step writes is **the time the driver pressed**: picked up, out for delivery, delivered, failed, the attempt, the proof photo, the cash collected,
  the parcel's dispatched / delivered time, the order's completed time, the delivery history and the assembly answer. So the customer's *Delivered on ...* line,
  the order timeline, the live board's clocks, the scorecard (on-time, time on the road, failed deliveries), the daily close and the cash held all tell what
  happened when it happened.
- The audit log keeps the moment the server **received** it: one row `delivery.step_sent_late` per late step, with `acted_at` and `received_at`.
- A step that arrives after the day was closed is not added to that closed day (a closed day keeps its figures); the next screens and the cash held show it.

## 4. Switches

None. The behaviour is the same for every driver and branch. (The dashboard's existing switches still apply: *slide to confirm*, the alternative number, the
assembly answer rule.)

## 5. Design note

**Decisions and defaults**

- *Driver: full queue; branch: none.* Drivers work where the signal is poor and a lost "delivered" costs money and trust. Branch staff stand at the counter with
  the customer: a queued hand-over or a queued "ready" would tell the customer or the driver something that has not reached anyone. Switching the buttons off with
  the reason is the honest choice.
- *What waits:* the six steps of the delivery itself. *What never waits:* "I have arrived" and "I can't take this" (time-critical for someone else), going on or
  off duty, and every branch step.
- *Ordering:* per job in the order pressed; one request at a time for the whole phone (a poor connection is not made worse by parallel uploads).
- *Never twice:* the server keeps the first answer that worked for each key (table `idempotency_keys`, scope `delivery_step`) **in the same transaction as the
  step**: either both are saved or neither. A retry after a lost answer gets the first answer back (`Idempotent-Replayed: true`), even if the job has moved on since.
  A refused step keeps nothing, so a corrected retry with the same key works. Keys are per account (another person's same key never sees the answer) and are
  removed after 7 days by `deliveries:prune-step-keys` (03:50 Qatar, on System health).
- *Old apps:* a request without `acted_at` behaves exactly as before. Apps up to build 90 already sent a key on deliver / fail / assembly, one per form, kept while
  the driver edits the form; for them the same key with **different** details falls back to the old rules by state (a delivered job stays delivered, a second
  failure reason is refused as before) instead of the new `422 idempotency_conflict`, which only an app that sends `acted_at` (the outbox, which never edits an
  item) receives.
- *Time bounds:* up to 2 minutes ahead of the server is taken as now (a phone clock a little fast); more is refused (`acted_at_in_future`) and the app sends the
  same update again without its time, so a wrong phone clock never blocks the outbox (the server then records when it arrived). Older than 24 hours is refused
  (`acted_at_too_old`): the driver discards it and calls the branch. A time before the job's previous step is moved up to that step, so a timeline never runs
  backwards (and a trip time is never negative).
- *Photos:* copied into the app's private support folder (`outbox/<key>.jpg`), never the gallery; deleted once sent or discarded. If the copy cannot be made, the
  camera's own file is used rather than losing the step. If the file is gone when it is sent, the update is shown as *Not sent* with *The photo of this update
  was lost. Discard it and do the step again.*
- *What is kept on the phone:* the outbox (the steps and their fields, no token, no password) in the app preferences, and the last good lists. Everything is
  cleared on sign-out (the outbox only after the driver chose to send or drop it). Package added: `path_provider` as a direct dependency (it was already in the
  build through other plugins) for the private photo folder.
- *Branch phone:* each driver job now carries `branch.phone` for "Call the branch".

**Definitions of what is shown**

| Shown | Means |
|---|---|
| *N updates waiting to send* | steps pressed on this phone that the server does not have yet (waiting, waiting behind another, or waiting for sign-in) |
| *N updates were not accepted* | kept steps the server refused; the driver decides (discard, try again, call the branch) |
| *Waiting to send* on a job | at least one of its steps is still on the phone; the card shows the state after the driver's last step |
| *Pressed at 2:05 PM* | the time on the phone when the driver pressed (Qatar time); it is also the time the server records |
| *Last updated 2:05 PM* / *Showing the list from 2:05 PM* | the time the list on screen was last read from the server (Qatar time) |
| `delivery.step_sent_late` (audit) | a step recorded with the driver's time: `acted_at` = pressed, `received_at` = arrived at the server |

**Trade-offs**

- The route of a delivery has a gap while the driver had no signal (positions are not queued).
- A day closed before a late step arrived does not include it.
- A driver who signs out anyway loses his waiting steps; the question says so.
