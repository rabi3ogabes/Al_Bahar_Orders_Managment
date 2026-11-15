import 'package:albahar_customer/core/live_order/live_activity_platform.dart';
import 'package:albahar_customer/core/live_order/live_order.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

const _channel = MethodChannel(MethodChannelLiveActivityPlatform.channelName);

LiveOrder _order() => LiveOrder.tryParse({
  'order_id': 'o1',
  'number': 'A-1',
  'phase': 'on_the_way',
  'sub': 0.5,
  'seq': 7,
  'title': 'Arrives at 9pm',
  'body': 'Ahmed is on the way.',
  'eta_to': '2026-11-14T18:00:00Z',
  'driver_name': 'Ahmed',
  'brand': {'primary': '#8A1538', 'accent': '#B3254F'},
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

  /// What Swift does when Apple hands it a token: a call into Dart on the same channel.
  Future<void> fromNative(String method, Object? arguments) async {
    final message = const StandardMethodCodec().encodeMethodCall(MethodCall(method, arguments));
    await TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger.handlePlatformMessage(
      _channel.name,
      message,
      (_) {},
    );
  }

  setUp(calls.clear);
  tearDown(() {
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger.setMockMethodCallHandler(_channel, null);
    _channel.setMethodCallHandler(null);
  });

  test('the channel name is the one Swift registers', () {
    expect(MethodChannelLiveActivityPlatform.channelName, 'albahar/live_activity');
  });

  group('show', () {
    test('sends the order with the brand, the stage name and the language, and maps what the iPhone did', () async {
      final platform = MethodChannelLiveActivityPlatform(ios: true);
      for (final (text, expected) in [
        ('started', LiveActivityResult.started),
        ('updated', LiveActivityResult.updated),
        ('ended', LiveActivityResult.ended),
        ('unavailable', LiveActivityResult.unavailable),
        ('invalid', LiveActivityResult.unavailable),
      ]) {
        answer((_) async => text);
        expect(
          await platform.show(_order(), locale: 'ar', stageLabel: 'في الطريق'),
          expected,
          reason: text,
        );
      }
      final args = Map<String, dynamic>.from(calls.last.arguments as Map);
      expect(calls.last.method, 'show');
      expect(args['order_id'], 'o1');
      expect(args['number'], 'A-1');
      expect(args['type'], 'delivery');
      expect(args['phase'], 'on_the_way');
      expect(args['step'], 2);
      expect(args['total'], 4);
      expect(args['sub'], 0.5);
      expect(args['title'], 'Arrives at 9pm');
      expect(args['body'], 'Ahmed is on the way.');
      expect(args['driver_name'], 'Ahmed');
      expect(args['eta_to_ms'], DateTime.utc(2026, 11, 14, 18).millisecondsSinceEpoch);
      expect(args['brand_primary'], 0xFF8A1538);
      expect(args['brand_accent'], 0xFFB3254F);
      expect(args['step_label'], 'في الطريق');
      expect(args['locale'], 'ar');
    });

    test('never hands the driver\'s phone number to a Live Activity (it is readable on a locked screen)', () async {
      answer((_) async => 'started');
      final order = LiveOrder.tryParse({
        'order_id': 'o2',
        'phase': 'on_the_way',
        'driver_name': 'Ahmed',
        'driver_phone': '+97455123456',
      })!;
      await MethodChannelLiveActivityPlatform(ios: true).show(order, locale: 'en');
      final args = Map<String, dynamic>.from(calls.single.arguments as Map);
      expect(args, isNot(contains('driver_phone')));
      expect(args.toString(), isNot(contains('97455123456')));
      expect(args['driver_name'], 'Ahmed');
    });

    test(
      'is unavailable, silently, when there is no native side (the Xcode steps are not done): nothing is retried',
      () async {
        // No handler registered: MissingPluginException.
        final platform = MethodChannelLiveActivityPlatform(ios: true);
        expect(await platform.show(_order(), locale: 'en'), LiveActivityResult.unavailable);
        expect(await platform.isAvailable(), isFalse);
        await platform.end('o1');
        await platform.endAll();
        expect(await platform.pendingTokens(), isEmpty);
        // Once it is known to be missing the channel is not called again.
        answer((_) async => 'started');
        expect(await platform.show(_order(), locale: 'en'), LiveActivityResult.unavailable);
        expect(calls, isEmpty);
      },
    );

    test(
      'a native failure is unavailable, and the next call still tries (Live Activities may be switched on again)',
      () async {
        final platform = MethodChannelLiveActivityPlatform(ios: true);
        answer((_) async => throw PlatformException(code: 'live_activity_failed', message: 'boom'));
        expect(await platform.show(_order(), locale: 'en'), LiveActivityResult.unavailable);
        answer((_) async => 'updated');
        expect(await platform.show(_order(), locale: 'en'), LiveActivityResult.updated);
      },
    );

    test('Android, the web and tests never touch the channel', () async {
      answer((_) async => 'started');
      final platform = MethodChannelLiveActivityPlatform(ios: false);
      expect(await platform.show(_order(), locale: 'en'), LiveActivityResult.unavailable);
      expect(await platform.isAvailable(), isFalse);
      await platform.end('o1');
      await platform.endAll();
      expect(await platform.pendingTokens(), isEmpty);
      expect(await platform.tokens.isEmpty, isTrue);
      expect(calls, isEmpty);
    });
  });

  group('the other calls', () {
    test('asks whether Live Activities can be shown', () async {
      answer((_) async => true);
      expect(await MethodChannelLiveActivityPlatform(ios: true).isAvailable(), isTrue);
      expect(calls.single.method, 'isAvailable');
      answer((_) async => false);
      expect(await MethodChannelLiveActivityPlatform(ios: true).isAvailable(), isFalse);
    });

    test('ends one activity by its order, or all of them', () async {
      answer((_) async => null);
      final platform = MethodChannelLiveActivityPlatform(ios: true);
      await platform.end('o1');
      await platform.endAll();
      expect(calls.map((c) => c.method), ['end', 'endAll']);
      expect(calls.first.arguments, 'o1');
    });
  });

  group('tokens', () {
    test('reads the tokens that arrived before anybody listened, and drops anything that is not a token', () async {
      answer(
        (_) async => [
          {'kind': 'activity', 'order_id': 'o1', 'token': 'AA11', 'environment': 'sandbox'},
          {'kind': 'start', 'token': 'BB22', 'environment': 'production'},
          {'kind': 'activity', 'token': 'no-order'},
          {'kind': 'other', 'token': 'x'},
          {'kind': 'start', 'token': '  '},
          'junk',
          null,
        ],
      );

      final tokens = await MethodChannelLiveActivityPlatform(ios: true).pendingTokens();

      expect(tokens, [
        const LiveActivityTokenEvent(kind: 'activity', orderId: 'o1', token: 'AA11', environment: 'sandbox'),
        const LiveActivityTokenEvent(kind: 'start', token: 'BB22', environment: 'production'),
      ]);
    });

    test('hands over the tokens Swift reports, in order, and ignores a call it does not know', () async {
      final platform = MethodChannelLiveActivityPlatform(ios: true);
      final seen = <LiveActivityTokenEvent>[];
      final sub = platform.tokens.listen(seen.add);

      await fromNative('token', {'kind': 'activity', 'order_id': 'o1', 'token': 'AA11', 'environment': 'sandbox'});
      await fromNative('token', {'kind': 'start', 'token': 'BB22'});
      await fromNative('token', {'kind': 'activity'});
      await fromNative('somethingElse', {'kind': 'start', 'token': 'CC33'});
      await Future<void>.delayed(Duration.zero);

      expect(seen.map((e) => e.slot), ['activity:o1', 'start']);
      expect(seen.first.environment, 'sandbox');
      expect(seen.last.environment, 'production', reason: 'an environment nobody named is the real one');
      await sub.cancel();
    });

    test('a second listener shares the same stream', () async {
      final platform = MethodChannelLiveActivityPlatform(ios: true);
      final a = <LiveActivityTokenEvent>[];
      final b = <LiveActivityTokenEvent>[];
      final subs = [platform.tokens.listen(a.add), platform.tokens.listen(b.add)];

      await fromNative('token', {'kind': 'start', 'token': 'BB22'});
      await Future<void>.delayed(Duration.zero);

      expect(a, hasLength(1));
      expect(b, hasLength(1));
      for (final s in subs) {
        await s.cancel();
      }
    });
  });

  group('LiveActivityTokenEvent', () {
    test('one slot per order and one for the phone', () {
      expect(activityEvent('o1', 'a').slot, 'activity:o1');
      expect(activityEvent('o1', 'b').slot, 'activity:o1');
      expect(const LiveActivityTokenEvent(kind: 'start', token: 'x', environment: 'sandbox').slot, 'start');
      expect(activityEvent('o1', 'a'), activityEvent('o1', 'a'));
      expect(activityEvent('o1', 'a').hashCode, activityEvent('o1', 'a').hashCode);
      expect(activityEvent('o1', 'a') == activityEvent('o1', 'b'), isFalse);
    });
  });

  test('NoLiveActivity is quietly unavailable', () async {
    const none = NoLiveActivity();
    expect(await none.isAvailable(), isFalse);
    expect(await none.show(_order(), locale: 'en'), LiveActivityResult.unavailable);
    await none.end('o1');
    await none.endAll();
    expect(await none.pendingTokens(), isEmpty);
    expect(await none.tokens.isEmpty, isTrue);
  });
}

LiveActivityTokenEvent activityEvent(String order, String token) =>
    LiveActivityTokenEvent(kind: 'activity', orderId: order, token: token, environment: 'production');
