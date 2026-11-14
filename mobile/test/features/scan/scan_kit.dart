import 'dart:async';

import 'package:albahar_customer/features/scan/data/scan_source.dart';
import 'package:flutter/material.dart';

import '../../helpers/fakes.dart';

/// A scanner without a camera: tests push codes into it exactly as the plugin would.
class FakeScanSource implements ScanSource {
  FakeScanSource({ScanCameraState camera = ScanCameraState.ready, ScanTorch torch = ScanTorch.off})
    : camera = ValueNotifier(camera),
      torch = ValueNotifier(torch);

  final _codes = StreamController<String>.broadcast();

  @override
  final ValueNotifier<ScanCameraState> camera;
  @override
  final ValueNotifier<ScanTorch> torch;

  int starts = 0;
  int stops = 0;
  int torchToggles = 0;
  int settingsOpened = 0;
  bool disposed = false;

  /// A barcode comes into view (call again to "see" it again).
  void emit(String code) => _codes.add(code);

  @override
  Stream<String> get codes => _codes.stream;

  @override
  Future<void> start() async => starts++;

  @override
  Future<void> stop() async => stops++;

  @override
  Future<void> toggleTorch() async {
    torchToggles++;
    torch.value = torch.value == ScanTorch.on ? ScanTorch.off : ScanTorch.on;
  }

  @override
  Future<void> openSettings() async => settingsOpened++;

  @override
  Widget buildPreview(BuildContext context) => const ColoredBox(key: ValueKey('fake-camera'), color: Colors.black);

  @override
  Future<void> dispose() async {
    disposed = true;
    await _codes.close();
  }
}

/// A lookup answer as the API sends it (the product card plus the scan fields).
Map<String, dynamic> scanAnswer(
  String id, {
  String? name,
  String variantId = 'v1',
  String? variantName,
  String amount = '100.00',
  bool inStock = true,
  bool purchasable = true,
  bool needsChoice = false,
  int? max = 5,
  String matchedBy = 'variant_barcode',
}) => {
  ...productCard(id, amount: amount),
  'name': name ?? 'Product $id',
  'variant_id': needsChoice ? null : variantId,
  'variant_name': variantName,
  'matched_by': matchedBy,
  'in_stock': inStock,
  'purchasable': purchasable,
  'needs_choice': needsChoice,
  'max_quantity': needsChoice ? null : max,
  'has_variants': needsChoice,
};

/// Barcodes and the cart on a pretend server: a code maps to a product, adding a variant that is already in the cart raises
/// its quantity (one line, as the real server does), and the cart answers with its whole self after every change.
class ScanServer {
  ScanServer({this.catalog = const {}, Map<String, int>? lines}) : lines = {...?lines};

  /// code → the API answer (or a ready error reply).
  final Map<String, Object> catalog;
  final Map<String, int> lines;
  final lookups = <String>[];
  final adds = <({String variantId, int quantity})>[];
  final patches = <String>[];
  final deletes = <String>[];
  Completer<void>? holdLookup;
  Completer<void>? holdAdd;
  bool failAdd = false;

  Map<String, dynamic>? _productOf(String variantId) {
    for (final v in catalog.values) {
      if (v is Map<String, dynamic> && v['variant_id'] == variantId) return v;
    }
    return null;
  }

  Map<String, dynamic> cartJson() {
    final items = [
      for (final e in lines.entries)
        if (_productOf(e.key) case final p?)
          {
            'id': 'i-${e.key}',
            'variant_id': e.key,
            'variant_name': p['variant_name'],
            'product': p,
            'quantity': e.value,
            'unit_price': p['price'],
            'line_total': money('${e.value * 100}.00'),
          },
    ];
    final count = lines.values.fold<int>(0, (a, b) => a + b);
    return {
      'id': 'c1',
      'branch_id': 'br1',
      'items': items,
      'items_count': count,
      'subtotal': money('${count * 100}.00'),
      'warnings': <String>[],
    };
  }

  Future<FakeReply?> handle(RecordedRequest r) async {
    if (r.path.startsWith('/products/by-code/')) {
      final code = Uri.decodeComponent(r.path.substring('/products/by-code/'.length));
      lookups.add(code);
      if (holdLookup != null) await holdLookup!.future;
      final hit = catalog[code];
      if (hit == null) {
        return error(404, 'product_not_found', message: "We couldn't find this product. Try again or search by name.");
      }
      return hit is FakeReply ? hit : ok(hit);
    }
    if (r.path == '/cart' && r.method == 'GET') return ok(cartJson());
    if (r.path == '/cart/suggestions') return ok(const []);
    if (r.path == '/cart/items' && r.method == 'POST') {
      final variant = '${r.body['variant_id']}';
      final qty = (r.body['quantity'] as num?)?.toInt() ?? 1;
      adds.add((variantId: variant, quantity: qty));
      if (holdAdd != null) await holdAdd!.future;
      if (failAdd) return error(422, 'item_unavailable_simple', message: 'This item is not available right now.');
      lines[variant] = (lines[variant] ?? 0) + qty;
      return ok(cartJson(), status: 201);
    }
    if (r.path.startsWith('/cart/items/i-') && r.method == 'PATCH') {
      final variant = r.path.substring('/cart/items/i-'.length);
      patches.add('$variant:${r.body['quantity']}');
      lines[variant] = (r.body['quantity'] as num).toInt();
      return ok(cartJson());
    }
    if (r.path.startsWith('/cart/items/i-') && r.method == 'DELETE') {
      final variant = r.path.substring('/cart/items/i-'.length);
      deletes.add(variant);
      lines.remove(variant);
      return ok(cartJson());
    }
    return null;
  }
}
