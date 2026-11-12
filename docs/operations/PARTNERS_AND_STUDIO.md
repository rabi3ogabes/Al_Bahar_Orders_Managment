# Partner statistics, issue log, photo studio

## Partner statistics (share a link)
Dashboard → Insights → **Partner statistics**.
1. Set a password (10+ characters), then turn **Share with partners** on. The secret link appears; copy it and send it with the password.
2. Partners open the link, type the password, and see one business day (Qatar time) of orders as a table or as cards:
   items, delivery fees, amount paid, SADAD transaction and status, SADAD fee, **net**. Click an order for items, money breakdown,
   SADAD transactions and the recorded driver route. The map shows drivers live. They can change the date and switch Arabic/English.
3. Net = amount paid − SADAD fee − delivery fee. The SADAD fee is calculated from **Settings → Integrations → SADAD fee (% and fixed)**
   because the gateway does not report its fee: set those two numbers from your SADAD contract, otherwise the fee shows 0.
4. "Make a new link" or changing the password signs every partner out. Turning sharing off stops the link at once.
   Wrong passwords are limited (6 tries per 5 minutes) and every sign-in / failed try / opened order is listed on the admin page.
5. Route history: every driver position during an order is kept for a year (stray pings for a week).

## Issue log
Dashboard → Insights → **Issue log**. Report payment problems, bugs, delivery problems for the dashboard, storefront, customer / driver /
staff apps or the server; set severity, link an order or SADAD transaction, move it Open → Investigating → Solved (with a note).
`/admin/issues?order=AB100123` opens the form already linked to that order.

## Photo studio and AI descriptions
* **Write with AI** (product editor, and the staff app's *Add product*): Claude writes the short and full description in English and Arabic from the
  title. Needs the key in **Settings → Integrations → Claude API key** (create it at console.anthropic.com). It only uses what the title/category say.
* **Photo studio** (product → Photos → *Improve with AI* or *Ask for new designs*): staff request *clean background*, *studio white*,
  *lifestyle scene* or *new designs*. The request waits in the queue; edited pictures are shown for approval and only go live when someone presses
  **Use this photo** (or **Make cover**).
* **Who edits the photos:** the queue is edited by Claude with the Higgsfield tools. Tell Claude *"process the photo requests"*: it reads
  `GET /api/v1/admin/catalog/photo-requests?status=requested`, claims each, edits the picture, uploads the results to
  `POST /api/v1/admin/catalog/photo-requests/{id}/results`, and you approve them in the dashboard. (Higgsfield is reachable only through Claude,
  so the server cannot do this by itself.)
* **Staff app:** the branch staff app has an *Add product* button (for staff with catalogue-create permission): camera/gallery photos, title,
  price, category, AI description, and an option to send the first photo to the studio.
