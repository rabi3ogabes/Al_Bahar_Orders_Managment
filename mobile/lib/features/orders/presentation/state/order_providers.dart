import 'package:albahar_customer/core/models/paginated.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/widgets/paged.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/orders/data/order_repository.dart';
import 'package:albahar_customer/features/orders/domain/order_models.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Status filter groups for the orders list. The backend's `?status=` takes a
/// single value, so multi-status groups are filtered client-side by
/// [OrderRepository.list] (see API_ASSUMPTIONS.md §6).
enum OrderFilter {
  all(null),
  active({
    'pending_payment',
    'confirmed',
    'processing',
    'partially_fulfilled',
    'ready_for_pickup',
    'out_for_delivery',
    'cancellation_requested',
  }),
  completed({'completed'}),
  cancelled({'cancelled', 'partially_cancelled', 'returned'});

  const OrderFilter(this.statuses);
  final Set<String>? statuses;
}

class OrdersController extends PagedNotifier<OrderSummary> {
  OrdersController(this.filter);
  final OrderFilter filter;

  @override
  void watchDependencies() {
    ref.watch(isAuthenticatedProvider);
    ref.watch(localeCodeProvider);
  }

  @override
  Future<Paginated<OrderSummary>> fetchPage(int page) =>
      ref.read(orderRepositoryProvider).list(statuses: filter.statuses, page: page);
}

final ordersProvider = AsyncNotifierProvider.autoDispose
    .family<OrdersController, PagedState<OrderSummary>, OrderFilter>(OrdersController.new);

final orderDetailProvider = FutureProvider.autoDispose.family<Order, String>((ref, id) {
  ref.watch(localeCodeProvider);
  return ref.watch(orderRepositoryProvider).get(id);
});

final orderTimelineProvider = FutureProvider.autoDispose.family<List<TimelineEntry>, String>((ref, id) {
  ref.watch(localeCodeProvider);
  return ref.watch(orderRepositoryProvider).timeline(id);
});

final pickupPassProvider = FutureProvider.autoDispose.family<PickupPass, ({String orderId, String fulfillmentId})>(
  (ref, args) => ref.watch(orderRepositoryProvider).pickupPass(args.orderId, args.fulfillmentId),
);

/// Orders in progress for the signed-in customer (empty for guests).
final activeOrdersProvider = FutureProvider.autoDispose<List<OrderSummary>>((ref) {
  ref.watch(localeCodeProvider);
  if (!ref.watch(isAuthenticatedProvider)) return const [];
  return ref.watch(orderRepositoryProvider).active();
});
