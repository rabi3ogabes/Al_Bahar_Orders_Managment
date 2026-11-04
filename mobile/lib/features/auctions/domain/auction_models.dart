import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/features/checkout/domain/checkout_models.dart';
import 'package:flutter/foundation.dart';

/// `AuctionCard` (lists & home sections).
@immutable
class AuctionSummary {
  const AuctionSummary({
    required this.id,
    required this.title,
    required this.status,
    this.description,
    this.imageUrl,
    this.startsAt,
    this.endsAt,
    this.lotsCount = 0,
    this.type,
    this.serverTime,
  });

  factory AuctionSummary.fromJson(JsonMap json) => AuctionSummary(
    id: asString(json['id']),
    title: asString(json['title'] ?? json['name']),
    status: asString(json['status'], 'upcoming'),
    description: asStringOrNull(json['description']),
    imageUrl: asStringOrNull(json['image_url'] ?? asMapOrNull(json['image'])?['url']),
    startsAt: asDate(json['starts_at']),
    endsAt: asDate(json['ends_at']),
    lotsCount: asInt(json['lots_count'] ?? asList(json['lots']).length),
    type: asStringOrNull(json['type']),
    serverTime: asDate(json['server_time']),
  );

  final String id;
  final String title;

  /// `upcoming` | `live` | `completed` (or `scheduled`/`closed`).
  final String status;
  final String? description;
  final String? imageUrl;
  final DateTime? startsAt;
  final DateTime? endsAt;
  final int lotsCount;
  final String? type;
  final DateTime? serverTime;

  bool get isLive => status == 'live' || status == 'open';
  bool get isUpcoming => status == 'upcoming' || status == 'scheduled';
}

@immutable
class AuctionRegistration {
  const AuctionRegistration({required this.status, this.paddleNumber, this.depositRequired, this.depositPayment});

  factory AuctionRegistration.fromJson(JsonMap json) => AuctionRegistration(
    status: asString(json['status'], 'registered'),
    paddleNumber: asStringOrNull(json['paddle_number']),
    depositRequired: Money.maybe(json['deposit_required']),
    depositPayment: json['deposit_payment'] is Map ? PaymentAction.fromJson(asMap(json['deposit_payment'])) : null,
  );

  /// e.g. `registered`, `approved`, `pending`, `pending_deposit`, `rejected`.
  final String status;
  final String? paddleNumber;
  final Money? depositRequired;
  final PaymentAction? depositPayment;

  bool get canBid => status == 'registered' || status == 'approved' || status == 'active';
}

@immutable
class Auction {
  const Auction({
    required this.summary,
    required this.lots,
    this.termsHtml,
    this.registration,
    this.requiresRegistration = true,
    this.depositRequired,
  });

  factory Auction.fromJson(JsonMap json) {
    final termsRaw = json['terms'];
    return Auction(
      summary: AuctionSummary.fromJson(json),
      lots: mapList(json['lots'], Lot.fromJson),
      termsHtml: termsRaw is Map ? asStringOrNull(termsRaw['body']) : asStringOrNull(termsRaw),
      registration: json['my_registration'] is Map ? AuctionRegistration.fromJson(asMap(json['my_registration'])) : null,
      requiresRegistration: asBool(json['requires_registration'], true),
      depositRequired: Money.maybe(json['deposit_required']),
    );
  }

  final AuctionSummary summary;
  final List<Lot> lots;
  final String? termsHtml;
  final AuctionRegistration? registration;
  final bool requiresRegistration;
  final Money? depositRequired;

  String get id => summary.id;
  bool get canBid => !requiresRegistration || (registration?.canBid ?? false);
}

/// Authoritative lot state (`GET /auctions/lots/{id}`).
@immutable
class Lot {
  const Lot({
    required this.id,
    required this.lotNumber,
    required this.title,
    required this.status,
    required this.minNextBid,
    required this.lastSequence,
    this.auctionId,
    this.description,
    this.imageUrl,
    this.quantity = 1,
    this.startingPrice,
    this.currentPrice,
    this.minIncrement,
    this.buyNowPrice,
    this.reserveMet,
    this.bidCount = 0,
    this.startsAt,
    this.endsAt,
    this.extensionCount = 0,
    this.serverTime,
    this.myStatus,
    this.myMaxAutoBid,
    this.isWatching = false,
  });

