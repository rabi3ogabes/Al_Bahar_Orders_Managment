import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/router/app_shell.dart';
import 'package:albahar_customer/core/router/deep_links.dart';
import 'package:albahar_customer/features/auctions/presentation/auction_detail_screen.dart';
import 'package:albahar_customer/features/auctions/presentation/auctions_screen.dart';
import 'package:albahar_customer/features/auctions/presentation/lot_screen.dart';
import 'package:albahar_customer/features/auctions/presentation/my_auctions_screen.dart';
import 'package:albahar_customer/features/auctions/presentation/win_checkout_screen.dart';
import 'package:albahar_customer/features/auth/presentation/auth_screens.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/branches/presentation/branch_detail_screen.dart';
import 'package:albahar_customer/features/branches/presentation/branches_screen.dart';
import 'package:albahar_customer/features/cart/presentation/cart_screen.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/catalog/presentation/categories_screen.dart';
import 'package:albahar_customer/features/catalog/presentation/category_screen.dart';
import 'package:albahar_customer/features/catalog/presentation/product_detail_screen.dart';
import 'package:albahar_customer/features/catalog/presentation/product_list_screen.dart';
import 'package:albahar_customer/features/catalog/presentation/search_screen.dart';
import 'package:albahar_customer/features/checkout/presentation/checkout_screen.dart';
import 'package:albahar_customer/features/checkout/presentation/payment_status_screen.dart';
import 'package:albahar_customer/features/home/presentation/home_screen.dart';
import 'package:albahar_customer/features/notifications/presentation/notifications_screen.dart';
import 'package:albahar_customer/features/onboarding/presentation/onboarding_screens.dart';
import 'package:albahar_customer/features/onboarding/presentation/state/onboarding_state.dart';
import 'package:albahar_customer/features/orders/presentation/order_detail_screen.dart';
import 'package:albahar_customer/features/orders/presentation/order_tracking_screen.dart';
import 'package:albahar_customer/features/orders/presentation/orders_screen.dart';
import 'package:albahar_customer/features/orders/presentation/pickup_pass_screen.dart';
import 'package:albahar_customer/features/profile/presentation/account_screen.dart';
import 'package:albahar_customer/features/profile/presentation/address_form_screen.dart';
import 'package:albahar_customer/features/profile/presentation/addresses_screen.dart';
import 'package:albahar_customer/features/profile/presentation/edit_profile_screen.dart';
import 'package:albahar_customer/features/profile/presentation/loyalty_screen.dart';
import 'package:albahar_customer/features/profile/presentation/notification_prefs_screen.dart';
import 'package:albahar_customer/features/reviews/presentation/write_review_screen.dart';
import 'package:albahar_customer/features/settings/presentation/delete_account_screen.dart';
import 'package:albahar_customer/features/settings/presentation/settings_screen.dart';
import 'package:albahar_customer/features/support/presentation/cms_page_screen.dart';
import 'package:albahar_customer/features/support/presentation/support_screens.dart';
import 'package:albahar_customer/features/wishlist/presentation/wishlist_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

final rootNavigatorKey = GlobalKey<NavigatorState>(debugLabel: 'root');

/// A location requested before the router finished its splash (e.g. a
/// notification tap that launched the app). Consumed by the splash screen.
class LaunchLocation extends Notifier<String?> {
  @override
  String? build() => null;
  void set(String? location) => state = location;
  String? take() {
    final l = state;
    state = null;
    return l;
  }
}

final launchLocationProvider = NotifierProvider<LaunchLocation, String?>(LaunchLocation.new);

/// Paths that require a signed-in customer (prefix match).
const protectedPrefixes = <String>[
  '/checkout',
  '/orders',
  '/wishlist',
  '/notifications',
  '/profile',
  '/addresses',
  '/preferences',
  '/loyalty',
  '/support',
  '/my-auctions',
  '/payments',
  '/auctions/wins',
  '/settings/delete-account',
];

bool isProtectedPath(String path) {
  if (RegExp(r'^/products/[^/]+/reviews/new$').hasMatch(path)) return true;
  return protectedPrefixes.any((p) => path == p || path.startsWith('$p/'));
}

