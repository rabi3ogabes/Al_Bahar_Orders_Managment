import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/push/push_service.dart';
import 'package:albahar_customer/core/router/app_router.dart';
import 'package:albahar_customer/core/router/deep_links.dart';
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fakes.dart';

void main() {
  group('DeepLinks.toLocation', () {
    final cases = <String, String?>{
      'albahar://products/123': '/products/123',
      'albahar://orders/abc-1': '/orders/abc-1',
      'albahar://auctions/a1': '/auctions/a1',
      'albahar://auctions/lots/l9': '/auctions/lots/l9',
      'albahar://categories/sofas': '/categories/sofas',
      'albahar://payments/p1/return': '/payments/p1/return',
      'albahar://support/t7': '/support/t7',
      'https://albahar.qa/app/products/oslo-sofa': '/products/oslo-sofa',
      'https://www.albahar.qa/app/orders/9/': '/orders/9',
      'https://albahar.qa/app/products?featured=1': '/products?featured=1',
      'https://albahar.qa/app': '/home',
      'https://albahar.qa/blog/1': null,
      'https://evil.example/app/products/1': null,
      'albahar://unknown/thing': null,
      '/products/5': '/products/5',
    };
    cases.forEach((input, expected) {
      test('$input → $expected', () => expect(DeepLinks.fromString(input), expected));
    });

    test('share url uses universal link form', () {
      expect(DeepLinks.productShareUrl('oslo'), 'https://albahar.qa/app/products/oslo');
    });
  });

  group('notification payload → route', () {
    test('deep_link wins', () {
      expect(
        notificationLocation({
          'event': 'order.ready_for_pickup',
          'deep_link': 'albahar://orders/o1',
          'notification_id': 'n1',
        }),
        '/orders/o1',
      );
    });
    test('falls back to event ids', () {
      expect(notificationLocation({'event': 'auction.outbid', 'lot_id': 'L1'}), '/auctions/lots/L1');
      expect(notificationLocation({'event': 'auction.starting', 'auction_id': 'A1'}), '/auctions/A1');
      expect(notificationLocation({'event': 'order.shipped', 'order_id': 'O1'}), '/orders/O1');
      expect(notificationLocation({'event': 'support.reply', 'ticket_id': 'T1'}), '/support/T1');
    });
    test('unknown payload opens the notification centre', () {
      expect(notificationLocation({'event': 'misc', 'deep_link': 'https://evil.example/x'}), '/notifications');
      expect(notificationLocation(const {}), '/notifications');
    });
    test('categories for preference checks', () {
      expect(notificationCategory('marketing.new_collection'), 'marketing');
      expect(notificationCategory('auction.outbid'), 'auction');
      expect(notificationCategory('order.confirmed'), 'transactional');
    });
  });

  group('router redirects', () {
    String? r(String uri, {bool locale = true, bool onboarding = true, bool auth = false}) =>
        resolveRedirect(uri: Uri.parse(uri), localeChosen: locale, onboardingDone: onboarding, authenticated: auth);

    test('custom scheme links are normalised', () {
      expect(r('albahar://products/1'), '/products/1');
      expect(r('/app/orders/2'), '/orders/2');
      expect(r('albahar://nope'), '/home');
    });
    test('language → onboarding → auth gates', () {
      expect(r('/home', locale: false), '/language?from=%2Fhome');
      expect(r('/home', onboarding: false), '/onboarding?from=%2Fhome');
      expect(r('/orders/5'), '/login?from=%2Forders%2F5');
      expect(r('/products/5/reviews/new'), startsWith('/login'));
      expect(r('/products/5'), isNull, reason: 'catalog is public');
      expect(r('/cart'), isNull, reason: 'cart tab shows its own sign-in prompt');
    });
    test('signed-in users leave login for the original target', () {
      expect(r('/login?from=%2Forders%2F5', auth: true), '/orders/5');
      expect(r('/login/otp', auth: true), '/home');
      expect(r('/orders/5', auth: true), isNull);
    });
    test('protected path matcher', () {
      expect(isProtectedPath('/checkout'), isTrue);
      expect(isProtectedPath('/settings'), isFalse);
      expect(isProtectedPath('/settings/delete-account'), isTrue);
      expect(isProtectedPath('/auctions/wins/1/checkout'), isTrue);
      expect(isProtectedPath('/auctions/lots/1'), isFalse);
    });
  });

  group('PushService', () {
    test('tap navigates (or queues until router is ready) and marks read', () async {
      final h = await TestHarness.create();
      final push = h.read(pushServiceProvider);
      push.debugHandleTap({'deep_link': 'albahar://orders/o1'});
      expect(push.takePendingLocation(), '/orders/o1');

      final visited = <String>[];
      final opened = <String>[];
      push
        ..onNavigate = visited.add
        ..onOpened = opened.add;
      push.debugHandleTap({'event': 'auction.outbid', 'lot_id': 'L2', 'notification_id': 'n9'});
      expect(visited, ['/auctions/lots/L2']);
      expect(opened, ['n9']);
    });

    test('foreground banners respect cached push preferences', () async {
      final h = await TestHarness.create();
      final local = FakeLocalNotifier();
      final push = PushService(
        messaging: NoopPushMessaging(),
        local: local,
        api: h.read(apiClientProvider),
        prefs: h.read(appPreferencesProvider),
        enabled: true,
      );
      await h.read(appPreferencesProvider).setPushCategoryPrefs({'marketing': false, 'transactional': true});
      push
        ..debugHandleForeground(const PushMessage(data: {'event': 'marketing.sale'}, title: 'Sale'))
        ..debugHandleForeground(const PushMessage(data: {'event': 'order.confirmed'}, title: 'Order'));
      await Future<void>.delayed(Duration.zero);
      expect(local.shown.map((m) => m.title), ['Order']);
    });

    test('register posts the device token; unregister deletes it', () async {
      final h = await TestHarness.create(
        tokens: AuthTokens.fromJson(tokens('A', 'R')),
        handler: (r) => ok({'ok': true}),
      );
      final messaging = _FakeMessaging('fcm-token-1');
      final push = PushService(
        messaging: messaging,
        local: FakeLocalNotifier(),
        api: h.read(apiClientProvider),
        prefs: h.read(appPreferencesProvider),
        enabled: true,
      );
      await push.start();
      await push.register();
      final post = h.adapter.where('POST', '/me/devices').single;
      expect(post.body['push_token'], 'fcm-token-1');
      expect(post.body['platform'], isIn(['android', 'ios', 'web']));
      expect(post.body['device_id'], isNotEmpty);

      await push.unregister();
      final del = h.adapter.where('DELETE', '/me/devices').single;
      expect(del.body, {'push_token': 'fcm-token-1'});
      expect(h.read(appPreferencesProvider).registeredPushToken, isNull);
    });
  });
}

class _FakeMessaging extends NoopPushMessaging {
  _FakeMessaging(this.token);
  final String token;
  @override
  Future<bool> initialize() async => true;
  @override
  Future<bool> requestPermission() async => true;
  @override
  Future<String?> getToken() async => token;
}
