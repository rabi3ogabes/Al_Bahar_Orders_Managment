import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_client.dart';
import '../../../core/network/interceptors.dart';
import '../../../core/providers.dart';
import '../../../core/utils/json.dart';
import '../../../core/utils/platform.dart';
import '../domain/auth_models.dart';

/// Endpoints under `/auth` and `/me` profile basics.
class AuthRepository {
  AuthRepository(this._api);

  final ApiClient _api;

  static Options get _public => Options(extra: {RequestFlags.skipAuth: true});

  Map<String, String> get _device => {'device_name': deviceName(), 'platform': platformCode()};

  Future<OtpRequestResult> requestOtp(String phone, {String purpose = 'login'}) async {
    final data = await _api.post(
      '/auth/otp/request',
      data: {'phone': phone, 'purpose': purpose},
      options: _public,
    );
    return OtpRequestResult.fromJson(asMap(data));
  }

  Future<AuthResult> verifyOtp(String phone, String code) async {
    final data = await _api.post(
      '/auth/otp/verify',
      data: {'phone': phone, 'code': code, ..._device},
      options: _public,
    );
    return AuthResult.fromJson(asMap(data));
  }

  Future<Authenticated> register(RegistrationRequest request) async {
    final data = await _api.post('/auth/register', data: request.toJson(_device), options: _public);
    final result = AuthResult.fromJson(asMap(data));
    if (result is Authenticated) return result;
    throw StateError('Unexpected registration response');
  }

  /// [identifier] may be a phone number or an email address.
  Future<Authenticated> login(String identifier, String password) async {
    final isEmail = identifier.contains('@');
    final data = await _api.post(
      '/auth/login',
      data: {isEmail ? 'email' : 'phone': identifier, 'password': password, ..._device},
      options: _public,
    );
    final result = AuthResult.fromJson(asMap(data));
    if (result is Authenticated) return result;
    throw StateError('Unexpected login response');
  }

  Future<void> resetPassword({required String phone, required String code, required String password}) async {
    await _api.post(
      '/auth/password/reset',
      data: {'phone': phone, 'code': code, 'password': password, 'password_confirmation': password},
      options: _public,
    );
  }

  Future<void> logout({bool allDevices = false}) async {
    await _api.post(allDevices ? '/auth/logout-all' : '/auth/logout');
  }

  Future<AppUser> me() => _api.getObject('/me', AppUser.fromJson);

  Future<AppUser> updateProfile(JsonMap changes) async =>
      AppUser.fromJson(asMap(await _api.patch('/me', data: changes)));

  Future<void> changePassword({String? currentPassword, required String password}) async {
    await _api.put(
      '/me/password',
      data: {
        if (currentPassword != null && currentPassword.isNotEmpty) 'current_password': currentPassword,
        'password': password,
        'password_confirmation': password,
      },
    );
  }
}

final authRepositoryProvider = Provider<AuthRepository>((ref) => AuthRepository(ref.watch(apiClientProvider)));