/// Pure redirect logic (unit-tested). Returns a new location or null.
String? resolveRedirect({
  required Uri uri,
  required bool localeChosen,
  required bool onboardingDone,
  required bool authenticated,
}) {
  // 1. External links (custom scheme / universal link / "/app" prefix).
  if (uri.scheme.isNotEmpty || uri.path.startsWith(DeepLinks.webPrefix)) {
    return DeepLinks.toLocation(uri) ?? '/home';
  }
  final path = uri.path.isEmpty ? '/' : uri.path;
  final here = uri.toString();
  if (path == '/') return '/splash';
  String withFrom(String target) => '$target?from=${Uri.encodeComponent(here)}';

  const preAuth = {'/splash', '/language', '/onboarding'};
  if (!localeChosen && path != '/splash' && path != '/language') return withFrom('/language');
  if (!onboardingDone && !preAuth.contains(path)) return withFrom('/onboarding');
  if (!authenticated && isProtectedPath(path)) return withFrom('/login');
  if (authenticated && path.startsWith('/login')) {
    final from = uri.queryParameters['from'];
    return (from != null && from.startsWith('/') && !from.startsWith('/login')) ? from : '/home';
  }
  return null;
}

/// Bridges Riverpod state changes into go_router's refresh mechanism.
class RouterRefreshNotifier extends ChangeNotifier {
  RouterRefreshNotifier(Ref ref) {
    ref
      ..listen(isAuthenticatedProvider, (_, _) => notifyListeners())
      ..listen(localeControllerProvider, (_, _) => notifyListeners())
      ..listen(onboardingDoneProvider, (_, _) => notifyListeners());
  }
}

