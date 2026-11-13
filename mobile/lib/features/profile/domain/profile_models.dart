import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:flutter/foundation.dart';

@immutable
class Address {
  const Address({
    required this.id,
    required this.recipientName,
    required this.recipientPhone,
    this.label,
    this.deliveryZone,
    this.zoneNumber,
    this.streetNumber,
    this.buildingNumber,
    this.unitNumber,
    this.area,
    this.city,
    this.landmark,
    this.instructions,
    this.latitude,
    this.longitude,
    this.isDefault = false,
  });

  factory Address.fromJson(JsonMap json) => Address(
    id: asString(json['id']),
    label: asStringOrNull(json['label']),
    recipientName: asString(json['recipient_name']),
    recipientPhone: asString(json['recipient_phone']),
    deliveryZone: NamedRef.maybe(json['delivery_zone']),
    zoneNumber: asStringOrNull(json['zone_number']),
    streetNumber: asStringOrNull(json['street_number']),
    buildingNumber: asStringOrNull(json['building_number']),
    unitNumber: asStringOrNull(json['unit_number']),
    area: asStringOrNull(json['area']),
    city: asStringOrNull(json['city']),
    landmark: asStringOrNull(json['landmark']),
    instructions: asStringOrNull(json['instructions']),
    latitude: asDoubleOrNull(json['latitude']),
    longitude: asDoubleOrNull(json['longitude']),
    isDefault: asBool(json['is_default']),
  );

  final String id;
  final String? label;
  final String recipientName;
  final String recipientPhone;
  final NamedRef? deliveryZone;
  final String? zoneNumber;
  final String? streetNumber;
  final String? buildingNumber;
  final String? unitNumber;
  final String? area;
  final String? city;
  final String? landmark;
  final String? instructions;
  final double? latitude;
  final double? longitude;
  final bool isDefault;

  bool get hasPin => latitude != null && longitude != null;

  /// Qatar-style one-liner: "Zone 66, Street 850, Building 12, Unit 4 · West Bay".
  String summary({
    String zone = 'Zone',
    String street = 'Street',
    String building = 'Building',
    String unit = 'Unit',
    String separator = ', ',
  }) {
    final parts = <String>[
      if (zoneNumber != null) '$zone $zoneNumber',
      if (streetNumber != null) '$street $streetNumber',
      if (buildingNumber != null) '$building $buildingNumber',
      if (unitNumber != null) '$unit $unitNumber',
    ];
    final place = [area, city].whereType<String>().where((s) => s.isNotEmpty).join(separator);
    return [parts.join(separator), place].where((s) => s.isNotEmpty).join(' · ');
  }
}

/// Create/update body for addresses.
@immutable
class AddressInput {
  const AddressInput({
    required this.recipientName,
    required this.recipientPhone,
    this.label,
    this.deliveryZoneId,
    this.zoneNumber,
    this.streetNumber,
    this.buildingNumber,
    this.unitNumber,
    this.area,
    this.city,
    this.landmark,
    this.instructions,
    this.latitude,
    this.longitude,
    this.isDefault = false,
  });

  final String recipientName;
  final String recipientPhone;
  final String? label;
  final String? deliveryZoneId;
  final String? zoneNumber;
  final String? streetNumber;
  final String? buildingNumber;
  final String? unitNumber;
  final String? area;
  final String? city;
  final String? landmark;
  final String? instructions;
  final double? latitude;
  final double? longitude;
  final bool isDefault;

  /// Contract: recipient + (zone+street+building) or (lat+lng).
  bool get isLocatable =>
      (_has(zoneNumber) && _has(streetNumber) && _has(buildingNumber)) || (latitude != null && longitude != null);

  static bool _has(String? s) => s != null && s.trim().isNotEmpty;

  JsonMap toJson() {
    String? clean(String? s) => _has(s) ? s!.trim() : null;
    return {
      'label': clean(label),
      'recipient_name': recipientName.trim(),
      'recipient_phone': recipientPhone,
      'delivery_zone_id': deliveryZoneId,
      'zone_number': clean(zoneNumber),
      'street_number': clean(streetNumber),
      'building_number': clean(buildingNumber),
      'unit_number': clean(unitNumber),
      'area': clean(area),
      'city': clean(city) ?? 'Doha',
      'landmark': clean(landmark),
      'instructions': clean(instructions),
      'latitude': latitude,
      'longitude': longitude,
      'is_default': isDefault,
    };
  }
}