  factory Lot.fromJson(JsonMap json) => Lot(
    id: asString(json['id']),
    auctionId: asStringOrNull(json['auction_id'] ?? asMapOrNull(json['auction'])?['id']),
    lotNumber: asInt(json['lot_number']),
    title: asString(json['title']),
    description: asStringOrNull(json['description']),
    imageUrl: asStringOrNull(json['image_url']),
    quantity: asInt(json['quantity'], 1),
    status: asString(json['status'], 'scheduled'),
    startingPrice: Money.maybe(json['starting_price']),
    currentPrice: Money.maybe(json['current_price']),
    minNextBid: Money.maybe(json['min_next_bid']) ?? Money.maybe(json['starting_price']) ?? Money.zero,
    minIncrement: Money.maybe(json['min_increment']),
    buyNowPrice: Money.maybe(json['buy_now_price']),
    reserveMet: asBoolOrNull(json['reserve_met']),
    bidCount: asInt(json['bid_count']),
    lastSequence: asInt(json['last_sequence']),
    startsAt: asDate(json['starts_at']),
    endsAt: asDate(json['ends_at']),
    extensionCount: asInt(json['extension_count']),
    serverTime: asDate(json['server_time']),
    myStatus: asStringOrNull(json['my_status']),
    myMaxAutoBid: Money.maybe(json['my_max_auto_bid']),
    isWatching: asBool(json['is_watching']),
  );

  final String id;
  final String? auctionId;
  final int lotNumber;
  final String title;
  final String? description;
  final String? imageUrl;
  final int quantity;

  /// `scheduled` | `open` | `closing` | `closed` | `sold` | `unsold` | `cancelled`.
  final String status;
  final Money? startingPrice;
  final Money? currentPrice;
  final Money minNextBid;
  final Money? minIncrement;
  final Money? buyNowPrice;
  final bool? reserveMet;
  final int bidCount;
  final int lastSequence;
  final DateTime? startsAt;
  final DateTime? endsAt;
  final int extensionCount;
  final DateTime? serverTime;

  /// `null` | `leading` | `outbid` | `won` | `lost`.
  final String? myStatus;
  final Money? myMaxAutoBid;
  final bool isWatching;

  bool get isOpen => status == 'open' || status == 'closing';
  bool get isFinished => const {'closed', 'sold', 'unsold', 'cancelled'}.contains(status);
  Money get displayPrice => currentPrice ?? startingPrice ?? minNextBid;

  /// Applies a public broadcast payload (which omits `my_*` fields) while
  /// preserving the customer-specific state we already know.
  Lot mergePublic(Lot incoming) => Lot(
    id: incoming.id.isEmpty ? id : incoming.id,
    auctionId: incoming.auctionId ?? auctionId,
    lotNumber: incoming.lotNumber == 0 ? lotNumber : incoming.lotNumber,
    title: incoming.title.isEmpty ? title : incoming.title,
    description: incoming.description ?? description,
    imageUrl: incoming.imageUrl ?? imageUrl,
    quantity: incoming.quantity,
    status: incoming.status,
    startingPrice: incoming.startingPrice ?? startingPrice,
    currentPrice: incoming.currentPrice ?? currentPrice,
    minNextBid: incoming.minNextBid,
    minIncrement: incoming.minIncrement ?? minIncrement,
    buyNowPrice: incoming.buyNowPrice ?? buyNowPrice,
    reserveMet: incoming.reserveMet ?? reserveMet,
    bidCount: incoming.bidCount,
    lastSequence: incoming.lastSequence > lastSequence ? incoming.lastSequence : lastSequence,
    startsAt: incoming.startsAt ?? startsAt,
    endsAt: incoming.endsAt ?? endsAt,
    extensionCount: incoming.extensionCount,
    serverTime: incoming.serverTime ?? serverTime,
    myStatus: myStatus,
    myMaxAutoBid: myMaxAutoBid,
    isWatching: isWatching,
  );

