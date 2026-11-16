import 'package:albahar_customer/core/utils/json.dart';
import 'package:flutter/foundation.dart';

/// The dashboard's public settings (`GET /app-config`, no sign-in needed). Empty text means "show nothing".
@immutable
class PublicConfig {
  const PublicConfig({this.designedByEn = '', this.designedByAr = '', this.alternatePhoneEnabled = true});

  factory PublicConfig.fromJson(JsonMap json) {
    final designedBy = asMap(json['designed_by']);
    return PublicConfig(
      designedByEn: asString(designedBy['en']).trim(),
      designedByAr: asString(designedBy['ar']).trim(),
      // On until the dashboard says otherwise: an older server, a missing key or a wrong type all mean "ask for it as before".
      alternatePhoneEnabled: asBool(json['alternate_phone_enabled'], true),
    );
  }

  /// What the app showed before the server answered (and what it shows when nothing was ever cached).
  static const empty = PublicConfig();

  final String designedByEn;
  final String designedByAr;

  /// Dashboard switch "Alternative phone number" (`alternate_phone_enabled`). Off: the apps neither show nor send the number.
  final bool alternatePhoneEnabled;

  /// The "Designed by" line in [localeCode]'s language, the other language's line when its own is empty, or null (hide it).
  String? designedBy(String localeCode) {
    final (own, other) = localeCode == 'ar' ? (designedByAr, designedByEn) : (designedByEn, designedByAr);
    if (own.isNotEmpty) return own;
    return other.isEmpty ? null : other;
  }

  JsonMap toJson() => {
    'designed_by': {'en': designedByEn, 'ar': designedByAr},
    'alternate_phone_enabled': alternatePhoneEnabled,
  };

  @override
  bool operator ==(Object other) =>
      other is PublicConfig &&
      other.designedByEn == designedByEn &&
      other.designedByAr == designedByAr &&
      other.alternatePhoneEnabled == alternatePhoneEnabled;

  @override
  int get hashCode => Object.hash(designedByEn, designedByAr, alternatePhoneEnabled);
}
