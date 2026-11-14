// A programmable fake of the customer API for the checkout: two branches, delivery and pickup times that tests can change
// while the screen is open, a quote that behaves like the real one (slots, split choice, second phone number).
import 'package:albahar_customer/core/utils/qatar_time.dart';

import 'fakes.dart';

/// `YYYY-MM-DD` of today (Qatar) plus [offset] days.
String qDay(int offset) => QatarTime.isoDate(QatarTime.today().add(Duration(days: offset)));

/// One row of `GET /branches/{id}/{delivery|pickup}-slots`.
Map<String, dynamic> slotRow(
  String id,
  String date,
  String from,
  String to, {
  int available = 6,
  String? bookableUntil,
}) => {
  'id': id,
  'date': date,
  'starts_at': from,
  'ends_at': to,
  'capacity': 8,
  'available': available,
  'is_full': available == 0,
  'bookable_until': ?bookableUntil,
};

class CheckoutBackend {
  CheckoutBackend({
    Map<String, Map<String, List<Map<String, dynamic>>>>? delivery,
    Map<String, Map<String, List<Map<String, dynamic>>>>? pickup,
    this.savedAlternatePhone,
    this.mainPhone = '+97455123456',
    this.splitChoice = false,
    this.pickupBranches = const ['br1', 'br2'],
    this.deliveryPublishesSlots = true,
  }) : delivery = delivery ?? {},
       pickup = pickup ?? {};

  /// branch → date → slots (times the customer can book).
  final Map<String, Map<String, List<Map<String, dynamic>>>> delivery;
  final Map<String, Map<String, List<Map<String, dynamic>>>> pickup;

  String? savedAlternatePhone;
  final String mainPhone;
  bool splitChoice;
  final List<String> pickupBranches;
  final bool deliveryPublishesSlots;

  /// Slot ids the quote answers `slot_unavailable` for, whatever the lists say.
  final Set<String> refused = {};

  /// Which branch serves which address.
  final Map<String, String> branchOfAddress = {'a1': 'br1', 'a2': 'br2'};

  Map<String, dynamic> _span(Map<String, Map<String, List<Map<String, dynamic>>>> source, String branch, RecordedRequest r) {
    final from = r.query['from'] as String;
    final to = r.query['to'] as String;
    final onlyAvailable = r.query['only_available'] != null;
    final byDate = source[branch] ?? const {};
    final rows = [
      for (final e in (byDate.entries.toList()..sort((a, b) => a.key.compareTo(b.key))))
        if (e.key.compareTo(from) >= 0 && e.key.compareTo(to) <= 0 && e.value.isNotEmpty)
          {
            'date': e.key,
            'available': e.value.fold<int>(0, (a, s) => a + (s['available'] as int)),
            'slots': e.value,
          },
    ].where((d) => !onlyAvailable || (d['available'] as int) > 0).toList();
    return {'data': rows};
  }

  FakeReply _slots(Map<String, Map<String, List<Map<String, dynamic>>>> source, String branch, RecordedRequest r) {
    if (r.query['from'] != null) return FakeReply(200, _span(source, branch, r));
    return ok(source[branch]?[r.query['date']] ?? const []);
  }

  bool _slotIsFree(Map<String, Map<String, List<Map<String, dynamic>>>> source, String branch, String id, String? date) {
    final rows = source[branch]?[date] ?? const [];
    return rows.any((s) => s['id'] == id && (s['available'] as int) > 0) && !refused.contains(id);
  }

