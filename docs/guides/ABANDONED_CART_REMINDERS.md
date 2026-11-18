# Automatic reminders for abandoned carts

Dashboard → **Sales → Abandoned orders**. At the top of the page there is one card: **Automatic reminders**.
Switch it on and the platform reminds customers who left things in their cart, by itself, politely, and only when it is safe to do so.

- **See it:** anyone who can open *Abandoned orders* (permission *orders.view*). Branch staff see the results of their own branches only.
- **Switch it on or off, change the settings:** *marketing.edit* for the whole company (the admin, super admin and marketing manager roles). These
  settings message every customer, whatever branch they shop at, so a branch-only role cannot change them.
- It **ships switched off**. Nothing is ever sent until you switch it on.

## Turn it on in three steps

1. Open **Sales → Abandoned orders**. Read the sentence on the card, for example:
   *"When switched on: A reminder goes to customers whose cart has been idle for 1 hour, and again after 1 day, between 08:00 and 22:00 (Qatar time), up to 2 times."*
   That sentence is exactly what will happen.
2. Press **Reminder settings** if you want to change anything (below), then **Save changes**. The Save button stays grey until you change something.
3. Flip the big switch. It saves at once and shows **"Automatic reminders are on"** with an **Undo** button. There is no Save button next to the switch.

You can switch it off the same way at any time. Reminders that were due stop at once.

## The three ready-made schedules

In **Reminder settings → When and how → Quick setup** pick one, then adjust anything you like. *Balanced* is what you get on day one.

| Schedule | Reminders | Most per cart | What it feels like |
|---|---|---|---|
| **Gentle** | One reminder, **3 hours** after the customer last touched the cart | 1 | A single friendly nudge, the same evening. Best if you worry about bothering people. |
| **Balanced** (default) | **1 hour**, then **1 day** | 2 | A quick "your cart is waiting", and one more the next day for the customers who were just busy. |
| **Persistent** | **1 hour**, **1 day**, then **3 days** | 3 | A third and last reminder after three days. Best for big-ticket furniture where people take time to decide. |

An example for *Balanced*: a customer adds a sofa to the cart on Monday at 14:00 and leaves.
Reminder 1 goes at 15:00 on Monday. Reminder 2 goes at 14:00 on Tuesday. If the customer comes back to the cart, orders, or empties it in between, the rest of the sequence stops.

Every time is counted from **the last time the customer touched the cart**, never from when the cart was created.
Later reminders must come at least 3 hours after the one before. **No reminder ever comes sooner than 30 minutes after the customer last touched the cart**, whatever a step on the Customer journeys page says (that page refuses anything shorter for the abandoned-cart journey).

## What you can change

| Setting | Meaning |
|---|---|
| **Reminders 1, 2, 3** | Switch each on or off, and choose when it goes: the first after 30 minutes, 1, 3, 6 or 24 hours; the later ones from 3 hours to 3 days. Choosing a later time for one reminder moves the next ones forward if they would come too close. |
| **Most reminders per cart** | 1 to 5. A cart never gets more, **and the reminders your team sends by hand with the *Send reminder* button count too.** It is counted from what **really went out in the last 7 days** (and a **placed order** starts the next cart from zero), **not** from the cart's own counters: a customer who keeps changing a quantity does not earn new reminders, a new gap or a new limit with every tap. The gap works the same way: **3 hours** after any reminder, and **a full staff cooldown (24 hours by default) after a reminder your team sent by hand** (a staff reminder counts as the customer's reminder for that stretch). |
| **Quiet hours** | Default **22:00 to 08:00 Qatar time**. A reminder that falls in the quiet hours is **held** and goes out when they end, **unless that would make it more than 12 hours late**, in which case it is dropped (an old reminder is worse than none). Switch quiet hours off to allow any hour. Qatar has no daylight saving, so the times never shift. |
| **How customers are reached** | **Push notification**, **App inbox** (the notification list in the app) and **WhatsApp**. **Content > Notification controls is the single source of truth:** a channel switched off there for the *Cart reminder* never carries a reminder, and this window greys it out with *Switched off in Notification controls*. The *Cart reminder* row there now ships with push, the **app inbox** and WhatsApp on, so what that page shows is what is sent. A way that is not set up on the server yet is greyed out and says **Not set up yet**; your choice is kept and works as soon as it is set up. Push and the inbox are on by default here; WhatsApp is off. |
| **Recovery offer (optional)** | Pick a running promotion that needs a coupon code (for example your **free delivery** promotion: free delivery only ever comes through a coupon) and the reminder it goes with. Each customer gets **their own single-use code**, valid for 24 hours, 2 days, 3 days or 7 days, **never longer than the promotion itself lasts**. Promotions that checkout would refuse to most customers with a cart are **refused when you save**: *first order only*, *one customer group only*, ended, not started yet, no uses left, switched off, or applying by themselves without a code. The code is made **only when a message is really going out** (and voided if nothing reaches the customer). If the promotion ends or runs out later, the reminder still goes out **without** the offer sentence; a text of your own that *needs* the code ({{coupon}}) is **not sent** instead of going out with a blank. The message tells them the code and when it ends, even if your text does not mention it. |
| **Messages** | The title and message of each reminder in **English and Arabic**, with a **live preview** of what the customer sees. The customer gets the language of their app. |

