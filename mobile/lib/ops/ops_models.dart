import 'package:albahar_customer/core/utils/json.dart';
import 'package:flutter/foundation.dart';

/// Which of the two staff apps this build is.
enum OpsRole { driver, staff }

@immutable
class OpsAddress {
  const OpsAddress({required this.line, this.lat, this.lng, this.notes});

  factory OpsAddress.fromJson(Object? raw) {
    final j = raw is Map ? asMap(raw) : <String, dynamic>{};
    final parts = <String>[
      if (asStringOrNull(j['zone_number']) != null) 'Zone ${asString(j['zone_number'])}',
      if (asStringOrNull(j['street_number']) != null) 'Street ${asString(j['street_number'])}',
      if (asStringOrNull(j['building_number']) != null) 'Bldg ${asString(j['building_number'])}',
      if (asStringOrNull(j['unit_number']) != null) 'Unit ${asString(j['unit_number'])}',
      if (asStringOrNull(j['area']) != null) asString(j['area']),
    ];
    return OpsAddress(
      line: parts.isEmpty ? asString(j['label'], '—') : parts.join(' · '),
      lat: asDoubleOrNull(j['latitude']),
      lng: asDoubleOrNull(j['longitude']),
      notes: asStringOrNull(j['notes']) ?? asStringOrNull(j['landmark']),
    );
  }

  final String line;
  final double? lat;
  final double? lng;
  final String? notes;
}

/// One delivery job as the driver sees it.
@immutable
class DriverJob {
  const DriverJob({
    required this.id,
    required this.status,
    required this.orderNumber,
    required this.customerName,
    required this.customerPhone,
    required this.address,
    required this.paymentMethod,
    required this.codAmount,
    required this.requiresProof,
    required this.branchName,
    required this.scheduledFor,
  });

  factory DriverJob.fromJson(JsonMap j) => DriverJob(
    id: asString(j['id']),
    status: asString(j['status']),
    orderNumber: asString(j['order_number']),
    customerName: asString(j['customer_name']),
    customerPhone: asStringOrNull(j['customer_phone']),
    address: OpsAddress.fromJson(j['address']),
    paymentMethod: asString(j['payment_method']),
    codAmount: double.tryParse(asString(j['cod_amount'], '0')) ?? 0,
    requiresProof: asBool(j['requires_proof'], true),
    branchName: asStringOrNull(asMapOrNull(j['branch'])?['name']) ?? '',
    scheduledFor: asDate(j['scheduled_for']),
  );

  final String id;
  final String status;
  final String orderNumber;
  final String customerName;
  final String? customerPhone;
  final OpsAddress address;
  final String paymentMethod;
  final double codAmount;
  final bool requiresProof;
  final String branchName;
  final DateTime? scheduledFor;

  bool get isCod => paymentMethod == 'cod' && codAmount > 0;

  /// The next driver action for this status (null when nothing is left to do).
  String? get nextAction => switch (status) {
    'assigned' => 'accept',
    'accepted' => 'pickup',
    'picked_up' => 'out-for-delivery',
    'out_for_delivery' => 'deliver',
    _ => null,
  };
}

@immutable
class DriverMe {
  const DriverMe({required this.name, required this.onDuty, required this.openJobs, required this.branches});

  factory DriverMe.fromJson(JsonMap j) => DriverMe(
    name: asString(j['name']),
    onDuty: asBool(j['on_duty']),
    openJobs: asInt(j['open_jobs']),
    branches: mapList(j['branches'], (b) => asString(b['name'])),
  );

  final String name;
  final bool onDuty;
  final int openJobs;
  final List<String> branches;
}

@immutable
class StaffOverview {
  const StaffOverview({
    required this.branchId,
    required this.branchName,
    required this.autoDispatch,
    required this.fresh,
    required this.preparing,
    required this.ready,
    required this.out,
    required this.waitingForDriver,
    required this.driversOnDuty,
    required this.driversTotal,
  });

  factory StaffOverview.fromJson(JsonMap j) {
    final b = asMap(j['branch']);
    return StaffOverview(
      branchId: asString(b['id']),
      branchName: asString(b['name']),
      autoDispatch: asString(b['dispatch_mode']) == 'auto',
      fresh: asInt(j['new']),
      preparing: asInt(j['preparing']),
      ready: asInt(j['ready']),
      out: asInt(j['out_for_delivery']),
      waitingForDriver: asInt(j['waiting_for_driver']),
      driversOnDuty: asInt(j['drivers_on_duty']),
      driversTotal: asInt(j['drivers_total']),
    );
  }

  final String branchId;
  final String branchName;
  final bool autoDispatch;
  final int fresh;
  final int preparing;
  final int ready;
  final int out;
  final int waitingForDriver;
  final int driversOnDuty;
  final int driversTotal;
}

/// A delivery order in the branch queue.
@immutable
class QueueOrder {
  const QueueOrder({
    required this.fulfillmentId,
    required this.status,
    required this.number,
    required this.customerName,
    required this.customerPhone,
    required this.address,
    required this.itemsCount,
    required this.paymentMethod,
    required this.total,
    required this.deliveryStatus,
    required this.driverName,
    required this.driverId,
  });

  factory QueueOrder.fromJson(JsonMap j) {
    final d = asMapOrNull(j['delivery']);
    final driver = asMapOrNull(d?['driver']);
    return QueueOrder(
      fulfillmentId: asString(j['fulfillment_id']),
      status: asString(j['status']),
      number: asString(j['number']),
      customerName: asString(j['customer_name']),
      customerPhone: asStringOrNull(j['customer_phone']),
      address: OpsAddress.fromJson(j['address']),
      itemsCount: asInt(j['items_count']),
      paymentMethod: asString(j['payment_method']),
      total: asString(j['grand_total'], '0'),
      deliveryStatus: asStringOrNull(d?['status']),
      driverName: asStringOrNull(driver?['name']),
      driverId: asStringOrNull(driver?['id']),
    );
  }

  final String fulfillmentId;
  final String status;
  final String number;
  final String customerName;
  final String? customerPhone;
  final OpsAddress address;
  final int itemsCount;
  final String paymentMethod;
  final String total;
  final String? deliveryStatus;
  final String? driverName;
  final String? driverId;

  /// Staff step for the order status: accept → start preparing → mark ready; then assign a driver.
  String? get nextStep => switch (status) {
    'pending' => 'accepted',
    'accepted' => 'preparing',
    'preparing' => 'ready',
    _ => null,
  };
}

@immutable
class BranchDriver {
  const BranchDriver({
    required this.id,
    required this.name,
    required this.phone,
    required this.onDuty,
    required this.openJobs,
  });

  factory BranchDriver.fromJson(JsonMap j) => BranchDriver(
    id: asString(j['id']),
    name: asString(j['name']),
    phone: asStringOrNull(j['phone']),
    onDuty: asBool(j['on_duty']),
    openJobs: asInt(j['open_jobs']),
  );

  final String id;
  final String name;
  final String? phone;
  final bool onDuty;
  final int openJobs;
}
