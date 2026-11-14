import 'package:albahar_customer/core/network/api_client.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/features/scan/domain/scan_models.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// "Scan to add" lookups. The server decides what a scanned string means (barcode, SKU, store link, `albahar://` link, slug),
/// so the app never guesses: it sends the text as read and acts on the answer.
class ScanRepository {
  ScanRepository(this._api);
  final ApiClient _api;

  /// Longest text the server looks at; anything longer cannot be a code the shop printed, so it is not even sent.
  static const maxCodeLength = 512;

  /// Throws an [ApiException] with `code == 'product_not_found'` (404) when the code names no sellable product.
  Future<ScanLookup> byCode(String code, {String? branchId}) => _api.getObject(
    // Encoded as one path segment, so a whole link ("https://…/products/sofa") travels as a single value.
    '/products/by-code/${Uri.encodeComponent(code)}',
    ScanLookup.fromJson,
    query: {'branch_id': branchId},
  );
}

final scanRepositoryProvider = Provider<ScanRepository>((ref) => ScanRepository(ref.watch(apiClientProvider)));
