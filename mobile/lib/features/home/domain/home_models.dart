import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/features/auctions/domain/auction_models.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/rooms/domain/room_models.dart';
import 'package:flutter/foundation.dart';

/// Link target for banners and promotions: `{type: category|page|product|brand|auction|url|search, value}`.
@immutable
class HomeLink {
  const HomeLink({required this.type, required this.value});
  factory HomeLink.fromJson(JsonMap json) =>
      HomeLink(type: asString(json['type']).trim().toLowerCase(), value: asString(json['value']).trim());
  final String type;
  final String value;

  /// In-app location, or null for external URLs / unknown types.
  String? get location => switch (type) {
    'category' => '/categories/${Uri.encodeComponent(value)}',
    'product' => '/products/${Uri.encodeComponent(value)}',
    'auction' => '/auctions/${Uri.encodeComponent(value)}',
    'lot' => '/auctions/lots/${Uri.encodeComponent(value)}',
    'search' => '/search?q=${Uri.encodeQueryComponent(value)}',
    'collection' || 'products' => '/products?$value',
    'page' => '/pages/${Uri.encodeComponent(value)}',
    'brand' => '/products?brand_id=${Uri.encodeQueryComponent(value)}',
    _ => null,
  };

  bool get isExternal => type == 'url';

  /// The address of an external link, only when it is a real `https://` address (the app never opens plain `http://`, a `javascript:`
  /// or `tel:` address, or anything else a link could carry). Null for every other type and for an unusable address.
  Uri? get externalUri {
    if (!isExternal) return null;
    final uri = Uri.tryParse(value);
    if (uri == null || uri.scheme != 'https' || uri.host.isEmpty) return null;
    return uri;
  }

  /// Whether tapping can lead anywhere: a known type with a value (and, for an external link, an `https://` address). A slide with a
  /// link that cannot open is shown, and announced, like a slide without one.
  bool get isOpenable => value.isNotEmpty && (isExternal ? externalUri != null : location != null);
}

@immutable
class HomeBanner {
  const HomeBanner({required this.id, this.title, this.subtitle, this.imageUrl, this.link});
  factory HomeBanner.fromJson(JsonMap json) => HomeBanner(
    id: asString(json['id']),
    title: asStringOrNull(json['title'])?.trim(),
    subtitle: asStringOrNull(json['subtitle'])?.trim(),
    imageUrl: asStringOrNull(json['image_url']),
    link: json['link'] is Map ? HomeLink.fromJson(asMap(json['link'])) : null,
  );
  final String id;
  final String? title;
  final String? subtitle;
  final String? imageUrl;

  /// What a tap opens; null for a slide without a link (not tappable).
  final HomeLink? link;

  /// The slide can be tapped: it has a link that can open.
  bool get isTappable => link?.isOpenable ?? false;

  /// A title or subtitle to show over the picture.
  bool get hasText => (title?.isNotEmpty ?? false) || (subtitle?.isNotEmpty ?? false);
}

/// The shape of a slider's slides (`slider.aspect` of the home feed).
enum SliderAspect {
  wide('16:9', 16 / 9),
  cinema('21:9', 21 / 9),
  banner('3:1', 3),
  photo('4:3', 4 / 3),
  square('1:1', 1);

  const SliderAspect(this.code, this.ratio);

  /// The dashboard's name for it: `16:9`, `21:9`, `3:1`, `4:3` or `1:1`.
  final String code;

  /// Width divided by height.
  final double ratio;

  /// Anything the app does not know is the default 16:9, so a newer server can never break the home.
  static SliderAspect fromCode(Object? code) {
    final text = asString(code).trim().replaceAll(RegExp(r'[/x×]'), ':');
    for (final a in values) {
      if (a.code == text) return a;
    }
    return wide;
  }
}

