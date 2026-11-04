import 'package:flutter/foundation.dart';

import '../../../core/models/money.dart';
import '../../../core/utils/json.dart';

@immutable
class Rating {
  const Rating({required this.average, required this.count});

  factory Rating.fromJson(JsonMap json) => Rating(average: asDouble(json['average']), count: asInt(json['count']));

  static const empty = Rating(average: 0, count: 0);

  final double average;
  final int count;
}

/// `ProductCard` resource used in every list.
@immutable
class ProductSummary {
  const ProductSummary({
    required this.id,
    required this.slug,
    required this.sku,
    required this.name,
    required this.brand,
    required this.image,
    required this.price,
    required this.rating,
    required this.badges,
    required this.inStock,
    required this.hasVariants,
  });

  factory ProductSummary.fromJson(JsonMap json) => ProductSummary(
    id: asString(json['id']),
    slug: asStringOrNull(json['slug']),
    sku: asStringOrNull(json['sku']),
    name: asString(json['name']),
    brand: NamedRef.maybe(json['brand']),
    image: ImageRef.maybe(json['image']),
    price: EffectivePrice.maybe(json['price']),
    rating: json['rating'] is Map ? Rating.fromJson(asMap(json['rating'])) : Rating.empty,
    badges: asStringList(json['badges']),
    inStock: asBool(json['in_stock'], true),
    hasVariants: asBool(json['has_variants']),
  );

  final String id;
  final String? slug;
  final String? sku;
  final String name;
  final NamedRef? brand;
  final ImageRef? image;
  final EffectivePrice? price;
  final Rating rating;
  final List<String> badges;
  final bool inStock;
  final bool hasVariants;

  String heroTag([String scope = '']) => 'product-image-$scope-$id';
}

@immutable
class AttributeValue {
  const AttributeValue({required this.id, required this.code, required this.name, this.colorHex});

  factory AttributeValue.fromJson(JsonMap json) => AttributeValue(
    id: asString(json['id']),
    code: asString(json['code']),
    name: asString(json['name']),
    colorHex: asStringOrNull(json['color_hex']),
  );

  final String id;
  final String code;
  final String name;
  final String? colorHex;
}

@immutable
class ProductAttribute {
  const ProductAttribute({required this.code, required this.name, required this.type, required this.values});

  factory ProductAttribute.fromJson(JsonMap json) => ProductAttribute(
    code: asString(json['code']),
    name: asString(json['name']),
    type: asString(json['type'], 'select'),
    values: mapList(json['values'], AttributeValue.fromJson),
  );

  final String code;
  final String name;

  /// e.g. `select`, `color`, `text`.
  final String type;
  final List<AttributeValue> values;

  bool get isColor => type == 'color' || type == 'swatch';
}

@immutable
class ProductVariant {
  const ProductVariant({
    required this.id,
    required this.sku,
    required this.name,
    required this.attributeValueIds,
    required this.price,
    required this.availableInBranch,
    required this.inStock,
    required this.imageUrl,
  });

  factory ProductVariant.fromJson(JsonMap json) => ProductVariant(
    id: asString(json['id']),
    sku: asStringOrNull(json['sku']),
    name: asString(json['name']),
    attributeValueIds: asStringList(json['attribute_value_ids']),
    price: EffectivePrice.maybe(json['price']),
    availableInBranch: asBool(json['available_in_branch'], true),
    inStock: asBool(json['in_stock'], true),
    imageUrl: asStringOrNull(json['image_url']),
  );

  final String id;
  final String? sku;
  final String name;
  final List<String> attributeValueIds;
  final EffectivePrice? price;
  final bool availableInBranch;
  final bool inStock;
  final String? imageUrl;

  bool get purchasable => availableInBranch && inStock;
}

@immutable
class Specification {
  const Specification({required this.label, required this.value});
  factory Specification.fromJson(JsonMap json) =>
      Specification(label: asString(json['label']), value: asString(json['value']));
  final String label;
  final String value;
}

@immutable
class Dimensions {
  const Dimensions({this.length, this.width, this.height, this.unit = 'cm'});
  factory Dimensions.fromJson(JsonMap json) => Dimensions(
    length: asDoubleOrNull(json['length']),
    width: asDoubleOrNull(json['width']),
    height: asDoubleOrNull(json['height']),
    unit: asString(json['unit'], 'cm'),
  );
  final double? length;
  final double? width;
  final double? height;
  final String unit;

