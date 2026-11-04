import 'package:flutter/foundation.dart';

import '../../../core/storage/token_storage.dart';
import '../../../core/utils/json.dart';

/// `UserResource`.
@immutable
class AppUser {
  const AppUser({
    required this.id,
    required this.name,
    required this.phone,
    this.type = 'customer',
    this.email,
    this.phoneVerified = false,
    this.locale,
    this.avatarUrl,
    this.dateOfBirth,
    this.gender,
    this.referralCode,
    this.marketingConsent = false,
    this.hasPassword = false,
    this.createdAt,
  });

  factory AppUser.fromJson(JsonMap json) => AppUser(
    id: asString(json['id']),
    type: asString(json['type'], 'customer'),
    name: asString(json['name']),
    phone: asString(json['phone']),
    email: asStringOrNull(json['email']),
    phoneVerified: asBool(json['phone_verified']),
    locale: asStringOrNull(json['locale']),
    avatarUrl: asStringOrNull(json['avatar_url']),
    dateOfBirth: asStringOrNull(json['date_of_birth']),
    gender: asStringOrNull(json['gender']),
    referralCode: asStringOrNull(json['referral_code']),
    marketingConsent: asBool(json['marketing_consent']),
    hasPassword: asBool(json['has_password']),
    createdAt: asDate(json['created_at']),
  );

  final String id;
  final String type;
  final String name;
  final String phone;
  final String? email;
  final bool phoneVerified;
  final String? locale;
  final String? avatarUrl;

  /// `YYYY-MM-DD`.
  final String? dateOfBirth;

  /// `male` | `female` | null.
  final String? gender;
  final String? referralCode;
  final bool marketingConsent;
  final bool hasPassword;
  final DateTime? createdAt;

  String get firstName => name.trim().split(RegExp(r'\s+')).first;

  JsonMap toJson() => {
    'id': id,
    'type': type,
    'name': name,
    'phone': phone,
    'email': email,
    'phone_verified': phoneVerified,
    'locale': locale,
    'avatar_url': avatarUrl,
    'date_of_birth': dateOfBirth,
    'gender': gender,
    'referral_code': referralCode,
    'marketing_consent': marketingConsent,
    'has_password': hasPassword,
    'created_at': createdAt?.toIso8601String(),
  };
}

/// `POST /auth/otp/request` response.
@immutable
class OtpRequestResult {
  const OtpRequestResult({required this.sent, required this.expiresIn, required this.resendIn, this.debugCode});

  factory OtpRequestResult.fromJson(JsonMap json) => OtpRequestResult(
    sent: asBool(json['sent'], true),
    expiresIn: asInt(json['expires_in'], 300),
    resendIn: asInt(json['resend_in'], 60),
    debugCode: asStringOrNull(json['debug_code']),
  );

  final bool sent;
  final int expiresIn;
  final int resendIn;

  /// Only present in local development builds of the backend.
  final String? debugCode;
}

/// `{status, user, tokens}` or `{status: registration_required, …}`.
sealed class AuthResult {
  const AuthResult();

  factory AuthResult.fromJson(JsonMap json) {
    final status = asString(json['status']);
    if (status == 'registration_required') {
      return RegistrationRequired(
        registrationToken: asString(json['registration_token']),
        expiresIn: asInt(json['expires_in'], 900),
      );
    }
    return Authenticated(
      user: AppUser.fromJson(asMap(json['user'])),
      tokens: AuthTokens.fromJson(asMap(json['tokens'])),
    );
  }
}

class Authenticated extends AuthResult {
  const Authenticated({required this.user, required this.tokens});
  final AppUser user;
  final AuthTokens tokens;
}

class RegistrationRequired extends AuthResult {
  const RegistrationRequired({required this.registrationToken, required this.expiresIn});
  final String registrationToken;
  final int expiresIn;
}

@immutable
class RegistrationRequest {
  const RegistrationRequest({
    required this.registrationToken,
    required this.name,
    this.email,
    this.password,
    this.locale,
    this.referralCode,
    this.marketingConsent = false,
  });

  final String registrationToken;
  final String name;
  final String? email;
  final String? password;
  final String? locale;
  final String? referralCode;
  final bool marketingConsent;

  JsonMap toJson(Map<String, String> device) => {
    'registration_token': registrationToken,
    'name': name,
    if (email != null && email!.isNotEmpty) 'email': email,
    if (password != null && password!.isNotEmpty) ...{
      'password': password,
      'password_confirmation': password,
    },
    'locale': ?locale,
    if (referralCode != null && referralCode!.isNotEmpty) 'referral_code': referralCode,
    'marketing_consent': marketingConsent,
    ...device,
  };
}