  Map<String, dynamic> quoteFor(RecordedRequest r) {
    final b = r.body as Map;
    final type = (b['fulfillment_type'] as String?) ?? 'delivery';
    final isPickup = type == 'pickup';
    final branch = isPickup ? (b['branch_id'] as String? ?? 'br1') : (branchOfAddress[b['address_id']] ?? 'br1');
    final slotId = (isPickup ? b['pickup_slot_id'] : b['delivery_slot_id']) as String?;
    final slotDate = (isPickup ? b['pickup_date'] : b['delivery_date']) as String?;
    final source = isPickup ? pickup : delivery;
    final required = !isPickup && deliveryPublishesSlots;
    final errors = <Map<String, dynamic>>[];
    Map<String, dynamic>? slot;
    if (slotId != null && slotDate != null) {
      if (_slotIsFree(source, branch, slotId, slotDate)) {
        final row = source[branch]![slotDate]!.firstWhere((s) => s['id'] == slotId);
        slot = {'id': slotId, 'date': slotDate, 'starts_at': row['starts_at'], 'ends_at': row['ends_at']};
      } else {
        errors.add({'code': 'slot_unavailable', 'message': 'slot_unavailable'});
      }
    } else if (required) {
      errors.add({'code': 'slot_required', 'message': 'slot_required'});
    }
    return {
      'quote_hash': 'q-$branch-${slotId ?? 'none'}-${slotDate ?? ''}-${b['alternate_phone']}-${b['no_alternate_phone']}',
      'fulfillment_type': type,
      'is_split': false,
      'split_choice': splitChoice,
      'fulfillments': [
        {
          'branch': {'id': branch, 'name': branch == 'br1' ? 'West Bay' : 'Lusail'},
          'type': type,
          'items': [],
          'delivery_fee': money('25.00'),
          'slot': slot,
          'slot_required': required,
        },
      ],
      'lines': [],
      'totals': {'subtotal': money('100.00'), 'delivery_fee': money('25.00'), 'grand_total': money('125.00')},
      'payment_methods': [
        {'code': 'sadad', 'available': true},
        {'code': 'cod', 'available': !isPickup},
        {'code': 'pay_at_pickup', 'available': isPickup},
      ],
      'loyalty': {'balance': 0, 'max_redeemable_points': 0, 'points_to_earn': 0},
      'warnings': [],
      'errors': errors,
      'can_place_order': errors.isEmpty,
      'expires_at': '2026-11-05T12:00:00+03:00',
    };
  }

  FakeHandler get handler => (r) {
    final path = r.path;
    final key = '${r.method} $path';
    final slotsMatch = RegExp(r'^/branches/([^/]+)/(delivery|pickup)-slots$').firstMatch(path);
    if (r.method == 'GET' && slotsMatch != null) {
      return _slots(slotsMatch[2] == 'delivery' ? delivery : pickup, slotsMatch[1]!, r);
    }
    return switch (key) {
      'GET /me' => ok({
        'id': 'u1',
        'name': 'Noor',
        'phone': mainPhone,
        'alternate_phone': savedAlternatePhone,
      }),
      'GET /cart' => ok({
        'id': 'c1',
        'branch_id': 'br1',
        'fulfillment_type': 'delivery',
        'address_id': 'a1',
        'items': [],
        'items_count': 0,
        'subtotal': money('0.00'),
      }),
      'GET /me/addresses' => page([
        {'id': 'a1', 'recipient_name': 'Noor', 'recipient_phone': mainPhone, 'is_default': true, 'zone_number': '66'},
        {'id': 'a2', 'recipient_name': 'Noor', 'recipient_phone': mainPhone, 'zone_number': '70'},
      ]),
      'GET /branches' => page([
        for (final id in pickupBranches)
          {
            'id': id,
            'name': id == 'br1' ? 'West Bay' : 'Lusail',
            'pickup_enabled': true,
            'delivery_enabled': true,
          },
      ]),
      'POST /checkout/quote' => ok(quoteFor(r)),
      'POST /checkout/orders' => ok({
        'order': {'id': 'o1', 'number': 'AB1'},
        'payment': {'payment_id': 'p1', 'method': 'cod', 'status': 'pending', 'action': 'none'},
      }, status: 201),
      _ => const FakeReply(404, {'message': 'not mocked', 'code': 'not_found'}),
    };
  };
}