  bool get isEmpty => length == null && width == null && height == null;
}

@immutable
class Measure {
  const Measure({required this.value, required this.unit});
  static Measure? maybe(Object? json) {
    final m = asMapOrNull(json);
    final v = asDoubleOrNull(m?['value']);
    if (m == null || v == null) return null;
    return Measure(value: v, unit: asString(m['unit'], 'kg'));
  }

  final double value;
  final String unit;
}

@immutable
class Warranty {
  const Warranty({required this.durationMonths, this.provider, this.terms});
  static Warranty? maybe(Object? json) {
    final m = asMapOrNull(json);
    if (m == null) return null;
    return Warranty(
      durationMonths: asInt(m['duration_months']),
      provider: asStringOrNull(m['provider']),
      terms: asStringOrNull(m['terms']),
    );
  }

  final int durationMonths;
  final String? provider;
  final String? terms;
}

@immutable
class ProductVideo {
  const ProductVideo({required this.url, this.source, this.title});
  factory ProductVideo.fromJson(JsonMap json) => ProductVideo(
    url: asString(json['url']),
    source: asStringOrNull(json['source']),
    title: asStringOrNull(json['title']),
  );
  final String url;
  final String? source;
  final String? title;
}

/// `availability` block: whether the product can be bought at the branch.
@immutable
class PurchaseAvailability {
  const PurchaseAvailability({this.branchId, required this.purchasable, this.reason});
  factory PurchaseAvailability.fromJson(JsonMap json) => PurchaseAvailability(
    branchId: asStringOrNull(json['branch_id']),
    purchasable: asBool(json['purchasable'], true),
    reason: asStringOrNull(json['reason']),
  );
  final String? branchId;
  final bool purchasable;

  /// `null`, `not_in_branch`, `out_of_stock`.
  final String? reason;
}

@immutable
class ProductDetail {
  const ProductDetail({
    required this.summary,
    required this.shortDescription,
    required this.descriptionHtml,
    required this.category,
    required this.tags,
    required this.specifications,
    required this.dimensions,
    required this.weight,
    required this.warranty,
    required this.requiresAssembly,
    required this.assemblyServiceAvailable,
    required this.isOversized,
    required this.images,
    required this.videos,
    required this.attributes,
    required this.variants,
    required this.defaultVariantId,
    required this.availability,
    required this.related,
    required this.shareUrl,
    this.deliveryAvailable,
    this.pickupAvailable,
  });

  factory ProductDetail.fromJson(JsonMap json) {
    final summary = ProductSummary.fromJson(json);
    final images = mapList(json['images'], ImageRef.fromJson).where((i) => i.url.isNotEmpty).toList();
    return ProductDetail(
      summary: summary,
      shortDescription: asStringOrNull(json['short_description']),
      descriptionHtml: asStringOrNull(json['description']),
      category: NamedRef.maybe(json['category']),
      tags: asStringList(json['tags']),
      specifications: mapList(json['specifications'], Specification.fromJson),
      dimensions: json['dimensions'] is Map ? Dimensions.fromJson(asMap(json['dimensions'])) : const Dimensions(),
      weight: Measure.maybe(json['weight']),
      warranty: Warranty.maybe(json['warranty']),
      requiresAssembly: asBool(json['requires_assembly']),
      assemblyServiceAvailable: asBool(json['assembly_service_available']),
      isOversized: asBool(json['is_oversized']),
      images: images.isEmpty && summary.image != null ? [summary.image!] : images,
      videos: mapList(json['videos'], ProductVideo.fromJson),
      attributes: mapList(json['attributes'], ProductAttribute.fromJson),
      variants: mapList(json['variants'], ProductVariant.fromJson),
      defaultVariantId: asStringOrNull(json['default_variant_id']),
      availability: json['availability'] is Map
          ? PurchaseAvailability.fromJson(asMap(json['availability']))
          : const PurchaseAvailability(purchasable: true),
      related: mapList(json['related'], ProductSummary.fromJson),
      shareUrl: asStringOrNull(json['share_url']),
      // Optional fulfilment hints (see API_ASSUMPTIONS.md); null = unknown.
      deliveryAvailable: asBoolOrNull(asMapOrNull(json['fulfillment'])?['delivery']),
      pickupAvailable: asBoolOrNull(asMapOrNull(json['fulfillment'])?['pickup']),
    );
  }

