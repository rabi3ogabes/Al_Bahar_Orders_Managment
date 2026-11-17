import 'dart:io';

import 'package:albahar_customer/core/network/api_client.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/network/interceptors.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/ops/ops_account.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart' show TargetPlatform, defaultTargetPlatform;
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Calls the staff API (`/admin/...`) used by both the driver and the branch staff app.
class OpsRepository {
  OpsRepository(this._api);
  final ApiClient _api;

  /// Staff sign-in with an e-mail or a mobile number (both go in `email`) and the password. Returns the token pair and whether the
  /// person must now choose their own password (`must_change_password`).
  Future<OpsLogin> login(String email, String password, {String? twoFactorCode}) async {
    final data = await _api.post(
      '/admin/auth/login',
      data: {
        'email': email,
        'password': password,
        'platform': defaultTargetPlatform == TargetPlatform.iOS ? 'ios' : 'android',
        if (twoFactorCode != null && twoFactorCode.isNotEmpty) 'two_factor_code': twoFactorCode,
      },
      options: Options(extra: {RequestFlags.skipAuth: true}),
    );
    return OpsLogin.fromJson(asMap(data));
  }

  /// Revokes this device's token on the server.
  Future<void> logout() async {
    await _api.post('/auth/logout');
  }

  /// The dashboard settings this app follows, from the public `GET /app-config`: how a status is changed (`ops.status_style`:
  /// `buttons` or `slide`) and whether the alternative phone number is shown (`alternate_phone_enabled`). A server that does not send
  /// a setting means today's behaviour (plain buttons, number shown); an answer that is not the dashboard's JSON (a captive portal's
  /// page) throws, so it can never replace the last good one.
  Future<OpsAppConfig> appConfig() async {
    final data = await _api
        .get('/app-config', options: Options(extra: {RequestFlags.skipAuth: true}))
        .timeout(const Duration(seconds: 6));
    if (data is! Map) throw const FormatException('The app configuration is not an object.');
    return OpsAppConfig.fromJson(asMap(data));
  }

  /// How this app should ask for a status change (see [appConfig]).
  Future<OpsStatusStyle> statusStyle() async => (await appConfig()).statusStyle;

  /// The signed-in staff member: permission keys and whether they still have to choose their own password. `me` is the one staff call
  /// the server answers even before that.
  Future<OpsAccount> account() async => OpsAccount.fromJson(asMap(await _api.get('/admin/me')));

  /// Permission keys of the signed-in staff member.
  Future<List<String>> permissions() async => (await account()).permissions;

  /// The employee chooses their own password (`PUT /admin/me/password`): the server signs their other devices out, this one stays
  /// signed in with the same tokens. A refusal is a 422 whose `errors` name `current_password`, `password` or `password_confirmation`.
  Future<void> changePassword({required String current, required String password, required String confirmation}) async {
    await _api.put(
      '/admin/me/password',
      data: {'current_password': current, 'password': password, 'password_confirmation': confirmation},
    );
  }

  // Driver
  Future<DriverMe> driver() => _api.getObject('/admin/dispatch/driver', DriverMe.fromJson);

  Future<bool> setDuty(bool onDuty, {double? lat, double? lng}) async {
    final data = asMap(
      await _api.put('/admin/dispatch/driver/duty', data: {'on_duty': onDuty, 'latitude': ?lat, 'longitude': ?lng}),
    );
    return asBool(data['on_duty']);
  }

  /// Reports the phone's position. [recordedAt] is when the phone took the fix: the server shows it as the age of the
  /// position, so a fix that waited in a poor connection is not shown as live. If the phone's clock runs ahead of the
  /// server's (`location_in_future`) the report is sent again without the time, so a wrong clock never silences tracking.
  Future<void> heartbeat(
    double lat,
    double lng, {
    double? speedKmh,
    int? heading,
    int? accuracy,
    DateTime? recordedAt,
  }) async {
    Future<void> post({required bool withTime}) async {
      await _api.post(
        '/admin/dispatch/driver/location',
        data: {
          'latitude': lat,
          'longitude': lng,
          'speed_kmh': ?speedKmh,
          'heading': ?heading,
          'accuracy': ?accuracy,
          if (withTime && recordedAt != null) 'recorded_at': recordedAt.toUtc().toIso8601String(),
        },
      );
    }

    try {
      await post(withTime: true);
    } on ApiException catch (e) {
      if (recordedAt == null || e.code != 'location_in_future') rethrow;
      await post(withTime: false);
    }
  }

