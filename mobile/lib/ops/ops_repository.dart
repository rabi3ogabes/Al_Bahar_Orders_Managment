import 'dart:io';

import 'package:albahar_customer/core/network/api_client.dart';
import 'package:albahar_customer/core/network/interceptors.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Calls the staff API (`/admin/...`) used by both the driver and the branch staff app.
class OpsRepository {
  OpsRepository(this._api);
  final ApiClient _api;

  /// Email + password staff sign-in. Returns the token pair.
  Future<AuthTokens> login(String email, String password) async {
    final data = await _api.post(
      '/admin/auth/login',
      data: {'email': email, 'password': password},
      options: Options(extra: {RequestFlags.skipAuth: true}),
    );
    return AuthTokens.fromJson(asMap(asMap(data)['tokens']));
  }

  /// Permission keys of the signed-in staff member.
  Future<List<String>> permissions() async {
    final data = asMap(await _api.get('/admin/me'));
    if (asBool(data['is_super_admin'])) return const ['*'];
    return asStringList(data['permissions']);
  }

  // Driver
  Future<DriverMe> driver() => _api.getObject('/admin/dispatch/driver', DriverMe.fromJson);

  Future<bool> setDuty(bool onDuty, {double? lat, double? lng}) async {
    final data = asMap(
      await _api.put('/admin/dispatch/driver/duty', data: {'on_duty': onDuty, 'latitude': ?lat, 'longitude': ?lng}),
    );
    return asBool(data['on_duty']);
  }

  Future<void> heartbeat(double lat, double lng, {double? speedKmh, int? heading, int? accuracy}) async {
    await _api.post(
      '/admin/dispatch/driver/location',
      data: {'latitude': lat, 'longitude': lng, 'speed_kmh': ?speedKmh, 'heading': ?heading, 'accuracy': ?accuracy},
    );
  }

  Future<List<DriverJob>> jobs({bool history = false}) =>
      _api.getList('/admin/dispatch/driver/jobs', DriverJob.fromJson, query: {'scope': history ? 'history' : 'active'});

  /// accept | pickup | out-for-delivery (simple steps).
  Future<void> jobStep(String id, String action, {double? lat, double? lng}) async {
    await _api.post('/admin/deliveries/$id/$action', data: {'latitude': ?lat, 'longitude': ?lng});
  }

  Future<void> deliver(String id, {String? recipient, File? proof, double? codAmount}) async {
    final form = FormData.fromMap({
      if (recipient != null && recipient.isNotEmpty) 'recipient_name': recipient,
      if (proof != null) 'proof': await MultipartFile.fromFile(proof.path, filename: 'proof.jpg'),
      if (codAmount != null) ...{'cod[tender]': 'cash', 'cod[amount]': codAmount.toStringAsFixed(2)},
    });
    await _api.post('/admin/deliveries/$id/deliver', data: form);
  }

  Future<void> fail(String id, String reason) async {
    await _api.post('/admin/deliveries/$id/fail', data: {'reason_code': reason});
  }

  // Branch staff
  Future<StaffOverview> overview({String? branchId}) =>
      _api.getObject('/admin/dispatch/overview', StaffOverview.fromJson, query: {'branch_id': ?branchId});

  Future<List<QueueOrder>> queue(String stage, {String? branchId}) =>
      _api.getList('/admin/dispatch/queue', QueueOrder.fromJson, query: {'stage': stage, 'branch_id': ?branchId});

  Future<List<BranchDriver>> drivers({String? branchId}) =>
      _api.getList('/admin/dispatch/drivers', BranchDriver.fromJson, query: {'branch_id': ?branchId});

  Future<void> assign(String fulfillmentId, String driverId) async {
    await _api.post('/admin/dispatch/fulfillments/$fulfillmentId/assign', data: {'driver_id': driverId});
  }

  Future<void> advance(String fulfillmentId, String status) async {
    await _api.post('/admin/fulfillments/$fulfillmentId/transition', data: {'status': status});
  }
}

final opsRepositoryProvider = Provider<OpsRepository>((ref) => OpsRepository(ref.watch(apiClientProvider)));
