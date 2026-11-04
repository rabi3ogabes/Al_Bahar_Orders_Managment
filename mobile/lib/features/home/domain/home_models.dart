import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/features/auctions/domain/auction_models.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:flutter/foundation.dart';

/// Link target for banners: `{type: category|product|auction|url|search|page, value}`.
@immutable
class HomeLink {
  const HomeLink({required this.type, required this.value});
  factory HomeLink.fromJson(JsonMap json) =>
      HomeLink(type: asString(json['type']), value: asString(json['value']));
  final String type;
  final String value;

  /// In-app location, or null for external URLs / unknown types.
  String? get location => switch (type) {
    'category' => '/categories/$value',
    'product' => '/products/$value',
    'auction' => '/auctions/$value',
    'lot' => '/auctions/lots/$value',
    'search' => '/search?q=${Uri.encodeQueryComponent(value)}',
    'collection' || 'products' => '/products?$value',
    'page' => '/pages/$value',
    'brand' => '/products?brand_id=$value',
    _ => null,
  };

  bool get isExternal => type == 'url';
}

@immutable
class HomeBanner {
  const HomeBanner({required this.id, this.title, this.subtitle, this.imageUrl, this.link});
  factory HomeBanner.fromJson(JsonMap json) => HomeBanner(
    id: asString(json['id']),
    title: asStringOrNull(json['title']),
    subtitle: asStringOrNull(json['subtitle']),
    imageUrl: asStringOrNull(json['image_url']),
    link: json['link'] is Map ? HomeLink.fromJson(asMap(json['link'])) : null,
  );
  final String id;
  final String? title;
  final String? subtitle;
  final String? imageUrl;
  final HomeLink? link;
}

@immutable
class Promotion {
  const Promotion({required this.id, required this.name, this.description, this.badge, this.endsAt, this.link});
  factory Promotion.fromJson(JsonMap json) => Promotion(
    id: asString(json['id']),
    name: asString(json['name']),
    description: asStringOrNull(json['description']),
    badge: asStringOrNull(json['badge']),
    endsAt: asDate(json['ends_at']),
    link: json['link'] is Map ? HomeLink.fromJson(asMap(json['link'])) : null,
  );
  final String id;
  final String name;
  final String? description;
  final String? badge;
  final DateTime? endsAt;
  final HomeLink? link;
}

/// Server-driven home section. Unknown types parse to `null` and are skipped.
sealed class HomeSection {
  const HomeSection({required this.id, required this.type, this.title, this.layout});

  final String id;
  final String type;
  final String? title;
  final String? layout;

  static const productTypes = {'featured_products', 'new_arrivals', 'best_sellers', 'recommended', 'product_collection'};

  static HomeSection? fromJson(JsonMap json) {
    final type = asString(json['type']);
    final id = asString(json['id'], type);
    final title = asStringOrNull(json['title']);
    final layout = asStringOrNull(json['layout']);
    final items = json['items'];
    switch (type) {
      case 'banners':
        final banners = mapList(items, HomeBanner.fromJson);
        return banners.isEmpty ? null : BannersSection(id: id, title: title, layout: layout, banners: banners);
      case 'categories':
        final cats = mapList(items, Category.fromJson);
        return cats.isEmpty ? null : CategoriesSection(id: id, title: title, layout: layout, categories: cats);
      case 'brands':
        final brands = mapList(items, Brand.fromJson);
        return brands.isEmpty ? null : BrandsSection(id: id, title: title, layout: layout, brands: brands);
      case 'promotions':
        final promos = mapList(items, Promotion.fromJson);
        return promos.isEmpty ? null : PromotionsSection(id: id, title: title, layout: layout, promotions: promos);
      case 'upcoming_auctions':
      case 'live_auctions':
        final auctions = mapList(items, AuctionSummary.fromJson);
        return auctions.isEmpty
            ? null
            : AuctionsSection(id: id, type: type, title: title, layout: layout, auctions: auctions);
      default:
        if (productTypes.contains(type)) {
          final products = mapList(items, ProductSummary.fromJson);
          return products.isEmpty
              ? null
              : ProductsSection(
                  id: id,
                  type: type,
                  title: title,
                  layout: layout,
                  products: products,
                  seeAllQuery: asMapOrNull(json['see_all'])?.map((k, v) => MapEntry(k, v.toString())),
                );
        }
        return null; // Unknown type — ignored by contract.
    }
  }
}

class BannersSection extends HomeSection {
  const BannersSection({required super.id, super.title, super.layout, required this.banners}) : super(type: 'banners');
  final List<HomeBanner> banners;
}

class CategoriesSection extends HomeSection {
  const CategoriesSection({required super.id, super.title, super.layout, required this.categories})
    : super(type: 'categories');
  final List<Category> categories;
}

class ProductsSection extends HomeSection {
  const ProductsSection({
    required super.id,
    required super.type,
    super.title,
    super.layout,
    required this.products,
    this.seeAllQuery,
  });
  final List<ProductSummary> products;

  /// Optional query for "See all" (assumption); otherwise derived from type.
  final Map<String, String>? seeAllQuery;

  Map<String, String> get listingQuery =>
      seeAllQuery ??
      switch (type) {
        'featured_products' => {'featured': '1'},
        'new_arrivals' => {'new_arrival': '1', 'sort': '-created_at'},
        'best_sellers' => {'bestseller': '1', 'sort': '-popularity'},
        _ => const {},
      };
}

class BrandsSection extends HomeSection {
  const BrandsSection({required super.id, super.title, super.layout, required this.brands}) : super(type: 'brands');
  final List<Brand> brands;
}

class PromotionsSection extends HomeSection {
  const PromotionsSection({required super.id, super.title, super.layout, required this.promotions})
    : super(type: 'promotions');
  final List<Promotion> promotions;
}

class AuctionsSection extends HomeSection {
  const AuctionsSection({required super.id, required super.type, super.title, super.layout, required this.auctions});
  final List<AuctionSummary> auctions;
  bool get isLive => type == 'live_auctions';
}

@immutable
class HomeFeed {
  const HomeFeed(this.sections);
  factory HomeFeed.fromJson(JsonMap json) => HomeFeed(
    asList(json['sections']).whereType<Map<dynamic, dynamic>>().map((e) => HomeSection.fromJson(asMap(e))).whereType<HomeSection>().toList(),
  );
  final List<HomeSection> sections;
}
