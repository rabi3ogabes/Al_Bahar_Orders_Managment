import 'package:albahar_customer/core/live_order/live_order.dart';
import 'package:albahar_customer/core/live_order/live_order_platform.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

const _channel = MethodChannel(MethodChannelLiveOrderPlatform.channelName);

LiveOrder _order() => LiveOrder.tryParse({
  'order_id': 'o1',
  'number': 'A-1',
  'phase': 'on_the_way',
  'sub': 0.5,
  'seq': 7,
  'eta_label': 'Arrives at 9pm',
  'brand_primary': '#8A1538',
})!;

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  final calls = <MethodCall>[];
  void answer(Future<Object?> Function(MethodCall call) handler) {
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger.setMockMethodCallHandler(_channel, (call) {
      calls.add(call);
      return handler(call);
    });
  }

  setUp(calls.clear);
  tearDown(
    () => TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger.setMockMethodCallHandler(_channel, null),
  );

  test('the channel name is the one Kotlin registers', () {
    expect(MethodChannelLiveOrderPlatform.channelName, 'albahar/live_order');
  });

  group('show', () {
    test('sends the normalised state with the locale and the force flag, and maps the answers', () async {
      final platform = MethodChannelLiveOrderPlatform(android: true);
      for (final (answerText, expected) in [
        ('posted', LiveOrderShowResult.posted),
        ('skipped', LiveOrderShowResult.skipped),
        ('dismissed', LiveOrderShowResult.dismissed),
        ('disabled', LiveOrderShowResult.disabled),
        ('invalid', LiveOrderShowResult.posted),
      ]) {
        answer((_) async => answerText);
        expect(await platform.show(_order(), locale: 'ar', force: true), expected, reason: answerText);
      }
      final args = Map<String, dynamic>.from(calls.last.arguments as Map);
      expect(calls.last.method, 'show');
      expect(args['order_id'], 'o1');
      expect(args['phase'], 'on_the_way');
      expect(args['step'], 2);
      expect(args['sub'], 0.5);
      expect(args['seq'], 7);
      expect(args['eta_label'], 'Arrives at 9pm');
      expect(args['brand_primary'], 0xFF8A1538);
      expect(args['locale'], 'ar');
      expect(args['force'], isTrue);
      expect(args['arabic_digits'], isFalse);
      expect(args['deep_link'], '/orders/o1');
    });

    test('without the native side every call answers "unsupported" (the plain notification is used instead)', () async {
      // No handler registered: MissingPluginException.
      final platform = MethodChannelLiveOrderPlatform(android: true);
      expect(await platform.show(_order(), locale: 'en'), LiveOrderShowResult.unsupported);
      expect(await platform.permission(), LiveOrderPermission.granted);
      await platform.cancel('o1');
      await platform.cancelAll();
      expect(await platform.openSettings(), isFalse);
    });

    test('a platform that is not Android never touches the channel', () async {
      answer((_) async => 'posted');
      final platform = MethodChannelLiveOrderPlatform(android: false);
      expect(await platform.show(_order(), locale: 'en'), LiveOrderShowResult.unsupported);
      expect(await platform.requestPermission(), LiveOrderPermission.granted);
      expect(calls, isEmpty);
    });

    test('a failing native call is not retried with a second card on top', () async {
      answer((_) async => throw PlatformException(code: 'live_order_failed', message: 'boom'));
      final platform = MethodChannelLiveOrderPlatform(android: true);
      expect(await platform.show(_order(), locale: 'en'), LiveOrderShowResult.posted);
    });
  });

  group('cancel', () {
    test('sends the order id, or everything', () async {
      answer((_) async => null);
      final platform = MethodChannelLiveOrderPlatform(android: true);
      await platform.cancel('o1');
      await platform.cancelAll();
      expect(calls.map((c) => c.method), ['cancel', 'cancelAll']);
      expect(calls.first.arguments, 'o1');
    });
  });

  group('permission', () {
    test('reads the standing and asks through the native side', () async {
      final platform = MethodChannelLiveOrderPlatform(android: true);
      for (final (text, expected) in [
        ('granted', LiveOrderPermission.granted),
        ('denied', LiveOrderPermission.denied),
        ('blocked', LiveOrderPermission.blocked),
      ]) {
        answer((_) async => text);
        expect(await platform.permission(), expected);
        expect(await platform.requestPermission(), expected);
      }
      expect(calls.map((c) => c.method).toSet(), {'permissionStatus', 'requestPermission'});
    });

    test('opens the system settings', () async {
      answer((_) async => true);
      expect(await MethodChannelLiveOrderPlatform(android: true).openSettings(), isTrue);
      expect(calls.single.method, 'openSettings');
    });

    test('an answer nobody knows reads as granted rather than blocking the customer', () {
      expect(LiveOrderPermission.parse('???'), LiveOrderPermission.granted);
      expect(LiveOrderPermission.parse(null), LiveOrderPermission.granted);
    });
  });
}
