# Real-time order status: diagnosis and fix

Owner's problem: the delivery status of an order does not update in real time between the customer, driver and branch staff apps.
Measured on a local copy of the stack (Postgres + Redis queue + Reverb + the same queue worker command as production,
`queue:work redis --queue=default,notifications,exports`), with three real WebSocket clients (pusher-js) that sign in with their own bearer
tokens: `backend/tests/e2e-realtime/run.mjs`. Latency = request sent -> message received.

## Root causes, most serious first

| # | Defect | Evidence | Fix |
|---|--------|----------|-----|
| 1 | **The release apps never connect to the socket.** The CI build (`android-apk.yml`) passes only `FLAVOR=prod`, `API_BASE_URL` and `PUSH_ENABLED=false`. `AppConfig` then used the built-in prod defaults: host `ws.albahar.qa`, key `albahar`. That host is not the server, so all three apps (and the Flutter web build, which only has `API_BASE_URL=/api/v1`) fell back to slow polling: customer 30 s, staff 60 s, driver 60 s, customer order window never. | `mobile/lib/core/config/app_config.dart`, `.github/workflows/android-apk.yml`, `docker/php/Dockerfile` | The apps now ask the server where its socket is (`GET /api/v1/realtime-config`: key, scheme, host, port, path, poll interval) every time they connect. Without a REVERB_* define the socket also defaults to the API host. No workflow or Dockerfile change is needed. |
| 2 | **Messages were queued behind a sleeping worker.** Every broadcast was a queued job; `queue:work` sleeps up to 3 s when idle. Measured 1.0 - 2.9 s per message (median 2.06 s). | table below | Messages are sent straight to Reverb after the response has gone out (`defer()`), after the commit, never waiting for a worker. Median 89 ms, slowest 334 ms. |
| 3 | **Missing events.** Placing an order told nobody (staff never got the new-order alert their app was written for). Accepted/preparing/ready told the customer nothing (the order's own status stays "processing"). A cancellation request told nobody. Re-assigning a driver told neither driver. | table below | Messages now come from the models themselves (`OrderRealtime` + model hooks), so every change by anybody (customer, staff app, dashboard, driver, courier webhook, payment) is covered. |
| 4 | **Messages could lie.** The delivery ping fired inside the transaction with the order status read before the order status was recomputed, and was sent even when the transaction rolled back. Two messages for one change could arrive in either order. | `DeliveryService::ping()` (removed) | One flush per request after the commit, built from the committed state, with a per-order version `seq` (`orders.progress_seq`). |
| 5 | **Socket client fragility.** A refused private channel (`pusher:error 4009`) closed the whole socket for good; a failed channel authorisation was never retried; a socket that slept in the background was only noticed after about a minute; the staff and driver apps never reconnected on resume or when the network came back. | `mobile/lib/core/realtime/pusher_client.dart` | 4009 retries that channel with back-off; auth is retried; `probe()` on resume; one orchestrator (`LiveSync`) per screen group. |
| 6 | **Audience leak.** A delivery driver of a branch could join `private-branches.{id}` and hear every order and every colleague's position. | channel rule | `BranchChannel` now needs `orders.view` for that branch; drivers use their own channel. |

## Latency, before and after (first message heard, ms)

Before (`SETTLE=3600 MODE=legacy`), after (`SETTLE=500`). "none" = nobody was told.

| Transition | Before: customer / staff / driver | After: customer / staff / driver |
|---|---|---|
| customer places the order | none / none / - | 298 / 302 / - |
| staff accepts | 2765 / 2763 / - | 82 / 84 / - |
| staff starts preparing | **none** / 2060 / - | 45 / 49 / - |
| staff marks ready | **none** / 63 / - | 59 / 61 / - |
| staff assigns the driver | 1062 / 1064 / 1064 | 58 / 60 / 62 |
| driver accepts | 2058 / 2061 / 2061 | 70 / 72 / 74 |
| driver picks up | 2054 / 2056 / 2057 | 125 / 127 / 130 |
| driver out for delivery | 2077 / 2079 / 2079 | 84 / 87 / 91 |
| driver delivers (COD + proof) | 2057 / 2059 / 2059 | 107 / 109 / 112 |
| customer cancels (auto-approved) | 2901 / 2898 / - | 94 / 96 / - |
| driver reports failed delivery | 1240 / 1242 / 1242 | 86 / 89 / 93 |
| staff receives it back | 2058 / 2061 / 2061 | 79 / 82 / 91 |
| customer asks to cancel (needs approval) | none / none / - | 51 / 53 / - |

Median first-message latency: 2059 ms before, 89 ms after. Slowest: 2901 ms before, 334 ms after (placing an order: the number includes the
checkout request itself). Another branch's staff heard nothing in either run. Channel authorisation (bearer tokens of customer, staff and
driver all authorise their own channel; every foreign channel is refused): 5/5 deny checks pass.

## The contract (one shape, three audiences)

Event names are unchanged (`order.updated`, `dispatch.updated`), so installed apps keep working; the payloads gained fields.

| Audience | Channel | Class | Fields |
|---|---|---|---|
| Customer | `private-users.{id}` | `OrderUpdated` | order_id, number, status, status_label, payment_status, fulfillment_id, fulfillment_status, delivery_status, fulfillments[{id,type,status,delivery_status}], seq, at |
| Branch staff | `private-branches.{id}` (+ `private-fleet` for delivery changes) | `DispatchUpdated` | order_id, number, kind, order_level, status, order_status, fulfillment_id/type/status, stage, delivery_id/status, driver_id, driver_name, seq, at |
| Driver | `private-drivers.{id}` | `DriverJobUpdated` | order_id, number, kind, status, fulfillment_id, delivery_id, delivery_status, assigned, seq, at (`assigned=false`, `status=unassigned` when the job was taken away; never names the new driver) |

`seq` grows by one per change of an order and is also in `GET /orders`, `GET /orders/{id}`, the staff queue rows and the driver jobs
(`order_id`, `seq`). All messages implement `ShouldBroadcastNow` + `ShouldDispatchAfterCommit`.

## What is in place and what is not

Done: backend contract, publisher, observers, channel rules, `/realtime-config`, REST `seq`, the shared mobile core (socket client fixes, settings
discovery, `SeqGate`, `LiveSync`, `LiveLinkBadge`, `ops_board.dart` overlay rules), and the driver app wired end to end (messages move, finish or remove a job
at once; Live / Reconnecting / Offline badge; 15 s poll only while the socket is down; read on reconnect, resume and network back).
Not done in this pass (the pure rules exist and are tested, the wiring is not): branch staff app orchestrator (it keeps its reload-on-message,
which now receives the new messages, new-order alerts included), customer app orders list / detail / tracking overlay and badge, storefront `seq` handling.
