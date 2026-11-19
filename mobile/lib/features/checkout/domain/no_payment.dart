import 'package:albahar_customer/features/branches/domain/branch_models.dart';
import 'package:albahar_customer/features/checkout/domain/checkout_models.dart';

/// The ways forward when the quote leaves nothing to pay with (online payment is off and cash on delivery does not take a basket this
/// size): collect at a showroom and pay there, call the showroom, or send the basket to someone in the family. Pure, so it is tested alone.

/// A showroom where the customer can collect the order and pay on the spot. For a pickup order the showroom already chosen is left out
/// (it was what failed); for a delivery order any showroom that offers pickup and pay-at-pickup will do. Null when there is none.
Branch? collectAndPayBranch(
  List<Branch> branches, {
  required FulfillmentType type,
  String? currentBranchId,
  String? preferredBranchId,
}) {
  final candidates = [
    for (final b in branches)
      if (b.pickupEnabled && b.payAtPickupEnabled && !(type == FulfillmentType.pickup && b.id == currentBranchId)) b,
  ];
  return candidates.where((b) => b.id == preferredBranchId).firstOrNull ?? candidates.firstOrNull;
}

/// The showroom number to call: the branch that would prepare the order, else the pickup branch chosen, else the preferred one, else
/// any with a number. Only a number the API sent: never made up. Null when no showroom has one.
String? contactPhone(
  List<Branch> branches, {
  String? orderBranchId,
  String? pickupBranchId,
  String? preferredBranchId,
}) {
  String? phoneOf(String? id) {
    if (id == null) return null;
    final p = branches.where((b) => b.id == id).firstOrNull?.phone?.trim();
    return p == null || p.isEmpty ? null : p;
  }

  return phoneOf(orderBranchId) ??
      phoneOf(pickupBranchId) ??
      phoneOf(preferredBranchId) ??
      branches.map((b) => b.phone?.trim()).whereType<String>().where((p) => p.isNotEmpty).firstOrNull;
}

/// One piece of the basket as the message to the family lists it.
class ShareLine {
  const ShareLine({required this.name, required this.quantity, this.variantName, this.url});
  final String name;
  final int quantity;
  final String? variantName;

  /// The piece's page on the website, when its slug is known.
  final String? url;
}

/// "Please help" + each piece (with its page) + the total. A very long basket is cut after 15 pieces and says how many more.
String basketShareText(List<ShareLine> lines, {required String intro, required String total}) {
  final rows = <String>[
    for (final l in lines.take(15))
      [
        '• ${l.name}${l.variantName == null || l.variantName!.isEmpty ? '' : ' (${l.variantName})'} × ${l.quantity}',
        if (l.url != null) '  ${l.url}',
      ].join('\n'),
  ];
  return [intro, '', ...rows, if (lines.length > 15) '… +${lines.length - 15}', '', total].join('\n');
}
