import 'package:albahar_customer/core/router/app_router.dart';
import 'package:albahar_customer/core/router/deep_links.dart';
import 'package:flutter_test/flutter_test.dart';

/// Every `deep_link` the server puts in a push (backend: DomainEventSubscriber, ReferralService, CampaignService, JourneyService,
/// MessageDelivery, SupportService and the live order card) must open the screen it is meant for.
void main() {
  group('where a tap on a push goes', () {
    for (final (link, place) in [
      ('albahar://orders/0190c2a0-1b2c', '/orders/0190c2a0-1b2c'),
      ('/orders/0190c2a0-1b2c', '/orders/0190c2a0-1b2c'), // the live order card
      ('albahar://auctions/lots/L9', '/auctions/lots/L9'),
      ('albahar://products/P7', '/products/P7'),
      ('albahar://support/T3', '/support/T3'),
      ('albahar://cart', '/cart'),
      ('albahar://home', '/home'),
      ('albahar://categories', '/categories'),
      ('albahar://wishlist', '/wishlist'),
      ('albahar://referral', '/invite'), // the reward push: the server's name for the invite screen
      ('albahar://invite', '/invite'),
      ('albahar://loyalty', '/loyalty'),
      ('albahar://my-auctions', '/my-auctions'),
      ('https://albahar.qa/app/orders/o1', '/orders/o1'),
    ]) {
      test('$link opens $place', () {
        expect(notificationLocation({'deep_link': link, 'event': 'x'}), place);
      });
    }

    test('a link the app does not know falls back to the event, then to the notifications', () {
      expect(
        notificationLocation({'deep_link': 'albahar://nowhere', 'event': 'order.confirmed', 'order_id': 'o5'}),
        '/orders/o5',
      );
      expect(notificationLocation({'deep_link': '', 'event': 'auction.outbid', 'lot_id': 'L1'}), '/auctions/lots/L1');
      expect(notificationLocation({'deep_link': 'javascript:alert(1)'}), '/notifications');
      expect(notificationLocation({'deep_link': 'https://evil.example/app/orders/1'}), '/notifications');
      expect(notificationLocation({}), '/notifications');
    });

    test('the places the invite, loyalty and auction links open are only for signed-in customers', () {
      for (final path in ['/invite', '/loyalty', '/my-auctions', '/orders/o1', '/notifications']) {
        expect(isProtectedPath(path), isTrue, reason: path);
      }
    });

    test('a link from outside the app (a campaign, a browser) is understood too', () {
      expect(DeepLinks.toLocation(Uri.parse('albahar://referral')), '/invite');
      expect(DeepLinks.toLocation(Uri.parse('https://albahar.qa/app/loyalty')), '/loyalty');
      expect(DeepLinks.toLocation(Uri.parse('albahar://unknown-place')), isNull);
    });
  });

  group('which preference a push belongs to', () {
    test(
      'mirrors the server: offers, staff messages and journeys are marketing, auctions their own, the rest orders',
      () {
        expect(notificationCategory('campaign'), 'marketing');
        expect(notificationCategory('stock.price_drop'), 'marketing');
        expect(notificationCategory('cart.abandoned'), 'marketing');
        expect(notificationCategory('staff.message'), 'marketing');
        expect(notificationCategory('journey.abandoned_cart'), 'marketing');
        expect(notificationCategory('auction.outbid'), 'auction');
        expect(notificationCategory('order.out_for_delivery'), 'transactional');
        expect(notificationCategory('payment.failed'), 'transactional');
        expect(notificationCategory(null), 'transactional');
      },
    );
  });
}
