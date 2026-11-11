# Phase 09 — Scheduled Auctions, Live Auctions and Real-Time Bidding

## Objective
Implement a secure, server-authoritative auction engine supporting scheduled auctions and live auction events/lots.

## Auction types
1. Scheduled auction: opens and closes automatically according to configured timestamps.
2. Live auction: an auctioneer starts and closes lots within a live event; bids update in real time.

## Admin auction management
- Create/edit/draft/publish/schedule auctions.
- Arabic/English title and description.
- Images/videos.
- Link existing product/variant or auction-only lot.
- Lot number and order.
- Starting price.
- Reserve price (private).
- Minimum increment.
- Optional Buy Now.
- Start/end times.
- Branch eligibility.
- Quantity.
- Bidder registration requirements.
- Verified mobile requirement.
- Optional deposit.
- Terms and conditions.
- Winner payment deadline.
- Delivery/pickup eligibility.
- Automatic extension settings.
- Pause/resume/cancel with permissions and audit.

## Customer auction experience
- Upcoming, live and completed auctions.
- Auction details.
- Countdown.
- Current highest bid.
- Minimum next bid.
- Bid history according to privacy policy.
- Place bid.
- Watchlist.
- Bidder registration.
- Optional deposit payment.
- Outbid notifications.
- Winner/loser notifications.
- My bids and auction history.
- Winner payment and fulfillment.

## Bid validation
The server must validate:
- Auction/lot is open.
- Bidder is eligible and registered.
- Required deposit is valid.
- User is not blocked.
- Bid meets minimum increment.
- Bid does not violate configured rules.
- Bid is not a duplicate idempotent request.
- Auction end time has not passed according to server time.

## Concurrency and integrity
- Use a database transaction and row-level lock on auction/lot state.
- Assign monotonically increasing bid sequence within each lot.
- Persist accepted bid before broadcasting.
- Use unique idempotency key per bidder submission.
- Simultaneous bids must have deterministic ordering.
- Reject late bids.
- Do not rely on client countdown.
- Use Redis/WebSockets for distribution, but PostgreSQL is the authoritative bid ledger.
- Reconnect clients by fetching current authoritative state and last bid sequence.

## Automatic bidding
- Customer sets maximum bid.
- Server places proxy bids according to increment rules.
- Define tie policy (earliest valid maximum or other explicit policy).
- Never expose another bidder's maximum.
- Prevent the proxy engine from exceeding the bidder's maximum.
- Test competing auto-bids thoroughly.

## Anti-sniping
- Configure extension window and extension duration per auction/lot.
- A valid bid in the configured closing window extends the end time.
- Persist every extension event.
- Prevent infinite extension only if a maximum extension policy is configured.
- Server time is authoritative.

## Winner and payment workflow
- Close lot atomically.
- Check reserve price.
- Determine winner from accepted bids.
- Create winner record.
- Start payment deadline.
- Notify winner.
- Create auction order/payment intent after required confirmation.
- Allocate stock/lot quantity.
- On default, apply published auction terms and configurable next-bidder/relist policy.
- Record forfeiture/refund of deposit only according to agreed terms and legal review.

Auction COD must be disabled by default unless explicitly enabled by admin policy. Auction-specific terms and payment deadline must be displayed before bidding.

## Live event operations
- Auctioneer dashboard.
- Start/pause/resume lot.
- Close lot.
- Real-time bid feed.
- Moderator controls.
- Admin override requires reason and audit log.
- Reconnection and event replay.
- Connection presence is informational and not proof of a valid bid.

## Database tables
- auctions
- auction_translations
- auction_lots
- auction_products
- auction_branches
- auction_bidders
- auction_bids
- auction_auto_bids
- auction_extensions
- auction_winners
- auction_deposits
- auction_lot_events

## Tests
- Bid below minimum.
- Bid after close.
- Simultaneous bids.
- Duplicate bid request.
- Auto-bid competition and tie.
- Anti-sniping extension.
- Reserve not met.
- Winner payment success/expiry.
- Unpaid winner policy.
- WebSocket reconnect and event replay.
- Admin pause/cancel audit.

## Acceptance criteria
- Scheduled and live auctions work.
- PostgreSQL ledger is authoritative.
- No duplicate or invalid accepted bids.
- Concurrent bidding is deterministic.
- Winner calculation is reproducible from accepted bids.
- Auction payment and fulfillment are traceable.
