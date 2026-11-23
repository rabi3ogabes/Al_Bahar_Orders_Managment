# Auctions, loyalty and CMS: deep review (Wave 6, items 6.4 and 6.5)

What the owner and the auctioneer see now: a blocked bidder really stops (their maximum stops bidding, their bids on live lots are struck out and
the lot goes back to the best remaining bid); a lot that is put up again after an unpaid winner starts a clean round; customers are told
"you were outbid" only when they really lost the lead; the public event feed never shows the auctioneer's private reasons; a "buy now" bid is never
charged above the buy-now price. Loyalty tiers follow the points that really stand; a CMS page cannot be published with a blank language.

## Findings

| Area | What was wrong | Severity | Fix | Test |
|---|---|---|---|---|
| Auctions: block bidder | Blocking only set a flag. The bidder's proxy maximum kept bidding, their bids stayed and the blocked bidder could still win the lot. Two copies of the code (API, console), no lock, no actor on the audit row | High | `LotService::blockBidder` (permission `auctions.override`, lot locks, struck bids, recomputed leader, audit with actor); both entry points use it; `winningBid` and the second-chance offer skip blocked bidders | `stops a blocked bidder...`, `never picks a blocked bidder...`, `refuses to block...without the override permission`, `does not offer the second chance to a blocked bidder`, concurrency `blocking a bidder while bids arrive...` |
| Auctions: relist policy | After "relist" the old round's bids stayed accepted, so the next close picked an old bid as winner with no new bid; the extension counter carried over; a closed auction never reopened | High | Old bids are struck out, counters reset, auction goes back to live | `relisting an unpaid lot starts a clean round...` |
| Auctions: public feed | Auctioneer reasons (pause, cancel, extend, retract) were readable on the public lot channel and the replay endpoint | Medium | `publicPayload` also drops `reason` | `keeps admin reasons off the public event feed` |
| Auctions: outbid message | A leader whose own proxy took the lead straight back still got "you were outbid"; several messages per request | Medium | One message per displaced bidder, none for the final leader | `does not tell a leader they were outbid...`, `still tells a plain leader...` |
| Auctions: anti-sniping bound | `max_extensions` empty meant unlimited extensions | Medium | Platform cap `platform.auctions.hard_max_extensions` (100) applies always | `caps anti-sniping extensions...` (mutation checked: cap removed, test fails) |
| Auctions: buy now | A bid above the buy-now price was charged in full; buy now was impossible when price + increment passed the buy-now price | Medium | A bid at or above buy-now closes the lot at exactly the buy-now price and skips the increment check | `closes a buy-now bid at the buy-now price...`, `accepts buy-now even when...` |
| Auctions: audit | The second-chance offer left no audit row | Low | `auction.second_chance_offered` | `records a second-chance offer in the audit log` |
| Loyalty: tier | Referral rewards and staff credits raised lifetime points without re-checking the tier; a cancelled or refunded order kept its points in the lifetime total (order, cancel, repeat climbs the tier) | Medium | Tier re-checked on award and adjust; reversal takes the order's points off lifetime | `LoyaltyReviewTest` |
| CMS: pages | A page could be published with an empty body in one language (blank page); the API returned the empty Arabic body instead of falling back to English | Medium | Publishing requires both bodies; API falls back to English when the requested body is empty | `CmsReviewTest` (two tests) |

## Checked and fine

- Bid numbers are gap-free under the lot row lock (existing race test) and now also for simultaneous proxy maximums.
- Exact closing second: a bid at `ends_at` is closed (server time, `at < ends_at`); anti-sniping starts at the window edge inclusive.
- Equal maximums: earliest wins; a raise keeps priority time; lowering a maximum is refused.
- Rejected attempts are stored and replayed with the same idempotency key; bids are rate limited (`throttle:bids`).
- Stock is reserved when a lot opens and released when unsold, cancelled or defaulted; winner checkout converts the hold; the winner price is the bid amount.
- Public payloads never carry reserve prices or other bidders' maximums; bidders appear as paddle numbers; `server_time` is sent so apps use the server clock.
- Deposits: bidding needs a paid deposit; a defaulted winner's deposit is flagged for finance review, never auto-forfeited.
- Scheduler: `auctions:tick` every 5 s, `withoutOverlapping`, idempotent (state checks under row locks); default of unpaid winners rechecks status under lock.
- Loyalty: points earn on paid goods net of discounts and delivery, when the order completes, once per order; redemption locks the account row and is capped by balance and `max_redeem_percent`, so two tabs cannot spend the same points; cancellation and full refund reverse (redeemed returned, earned clawed back, never below zero, idempotent); expiry is FIFO and idempotent (`remaining` set to 0); staff adjustments need `loyalty.edit`, a reason, and are audited.
- CMS: page HTML is sanitised on save (script removed), slugs are unique and lower-case, every action checks `cms.*`, banner and section edits flush the home cache (and the feed cache lives 60 s, so a schedule end is honoured within a minute), both languages required for titles.

## Design note

- Block bidder strikes bids on open or paused lots only; bids on closed lots stay as history, but a blocked bidder never wins (`winningBid`).
- Struck bids use status `retracted` (same as an auctioneer retraction) so history stays visible in the console.
- Buy now: price is the buy-now price, not the typed amount (safest for the customer). Proxy bids never trigger buy now.
- Outbid notices: sent after commit, one per displaced bidder, amount = the amount that displaced them. Auction messages are transactional (not held by quiet hours); the customer can mute them per channel in the app.
- Hard extension cap 100: reversible in `config/platform.php`.
- Lifetime points = points of orders that still stand plus referral and staff credits; thresholds stay classic 0, silver 10 000, gold 40 000, platinum 100 000.
- Not changed: partial refunds do not claw back points (only full refunds and cancellations do; the order total shrinks through adjustments, which return redeemed points).