final routerProvider = Provider<GoRouter>((ref) {
  final refresh = RouterRefreshNotifier(ref);
  ref.onDispose(refresh.dispose);

  final router = GoRouter(
    navigatorKey: rootNavigatorKey,
    initialLocation: '/splash',
    refreshListenable: refresh,
    debugLogDiagnostics: false,
    redirect: (context, state) => resolveRedirect(
      uri: state.uri,
      localeChosen: ref.read(localeControllerProvider) != null,
      onboardingDone: ref.read(onboardingDoneProvider),
      authenticated: ref.read(isAuthenticatedProvider),
    ),
    errorBuilder: (context, state) => const NotFoundScreen(),
    routes: [
      GoRoute(path: '/splash', builder: (_, _) => const SplashScreen()),
      GoRoute(
        path: '/language',
        builder: (_, s) => LanguageScreen(from: s.uri.queryParameters['from']),
      ),
      GoRoute(
        path: '/onboarding',
        builder: (_, s) => OnboardingScreen(from: s.uri.queryParameters['from']),
      ),
      GoRoute(
        path: '/login',
        builder: (_, s) => PhoneLoginScreen(from: s.uri.queryParameters['from']),
        routes: [
          GoRoute(
            path: 'otp',
            builder: (_, s) {
              final args = s.extra;
              if (args is! OtpArgs) return PhoneLoginScreen(from: s.uri.queryParameters['from']);
              return OtpScreen(args: args);
            },
          ),
          GoRoute(
            path: 'register',
            builder: (_, s) {
              final args = s.extra;
              if (args is! RegisterArgs) return PhoneLoginScreen(from: s.uri.queryParameters['from']);
              return RegisterScreen(args: args);
            },
          ),
          GoRoute(
            path: 'password',
            builder: (_, s) => PasswordLoginScreen(from: s.uri.queryParameters['from']),
          ),
          GoRoute(path: 'reset', builder: (_, _) => const PasswordResetScreen()),
        ],
      ),
      StatefulShellRoute.indexedStack(
        builder: (context, state, shell) => AppShell(shell: shell),
        branches: [
          StatefulShellBranch(
            routes: [GoRoute(path: '/home', builder: (_, _) => const HomeScreen())],
          ),
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: '/categories',
                builder: (_, _) => const CategoriesScreen(),
                routes: [
                  GoRoute(
                    path: ':id',
                    parentNavigatorKey: rootNavigatorKey,
                    builder: (_, s) => CategoryScreen(categoryId: s.pathParameters['id']!),
                  ),
                ],
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: '/auctions',
                builder: (_, s) => AuctionsScreen(initialStatus: s.uri.queryParameters['status']),
                routes: [
                  GoRoute(
                    path: 'lots/:lotId',
                    parentNavigatorKey: rootNavigatorKey,
                    builder: (_, s) => LotScreen(lotId: s.pathParameters['lotId']!),
                  ),
                  GoRoute(
                    path: 'wins/:winId/checkout',
                    parentNavigatorKey: rootNavigatorKey,
                    builder: (_, s) => WinCheckoutScreen(winId: s.pathParameters['winId']!, extra: s.extra),
                  ),
                  GoRoute(
                    path: ':id',
                    parentNavigatorKey: rootNavigatorKey,
                    builder: (_, s) => AuctionDetailScreen(auctionId: s.pathParameters['id']!),
                  ),
                ],
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [GoRoute(path: '/cart', builder: (_, _) => const CartScreen())],
          ),
          StatefulShellBranch(
            routes: [GoRoute(path: '/account', builder: (_, _) => const AccountScreen())],
          ),
        ],
      ),
      GoRoute(
        path: '/products',
        builder: (_, s) => ProductListScreen(
          initialQuery: ProductQuery.fromRoute(s.uri.queryParameters),
          title: s.uri.queryParameters['title'],
        ),
        routes: [
          GoRoute(
            path: ':id',
            builder: (_, s) => ProductDetailScreen(
              productId: s.pathParameters['id']!,
              heroScope: s.uri.queryParameters['hero'] ?? '',
              preview: s.extra is ProductSummary ? s.extra! as ProductSummary : null,
            ),
            routes: [
              GoRoute(
                path: 'reviews/new',
                builder: (_, s) =>
                    WriteReviewScreen(productId: s.pathParameters['id']!, productName: s.uri.queryParameters['name']),
              ),
            ],
          ),
        ],
      ),
      GoRoute(
        path: '/search',
        builder: (_, s) => SearchScreen(initialQuery: s.uri.queryParameters['q']),
      ),
      GoRoute(
        path: '/branches',
        builder: (_, s) => BranchesScreen(pickMode: s.uri.queryParameters['pick'] == '1'),
        routes: [
          GoRoute(
            path: ':id',
            builder: (_, s) => BranchDetailScreen(branchId: s.pathParameters['id']!),
          ),
        ],
      ),
      GoRoute(path: '/checkout', builder: (_, _) => const CheckoutScreen()),
      GoRoute(
        path: '/payments/:id/return',
        builder: (_, s) => PaymentStatusScreen(
          paymentId: s.pathParameters['id']!,
          orderId: s.uri.queryParameters['order_id'],
          orderNumber: s.uri.queryParameters['number'],
        ),
      ),
      GoRoute(
        path: '/orders',
        builder: (_, _) => const OrdersScreen(),
        routes: [
          GoRoute(
            path: ':id',
            builder: (_, s) =>
                OrderDetailScreen(orderId: s.pathParameters['id']!, justPlaced: s.uri.queryParameters['placed'] == '1'),
            routes: [
              GoRoute(
                path: 'track',
                builder: (_, s) => OrderTrackingScreen(orderId: s.pathParameters['id']!),
              ),
              GoRoute(
                path: 'pickup/:fid',
                builder: (_, s) =>
                    PickupPassScreen(orderId: s.pathParameters['id']!, fulfillmentId: s.pathParameters['fid']!),
              ),
            ],
          ),
        ],
      ),
      GoRoute(path: '/wishlist', builder: (_, _) => const WishlistScreen()),
      GoRoute(
        path: '/my-auctions',
        builder: (_, s) => MyAuctionsScreen(initialTab: s.uri.queryParameters['tab']),
      ),
      GoRoute(path: '/notifications', builder: (_, _) => const NotificationsScreen()),
      GoRoute(path: '/profile/edit', builder: (_, _) => const EditProfileScreen()),
      GoRoute(
        path: '/addresses',
        builder: (_, s) => AddressesScreen(pickMode: s.uri.queryParameters['pick'] == '1'),
        routes: [
          GoRoute(path: 'new', builder: (_, _) => const AddressFormScreen()),
          GoRoute(
            path: ':id/edit',
            builder: (_, s) => AddressFormScreen(addressId: s.pathParameters['id']),
          ),
        ],
      ),
      GoRoute(path: '/preferences/notifications', builder: (_, _) => const NotificationPrefsScreen()),
      GoRoute(path: '/loyalty', builder: (_, _) => const LoyaltyScreen()),
      GoRoute(
        path: '/support',
        builder: (_, _) => const SupportScreen(),
        routes: [
          GoRoute(
            path: 'new',
            builder: (_, s) => NewTicketScreen(orderId: s.uri.queryParameters['order_id']),
          ),
          GoRoute(
            path: ':id',
            builder: (_, s) => TicketScreen(ticketId: s.pathParameters['id']!),
          ),
        ],
      ),
      GoRoute(
        path: '/pages/:slug',
        builder: (_, s) => CmsPageScreen(slug: s.pathParameters['slug']!),
      ),
      GoRoute(
        path: '/settings',
        builder: (_, _) => const SettingsScreen(),
        routes: [GoRoute(path: 'delete-account', builder: (_, _) => const DeleteAccountScreen())],
      ),
    ],
  );
  ref.onDispose(router.dispose);
  return router;
});
