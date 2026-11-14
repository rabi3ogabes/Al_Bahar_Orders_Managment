import 'package:albahar_customer/core/models/paginated.dart';
import 'package:albahar_customer/core/network/api_client.dart';
import 'package:albahar_customer/core/network/interceptors.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/core/realtime/realtime_settings.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/features/auctions/domain/auction_models.dart';
import 'package:albahar_customer/features/checkout/domain/checkout_models.dart';
import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

class AuctionRepository {
  AuctionRepository(this._api);
  final ApiClient _api;

  Future<Paginated<AuctionSummary>> list(String status, {int page = 1}) =>
      _api.getPage('/auctions', AuctionSummary.fromJson, query: {'status': status, 'page': page});

  Future<Auction> auction(String id) => _api.getObject('/auctions/$id', Auction.fromJson);

  Future<Lot> lot(String lotId) => _api.getObject('/auctions/lots/$lotId', Lot.fromJson);

  /// Plain list (latest 100, newest first); not paginated.
  Future<List<BidEntry>> bids(String lotId) => _api.getList('/auctions/lots/$lotId/bids', BidEntry.fromJson);

  /// Event replay after reconnect; sorted by sequence ascending.
  Future<List<LotEvent>> events(String lotId, {required int afterSequence}) async {
    final data = await _api.get('/auctions/lots/$lotId/events', query: {'after_sequence': afterSequence});
    final events = mapList(data, LotEvent.fromJson)..sort((a, b) => a.sequence.compareTo(b.sequence));
    return events;
  }

  Future<AuctionRegistration> register(String auctionId) async => AuctionRegistration.fromJson(
    asMap(await _api.post('/auctions/$auctionId/register', data: {'accept_terms': true})),
  );

  /// Returns the authoritative lot after the bid.
  Future<({BidEntry? bid, Lot lot})> placeBid(String lotId, String amount, {required String idempotencyKey}) async {
    final data = asMap(
      await _api.post(
        '/auctions/lots/$lotId/bids',
        data: {'amount': amount},
        options: Idempotency.options(idempotencyKey),
      ),
    );
    return (
      // The bid echo has no `bidder`; it is always the caller's own bid.
      bid: data['bid'] is Map ? BidEntry.fromJson(asMap(data['bid'])).asMine() : null,
      lot: Lot.fromJson(asMap(data['lot'])),
    );
  }

  Future<void> setAutoBid(String lotId, String maxAmount) async =>
      _api.post('/auctions/lots/$lotId/auto-bid', data: {'max_amount': maxAmount});

  Future<void> cancelAutoBid(String lotId) async => _api.delete('/auctions/lots/$lotId/auto-bid');

  Future<void> watch(String lotId) async => _api.post('/auctions/lots/$lotId/watch');

  Future<void> unwatch(String lotId) async => _api.delete('/auctions/lots/$lotId/watch');

  Future<Paginated<MyBid>> myBids({int page = 1}) =>
      _api.getPage('/me/auctions/bids', MyBid.fromJson, query: {'page': page});

  /// `GET /me/auctions/{bids,watchlist,wins}` are plain (unpaginated) lists.
  Future<Paginated<Lot>> watchlist({int page = 1}) => _api.getPage(
    '/me/auctions/watchlist',
    (j) => Lot.fromJson(j['lot'] is Map ? asMap(j['lot']) : j),
    query: {'page': page},
  );

  Future<Paginated<AuctionWin>> wins({int page = 1}) =>
      _api.getPage('/me/auctions/wins', AuctionWin.fromJson, query: {'page': page});

  Future<PlacedOrder> checkoutWin(
    String winnerId, {
    required FulfillmentType fulfillmentType,
    String? addressId,
    String? branchId,
    required PaymentMethod paymentMethod,
    required String idempotencyKey,
  }) async => PlacedOrder.fromJson(
    asMap(
      await _api.post(
        '/me/auctions/wins/$winnerId/checkout',
        data: {
          'fulfillment_type': fulfillmentType.api,
          'address_id': ?addressId,
          'branch_id': ?branchId,
          'payment_method': paymentMethod.api,
        },
        options: Idempotency.options(idempotencyKey),
      ),
    ),
  );

  /// Laravel broadcasting auth for private channels.
  Future<String?> authorizeChannel(String url, String socketId, String channel) async {
    final data = await _api.dio.post<dynamic>(
      url,
      data: {'socket_id': socketId, 'channel_name': channel},
      options: Options(contentType: Headers.jsonContentType),
    );
    return asStringOrNull(asMap(data.data)['auth']);
  }
}

final auctionRepositoryProvider = Provider<AuctionRepository>((ref) => AuctionRepository(ref.watch(apiClientProvider)));

/// Where the socket is, asked of the server (see RealtimeSettingsLoader).
final realtimeSettingsLoaderProvider = Provider<RealtimeSettingsLoader>((ref) {
  final api = ref.watch(apiClientProvider);
  return RealtimeSettingsLoader(
    config: ref.watch(appConfigProvider),
    fetch: () async => asMap(await api.get('/realtime-config')),
  );
});

/// App-wide realtime client (lazy-connecting). It asks the server where its socket is every time it opens a connection, so the build
/// carries no socket address that could go stale (a missing REVERB_* define in a release build used to leave every app without live
/// updates).
final pusherClientProvider = Provider<PusherClient>((ref) {
  final config = ref.watch(appConfigProvider);
  final repo = ref.watch(auctionRepositoryProvider);
  final settings = ref.watch(realtimeSettingsLoaderProvider);
  final client = PusherClient(
    uriResolver: () async => (await settings.load()).uri,
    authorizer: (socketId, channel) => repo.authorizeChannel(config.broadcastingAuthUrl, socketId, channel),
  );
  ref.onDispose(client.dispose);
  return client;
});
