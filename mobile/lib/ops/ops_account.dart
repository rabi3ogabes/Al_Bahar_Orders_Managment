import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

/// The code the staff API answers (HTTP 403) to every call but `me` and the password change while an employee still has the
/// temporary password an administrator set (see `docs/guides/BRANCH_EMPLOYEES.md`).
const kPasswordChangeRequired = 'password_change_required';

/// What the staff sign-in answers: the token pair, and whether the person must choose their own password before anything else.
@immutable
class OpsLogin {
  const OpsLogin({required this.tokens, this.mustChangePassword = false});

  /// The body of `POST /admin/auth/login` (already unwrapped from `data`).
  factory OpsLogin.fromJson(JsonMap json) => OpsLogin(
    tokens: AuthTokens.fromJson(asMap(json['tokens'])),
    mustChangePassword: asBool(json['must_change_password']),
  );

  final AuthTokens tokens;
  final bool mustChangePassword;
}

/// The signed-in staff member as `GET /admin/me` describes them.
@immutable
class OpsAccount {
  const OpsAccount({required this.permissions, this.mustChangePassword = false});

  factory OpsAccount.fromJson(JsonMap json) => OpsAccount(
    permissions: asBool(json['is_super_admin']) ? const ['*'] : asStringList(json['permissions']),
    mustChangePassword: asBool(json['must_change_password']),
  );

  final List<String> permissions;
  final bool mustChangePassword;
}

/// Tells the session that the server wants the person to choose their own password: any staff call can say so (the account may have
/// been reset while the app was open), so it is read from every answer, not only from the sign-in.
class PasswordChangeInterceptor extends Interceptor {
  PasswordChangeInterceptor(this.onRequired);

  final VoidCallback onRequired;

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    final response = err.response;
    if (response?.statusCode == 403 && asMap(response?.data)['code'] == kPasswordChangeRequired) onRequired();
    handler.next(err);
  }
}

/// The server's rules for a new password (Laravel `Password::min(12)->mixedCase()->numbers()` and `different:current_password`),
/// worked out as the person types so the checklist can tick each one.
@immutable
class PasswordRules {
  const PasswordRules({
    required this.longEnough,
    required this.mixedCase,
    required this.hasNumber,
    required this.differs,
    required this.matches,
  });

  factory PasswordRules.check({required String current, required String password, required String repeat}) =>
      PasswordRules(
        longEnough: password.runes.length >= minLength,
        mixedCase: _lower.hasMatch(password) && _upper.hasMatch(password),
        hasNumber: _number.hasMatch(password),
        differs: password.isNotEmpty && password != current,
        matches: password.isNotEmpty && password == repeat,
      );

  static const minLength = 12;
  static final _lower = RegExp(r'\p{Ll}', unicode: true);
  static final _upper = RegExp(r'\p{Lu}', unicode: true);
  static final _number = RegExp(r'\p{N}', unicode: true);

  final bool longEnough;
  final bool mixedCase;
  final bool hasNumber;
  final bool differs;
  final bool matches;

  /// The four rules of the checklist (the repeat field has its own message).
  bool get strong => longEnough && mixedCase && hasNumber && differs;

  /// Everything the server will check, so the button may be pressed.
  bool get valid => strong && matches;
}

/// The staff sign-in takes an e-mail or a mobile number. An e-mail is sent as typed (only trimmed, never lower-cased: the server does
/// that); a number written with Arabic-Indic digits is sent with ordinary digits, the way the server reads it.
String normalizeLoginName(String raw) {
  final text = raw.trim();
  if (text.contains('@')) return text;
  final out = StringBuffer();
  for (final r in text.runes) {
    if (r >= 0x0660 && r <= 0x0669) {
      out.writeCharCode(0x30 + r - 0x0660);
    } else if (r >= 0x06F0 && r <= 0x06F9) {
      out.writeCharCode(0x30 + r - 0x06F0);
    } else {
      out.writeCharCode(r);
    }
  }
  return out.toString();
}