### Words, details and the preview

In the **Messages** tab pick the reminder, then edit its English and Arabic title and message. Press a chip to add a detail at the cursor:

| Chip | Becomes | Example |
|---|---|---|
| First name | the customer's first name | Noor |
| Items (2 items) | the number of items, ready to read | 2 items · منتجان |
| Number of items | just the number | 2 |
| Cart total | the total of what can be bought now | QAR 2,000.00 |
| Link to the cart | the web link back to the cart (WhatsApp shows it on its own line) | https://…/cart |
| Offer code, Code end time, Offer name | only when the reminder has an offer | BAHAR-7K2M9Q |
| First product, Store link | the first product's name; the store address | |

The preview uses an example customer (Fatima, 2 items worth QAR 1,250.00). **Use the standard words** puts the shipped text of that reminder back.
The details always count **what can be bought right now**: a product that went out of stock is not counted.

### The words we ship (short, warm, no pressure)

The Arabic below was **drafted by AI in a natural Gulf tone. Please have a native speaker read it before you rely on it.**

| Reminder | English | العربية |
|---|---|---|
| **1** · after 1 hour | **Your cart is waiting** · Hi {name}, your cart has {items} worth {total}. Come back whenever you are ready. | **سلتك بانتظارك** · هلا {name}، في سلتك {items} بقيمة {total}. ارجع لها وقت ما تحب. |
| **2** · after 1 day | **Still thinking it over?** · Hi {name}, we saved your cart with {items}. It is one tap away when you are ready. | **سلتك محفوظة لك** · هلا {name}، حفظنا لك سلتك وفيها {items}. تكمل طلبك بضغطة وحدة وقت ما تبي. |
| **3** · after 3 days (off) | **Your cart is still saved** · Hi {name}, a friendly note: your cart with {items} is still here if you would like it. | **سلتك للحين محفوظة** · هلا {name}، تذكير بسيط: سلتك وفيها {items} للحين محفوظة إذا حاب تكمل. |

If you attach an offer, one more sentence is added: *"Your gift: Free delivery. Use code BAHAR-… before tomorrow 20:00."* / *"هديتنا لك: توصيل مجاني. استخدم الرمز … قبل …"*.

## Who is never reminded

A reminder is checked again **at the moment it would go out**. It is **not sent** when:

- the customer has **not agreed to marketing messages**, or switched this kind of message off in the app, or has not agreed to WhatsApp (for the WhatsApp way);
- the customer **ordered** since leaving the cart, **emptied** the cart, or **changed the cart** (they came back: the sequence ends, and starts again from reminder 1 if they leave again);
- staff **discarded** the cart (and if staff press **Undo** on the discard, the reminders carry on);
- the customer **already ordered** since leaving the cart, even through an order your team built for them on the phone (no source cart), also in the same minute the reminder would go out;
- **nothing in the cart can be bought** any more (everything is out of stock or switched off);
- the cart already got **the most reminders per cart** (counted over the last 7 days, see above), or another reminder about it went out less than 3 hours ago (24 hours after a staff reminder);
- the customer already got the **daily limit of messages** (Marketing → Customer journeys → Safety rules, default 3 in 24 hours), or your team messaged them by hand a moment ago;
- the **Cart reminder** message is switched off in **Content → Notification controls**, or **Campaign messages** are switched off there. The card shows a yellow note when this is the case and links to the page. A **channel** switched off for the Cart reminder there is left out too.
- it is a reminder more than 12 hours late (see quiet hours).

A reminder that is not sent never piles up: nothing is "sent late" in a burst.

## Results

The card shows the last **7 or 30 days**:

