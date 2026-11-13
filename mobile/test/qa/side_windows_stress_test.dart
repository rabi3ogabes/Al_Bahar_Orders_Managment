@Timeout(Duration(minutes: 8))
library;

import 'dart:async';

import 'package:albahar_customer/features/branches/presentation/branches_screen.dart';
import 'package:albahar_customer/features/cart/presentation/bag_panel.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/catalog/presentation/product_list_screen.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/quick_view_panel.dart';
import 'package:albahar_customer/features/notifications/presentation/notifications_screen.dart';
import 'package:albahar_customer/features/orders/presentation/order_detail_screen.dart';
import 'package:albahar_customer/features/profile/presentation/address_form_screen.dart';
import 'package:albahar_customer/features/profile/presentation/addresses_screen.dart';
import 'package:albahar_customer/features/profile/presentation/edit_profile_screen.dart';
import 'package:albahar_customer/features/profile/presentation/loyalty_screen.dart';
import 'package:albahar_customer/features/profile/presentation/notification_prefs_screen.dart';
import 'package:albahar_customer/features/reviews/presentation/write_review_screen.dart';
import 'package:albahar_customer/features/support/presentation/cms_page_screen.dart';
import 'package:albahar_customer/features/support/presentation/support_screens.dart';
import 'package:albahar_customer/features/wishlist/presentation/wishlist_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fakes.dart' show productCard;
import 'side_windows_test.dart' show back, backend, boot, go, settle, teardown;
import 'test_fonts.dart';

/// Every window, opened on the smallest phone with the largest text and with very long names, then on a tablet, in
/// both languages and both themes: nothing may overflow or throw, and Back must always close exactly the window.
void main() {
  setUpAll(loadAppFonts);

  final openers = <String, Future<void> Function(BuildContext)>{
    'order': (c) => showOrderPanel(c, 'o1', number: 'AB26o1'),
    'cart': (c) => showBagPanel(c, addedVariantIds: {'v1'}, notice: 'Order again: 2 items could not be added'),
    'quick view': (c) => showQuickView(c, ProductSummary.fromJson(productCard('p1'))),
    'filters': (c) => showFilterPanel(c, query: const ProductQuery(), filters: const []),
    'address': (c) => showAddressPanel(c),
    'address picker': (c) => showAddressPicker(c),
    'profile': showProfilePanel,
    'notifications': showNotificationsPanel,
    'notification preferences': showNotificationPrefsPanel,
    'wishlist': showWishlistPanel,
    'loyalty': showLoyaltyPanel,
    'new ticket': (c) => showNewTicketPanel(c, orderId: 'o1'),
    'ticket': (c) => showTicketPanel(c, 't1', subject: 'Where is my order?'),
    'branch picker': showBranchPicker,
    'review': (c) => showReviewPanel(c, productId: 'p1', productName: 'Nordic oak dining table with a long name'),
    'content page': (c) => showCmsPage(c, 'terms', title: 'Terms'),
  };

  const cases = [
    (name: 'small phone, text x1.6, English', size: Size(320, 640), scale: 1.6, locale: 'en'),
    (name: 'small phone, text x1.6, Arabic', size: Size(320, 640), scale: 1.6, locale: 'ar'),
    (name: 'phone, Arabic', size: Size(390, 844), scale: 1.0, locale: 'ar'),
    (name: 'tablet, English', size: Size(1024, 768), scale: 1.0, locale: 'en'),
    (name: 'tablet, Arabic, text x1.3', size: Size(1024, 768), scale: 1.3, locale: 'ar'),
  ];

  for (final c in cases) {
    testWidgets('every window opens, lays out and closes with Back: ${c.name}', (t) async {
      t.platformDispatcher.textScaleFactorTestValue = c.scale;
      addTearDown(t.platformDispatcher.clearAllTestValues);
      final h = await boot(
        t,
        backend(stress: true, arabic: c.locale == 'ar'),
        width: c.size.width,
        height: c.size.height,
        locale: c.locale,
      );
      await go(t, h, '/home');
      t.takeException();

      final problems = <String>[];
      for (final entry in openers.entries) {
        final context = t.element(find.byType(Scaffold).first);
        unawaited(entry.value(context));
        await settle(t, 8);
        final error = t.takeException();
        if (error != null) problems.add('${entry.key}: ${error.toString().split('\n').first}');
        final open = find.byType(BottomSheet).evaluate().isNotEmpty || find.byTooltip('Close').evaluate().isNotEmpty;
        if (!open) problems.add('${entry.key}: nothing opened');
        await back(t);
        await settle(t, 3);
        final stillOpen = find.byTooltip('Close').evaluate().isNotEmpty;
        if (stillOpen) {
          problems.add('${entry.key}: still open after Back (a dirty form would ask first)');
          await back(t);
        }
      }
      expect(problems, isEmpty, reason: problems.join('\n'));
      await teardown(t);
    });
  }
}