@immutable
class NotificationPreference {
  const NotificationPreference({required this.channel, required this.category, required this.isEnabled});
  factory NotificationPreference.fromJson(JsonMap json) => NotificationPreference(
    channel: asString(json['channel']),
    category: asString(json['category']),
    isEnabled: asBool(json['is_enabled']),
  );
  final String channel;
  final String category;
  final bool isEnabled;

  NotificationPreference withEnabled(bool v) =>
      NotificationPreference(channel: channel, category: category, isEnabled: v);

  JsonMap toJson() => {'channel': channel, 'category': category, 'is_enabled': isEnabled};
}

@immutable
class LoyaltyTransaction {
  const LoyaltyTransaction({
    required this.type,
    required this.points,
    this.balanceAfter,
    this.reason,
    this.createdAt,
    this.expiresAt,
  });
  factory LoyaltyTransaction.fromJson(JsonMap json) => LoyaltyTransaction(
    type: asString(json['type']),
    points: asInt(json['points']),
    balanceAfter: asIntOrNull(json['balance_after']),
    reason: asStringOrNull(json['reason']),
    createdAt: asDate(json['created_at']),
    expiresAt: asDate(json['expires_at']),
  );
  final String type;
  final int points;
  final int? balanceAfter;
  final String? reason;
  final DateTime? createdAt;
  final DateTime? expiresAt;
}

@immutable
class LoyaltySummary {
  const LoyaltySummary({
    required this.balance,
    this.tier,
    this.value,
    this.lifetimeEarned = 0,
    this.transactions = const [],
  });
  factory LoyaltySummary.fromJson(JsonMap json) => LoyaltySummary(
    balance: asInt(json['balance']),
    tier: asStringOrNull(json['tier'] is Map ? asMap(json['tier'])['name'] : json['tier']),
    value: Money.maybe(json['value']),
    lifetimeEarned: asInt(json['lifetime_earned']),
    transactions: mapList(json['transactions'], LoyaltyTransaction.fromJson),
  );
  final int balance;
  final String? tier;
  final Money? value;
  final int lifetimeEarned;
  final List<LoyaltyTransaction> transactions;
}

/// One invited friend: first name and progress only (no personal data).
@immutable
class ReferralFriend {
  const ReferralFriend({required this.name, required this.status});
  factory ReferralFriend.fromJson(JsonMap json) =>
      ReferralFriend(name: asString(json['name']), status: asString(json['status'], 'pending'));
  final String name;

  /// `pending` | `rewarded` | `closed`.
  final String status;
}

@immutable
class ReferralInfo {
  const ReferralInfo({
    this.enabled = true,
    this.code = '',
    this.shareUrl,
    this.shareMessage,
    this.referrerRewardText,
    this.friendRewardText,
    this.referredCount = 0,
    this.pendingCount = 0,
    this.rewardedCount = 0,
    this.earnedTotal,
    this.pendingTotal,
    this.friends = const [],
  });
  factory ReferralInfo.fromJson(JsonMap json) {
    if (json['enabled'] == false) return const ReferralInfo(enabled: false);
    final referrer = json['referrer_reward'] is Map ? asMap(json['referrer_reward']) : const <String, dynamic>{};
    final friend = json['friend_reward'] is Map ? asMap(json['friend_reward']) : const <String, dynamic>{};
    return ReferralInfo(
      code: asString(json['code']),
      shareUrl: asStringOrNull(json['share_url']),
      shareMessage: asStringOrNull(json['share_message']),
      referrerRewardText: asStringOrNull(referrer['text']),
      friendRewardText: asStringOrNull(friend['text']),
      referredCount: asInt(json['referred_count']),
      pendingCount: asInt(json['pending_count']),
      rewardedCount: asInt(json['rewarded_count']),
      earnedTotal: Money.maybe(json['earned_total']),
      pendingTotal: Money.maybe(json['pending_total']),
      friends: mapList(json['friends'], ReferralFriend.fromJson),
    );
  }
  final bool enabled;
  final String code;
  final String? shareUrl;
  final String? shareMessage;
  final String? referrerRewardText;
  final String? friendRewardText;
  final int referredCount;
  final int pendingCount;
  final int rewardedCount;
  final Money? earnedTotal;
  final Money? pendingTotal;
  final List<ReferralFriend> friends;
}

@immutable
class DeletionRequest {
  const DeletionRequest({required this.id, required this.status, this.scheduledFor});
  factory DeletionRequest.fromJson(JsonMap json) => DeletionRequest(
    id: asString(json['id']),
    status: asString(json['status']),
    scheduledFor: asDate(json['scheduled_for']),
  );
  final String id;
  final String status;
  final DateTime? scheduledFor;
}