  Future<List<DriverJob>> jobs({bool history = false}) =>
      _api.getList('/admin/dispatch/driver/jobs', DriverJob.fromJson, query: {'scope': history ? 'history' : 'active'});

  /// accept | pickup | out-for-delivery (simple steps).
  Future<void> jobStep(String id, String action, {double? lat, double? lng}) async {
    await _api.post('/admin/deliveries/$id/$action', data: {'latitude': ?lat, 'longitude': ?lng});
  }

  /// A photo upload on a mobile connection needs more time than a normal call; never retried automatically.
  static final _upload = Options(
    sendTimeout: const Duration(seconds: 120),
    receiveTimeout: const Duration(seconds: 120),
  );

  Future<void> deliver(String id, {String? recipient, File? proof, double? codAmount}) async {
    final form = FormData.fromMap({
      if (recipient != null && recipient.isNotEmpty) 'recipient_name': recipient,
      if (proof != null) 'proof': await MultipartFile.fromFile(proof.path, filename: 'proof.jpg'),
      if (codAmount != null) ...{'cod[tender]': 'cash', 'cod[amount]': codAmount.toStringAsFixed(2)},
    });
    await _api.post('/admin/deliveries/$id/deliver', data: form, options: _upload);
  }

  Future<void> fail(String id, String reason) async {
    await _api.post('/admin/deliveries/$id/fail', data: {'reason_code': reason});
  }

  /// Failed delivery handed back at the branch.
  Future<void> returnToBranch(String id) async {
    await _api.post('/admin/deliveries/$id/return');
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
        // The server may ask Claude twice (up to a minute each).
        options: Options(receiveTimeout: const Duration(seconds: 150)),
      ),
    );
    return ProductDraftText.fromJson(data);
  }

  /// Creates the product (without photos) and returns its id.
  Future<String> createProduct({
    required String sku,
    required String price,
    required String titleEn,
    required String titleAr,
    required ProductDraftText text,
    String? categoryId,
    required bool publish,
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
    return asString(data['id']);
  }

  /// Uploads one photo to a product and returns the image id.
  Future<String> uploadProductImage(String productId, File photo) async {
    final res = asMap(
      await _api.post(
        '/admin/catalog/products/$productId/images',
        data: FormData.fromMap({'image': await MultipartFile.fromFile(photo.path, filename: 'photo.jpg')}),
        options: _upload,
      ),
    );
    return asString(res['id']);
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

  Future<List<FleetDriver>> fleet({String? branchId}) =>
      _api.getList('/admin/dispatch/fleet', FleetDriver.fromJson, query: {'branch_id': ?branchId});

  Future<void> assign(String fulfillmentId, String driverId) async {
    await _api.post('/admin/dispatch/fulfillments/$fulfillmentId/assign', data: {'driver_id': driverId});
  }

  Future<void> advance(String fulfillmentId, String status) async {
    await _api.post('/admin/fulfillments/$fulfillmentId/transition', data: {'status': status});
  }

  // Missing items found while preparing (see docs/api/ORDER_ADJUSTMENTS.md)

  /// Whether this parcel can still be corrected (only while it is accepted or being prepared), the reasons to choose from and its lines.
  Future<AdjustContext> adjustContext(String fulfillmentId) =>
      _api.getObject('/admin/fulfillments/$fulfillmentId/adjustments/context', AdjustContext.fromJson);

  /// What the branch has in stock that can replace a line (never what is already in the parcel). [search] narrows it by name or SKU.
  Future<List<Substitute>> substitutes(String fulfillmentId, {String search = '', int limit = 20}) => _api.getList(
    '/admin/fulfillments/$fulfillmentId/substitutes',
    Substitute.fromJson,
    query: {'search': search.trim(), 'limit': limit},
  );

  /// Corrects the parcel: one request is one revision (any number of line changes, one reason, one notification to the customer, one
  /// revised invoice). With [preview] the server works everything out and writes nothing.
  Future<AdjustResult> adjust(
    String fulfillmentId, {
    required List<AdjustChange> changes,
    required String reasonCode,
    String? note,
    bool preview = false,
  }) async {
    final text = note?.trim() ?? '';
    final data = await _api.post(
      '/admin/fulfillments/$fulfillmentId/adjustments',
      data: {
        'changes': [for (final c in changes) c.toJson()],
        'reason_code': reasonCode,
        if (text.isNotEmpty) 'note': text,
        'preview': preview,
      },
    );
    return AdjustResult.fromJson(asMap(data));
  }
}

final opsRepositoryProvider = Provider<OpsRepository>((ref) => OpsRepository(ref.watch(apiClientProvider)));
