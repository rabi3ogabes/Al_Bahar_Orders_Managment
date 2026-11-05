import 'package:flutter/foundation.dart';

import '../../../core/utils/json.dart';

@immutable
class BranchOpening {
  const BranchOpening({required this.isOpen, this.reason, this.closesAt, this.nextOpenAt});
  factory BranchOpening.fromJson(JsonMap json) => BranchOpening(
    isOpen: asBool(json['is_open']),
    reason: asStringOrNull(json['reason']),
    closesAt: asDate(json['closes_at']),
    nextOpenAt: asDate(json['next_open_at']),
  );
  final bool isOpen;
  final String? reason;
  final DateTime? closesAt;
  final DateTime? nextOpenAt;
}

@immutable
class Branch {
  const Branch({
    required this.id,
    required this.name,
    this.code,
    this.description,
    this.phone,
    this.email,
    this.address,
    this.area,
    this.latitude,
    this.longitude,
    this.pickupEnabled = false,
    this.deliveryEnabled = false,
    this.codEnabled = false,
    this.payAtPickupEnabled = false,
    this.opening,
    this.distanceKm,
  });

  factory Branch.fromJson(JsonMap json) => Branch(
    id: asString(json['id']),
    code: asStringOrNull(json['code']),
    name: asString(json['name']),
    description: asStringOrNull(json['description']),
    phone: asStringOrNull(json['phone']),
    email: asStringOrNull(json['email']),
    address: asStringOrNull(json['address']),
    area: asStringOrNull(json['area']),
    latitude: asDoubleOrNull(json['latitude']),
    longitude: asDoubleOrNull(json['longitude']),
    pickupEnabled: asBool(json['pickup_enabled']),
    deliveryEnabled: asBool(json['delivery_enabled']),
    codEnabled: asBool(json['cod_enabled']),
    payAtPickupEnabled: asBool(json['pay_at_pickup_enabled']),
    opening: json['opening'] is Map ? BranchOpening.fromJson(asMap(json['opening'])) : null,
    distanceKm: asDoubleOrNull(json['distance_km']),
  );

  final String id;
  final String? code;
  final String name;
  final String? description;
  final String? phone;
  final String? email;
  final String? address;
  final String? area;
  final double? latitude;
  final double? longitude;
  final bool pickupEnabled;
  final bool deliveryEnabled;
  final bool codEnabled;
  final bool payAtPickupEnabled;
  final BranchOpening? opening;
  final double? distanceKm;

  bool get hasLocation => latitude != null && longitude != null;

  BranchRef get ref => BranchRef(id: id, name: name);
}

/// Minimal persisted reference to the preferred branch.
@immutable
class BranchRef {
  const BranchRef({required this.id, required this.name});
  factory BranchRef.fromJson(JsonMap json) => BranchRef(id: asString(json['id']), name: asString(json['name']));
  final String id;
  final String name;
  JsonMap toJson() => {'id': id, 'name': name};

  @override
  bool operator ==(Object other) => other is BranchRef && other.id == id;
  @override
  int get hashCode => id.hashCode;
}

@immutable
class WeeklyHours {
  const WeeklyHours({
    required this.dayOfWeek,
    required this.isClosed,
    this.opensAt,
    this.closesAt,
    this.breakStartsAt,
    this.breakEndsAt,
  });
  factory WeeklyHours.fromJson(JsonMap json) => WeeklyHours(
    dayOfWeek: asInt(json['day_of_week']),
    isClosed: asBool(json['is_closed']),
    opensAt: asStringOrNull(json['opens_at']),
    closesAt: asStringOrNull(json['closes_at']),
    breakStartsAt: asStringOrNull(json['break_starts_at']),
    breakEndsAt: asStringOrNull(json['break_ends_at']),
  );

  /// 0 = Sunday … 6 = Saturday.
  final int dayOfWeek;
  final bool isClosed;
  final String? opensAt;
  final String? closesAt;
  final String? breakStartsAt;
  final String? breakEndsAt;
}

@immutable
class BranchHoliday {
  const BranchHoliday({required this.name, this.type, this.startsAt, this.endsAt});
  factory BranchHoliday.fromJson(JsonMap json) => BranchHoliday(
    name: asString(json['name']),
    type: asStringOrNull(json['type']),
    startsAt: asDate(json['starts_at']),
    endsAt: asDate(json['ends_at']),
  );
  final String name;
  final String? type;
  final DateTime? startsAt;
  final DateTime? endsAt;
}

@immutable
class BranchHours {
  const BranchHours({required this.timezone, required this.weekly, required this.holidays});
  factory BranchHours.fromJson(JsonMap json) => BranchHours(
    timezone: asString(json['timezone'], 'Asia/Qatar'),
    weekly: mapList(json['weekly'], WeeklyHours.fromJson)..sort((a, b) => a.dayOfWeek.compareTo(b.dayOfWeek)),
    holidays: mapList(json['holidays'], BranchHoliday.fromJson),
  );
  final String timezone;
  final List<WeeklyHours> weekly;
  final List<BranchHoliday> holidays;
}

@immutable
class BranchSlot {
  const BranchSlot({
    required this.id,
    required this.date,
    required this.startsAt,
    required this.endsAt,
    required this.available,
    required this.isFull,
    this.capacity = 0,
  });
  factory BranchSlot.fromJson(JsonMap json) => BranchSlot(
    id: asString(json['id']),
    date: asString(json['date']),
    startsAt: asString(json['starts_at']),
    endsAt: asString(json['ends_at']),
    capacity: asInt(json['capacity']),
    available: asInt(json['available']),
    isFull: asBool(json['is_full']),
  );
  final int capacity;
  bool get selectable => !isFull && available > 0;
  final String id;
  final String date;
  final String startsAt;
  final String endsAt;
  final int available;
  final bool isFull;
}

/// Pickup and delivery slots share one shape (`/pickup-slots`, `/delivery-slots`).
typedef PickupSlot = BranchSlot;
typedef DeliverySlot = BranchSlot;

@immutable
class DeliveryZone {
  const DeliveryZone({required this.id, required this.name, this.zoneNumbers = const []});
  factory DeliveryZone.fromJson(JsonMap json) => DeliveryZone(
    id: asString(json['id']),
    name: asString(json['name']),
    zoneNumbers: asStringList(json['zone_numbers']),
  );
  final String id;
  final String name;
  final List<String> zoneNumbers;
}
