import 'package:albahar_customer/core/network/api_client.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/push/push_service.dart';
import 'package:albahar_customer/core/storage/app_preferences.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_staff_models.dart';
import 'package:albahar_customer/ops/staff/staff_providers.dart';
import 'package:dio/dio.dart';
import 'package:flutter_riverpod/misc.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// A clock as the server writes it.
Map<String, dynamic> clockJson(
  String state,
  int age, {
  String kind = 'pending',
  bool paused = false,
  int? amberAt = 5,
  int? redAt = 15,
}) => {
  'state': state,
  'age_minutes': age,
  'label': '$age min',
  'kind': kind,
  'wall_minutes': age,
  'paused': paused,
  'amber_at': amberAt,
  'red_at': redAt,
};

/// One row of the staff queue with the fields of the order clock era.
QueueOrder staffOrder({
  String id = 'f1',
  String status = 'pending',
  String number = 'AB100001',
  String type = 'delivery',
  Map<String, dynamic>? clock,
  Map<String, dynamic>? cancellation,
  String method = 'cod',
  String total = '120.00',
  String paymentStatus = 'pending',
  String due = '0.00',
  Map<String, dynamic>? pickup,
  String? orderId,
  String customer = 'Hamad',
  String? phone,
  String? driver,
  String? placedAt,
  Map<String, dynamic>? waiting,
}) => QueueOrder.fromJson({
  'fulfillment_id': id,
  'order_id': orderId ?? 'o-$id',
  'type': type,
  'status': status,
  'number': number,
  'placed_at': placedAt,
  'customer_name': customer,
  'customer_phone': phone,
  'items_count': 3,
  'payment_method': method,
  'payment_status': paymentStatus,
  'amount_due': due,
  'grand_total': total,
  'address': {'zone_number': '24', 'street_number': '9'},
  'delivery': driver == null
      ? null
      : {
          'id': 'dl-$id',
          'status': 'assigned',
          'driver': {'id': 'u1', 'name': driver},
        },
  'clock': clock,
  'cancellation_request': cancellation,
  'waiting_for_customer': waiting,
  'pickup': pickup,
  'items': [
    {'id': 'i-$id', 'name': 'Oslo sofa', 'quantity': 3},
  ],
});

Map<String, dynamic> cancellationJson({
  String id = 'c1',
  String reason = 'Changed my mind',
  String status = 'requested',
}) => {'id': id, 'status': status, 'reason': reason, 'requested_at': '2026-11-19T10:00:00+03:00'};

/// The overview of branch `b1` with whatever a test needs on top.
StaffOverview staffOverview({
  String id = 'b1',
  String name = 'Al Markhiya Main Branch',
  int fresh = 1,
  int preparing = 0,
  int ready = 1,
  int pickupNew = 0,
  int pickupPreparing = 0,
  int pickupReady = 0,
  List<Map<String, String>> branches = const [],
  Map<String, bool>? can,
}) => StaffOverview.fromJson({
  'branch': {'id': id, 'name': name, 'dispatch_mode': 'manual'},
  'new': fresh,
  'preparing': preparing,
  'ready': ready,
  'out_for_delivery': 0,
  'failed': 0,
  'waiting_for_driver': 0,
  'drivers_on_duty': 1,
  'drivers_total': 2,
  'pickup': {'new': pickupNew, 'preparing': pickupPreparing, 'ready': pickupReady},
  'branches': branches,
  'can': ?can,
});

/// A pass that checked out for hand-over.
PickupCheck passOk({
  String number = 'AB100050',
  String customer = 'Noura',
  String method = 'pay_at_pickup',
  String due = '0.00',
  List<PickupItem>? items,
}) => PickupCheck(
  result: 'success',
  reservationId: 'rv1',
  orderNumber: number,
  customerName: customer,
  paymentMethod: method,
  paymentStatus: due == '0.00' ? 'paid' : 'pending',
  amountDue: due,
  items:
      items ??
      const [
        PickupItem(id: 'i1', name: 'Oslo sofa', variant: 'Grey', quantity: 3, handedOver: 0),
        PickupItem(id: 'i2', name: 'Oslo lamp', quantity: 1, handedOver: 0),
      ],
);

Map<String, dynamic> attentionItemJson(
  String id,
  String kind, {
  String target = 'open_order',
  String state = 'red',
  int age = 20,
  String subject = 'AB100001',
  String? fulfillmentId,
  String? orderId,
  String? branchId,
  String detail = 'Hamad · 3 items',
  String? hint,
  String? phone,
}) => {
  'id': id,
  'kind': kind,
  'target': target,
  'in_app': target != 'dashboard',
  'subject': subject,
  'subject_is_number': true,
  'label': 'Waiting to be accepted',
  'detail': detail,
  'note': null,
  'state': state,
  'age_minutes': age,
  'age_label': '$age min',
  'branch_id': branchId,
  'branch_code': 'AAA',
  'order_id': orderId,
  'fulfillment_id': fulfillmentId,
  'mine': false,
  'call': phone == null ? null : {'label': 'Call customer', 'phone': phone},
  'hint': hint,
};

AttentionSummary attentionOf(List<Map<String, dynamic>> items, {int? total}) => AttentionSummary.fromJson({
  'items': items,
  'counts': {
    'total': total ?? items.length,
    'red': items.where((i) => i['state'] == 'red').length,
    'amber': items.where((i) => i['state'] == 'amber').length,
    'ok': items.where((i) => i['state'] == 'ok').length,
    'by_kind': <String, int>{},
  },
  'truncated': false,
});

/// A phone that remembers: real preferences with nothing in them, for the tests that follow what is kept on the device.
Future<Override> memoryPrefs([Map<String, Object> initial = const {}]) async {
  SharedPreferences.setMockInitialValues(initial);
  return sharedPreferencesProvider.overrideWithValue(await SharedPreferences.getInstance());
}

/// A chime that only counts.
class CountingChime implements StaffChime {
  int plays = 0;

  @override
  void play() => plays++;
}

class StaffTestLocalNotifier implements LocalNotifier {
  @override
  Future<void> initialize(void Function(String? payload) onTap) async {}
  @override
  Future<void> show(PushMessage message) async {}
  @override
  Future<void> showProgress(Map<String, dynamic> data) async {}
  @override
  Future<String?> launchPayload() async => null;
}

/// A push service with no Firebase: tests hand it a tapped notification ([PushService.debugHandleTap]) exactly as the phone would.
PushService staffPushService(SharedPreferences prefs) => PushService(
  messaging: NoopPushMessaging(),
  local: StaffTestLocalNotifier(),
  api: ApiClient(Dio()),
  prefs: AppPreferences(prefs),
  enabled: false,
);
