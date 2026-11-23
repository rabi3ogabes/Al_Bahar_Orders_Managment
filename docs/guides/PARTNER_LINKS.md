# Partner links (one link and password per partner)

Roadmap 6.1. Dashboard: **Insights → Partner statistics**.

## What the owner sees
- **Your partners**: a list with each partner's name, branches, status (Active, Locked for 15 minutes, Revoked, Ended), end date and last sign-in.
- **Add a partner** opens a side window: name, contact (only so you remember who it is), the branches they may see (or all), and an optional end date. After **Create link** a window shows the link and the password **once**. Copy both and send them. The password is stored only as a hash and cannot be shown again.
- Per partner: **Edit**, **New link and password** (the old ones stop at once; this also brings a revoked partner back), **Revoke** (stops at once, even for someone already signed in).
- **Old shared link**: the previous single link and password. It **keeps working until you switch it off** (the switch is on this page; nothing changes today). A banner recommends per-partner links.
- **Recent access**: who opened what. Partner rows show the partner's name and a short hash of the address (never the address itself).

## What a partner sees
The same statistics page as before, limited to their branches: the day's orders (items, fees, SADAD fee, net), the order detail, the live drivers, the recorded routes and the live order toasts. A revoked or ended link answers a friendly "This link is no longer active" (HTTP 403). Five wrong passwords lock that link for 15 minutes; a per-address limit also applies as before.

## What a partner never sees (per-partner links)
- No customer name and no phone number (the page shows "Customer" and the order number).
- Drop-off points are snapped to a **500 m grid** (`Geo::coarsen`): the point shown is the centre of the 500 m square the real point is in, so the real place is within about 350 m. This applies to the order's destination, the destination of a driver's current job, the stops and **every point of a recorded route** (consecutive points in the same square are folded into one). Driver positions on the live map are real (that is what the live map is for) and carry the driver's first name only.
- Orders and drivers of other branches.

## Design note
- **Old link unchanged**: it still shows names and exact places, because the owner asked that nothing breaks until they act. It has no scope (null) in `PartnerReport`; a per-partner link always passes a `PartnerScope`.
- **Branches**: a partner has a list of branches or "all branches" (a flag, so a branch added later is included only for "all"). A partner with no branches sees nothing. An order is visible when any of its parts (fulfillments) belongs to one of the branches.
- **Token**: 40 random characters, unique, stored as is (the link is not the secret that protects data; the password is, and it is hashed with bcrypt). The browser session stores a fingerprint of id + token + password hash, so a new link or password signs the partner out, and the database is read on every request so a revoke is immediate.
- **Lockout** is per link (5 wrong passwords, 15 minutes) whoever types them; someone who knows a link can therefore lock it for 15 minutes but never get in. Per-address throttling (6 tries / 5 min) is kept.
- **Logging**: events `unlocked`, `failed`, `locked_out`, `opened`, `viewed_day`, `viewed_order`, `viewed_live`, `viewed_routes`. The pages that poll (the day, the live map, routes) log at most once per 15 minutes per partner and address; opening an order is always logged. The address is stored as HMAC-SHA256 with the app key (`ip_hash`).
- **Audit log**: `partner.created`, `partner.updated`, `partner.regenerated`, `partner.revoked`, plus the old link's `partners.*` actions.
- **Numbers**: the day totals are computed from the visible orders only (branch-limited). Net = paid − SADAD fee − delivery fee, as before; at most 1000 orders per day are read.
- Tables: `partners`, `partner_branches`, and `partner_access_logs` gained `partner_id` and `ip_hash`.