| Number | Meaning |
|---|---|
| **Reminders sent** | automatic reminders that went out (one per reminder, however many ways carried it) |
| **Carts won back** | the customer **ordered after a reminder** (within the window set on the journeys page, 7 days by default); each order is counted once |
| **Revenue won back** | the value of those orders |
| **Not sent (error)** | reminders that could not be sent because of an error (a phone that could not be reached, the WhatsApp service refusing). A customer who simply did not agree is not an error. |

Open a cart (the **Open** button on a row) to see its **Reminders** history. It never promises a reminder that would be skipped: for a customer who has not agreed, is inactive, has every way closed or has nothing left to buy it says *"No automatic reminder: <plain reason>"* instead of a time. It shows: every reminder with the time and the ways it went out, the ones staff sent by hand
(with who), the **plain reason** a way did not go out (for example *"The customer has not agreed to WhatsApp messages."*) and **what comes next** (for example *"Next automatic reminder: reminder 2, 19 Nov, 08:00"*).
Branch managers see the numbers of their own branches.

## The *Send reminder* button on one cart

It still works, and it **shares the same counters** with the automatic reminders: a reminder you send by hand counts toward the most per cart, and the automatic ones will not repeat it.
It is refused for 24 hours after any other reminder or message to that customer (Organization → Settings → Notifications → *Minimum gap before a reminder sent by staff*).
Its text is the **Cart reminder** message of *Content → Notification templates* (the automatic reminders use the words above).

## How it works (for the team)

- **One engine.** The automatic reminders are the **abandoned-cart customer journey** (*Marketing → Customer journeys*), which already did timing, quiet hours, expiry, consent and order attribution. The old separate 15-minute command `marketing:abandoned-carts` was **retired**; there is no second sender. The Customer journeys page shows the same journey with its steps and the activity of every message, and its switch is the same switch.
- **The scheduler** runs `journeys:tick` every minute (the Docker `scheduler` service or `php artisan schedule:work`). Each pass starts runs for carts idle long enough, ends runs that must end, and sends what is due.
- **Safe to run twice.** A run is leased (one UPDATE decides who owns it) while it is being handled, and each message has a unique row per run, step and channel, so a retried or overlapping scheduler pass can never send twice. A pass handles at most 100 carts; the rest follows on the next minute. The switch and the notification controls are read again before every run and every send, so switching off while a pass is running stops it at once. A pass that meets a row another pass created counts and audits nothing for it. No message is sent inside a database transaction.
- **Counters and durable facts.** Every reminder that goes out, automatic or by hand, adds one to `carts.abandoned_notifications` and sets `abandoned_notified_at` (shown on the page); a customer touching the cart resets both. The **limit and the gap do not use them**: they come from `journey_deliveries` (sent or delivered, by cart) and the audit log (`cart.reminder_sent`, staff) of the last 7 days (`AbandonedCartReminders::recent()` and `holdUntil()`), so touching the cart cannot defeat them.
- **Every send is recorded** in the journey activity (one row per way) and in the audit log (`cart.reminder_sent`, `source = automatic`), and changes of the switch and settings are audited (`marketing.journey_enabled` / `_disabled`, `marketing.cart_reminders_saved`).
- **Old data.** A starter journey from before this feature gets the new default schedule and words only when it still matches **everything** the old seeder wrote (every title, text, WhatsApp wording, channel, label, delay, quiet hours, limits, no promotion, no extra step) and was never switched on or run. Change **one** thing and it is left exactly as it was. Reminders the journey had already sent are copied onto the open carts' counters once, by the migration.
- Tests: `tests/Feature/Marketing/AbandonedCartRemindersTest.php`.

## Check that it works on the live site

1. Sign in as an admin, open **Sales → Abandoned orders** and read the sentence on the card. Check **Notification controls → Campaigns and reminders**: *Cart reminder* and *Campaign messages* must be on (the card warns you if not).
2. Use your **own test customer account** that agreed to marketing messages in the app: add an item to the cart and leave.
3. Switch the card on. After the first delay (default 1 hour, and outside quiet hours) the reminder arrives in the app inbox (and as a push notification when push is set up).
4. Come back to the page: **Reminded** at the top and **Reminders sent** on the card go up; open your test customer's cart and read its **Reminders** history. Press **Undo** or switch the card off whenever you like.
5. To try the wording without waiting: *Marketing → Customer journeys → Message templates → open the reminder → Send a test* sends it to your own account.

If a reminder does not arrive, open the customer's cart: the history says why (not agreed, switched off, not set up, quiet hours, limit reached).
