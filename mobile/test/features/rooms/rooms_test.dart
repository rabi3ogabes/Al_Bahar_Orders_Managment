@Timeout(Duration(minutes: 5))
library;

// "Shop by room": reading the `rooms` section of the home feed, the tiles on the real home (English, and Arabic at text size 1.3), and a tap that opens
// the shop filtered to that room.

import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/home/domain/home_models.dart';
import 'package:albahar_customer/features/rooms/domain/room_models.dart';
import 'package:albahar_customer/features/rooms/presentation/room_tiles.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/fakes.dart';
import '../../qa/side_windows_test.dart' show ar, backend, boot, en, go, settle, teardown;
import '../../qa/test_fonts.dart';

Map<String, dynamic> _room(String slug, String name, int count, {String? image, String? description}) => {
  'id': 'r-$slug',
  'slug': slug,
  'name': name,
  'description': description,
  'image_url': image,
  'thumb_url': null,
  'products_count': count,
  'link': {'type': 'room', 'value': slug},
};

Map<String, dynamic> _homeWith(List<Map<String, dynamic>> rooms) => {
  'sections': [
    {'id': 'cat', 'type': 'categories', 'title': 'Categories', 'items': []},
    {'id': 'rm', 'type': 'rooms', 'layout': 'grid', 'title': 'Shop by room', 'items': rooms},
    {
      'id': 'new',
      'type': 'new_arrivals',
      'title': 'New in',
      'items': [productCard('1')],
    },
  ],
};

void main() {
  setUpAll(loadAppFonts);

  group('the feed', () {
    test('a rooms section is read, and a room without an address word or a section without rooms is left out', () {
      final s = HomeSection.fromJson({
        'id': 'rm',
        'type': 'rooms',
        'title': 'Shop by room',
        'items': [_room('bedroom', 'Bedroom', 12), _room('', 'Broken', 3), _room('kitchen', 'المطبخ', 1)],
      });
      expect(s, isA<RoomsSection>());
      final rooms = (s! as RoomsSection).rooms;
      expect(rooms.map((r) => r.slug), ['bedroom', 'kitchen']);
      expect(rooms.last.name, 'المطبخ');
      expect(rooms.first.productsCount, 12);
      expect(HomeSection.fromJson({'id': 'rm', 'type': 'rooms', 'items': []}), isNull);
      expect(HomeSection.fromJson({'id': 'rm', 'type': 'rooms'}), isNull);
    });

    test('a room leads to the shop list of that room, with an Arabic name safely encoded', () {
      final room = ShopRoom.fromJson(_room('living-room', 'غرفة المعيشة & المجلس', 4));
      final uri = Uri.parse(room.listingLocation);
      expect(uri.path, '/products');
      expect(uri.queryParameters, {'room': 'living-room', 'title': 'غرفة المعيشة & المجلس'});
    });

    test('the product query keeps the room in the route, the request and the equality', () {
      final q = ProductQuery.fromRoute({'room': 'bedroom'});
      expect(q.room, 'bedroom');
      expect(q.toQuery(branchId: 'b1')['room'], 'bedroom');
      expect(q.clearFilters().room, 'bedroom');
      expect(q.copyWith(inStock: true).room, 'bedroom');
      expect(q, ProductQuery.fromRoute({'room': 'bedroom'}));
      expect(q, isNot(ProductQuery.fromRoute({'room': 'kitchen'})));
      expect(q.hashCode, ProductQuery.fromRoute({'room': 'bedroom'}).hashCode);
      expect(const ProductQuery().toQuery()['room'], isNull);
    });
  });

  group('the home', () {
    FakeHandler shop(List<Map<String, dynamic>> rooms, {List<RecordedRequest>? seen}) => backend(
      extra: (r) {
        seen?.add(r);
        if (r.method == 'GET' && r.path == '/home') return ok(_homeWith(rooms));
        return null;
      },
    );

    testWidgets('shows a large tile for each room with its name and how many products it holds', (t) async {
      final h = await boot(t, shop([_room('bedroom', 'Bedroom', 12), _room('kitchen', 'Kitchen', 1)]));
      await go(t, h, '/home');
      await settle(t, 10);
      expect(find.text('Shop by room'), findsOneWidget);
      expect(find.byType(RoomTile), findsNWidgets(2));
      expect(find.text('Bedroom'), findsOneWidget);
      expect(find.text('12 products'), findsOneWidget);
      expect(find.text('1 product'), findsOneWidget);
      expect(find.bySemanticsLabel('Bedroom, 12 products'), findsOneWidget);
      await teardown(t);
    });

    testWidgets('a tap opens the shop filtered to that room', (t) async {
      final seen = <RecordedRequest>[];
      final h = await boot(t, shop([_room('bedroom', 'Bedroom', 12)], seen: seen));
      await go(t, h, '/home');
      await settle(t, 10);
      await t.tap(find.byType(RoomTile).first);
      await settle(t, 10);
      final asked = seen.where((r) => r.method == 'GET' && r.path == '/products' && r.query['room'] == 'bedroom');
      expect(asked, isNotEmpty, reason: 'the list was asked for the room');
      expect(find.text('Bedroom'), findsWidgets, reason: 'and its name is the title of the list');
      await teardown(t);
    });

    testWidgets('leaves the section out when no room has products, and shows nothing broken', (t) async {
      final h = await boot(t, shop(const []));
      await go(t, h, '/home');
      await settle(t, 10);
      expect(find.byType(RoomTile), findsNothing);
      expect(find.text('Shop by room'), findsNothing);
      await teardown(t);
    });

    testWidgets('reads right to left in Arabic at text size 1.3 without a layout error', (t) async {
      t.platformDispatcher.textScaleFactorTestValue = 1.3;
      addTearDown(t.platformDispatcher.clearTextScaleFactorTestValue);
      final h = await boot(
        t,
        shop([_room('bedroom', 'غرفة النوم', 12), _room('living-room', 'غرفة المعيشة والمجلس الكبير', 2)]),
        width: 360,
        locale: 'ar',
      );
      await go(t, h, '/home');
      await settle(t, 10);
      expect(find.byType(RoomTile), findsNWidgets(2));
      expect(find.text('غرفة النوم'), findsOneWidget);
      expect(find.text(ar.roomProductsCount(12)), findsOneWidget);
      expect(Directionality.of(t.element(find.byType(RoomTile).first)), TextDirection.rtl);
      expect(t.takeException(), isNull);
      expect(en.roomProductsCount(2), '2 products');
      await teardown(t);
    });
  });
}
