import 'package:albahar_customer/core/network/api_client.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/features/deals/domain/deal_models.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// The phone's own clock. Overridden in tests so a countdown can be driven without waiting.
final dealDeviceNowProvider = Provider<DateTime Function()>((ref) => DateTime.now);

/// How far the server's clock is ahead of this phone (`server_time - device time`) as of the last deals answer. Every
/// countdown adds it to the phone's clock, so a wrong phone clock never shows a wrong deal time.
class DealSkew extends Notifier<Duration> {
  @override
  Duration build() => Duration.zero;

  void set(Duration skew) {
    if (skew != state) state = skew;
  }
}

final dealSkewProvider = NotifierProvider<DealSkew, Duration>(DealSkew.new);

class DealsRepository {
  DealsRepository(this._api, {required DateTime Function() now, required void Function(Duration skew) onSkew})
    : _now = now,
      _onSkew = onSkew;

  final ApiClient _api;
  final DateTime Function() _now;
  final void Function(Duration skew) _onSkew;

  /// `GET /deals`. [home] asks only for the deals the admin put on the home page; [limitProducts] (1 to 12) is how many
  /// products come with each deal.
  Future<DealsFeed> feed({bool home = false, int limitProducts = 8}) async {
    final body = asMap(
      await _api.getRaw('/deals', query: {'home': home ? true : null, 'limit_products': limitProducts.clamp(1, 12)}),
    );
    final feed = DealsFeed.fromJson(
      asMap(body['data']),
      deviceNow: _now(),
      fallbackServerTime: body['server_time'] ?? asMap(body['meta'])['server_time'],
    );
    if (feed.serverTime != null) _onSkew(feed.skew);
    return feed;
  }

  /// `GET /deals/{id}`: the deal and one page (24) of its products.
  Future<DealPage> detail(String id, {int page = 1}) async {
    final body = asMap(await _api.getRaw('/deals/${Uri.encodeComponent(id)}', query: {'page': page > 1 ? page : null}));
    final data = asMap(body['data']);
    final result = DealPage.fromJson(
      data,
      deviceNow: _now(),
      fallbackServerTime: body['server_time'] ?? asMap(body['meta'])['server_time'],
    );
    if (data['server_time'] != null || body['server_time'] != null) _onSkew(result.skew);
    return result;
  }
}

final dealsRepositoryProvider = Provider<DealsRepository>(
  (ref) => DealsRepository(
    ref.watch(apiClientProvider),
    now: ref.watch(dealDeviceNowProvider),
    onSkew: (skew) => ref.read(dealSkewProvider.notifier).set(skew),
  ),
);
