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

  // Catalogue: add a product with photos, let Claude write the description, ask the photo studio.
  Future<List<ShopCategory>> categories() async => _flatten(await _api.getList('/categories', ShopCategory.fromJson));

  List<ShopCategory> _flatten(List<ShopCategory> tree, [String prefix = '']) => [
    for (final c in tree) ...[
      ShopCategory(id: c.id, name: '$prefix${c.name}'),
      ..._flatten(c.children, '$prefix${c.name} › '),
    ],
  ];

  Future<bool> aiReady() async => asBool(asMap(await _api.get('/admin/catalog/ai/status'))['description_ready']);

  Future<ProductDraftText> describe({String? titleEn, String? titleAr, String? categoryId}) async {
    final data = asMap(
      await _api.post(
        '/admin/catalog/ai/describe',
        data: {'title_en': ?titleEn, 'title_ar': ?titleAr, 'category_id': ?categoryId},
      ),
    );
    return ProductDraftText.fromJson(data);
  }

  /// Creates the product, then uploads the photos. Returns the product id and the uploaded image ids.
  Future<({String id, List<String> imageIds})> createProduct({
    required String sku,
    required String price,
    required String titleEn,
    required String titleAr,
    required ProductDraftText text,
    String? categoryId,
    required bool publish,
    required List<File> photos,
  }) async {
    final data = asMap(
      await _api.post(
        '/admin/catalog/products',
        data: {
          'sku': sku,
          'price': price,
          'category_id': ?categoryId,
          'status': publish ? 'active' : 'draft',
          'available_in_all_branches': true,
          'translations': {
            'en': {'name': titleEn, 'short_description': text.shortEn, 'description': text.descriptionEn},
            'ar': {'name': titleAr, 'short_description': text.shortAr, 'description': text.descriptionAr},
          },
        },
      ),
    );
    final id = asString(data['id']);
    final ids = <String>[];
    for (final f in photos) {
      final res = asMap(
        await _api.post(
          '/admin/catalog/products/$id/images',
          data: FormData.fromMap({'image': await MultipartFile.fromFile(f.path, filename: 'photo.jpg')}),
        ),
      );
      ids.add(asString(res['id']));
    }
    return (id: id, imageIds: ids);
  }

  Future<void> askPhotoStudio(String productId, String imageId, String kind) async {
    await _api.post('/admin/catalog/products/$productId/photo-requests', data: {'image_id': imageId, 'kind': kind});
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