  final ProductSummary summary;
  final String? shortDescription;
  final String? descriptionHtml;
  final NamedRef? category;
  final List<String> tags;
  final List<Specification> specifications;
  final Dimensions dimensions;
  final Measure? weight;
  final Warranty? warranty;
  final bool requiresAssembly;
  final bool assemblyServiceAvailable;
  final bool isOversized;
  final List<ImageRef> images;
  final List<ProductVideo> videos;
  final List<ProductAttribute> attributes;
  final List<ProductVariant> variants;
  final String? defaultVariantId;
  final PurchaseAvailability availability;
  final List<ProductSummary> related;
  final String? shareUrl;
  final bool? deliveryAvailable;
  final bool? pickupAvailable;

  String get id => summary.id;
  String get name => summary.name;

  ProductVariant? variantById(String? id) {
    if (id == null) return null;
    for (final v in variants) {
      if (v.id == id) return v;
    }
    return null;
  }

  ProductVariant? get defaultVariant =>
      variantById(defaultVariantId) ?? variants.where((v) => v.purchasable).firstOrNull ?? variants.firstOrNull;

  /// Finds the variant whose attribute values match [selection]
  /// (attribute code → value id). Returns null if no exact match.
  ProductVariant? resolveVariant(Map<String, String> selection) {
    if (variants.isEmpty) return null;
    final wanted = selection.values.toSet();
    final matches = variants.where((v) => wanted.every(v.attributeValueIds.contains)).toList();
    if (matches.isEmpty) return null;
    // Prefer a purchasable match when the selection is partial.
    return matches.where((v) => v.purchasable).firstOrNull ?? matches.first;
  }

  /// Selection map (attribute code → value id) for a variant.
  Map<String, String> selectionFor(ProductVariant variant) {
    final map = <String, String>{};
    for (final attr in attributes) {
      for (final value in attr.values) {
        if (variant.attributeValueIds.contains(value.id)) map[attr.code] = value.id;
      }
    }
    return map;
  }

  /// Whether choosing [valueId] for [attributeCode] (keeping the rest of
  /// [selection]) leads to any existing variant.
  bool isValueSelectable(Map<String, String> selection, String attributeCode, String valueId) {
    final candidate = {...selection, attributeCode: valueId};
    return variants.any((v) => candidate.values.every(v.attributeValueIds.contains));
  }

  /// Images for the current variant first, then the rest.
  List<ImageRef> imagesFor(ProductVariant? variant) {
    if (variant == null) return images;
    final specific = images.where((i) => i.variantId == variant.id).toList();
    if (specific.isEmpty) {
      if (variant.imageUrl != null && images.every((i) => i.url != variant.imageUrl)) {
        return [ImageRef(url: variant.imageUrl!), ...images];
      }
      return images;
    }
    return [...specific, ...images.where((i) => i.variantId != variant.id)];
  }
}

@immutable
class CatalogCategory {
  const CatalogCategory({
    required this.id,
    required this.slug,
    required this.name,
    this.imageUrl,
    this.iconUrl,
    this.bannerUrl,
    this.children = const [],
  });

  factory CatalogCategory.fromJson(JsonMap json) => CatalogCategory(
    id: asString(json['id']),
    slug: asStringOrNull(json['slug']),
    name: asString(json['name']),
    imageUrl: asStringOrNull(json['image_url']),
    iconUrl: asStringOrNull(json['icon_url']),
    bannerUrl: asStringOrNull(json['banner_url']),
    children: mapList(json['children'], CatalogCategory.fromJson),
  );

  final String id;
  final String? slug;
  final String name;
  final String? imageUrl;
  final String? iconUrl;
  final String? bannerUrl;
  final List<CatalogCategory> children;

  bool get hasChildren => children.isNotEmpty;
}

@immutable
class AttributeFilter {
  const AttributeFilter({required this.attribute, required this.values});
  factory AttributeFilter.fromJson(JsonMap json) {
    final attr = asMap(json['attribute']);
    return AttributeFilter(
      attribute: ProductAttribute(
        code: asString(attr['code']),
        name: asString(attr['name']),
        type: asString(attr['type'], 'select'),
        values: const [],
      ),
      values: mapList(json['values'], AttributeValue.fromJson),
    );
  }
  final ProductAttribute attribute;
  final List<AttributeValue> values;
}