/// How one slider behaves and looks (`slider` of a `banners` section). Every key is optional: a feed from an older server has none,
/// and the slider then autoplays every 5 seconds, 16:9, with dots, titles and rounded corners.
@immutable
class SliderSettings {
  const SliderSettings({
    this.autoplay = true,
    this.intervalSeconds = defaultInterval,
    this.aspect = SliderAspect.wide,
    this.dots = true,
    this.showTitles = true,
    this.rounded = true,
  });

  factory SliderSettings.fromJson(Object? json) {
    if (json is! Map) return const SliderSettings();
    final m = asMap(json);
    return SliderSettings(
      autoplay: asBool(m['autoplay'], true),
      intervalSeconds: asInt(m['interval'], defaultInterval).clamp(minInterval, maxInterval),
      aspect: SliderAspect.fromCode(m['aspect']),
      dots: asBool(m['dots'], true),
      showTitles: asBool(m['show_titles'], true),
      rounded: asBool(m['rounded'], true),
    );
  }

  static const defaultInterval = 5;
  static const minInterval = 3;
  static const maxInterval = 15;

  final bool autoplay;

  /// Seconds each slide stays (3 to 15).
  final int intervalSeconds;
  final SliderAspect aspect;
  final bool dots;
  final bool showTitles;
  final bool rounded;

  @override
  bool operator ==(Object other) =>
      other is SliderSettings &&
      other.autoplay == autoplay &&
      other.intervalSeconds == intervalSeconds &&
      other.aspect == aspect &&
      other.dots == dots &&
      other.showTitles == showTitles &&
      other.rounded == rounded;

  @override
  int get hashCode => Object.hash(autoplay, intervalSeconds, aspect, dots, showTitles, rounded);
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

  static const productTypes = {
    'featured_products',
    'new_arrivals',
    'best_sellers',
    'recommended',
    'product_collection',
  };

  static HomeSection? fromJson(JsonMap json) {
    final type = asString(json['type']);
    final id = asString(json['id'], type);
    final title = asStringOrNull(json['title']);
    final layout = asStringOrNull(json['layout']);
    final items = json['items'];
    switch (type) {
      case 'banners':
        final banners = mapList(items, HomeBanner.fromJson);
        return banners.isEmpty
            ? null
            : BannersSection(
                id: id,
                title: title,
                subtitle: asStringOrNull(json['subtitle']),
                layout: layout,
                banners: banners,
                slider: SliderSettings.fromJson(json['slider']),
              );
      case 'categories':
        final cats = mapList(items, CatalogCategory.fromJson);
        return cats.isEmpty ? null : CategoriesSection(id: id, title: title, layout: layout, categories: cats);
      case 'rooms':
        final rooms = mapList(items, ShopRoom.fromJson).where((r) => r.slug.isNotEmpty).toList();
        return rooms.isEmpty ? null : RoomsSection(id: id, title: title, layout: layout, rooms: rooms);
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

/// One slider of the home: its slides in the dashboard's order, and how it looks and behaves. The home may have several, each at the
/// position the dashboard gave it among the other sections.
class BannersSection extends HomeSection {
  const BannersSection({
    required super.id,
    super.title,
    this.subtitle,
    super.layout,
    required this.banners,
    this.slider = const SliderSettings(),
  }) : super(type: 'banners');
  final List<HomeBanner> banners;
  final String? subtitle;
  final SliderSettings slider;
}

class CategoriesSection extends HomeSection {
  const CategoriesSection({required super.id, super.title, super.layout, required this.categories})
    : super(type: 'categories');
  final List<CatalogCategory> categories;
}

/// "Shop by room": big tiles, each leading to the shop filtered to that room.
class RoomsSection extends HomeSection {
  const RoomsSection({required super.id, super.title, super.layout, required this.rooms}) : super(type: 'rooms');
  final List<ShopRoom> rooms;
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
    asList(json['sections'])
        .whereType<Map<dynamic, dynamic>>()
        .map((e) => HomeSection.fromJson(asMap(e)))
        .whereType<HomeSection>()
        .toList(),
  );
  final List<HomeSection> sections;
}
