import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../utils/json.dart';

/// `tokens` object returned by the auth endpoints.
@immutable
class AuthTokens {
  const AuthTokens({
    required this.accessToken,
    required this.refreshToken,
    this.tokenType = 'Bearer',
    this.expiresIn,
    this.sessionId,
    this.obtainedAt,
  });

  factory AuthTokens.fromJson(JsonMap json) => AuthTokens(
    accessToken: asString(json['access_token']),
    refreshToken: asString(json['refresh_token']),
    tokenType: asString(json['token_type'], 'Bearer'),
    expiresIn: asIntOrNull(json['expires_in']),
    sessionId: asStringOrNull(json['session_id']),
    obtainedAt: asDate(json['obtained_at']) ?? DateTime.now(),
  );

  final String accessToken;
  final String refreshToken;
  final String tokenType;
  final int? expiresIn;
  final String? sessionId;
  final DateTime? obtainedAt;

  JsonMap toJson() => {
    'access_token': accessToken,
    'refresh_token': refreshToken,
    'token_type': tokenType,
    'expires_in': expiresIn,
    'session_id': sessionId,
    'obtained_at': (obtainedAt ?? DateTime.now()).toIso8601String(),
  };

  // Never print secrets.
  @override
  String toString() => 'AuthTokens(session: $sessionId)';
}

/// Persistence for auth tokens. Values are cached in memory after the first
/// read so interceptors can attach the header synchronously-ish.
abstract class TokenStore {
  Future<AuthTokens?> read();
  Future<void> write(AuthTokens tokens);
  Future<void> clear();

  /// Last known value (null if never read or cleared).
  AuthTokens? get cached;
}

/// Platform secure storage (Keychain / EncryptedSharedPreferences-backed
/// Keystore). On web it falls back to the plugin's WebCrypto storage.
class SecureTokenStore implements TokenStore {
  SecureTokenStore([FlutterSecureStorage? storage])
    : _storage = storage ?? const FlutterSecureStorage();

  static const _key = 'albahar.auth.tokens.v1';
  final FlutterSecureStorage _storage;
  AuthTokens? _cache;
  bool _loaded = false;

  @override
  AuthTokens? get cached => _cache;

  @override
  Future<AuthTokens?> read() async {
    if (_loaded) return _cache;
    try {
      final raw = await _storage.read(key: _key);
      _cache = raw == null ? null : AuthTokens.fromJson(asMap(jsonDecode(raw)));
    } catch (_) {
      // Corrupted entry (e.g. restored backup without keychain) => signed out.
      _cache = null;
      await _safeDelete();
    }
    _loaded = true;
    return _cache;
  }

  @override
  Future<void> write(AuthTokens tokens) async {
    _cache = tokens;
    _loaded = true;
    await _storage.write(key: _key, value: jsonEncode(tokens.toJson()));
  }

  @override
  Future<void> clear() async {
    _cache = null;
    _loaded = true;
    await _safeDelete();
  }

  Future<void> _safeDelete() async {
    try {
      await _storage.delete(key: _key);
    } catch (_) {}
  }
}

/// In-memory implementation used by tests and as a fallback.
class MemoryTokenStore implements TokenStore {
  MemoryTokenStore([this._tokens]);

  AuthTokens? _tokens;

  @override
  AuthTokens? get cached => _tokens;

  @override
  Future<AuthTokens?> read() async => _tokens;

  @override
  Future<void> write(AuthTokens tokens) async => _tokens = tokens;

  @override
  Future<void> clear() async => _tokens = null;
}
