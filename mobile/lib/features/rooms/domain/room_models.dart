import 'package:albahar_customer/core/utils/json.dart';
import 'package:flutter/foundation.dart';

/// A room of "Shop by room" (the `rooms` section of `GET /home`, and `GET /rooms`): a title in the app's language, a picture, and how many products it holds.
/// The server leaves out rooms with no product, so a room here always leads somewhere.
@immutable
class ShopRoom {
  const ShopRoom({
    required this.id,
    required this.slug,
    required this.name,
    required this.productsCount,
    this.description,
    this.imageUrl,
  });

  factory ShopRoom.fromJson(JsonMap json) => ShopRoom(
    id: asString(json['id']),
    slug: asString(json['slug']),
    name: asString(json['name']),
    description: asStringOrNull(json['description']),
    imageUrl: asStringOrNull(json['image_url']),
    productsCount: asInt(json['products_count']),
  );

  final String id;
  final String slug;
  final String name;
  final String? description;
  final String? imageUrl;
  final int productsCount;

  /// The shop list of this room: `/products?room=bedroom&title=Bedroom`.
  String get listingLocation => Uri(path: '/products', queryParameters: {'room': slug, 'title': name}).toString();
}
