@Timeout(Duration(minutes: 5))
library;

// The link in the "How is your ...?" message (`albahar://products/{id}?review=1`): the app opens the product with the review window open, and a signed-out
// visitor is taken to sign in first.
import 'dart:async';

import 'package:albahar_customer/core/router/app_router.dart';
import 'package:albahar_customer/core/router/deep_links.dart';
import 'package:albahar_customer/features/auth/domain/auth_models.dart';
import 'package:albahar_customer/features/auth/presentation/require_auth.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../qa/customer_fixtures.dart';
import '../../qa/side_windows_test.dart' show backend, boot, en, settle, teardown;
import '../../qa/test_fonts.dart';

void main() {
  setUpAll(loadAppFonts);

  test('the link keeps its review mark, for the app link and the web link alike', () {
    expect(DeepLinks.fromString('albahar://products/p1?review=1'), '/products/p1?review=1');
    expect(DeepLinks.fromString('https://albahar.qa/app/products/p1?review=1'), '/products/p1?review=1');
    expect(DeepLinks.fromString('albahar://products/p1'), '/products/p1');
  });

  testWidgets('a signed-in customer opens the product with the review window open', (t) async {
    final h = await boot(t, backend());
    unawaited(h.read(routerProvider).push('/products/p1?review=1'));
    await settle(t, 10);
    expect(find.text(en.writeReviewTitle), findsOneWidget);
    await teardown(t);
  });

  testWidgets('the window does not open when the mark is missing', (t) async {
    final h = await boot(t, backend());
    unawaited(h.read(routerProvider).push('/products/p1'));
    await settle(t, 10);
    expect(find.text(en.writeReviewTitle), findsNothing);
    await teardown(t);
  });

  testWidgets('a signed-out visitor signs in first, and the review window opens when they are back', (t) async {
    final h = await boot(t, backend(), signedIn: false);
    unawaited(h.read(routerProvider).push('/products/p1?review=1'));
    await settle(t, 10);
    expect(currentAppLocation(h.read(routerProvider)), startsWith('/login'));
    expect(find.text(en.writeReviewTitle), findsNothing);

    await h
        .read(sessionControllerProvider.notifier)
        .signIn(
          Authenticated(
            user: const AppUser(id: 'u1', name: 'Noor', phone: '+97455123456', locale: 'en'),
            tokens: signedInTokens,
          ),
        );
    leaveSignIn(rootNavigatorKey.currentContext!, '/products/p1?review=1');
    await settle(t, 12);
    expect(find.text(en.writeReviewTitle), findsOneWidget);
    await teardown(t);
  });
}
