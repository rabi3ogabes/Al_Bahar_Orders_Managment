import 'package:albahar_customer/core/models/paginated.dart';
import 'package:albahar_customer/core/network/api_client.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

class CatalogRepository {
  CatalogRepository(this._api);
  final ApiClient _api;

  Future<List<Category>> categories() => _api.getList('/categories', Category.fromJson);

  Future<CategoryDetail> category(String id) => _api.getObject('/categories/$id', CategoryDetail.fromJson);

  Future<List<Brand>> brands() => _api.getList('/brands', Brand.fromJson);

  Future<Paginated<ProductSummary>> products(ProductQuery query, {String? branchId, int page = 1, int perPage = 20}) {
    final hasQ = query.q != null && query.q!.trim().isNotEmpty;
    return _api.getPage(
      hasQ ? '/products/search' : '/products',
      ProductSummary.fromJson,
      query: {...query.toQuery(branchId: branchId), 'page': page, 'per_page': perPage},
    );
  }

  Future<ProductDetail> product(String idOrSlug, {String? branchId}) =>
      _api.getObject('/products/$idOrSlug', ProductDetail.fromJson, query: {'branch_id': branchId});

  Future<List<BranchStock>> availability(String productId, {String? branchId}) async {
    final data = await _api.get('/products/$productId/availability', query: {'branch_id': branchId});
    return mapList(asMap(data)['branches'], BranchStock.fromJson);
  }

  Future<Paginated<ProductSummary>> branchProducts(String branchId, {int page = 1}) =>
      _api.getPage('/branches/$branchId/products', ProductSummary.fromJson, query: {'page': page});

  Future<Paginated<Review>> reviews(String productId, {int page = 1}) =>
      _api.getPage('/products/$productId/reviews', Review.fromJson, query: {'page': page});

  Future<Paginated<ProductQuestion>> questions(String productId, {int page = 1}) =>
      _api.getPage('/products/$productId/questions', ProductQuestion.fromJson, query: {'page': page});

  Future<void> askQuestion(String productId, String question) async {
    await _api.post('/products/$productId/questions', data: {'question': question});
  }

  /// Multipart when photos are attached (`photos[]`, ≤ 4).
  Future<void> submitReview(
    String productId, {
    required int rating,
    String? title,
    String? body,
    List<MultipartFile> photos = const [],
  }) async {
    final fields = <String, dynamic>{
      'rating': rating,
      if (title != null && title.isNotEmpty) 'title': title,
      if (body != null && body.isNotEmpty) 'body': body,
    };
    if (photos.isEmpty) {
      await _api.post('/products/$productId/reviews', data: fields);
      return;
    }
    final form = FormData.fromMap(fields);
    for (final p in photos.take(4)) {
      form.files.add(MapEntry('photos[]', p));
    }
    await _api.post('/products/$productId/reviews', data: form);
  }

  Future<void> reportReview(String reviewId, String reason) async {
    await _api.post('/reviews/$reviewId/report', data: {'reason': reason});
  }

  Future<void> createStockAlert({required String variantId, String type = 'back_in_stock', String? branchId}) async {
    await _api.post('/me/stock-alerts', data: {'variant_id': variantId, 'type': type, 'branch_id': ?branchId});
  }
}

final catalogRepositoryProvider = Provider<CatalogRepository>(
  (ref) => CatalogRepository(ref.watch(apiClientProvider)),
);
