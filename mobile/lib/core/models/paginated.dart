import 'package:flutter/foundation.dart';

import '../utils/json.dart';

/// Laravel paginated collection: `{data: [...], links: {...}, meta: {...}}`.
@immutable
class Paginated<T> {
  const Paginated({
    required this.items,
    required this.currentPage,
    required this.lastPage,
    required this.perPage,
    required this.total,
    this.extraMeta = const {},
  });

  factory Paginated.fromJson(JsonMap json, T Function(JsonMap) fromJson) {
    final meta = asMap(json['meta']);
    final items = mapList(json['data'], fromJson);
    final links = asMap(json['links']);
    final current = asInt(meta['current_page'], 1);
    // Some endpoints may use cursor/simple pagination without last_page.
    final last = meta.containsKey('last_page')
        ? asInt(meta['last_page'], current)
        : (links['next'] != null ? current + 1 : current);
    return Paginated(
      items: items,
      currentPage: current,
      lastPage: last,
      perPage: asInt(meta['per_page'], items.length),
      total: asInt(meta['total'], items.length),
      extraMeta: meta,
    );
  }

  final List<T> items;
  final int currentPage;
  final int lastPage;
  final int perPage;
  final int total;
  final JsonMap extraMeta;

  bool get hasMore => currentPage < lastPage;
}