@immutable
class CategoryDetail {
  const CategoryDetail({required this.category, required this.filters});
  factory CategoryDetail.fromJson(JsonMap json) => CategoryDetail(
    category: CatalogCategory.fromJson(json),
    filters: mapList(json['filters'], AttributeFilter.fromJson),
  );
  final CatalogCategory category;
  final List<AttributeFilter> filters;
}

@immutable
class Brand {
  const Brand({required this.id, required this.name, this.slug, this.logoUrl});
  factory Brand.fromJson(JsonMap json) => Brand(
    id: asString(json['id']),
    name: asString(json['name']),
    slug: asStringOrNull(json['slug']),
    logoUrl: asStringOrNull(json['logo_url']),
  );
  final String id;
  final String name;
  final String? slug;
  final String? logoUrl;
}

/// Per-branch availability: `GET /products/{id}/availability`.
@immutable
class BranchStock {
  const BranchStock({required this.branch, required this.variants});
  factory BranchStock.fromJson(JsonMap json) => BranchStock(
    branch: NamedRef.fromJson(asMap(json['branch'])),
    variants: mapList(json['variants'], VariantStock.fromJson),
  );
  final NamedRef branch;
  final List<VariantStock> variants;

  VariantStock? forVariant(String? variantId) =>
      variants.where((v) => v.variantId == variantId).firstOrNull ?? variants.firstOrNull;
}

@immutable
class VariantStock {
  const VariantStock({required this.variantId, required this.available, required this.inStock, this.price});
  factory VariantStock.fromJson(JsonMap json) => VariantStock(
    variantId: asString(json['variant_id']),
    available: asInt(json['available']),
    inStock: asBool(json['in_stock']),
    price: EffectivePrice.maybe(json['price']),
  );
  final String variantId;

  /// Capped at 10 for display by the server.
  final int available;
  final bool inStock;
  final EffectivePrice? price;
}

@immutable
class Review {
  const Review({
    required this.id,
    required this.rating,
    this.title,
    this.body,
    this.authorName,
    this.verifiedPurchase = false,
    this.photos = const [],
    this.createdAt,
  });

  factory Review.fromJson(JsonMap json) => Review(
    id: asString(json['id']),
    rating: asInt(json['rating']),
    title: asStringOrNull(json['title']),
    body: asStringOrNull(json['body']),
    // Backend sends `author` as the reviewer's first name (string); older
    // shapes (`author_name`, `author: {name}`) are still accepted.
    authorName:
        asStringOrNull(json['author_name']) ??
        (json['author'] is Map ? asStringOrNull(asMap(json['author'])['name']) : asStringOrNull(json['author'])),
    verifiedPurchase: asBool(json['is_verified_purchase'] ?? json['verified_purchase']),
    photos: asList(json['photos']).map(ImageRef.maybe).whereType<ImageRef>().toList(),
    createdAt: asDate(json['created_at']),
  );

  final String id;
  final int rating;
  final String? title;
  final String? body;
  final String? authorName;
  final bool verifiedPurchase;
  final List<ImageRef> photos;
  final DateTime? createdAt;
}

@immutable
class ProductQuestion {
  const ProductQuestion({required this.id, required this.question, this.answer, this.answeredAt, this.createdAt});
  factory ProductQuestion.fromJson(JsonMap json) => ProductQuestion(
    id: asString(json['id']),
    question: asString(json['question']),
    answer: asStringOrNull(json['answer']),
    answeredAt: asDate(json['answered_at']),
    // The backend only exposes `answered_at` (no `created_at`).
    createdAt: asDate(json['created_at']) ?? asDate(json['answered_at']),
  );
  final String id;
  final String question;
  final String? answer;
  final DateTime? answeredAt;
  final DateTime? createdAt;
}

/// `POST /reviews/{id}/report` reasons accepted by the backend.
const reviewReportReasons = ['spam', 'offensive', 'irrelevant', 'fake', 'other'];

/// Sort options accepted by `/products`.
enum ProductSort {
  relevance('relevance'),
  priceAsc('price'),
  priceDesc('-price'),
  newest('-created_at'),
  rating('-rating'),
  popularity('-popularity');

