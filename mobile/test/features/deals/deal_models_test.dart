import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/deals/domain/deal_countdown.dart';
import 'package:albahar_customer/features/deals/domain/deal_info.dart';
import 'package:albahar_customer/features/deals/domain/deal_models.dart';
import 'package:flutter/painting.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/fakes.dart';
import 'deals_fixtures.dart';

void main() {
  group('DealDesign.fromJson', () {
    test('reads every field of the contract', () {
      final d = DealDesign.fromJson(
        designJson(
          style: 'luxe',
          shape: 'pill',
          look: 'text',
          units: ['s', 'h', 'm'],
          urgency: 15,
          stockBar: false,
          percentBadge: false,
          icon: 'crown',
          imageUrl: 'https://cdn.test/deal.jpg',
        ),
      );
      expect(d.style, DealStyle.luxe);
      expect(d.shape, DealShape.pill);
      expect(d.look, DealTimerLook.text);
      expect(d.units, [DealUnit.hours, DealUnit.minutes, DealUnit.seconds]); // sorted biggest first
      expect(d.urgencyMinutes, 15);
      expect(d.stockBar, isFalse);
      expect(d.percentBadge, isFalse);
      expect(d.icon, DealIconKey.crown);
      expect(
        d.colors,
        const DealColors(background: Color(0xFF1B2A41), text: Color(0xFFFFFFFF), accent: Color(0xFFF2B84B)),
      );
      expect(d.headlineFor('en'), 'Ends in');
      expect(d.headlineFor('ar'), 'ينتهي خلال');
      expect(d.ctaFor('ar'), 'تسوق العرض');
      expect(d.imageUrl, 'https://cdn.test/deal.jpg');
    });

    for (final raw in <Object?>[null, 'x', 7, <Object?>[], <String, dynamic>{}]) {
      test('falls back to the contract defaults for $raw', () {
        final d = DealDesign.fromJson(raw);
        expect(d.style, DealStyle.classic);
        expect(d.shape, DealShape.rounded);
        expect(d.look, DealTimerLook.boxes);
        expect(d.units, DealDesign.defaultUnits);
        expect(d.urgencyMinutes, 60);
        expect(d.stockBar, isTrue);
        expect(d.percentBadge, isTrue);
        expect(d.icon, DealIconKey.flame);
        expect(d.colors, isNull);
        expect(d.imageUrl, isNull);
        expect(d.headlineFor('en'), isNull);
      });
    }

    test('unknown or garbage values never throw and fall back field by field', () {
      final d = DealDesign.fromJson({
        'style': 'neon',
        'shape': 42,
        'colors': {'background': 'red', 'text': '#FFFFFF', 'accent': '#000000'},
        'timer': {
          'units': ['d', 'x', 7, null],
          'look': 'hologram',
          'urgency_minutes': 'soon',
        },
        'stock_bar': 'nope',
        'icon': 'rocket',
        'image_url': 'http://insecure.test/a.jpg',
        'headline_en': '  ',
      });
      expect(d.style, DealStyle.classic);
      expect(d.shape, DealShape.rounded);
      expect(d.colors, isNull, reason: 'one bad colour voids the set');
      expect(d.units, DealDesign.defaultUnits, reason: 'one valid unit is not a countdown');
      expect(d.look, DealTimerLook.boxes);
      expect(d.urgencyMinutes, 60, reason: 'garbage reads as the default');
      expect(d.icon, DealIconKey.flame);
      expect(d.imageUrl, isNull, reason: 'only https pictures');
      expect(d.headlineFor('en'), isNull, reason: 'a blank headline is no headline');
      expect(d.headlineFor('fr'), isNull);
    });

    test('urgency 0 means off and negative or huge values are clamped', () {
      expect(DealDesign.fromJson(designJson(urgency: 0)).urgencyMinutes, 0);
      expect(DealDesign.fromJson(designJson(urgency: -5)).urgencyMinutes, 0);
      expect(DealDesign.fromJson(designJson(urgency: 999999)).urgencyMinutes, 10080);
    });

    test('equal designs compare equal', () {
      expect(DealDesign.fromJson(designJson()), DealDesign.fromJson(designJson()));
      expect(DealDesign.fromJson(designJson()).hashCode, DealDesign.fromJson(designJson()).hashCode);
      expect(DealDesign.fromJson(designJson()) == DealDesign.fromJson(designJson(style: 'bold')), isFalse);
    });
  });

  group('DealInfo (the deal block of a product)', () {
    test('parses a full block', () {
      final i = DealInfo.maybe(dealBlockJson(customerRemaining: 1))!;
      expect(i.dealId, 'd1');
      expect(i.phase, DealPhase.live);
      expect(i.dealPrice?.formatted, 'QAR 70.00');
      expect(i.regularPrice?.formatted, 'QAR 100.00');
      expect(i.percent, 30);
      expect(i.remaining, 12);
      expect(i.remainingPercent, 24);
      expect(i.limitPerOrder, 2);
      expect(i.limitPerCustomer, 3);
      expect(i.customerRemaining, 1);
      expect(i.soldOut, isFalse);
      expect(i.design.style, DealStyle.classic);
    });

    for (final raw in <Object?>[
      null,
      'deal',
      5,
      <Object?>[],
      <String, dynamic>{},
      {'deal_id': ''},
      {'deal_id': null},
    ]) {
      test('no block for $raw', () => expect(DealInfo.maybe(raw), isNull));
    }

    test('every other field missing or garbage reads as "not there", never as a crash', () {
      final i = DealInfo.maybe({
        'deal_id': 'x',
        'phase': 'weird',
        'starts_at': 'yesterday',
        'ends_at': 12,
        'deal_price': 'free',
        'regular_price': [],
        'percent': 'lots',
        'remaining': -3,
        'remaining_percent': 250,
        'limit_per_order': 0,
        'limit_per_customer': 'x',
        'customer_remaining': null,
        'sold_out': 'maybe',
        'design': 'plain',
      })!;
      expect(i.phase, DealPhase.unknown);
      expect(i.startsAt, isNull);
      expect(i.endsAt, isNull);
      expect(i.dealPrice, isNull);
      expect(i.regularPrice, isNull);
      expect(i.percent, isNull);
      expect(i.remaining, isNull);
      expect(i.remainingPercent, 100, reason: 'a share is clamped to 0 to 100');
      expect(i.limitPerOrder, isNull, reason: '0 means no limit');
      expect(i.limitPerCustomer, isNull);
      expect(i.customerRemaining, isNull);
      expect(i.soldOut, isFalse);
      expect(i.design, DealDesign.fallback);
      expect(i.showsDealPriceAt(dealsNow), isFalse);
    });

    test('a plain decimal price is read like money', () {
      final i = DealInfo.maybe({'deal_id': 'x', 'deal_price': '45.5', 'regular_price': 60})!;
      expect(i.dealPrice?.formatted, 'QAR 45.5');
      expect(i.regularPrice?.formatted, 'QAR 60.00');
    });

    test('the deal price shows only while live, in stock, and with units left for this customer', () {
      DealInfo info(Map<String, dynamic> o) => DealInfo.maybe({...dealBlockJson(), ...o})!;
      expect(info({}).showsDealPriceAt(dealsNow), isTrue);
      expect(
        info({'phase': 'scheduled', 'starts_at': iso(dealsNow.add(const Duration(hours: 1)))})
            .showsDealPriceAt(dealsNow),
        isFalse,
      );
      expect(info({'phase': 'sold_out'}).showsDealPriceAt(dealsNow), isFalse);
      expect(info({'sold_out': true}).showsDealPriceAt(dealsNow), isFalse);
      expect(info({'phase': 'ended'}).showsDealPriceAt(dealsNow), isFalse);
      expect(info({'phase': 'paused'}).showsDealPriceAt(dealsNow), isFalse);
      expect(info({'deal_price': null}).showsDealPriceAt(dealsNow), isFalse);
      final used = info({'customer_remaining': 0});
      expect(used.limitReached, isTrue);
      expect(used.showsDealPriceAt(dealsNow), isFalse);
      expect(info({'customer_remaining': 2}).showsDealPriceAt(dealsNow), isTrue);
      expect(info({'customer_remaining': null}).limitReached, isFalse);
    });

    test('a live deal ends, and a scheduled one goes live, when the server clock says so', () {
      final live = DealInfo.maybe(dealBlockJson(endsAt: dealsNow.add(const Duration(seconds: 10))))!;
      expect(live.phaseAt(dealsNow), DealPhase.live);
      expect(live.phaseAt(dealsNow.add(const Duration(seconds: 10))), DealPhase.ended);
      expect(live.showsDealPriceAt(dealsNow.add(const Duration(seconds: 11))), isFalse);
      final soon = DealInfo.maybe(
        dealBlockJson(
          phase: 'scheduled',
          startsAt: dealsNow.add(const Duration(minutes: 1)),
          endsAt: dealsNow.add(const Duration(hours: 1)),
        ),
      )!;
      expect(soon.phaseAt(dealsNow), DealPhase.scheduled);
      expect(soon.phaseAt(dealsNow.add(const Duration(minutes: 1))), DealPhase.live);
      expect(soon.phaseAt(dealsNow.add(const Duration(hours: 2))), DealPhase.ended);
    });
  });

  group('ProductSummary.deal', () {
    test('a card with a deal block carries it; one without (an older server) has none', () {
      expect(ProductSummary.fromJson(dealProductJson('p1')).deal?.dealId, 'd1');
      expect(ProductSummary.fromJson(productCard('p1')).deal, isNull);
      expect(ProductSummary.fromJson({...productCard('p1'), 'deal': null}).deal, isNull);
      expect(ProductSummary.fromJson({...productCard('p1'), 'deal': 'x'}).deal, isNull);
    });

    test('the detail of a product reads the block through its summary', () {
      final d = ProductDetail.fromJson({...dealProductJson('p1'), 'images': <Object?>[], 'variants': <Object?>[]});
      expect(d.summary.deal?.percent, 30);
    });
  });

  group('Deal and the feed', () {
    test('parses the list payload and orders nothing itself (the server sorts)', () {
      final feed = DealsFeed.fromJson(
        feedJson(
          live: [
            dealJson(id: 'a'),
            dealJson(id: 'b', phase: 'sold_out'),
          ],
          upcoming: [dealJson(id: 'c', phase: 'scheduled', startsAt: dealsNow.add(const Duration(hours: 3)))],
        ),
        deviceNow: dealsNow,
      );
      expect([for (final d in feed.live) d.id], ['a', 'b']);
      expect(feed.live.last.phase, DealPhase.soldOut);
      expect(feed.upcoming.single.phase, DealPhase.scheduled);
      expect(feed.hasLive, isTrue);
      final a = feed.live.first;
      expect(a.name, 'Weekend Furniture Drop');
      expect(a.tagline, 'Big pieces, small prices');
      expect(a.percent, 30);
      expect(a.limitPerOrder, 2);
      expect(a.limitPerCustomer, 3);
      expect(a.productsTotal, 2);
      expect(a.products.map((p) => p.deal?.dealId), ['d1', 'd1']);
      expect(a.design.style, DealStyle.classic);
      expect(a.dueAt, a.endsAt);
      expect(feed.upcoming.single.dueAt, feed.upcoming.single.startsAt);
    });

    test('a sold-out deal alone is not "live" for the footer dot', () {
      final feed = DealsFeed.fromJson(
        feedJson(live: [dealJson(phase: 'sold_out')]),
        deviceNow: dealsNow,
      );
      expect(feed.live, hasLength(1));
      expect(feed.hasLive, isFalse);
    });

    test('an ended or paused deal in a list is dropped; a deal without an id too', () {
      final feed = DealsFeed.fromJson(
        feedJson(
          live: [
            dealJson(phase: 'ended'),
            dealJson(id: 'x', phase: 'paused'),
            dealJson(id: ''),
            dealJson(id: 'ok'),
          ],
        ),
        deviceNow: dealsNow,
      );
      expect([for (final d in feed.live) d.id], ['ok']);
    });

    test('an unknown phase counts as the list it came in: live in live, scheduled in upcoming', () {
      final feed = DealsFeed.fromJson(
        feedJson(
          live: [dealJson(id: 'l', phase: 'mystery')],
          upcoming: [dealJson(id: 'u', phase: 'mystery')],
        ),
        deviceNow: dealsNow,
      );
      expect(feed.live.single.phase, DealPhase.live);
      expect(feed.upcoming.single.phase, DealPhase.scheduled);
    });

    test('empty, missing and garbage payloads give an empty feed, never a crash', () {
      for (final raw in <Map<String, dynamic>>[
        {},
        {'live': 'x', 'upcoming': 5},
        {
          'live': [null, 3, 'a', <Object?>[]],
          'upcoming': <Object?>[],
        },
      ]) {
        final feed = DealsFeed.fromJson(raw, deviceNow: dealsNow);
        expect(feed.isEmpty, isTrue);
        expect(feed.serverTime, isNull);
        expect(feed.skew, Duration.zero);
      }
    });

    test('server_time gives the clock skew; the end time comes from seconds_left when ends_at is missing', () {
      final phoneNow = dealsNow.subtract(const Duration(minutes: 3)); // the phone is 3 minutes behind
      final feed = DealsFeed.fromJson(
        feedJson(
          live: [
            {...dealJson(), 'ends_at': null, 'seconds_left': 600},
          ],
        ),
        deviceNow: phoneNow,
      );
      expect(feed.skew, const Duration(minutes: 3));
      expect(feed.live.single.endsAt, dealsNow.add(const Duration(seconds: 600)));
    });

    test('seconds_to_start gives a scheduled deal its start when starts_at is missing', () {
      final deal = Deal.fromJson(
        {...dealJson(phase: 'scheduled'), 'starts_at': null, 'seconds_to_start': 600},
        fallbackPhase: DealPhase.scheduled,
        receivedAt: dealsNow,
      );
      expect(deal.startsAt, dealsNow.add(const Duration(seconds: 600)));
      expect(deal.dueAt, deal.startsAt);
      final withDate = Deal.fromJson({...dealJson(phase: 'scheduled'), 'seconds_to_start': 5}, receivedAt: dealsNow);
      expect(
        withDate.startsAt,
        isNot(dealsNow.add(const Duration(seconds: 5))),
        reason: 'starts_at wins when it is there',
      );
    });

    test('garbage inside one deal degrades that field only', () {
      final deal = Deal.fromJson({
        'id': 'z',
        'name': 7,
        'tagline': '',
        'phase': null,
        'seconds_left': 'soon',
        'discount': 'big',
        'limits': [1, 2],
        'products_total': 'many',
        'products': 'none',
        'design': null,
        'show_on_home': 'yes',
      });
      expect(deal.id, 'z');
      expect(deal.name, '7');
      expect(deal.tagline, isNull);
      expect(deal.phase, DealPhase.live);
      expect(deal.secondsLeft, 0);
      expect(deal.percent, isNull);
      expect(deal.limitPerOrder, isNull);
      expect(deal.productsTotal, 0);
      expect(deal.products, isEmpty);
      expect(deal.design, DealDesign.fallback);
      expect(deal.dueAt, isNull);
    });

    test('percent is read only for a percentage rule and only from 1 to 100', () {
      expect(Deal.fromJson(dealJson(discountValue: '30.00')).percent, 30);
      expect(Deal.fromJson(dealJson(discountType: 'amount_off', discountValue: '20')).percent, isNull);
      expect(Deal.fromJson(dealJson(discountType: 'fixed_price', discountValue: '')).percent, isNull);
      expect(Deal.fromJson(dealJson(discountValue: '0')).percent, isNull);
      expect(Deal.fromJson(dealJson(discountValue: '250')).percent, isNull);
      expect(Deal.fromJson(dealJson(discountType: 'future_rule')).discountType, DealDiscountType.unknown);
    });

    test('products repeated in a list show once', () {
      final deal = Deal.fromJson(
        dealJson(products: [dealProductJson('p1'), dealProductJson('p1'), dealProductJson('p2')]),
      );
      expect(deal.products.map((p) => p.id), ['p1', 'p2']);
    });
  });

  group('DealPage (GET /deals/{id})', () {
    test('reads the deal, its page of products and the paging meta', () {
      final page = DealPage.fromJson({
        ...dealJson(products: <Object?>[]),
        'server_time': iso(dealsNow),
        'products': {
          'data': [dealProductJson('p1'), dealProductJson('p2')],
          'meta': {'current_page': 2, 'last_page': 4, 'total': 70},
        },
      }, deviceNow: dealsNow);
      expect(page.deal.products, hasLength(2));
      expect(page.currentPage, 2);
      expect(page.lastPage, 4);
      expect(page.total, 70);
      expect(page.hasMore, isTrue);
    });

    test('a plain list of products or missing meta is one page', () {
      final one = DealPage.fromJson(dealJson(), deviceNow: dealsNow);
      expect(one.hasMore, isFalse);
      expect(one.currentPage, 1);
      final odd = DealPage.fromJson({
        ...dealJson(),
        'products': {
          'data': <Object?>[],
          'meta': {'current_page': 5, 'last_page': 2},
        },
      }, deviceNow: dealsNow);
      expect(odd.lastPage, 5, reason: 'the last page is never before the current one');
      expect(odd.hasMore, isFalse);
    });
  });
}
