import 'package:albahar_customer/features/branches/domain/branch_models.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:flutter_test/flutter_test.dart';

Branch _b(String id, {bool delivery = true, bool? open}) => Branch(
  id: id,
  name: id,
  deliveryEnabled: delivery,
  opening: open == null ? null : BranchOpening(isOpen: open),
);

void main() {
  test('picks the nearest branch that delivers and is open', () {
    final list = [
      _b('closed-near', open: false),
      _b('pickup-only', delivery: false, open: true),
      _b('open-far', open: true),
    ];
    expect(pickBestBranch(list)?.id, 'open-far');
  });

  test('falls back to the nearest delivering branch when none is open, then to the first', () {
    expect(pickBestBranch([_b('a', open: false), _b('b', open: false)])?.id, 'a');
    expect(pickBestBranch([_b('p', delivery: false)])?.id, 'p');
    expect(pickBestBranch(const []), isNull);
  });
}
