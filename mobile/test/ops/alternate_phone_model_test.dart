// The customer's second number reaches the driver and staff apps only as an optional field: older servers omit it.
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:flutter_test/flutter_test.dart';

Map<String, dynamic> _job([Map<String, dynamic> extra = const {}]) => {
  'id': 'd1',
  'status': 'assigned',
  'order_number': 'AB1',
  'customer_name': 'Noor',
  'customer_phone': '+97455123456',
  'payment_method': 'cod',
  'cod_amount': '10.00',
  'branch': {'name': 'West Bay'},
  ...extra,
};

Map<String, dynamic> _queue([Map<String, dynamic> extra = const {}]) => {
  'fulfillment_id': 'f1',
  'status': 'ready',
  'number': 'AB1',
  'customer_name': 'Noor',
  'customer_phone': '+97455123456',
  'payment_method': 'cod',
  'grand_total': '100.00',
  ...extra,
};

void main() {
  test('driver job: the second number is read when present', () {
    expect(DriverJob.fromJson(_job({'alternate_phone': '+97455987654'})).alternatePhone, '+97455987654');
  });

  test('driver job: absent (older server), null or blank means none', () {
    expect(DriverJob.fromJson(_job()).alternatePhone, isNull);
    expect(DriverJob.fromJson(_job({'alternate_phone': null})).alternatePhone, isNull);
    expect(DriverJob.fromJson(_job({'alternate_phone': '  '})).alternatePhone, isNull);
  });

  test('queue order: same rules', () {
    expect(QueueOrder.fromJson(_queue({'alternate_phone': '+97455987654'})).alternatePhone, '+97455987654');
    expect(QueueOrder.fromJson(_queue()).alternatePhone, isNull);
    expect(QueueOrder.fromJson(_queue({'alternate_phone': ''})).alternatePhone, isNull);
  });
}