  const ProductSort(this.apiValue);
  final String apiValue;
}

/// Immutable query for product listings.
@immutable
class ProductQuery {
  const ProductQuery({
    this.categoryId,
    this.q,
    this.brandIds = const {},
    this.attributeValueIds = const {},
    this.priceMin,
    this.priceMax,
    this.inStock = false,
    this.onSale = false,
    this.featured = false,
    this.newArrival = false,
    this.bestseller = false,
    this.sort = ProductSort.relevance,
  });

  final String? categoryId;
  final String? q;
  final Set<String> brandIds;
  final Set<String> attributeValueIds;
  final String? priceMin;
  final String? priceMax;
  final bool inStock;
  final bool onSale;
  final bool featured;
  final bool newArrival;
  final bool bestseller;
  final ProductSort sort;

  int get activeFilterCount =>
      brandIds.length +
      attributeValueIds.length +
      (priceMin != null || priceMax != null ? 1 : 0) +
      (inStock ? 1 : 0) +
      (onSale ? 1 : 0);

  ProductQuery copyWith({
    String? categoryId,
    String? q,
    Set<String>? brandIds,
    Set<String>? attributeValueIds,
    String? Function()? priceMin,
    String? Function()? priceMax,
    bool? inStock,
    bool? onSale,
    ProductSort? sort,
  }) => ProductQuery(
    categoryId: categoryId ?? this.categoryId,
    q: q ?? this.q,
    brandIds: brandIds ?? this.brandIds,
    attributeValueIds: attributeValueIds ?? this.attributeValueIds,
    priceMin: priceMin != null ? priceMin() : this.priceMin,
    priceMax: priceMax != null ? priceMax() : this.priceMax,
    inStock: inStock ?? this.inStock,
    onSale: onSale ?? this.onSale,
    featured: featured,
    newArrival: newArrival,
    bestseller: bestseller,
    sort: sort ?? this.sort,
  );

  ProductQuery clearFilters() => ProductQuery(
    categoryId: categoryId,
    q: q,
    featured: featured,
    newArrival: newArrival,
    bestseller: bestseller,
    sort: sort,
  );

  Map<String, dynamic> toQuery({String? branchId}) => {
    'branch_id': branchId,
    'category_id': categoryId,
    'q': q,
    'brand_id': brandIds.toList(),
    'attribute_values': attributeValueIds.toList(),
    'price_min': priceMin,
    'price_max': priceMax,
    if (inStock) 'in_stock': true,
    if (onSale) 'on_sale': true,
    if (featured) 'featured': true,
    if (newArrival) 'new_arrival': true,
    if (bestseller) 'bestseller': true,
    if (sort != ProductSort.relevance || (q != null && q!.isNotEmpty)) 'sort': sort.apiValue,
  };

  /// Parses query parameters from a deep link / route.
  factory ProductQuery.fromRoute(Map<String, String> params) => ProductQuery(
    categoryId: params['category_id'],
    q: params['q'],
    featured: params['featured'] == '1',
    newArrival: params['new_arrival'] == '1',
    bestseller: params['bestseller'] == '1',
    onSale: params['on_sale'] == '1',
    brandIds: params['brand_id'] != null ? {params['brand_id']!} : const {},
    sort: ProductSort.values.where((s) => s.apiValue == params['sort']).firstOrNull ?? ProductSort.relevance,
  );

  @override
  bool operator ==(Object other) =>
      other is ProductQuery &&
      other.categoryId == categoryId &&
      other.q == q &&
      setEquals(other.brandIds, brandIds) &&
      setEquals(other.attributeValueIds, attributeValueIds) &&
      other.priceMin == priceMin &&
      other.priceMax == priceMax &&
      other.inStock == inStock &&
      other.onSale == onSale &&
      other.featured == featured &&
      other.newArrival == newArrival &&
      other.bestseller == bestseller &&
      other.sort == sort;

  @override
  int get hashCode => Object.hash(
    categoryId,
    q,
    Object.hashAllUnordered(brandIds),
    Object.hashAllUnordered(attributeValueIds),
    priceMin,
    priceMax,
    inStock,
    onSale,
    featured,
    newArrival,
    bestseller,
    sort,
  );
}