  Lot copyWith({String? myStatus, Money? Function()? myMaxAutoBid, bool? isWatching, int? lastSequence}) => Lot(
    id: id,
    auctionId: auctionId,
    lotNumber: lotNumber,
    title: title,
    description: description,
    imageUrl: imageUrl,
    quantity: quantity,
    status: status,
    startingPrice: startingPrice,
    currentPrice: currentPrice,
    minNextBid: minNextBid,
    minIncrement: minIncrement,
    buyNowPrice: buyNowPrice,
    reserveMet: reserveMet,
    bidCount: bidCount,
    lastSequence: lastSequence ?? this.lastSequence,
    startsAt: startsAt,
    endsAt: endsAt,
    extensionCount: extensionCount,
    serverTime: serverTime,
    myStatus: myStatus ?? this.myStatus,
    myMaxAutoBid: myMaxAutoBid != null ? myMaxAutoBid() : this.myMaxAutoBid,
    isWatching: isWatching ?? this.isWatching,
  );
}

@immutable
class BidEntry {
  const BidEntry({required this.bidNumber, required this.amount, required this.bidder, this.placedAt, this.isMine = false});

  factory BidEntry.fromJson(JsonMap json) {
    final bidder = asString(json['bidder'], '');
    return BidEntry(
      bidNumber: asInt(json['bid_number'] ?? json['sequence']),
      amount: Money.maybe(json['amount']) ?? Money.zero,
      bidder: bidder,
      placedAt: asDate(json['placed_at'] ?? json['created_at']),
      isMine: asBool(json['is_mine']) || bidder == 'You',
    );
  }

  final int bidNumber;
  final Money amount;

  /// Masked public identity ("Bidder 12") or "You".
  final String bidder;
  final DateTime? placedAt;
  final bool isMine;
}

/// Real-time / replayed lot event.
@immutable
class LotEvent {
  const LotEvent({required this.sequence, required this.type, this.lot, this.bid});

  factory LotEvent.fromJson(JsonMap json, {String? type}) => LotEvent(
    sequence: asInt(json['sequence']),
    type: type ?? asString(json['type'] ?? json['event'], 'bid.accepted'),
    lot: json['lot'] is Map ? Lot.fromJson(asMap(json['lot'])) : null,
    bid: json['bid'] is Map ? BidEntry.fromJson(asMap(json['bid'])) : null,
  );

  final int sequence;

  /// `bid.accepted` | `lot.extended` | `lot.status`.
  final String type;
  final Lot? lot;
  final BidEntry? bid;
}

/// `GET /me/auctions/bids` row (shape assumed — see API_ASSUMPTIONS.md).
@immutable
class MyBid {
  const MyBid({required this.id, required this.amount, required this.lot, this.placedAt, this.isAuto = false});
  factory MyBid.fromJson(JsonMap json) => MyBid(
    id: asString(json['id']),
    amount: Money.maybe(json['amount']) ?? Money.zero,
    lot: Lot.fromJson(asMap(json['lot'])),
    placedAt: asDate(json['placed_at'] ?? json['created_at']),
    isAuto: asBool(json['is_auto']),
  );
  final String id;
  final Money amount;
  final Lot lot;
  final DateTime? placedAt;
  final bool isAuto;
}

/// `GET /me/auctions/wins` row (shape assumed).
@immutable
class AuctionWin {
  const AuctionWin({required this.id, required this.lot, required this.amount, this.status, this.payBy, this.orderId});
  factory AuctionWin.fromJson(JsonMap json) => AuctionWin(
    id: asString(json['id']),
    lot: Lot.fromJson(asMap(json['lot'])),
    amount: Money.maybe(json['amount'] ?? json['winning_amount']) ?? Money.zero,
    status: asStringOrNull(json['status']),
    payBy: asDate(json['checkout_deadline'] ?? json['pay_by']),
    orderId: asStringOrNull(json['order_id'] ?? asMapOrNull(json['order'])?['id']),
  );
  final String id;
  final Lot lot;
  final Money amount;

  /// e.g. `pending_checkout`, `paid`, `forfeited`.
  final String? status;
  final DateTime? payBy;
  final String? orderId;

  bool get needsCheckout => orderId == null && (status == null || status == 'pending_checkout' || status == 'pending');
}
