import 'package:albahar_customer/core/live_order/live_activity_registrar.dart';
import 'package:albahar_customer/core/storage/app_preferences.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'live_activity_fakes.dart';

class _Rig {
  _Rig(this.prefs) {
    registrar = LiveActivityRegistrar(
      platform: platform,
      api: api,
      prefs: prefs,
      localeCode: () => locale,
      appVersion: () async => '1.4.0+7',
    );
  }

  final AppPreferences prefs;
  final platform = FakeActivity();
  final api = FakeActivityApi();
  late final LiveActivityRegistrar registrar;
  String locale = 'en';

  static Future<_Rig> create({Map<String, Object> saved = const {}}) async {
    SharedPreferences.setMockInitialValues(saved);
    return _Rig(AppPreferences(await SharedPreferences.getInstance()));
  }

  /// Lets the queued work run.
  Future<void> settle() => Future<void>.delayed(Duration.zero).then((_) => Future<void>.delayed(Duration.zero));
}

void main() {
  test(
    'reports a running activity\'s token for the signed-in customer, with the phone, the language and the version',
    () async {
      final rig = await _Rig.create();
      rig.registrar.onSignedIn();
      await rig.settle();

      rig.platform.emit(activityToken('o1', 'AA11', environment: 'sandbox'));
      await rig.settle();

      expect(rig.api.activities, hasLength(1));
      final sent = rig.api.activities.single;
      expect(sent['order_id'], 'o1');
      expect(sent['token'], 'AA11');
      expect(sent['environment'], 'sandbox');
      expect(sent['locale'], 'en');
      expect(sent['app_version'], '1.4.0+7');
      expect(sent['device_id'], isNotEmpty);
      expect(sent['device_id'], rig.prefs.installId, reason: 'the same install id the push token is registered with');
    },
  );

  test('uses the install id the push token already has', () async {
    final rig = await _Rig.create(saved: {'install_id': 'install-123'});
    await rig.prefs.setInstallId('install-123');
    rig.registrar.onSignedIn();
    rig.platform.emit(activityToken('o1', 'AA11'));
    await rig.settle();

    expect(rig.api.activities.single['device_id'], 'install-123');
  });

  test('reports the phone\'s push-to-start token on its own endpoint', () async {
    final rig = await _Rig.create();
    rig.registrar.onSignedIn();
    rig.platform.emit(startToken('BB22'));
    await rig.settle();

    expect(rig.api.starts.single['token'], 'BB22');
    expect(rig.api.activities, isEmpty);
  });

  test('reports nothing while nobody is signed in, and nothing is kept for the next person', () async {
    final rig = await _Rig.create();
    rig.platform.emit(activityToken('o1', 'AA11')); // nobody listens yet
    await rig.settle();
    expect(rig.api.activities, isEmpty);
    expect(rig.platform.listeners, 0);

    rig.registrar.onSignedIn();
    await rig.settle();
    rig.platform.emit(activityToken('o2', 'CC33'));
    await rig.settle();
    await rig.registrar.onSignedOut();
    rig.platform.emit(activityToken('o3', 'DD44'));
    await rig.settle();

    expect(rig.api.activities.map((a) => a['order_id']), ['o2']);
    expect(rig.platform.listeners, 0, reason: 'sign-out stops listening');
  });

  test(
    'picks up the tokens that were waiting (the app was started in the background) when somebody signs in',
    () async {
      final rig = await _Rig.create();
      rig.platform.pending = [activityToken('o1', 'AA11'), startToken('BB22')];
      rig.registrar.onSignedIn();
      await rig.settle();

      expect(rig.api.activities.single['token'], 'AA11');
      expect(rig.api.starts.single['token'], 'BB22');
    },
  );

  test('does not report the same token twice, but reports a token that changed (Apple rotates them)', () async {
    final rig = await _Rig.create();
    rig.registrar.onSignedIn();
    rig.platform
      ..emit(activityToken('o1', 'AA11'))
      ..emit(activityToken('o1', 'AA11'));
    await rig.settle();
    await rig.registrar.flush();
    expect(rig.api.activities, hasLength(1));

    rig.platform.emit(activityToken('o1', 'AA99'));
    await rig.settle();
    expect(rig.api.activities.map((a) => a['token']), ['AA11', 'AA99']);
    expect(rig.registrar.reportedSlots, {'activity:o1'});
  });

  test('keeps a token the server could not take and sends it when the app comes back or the network does', () async {
    final rig = await _Rig.create();
    rig.api.error = Exception('offline');
    rig.registrar.onSignedIn();
    rig.platform.emit(activityToken('o1', 'AA11'));
    await rig.settle();
    expect(rig.api.activities, isEmpty);
    expect(rig.registrar.reportedSlots, isEmpty);

    rig.api.error = null;
    await rig.registrar.flush();

    expect(rig.api.activities.single['token'], 'AA11');
    expect(rig.registrar.reportedSlots, {'activity:o1'});
  });

  test('one token the server refuses does not stop the next one', () async {
    final rig = await _Rig.create();
    rig.registrar.onSignedIn();
    await rig.settle();
    rig.api.error = Exception('422');
    rig.platform.emit(activityToken('o1', 'AA11'));
    await rig.settle();
    rig.api.error = null;
    rig.platform.emit(activityToken('o2', 'BB22'));
    await rig.settle();

    // o1 is retried together with o2 now that the server answers.
    expect(rig.api.activities.map((a) => a['order_id']).toSet(), {'o1', 'o2'});
  });

  test('takes this phone\'s tokens back at sign-out, once, and says nothing when nothing was reported', () async {
    final rig = await _Rig.create();
    rig.registrar.onSignedIn();
    await rig.settle();
    await rig.registrar.onSignedOut();
    expect(rig.api.unregistered, isEmpty);

    rig.registrar.onSignedIn();
    rig.platform.emit(activityToken('o1', 'AA11'));
    await rig.settle();
    await rig.registrar.onSignedOut();
    await rig.registrar.onSignedOut();

    expect(rig.api.unregistered, [rig.prefs.installId]);
    expect(rig.registrar.reportedSlots, isEmpty);
  });

  test('a failing sign-out call never throws (the session is gone, the phone is offline)', () async {
    final rig = await _Rig.create();
    rig.api.unregisterError = Exception('401');
    rig.registrar.onSignedIn();
    rig.platform.emit(activityToken('o1', 'AA11'));
    await rig.settle();

    await rig.registrar.onSignedOut();

    expect(rig.registrar.reportedSlots, isEmpty);
  });

  test('a second customer on the same phone starts from nothing', () async {
    final rig = await _Rig.create();
    rig.registrar.onSignedIn();
    rig.platform.emit(activityToken('o1', 'AA11'));
    await rig.settle();
    await rig.registrar.onSignedOut();

    rig.registrar.onSignedIn();
    await rig.settle();
    expect(
      rig.api.activities,
      hasLength(1),
      reason: 'the first customer\'s token is not reported again for the second',
    );
    rig.platform.emit(activityToken('o9', 'ZZ99'));
    await rig.settle();
    expect(rig.api.activities.last['order_id'], 'o9');
  });

  test('reports everything again, in the new language, when the app\'s language changes', () async {
    final rig = await _Rig.create();
    rig.registrar.onSignedIn();
    rig.platform
      ..emit(activityToken('o1', 'AA11'))
      ..emit(startToken('BB22'));
    await rig.settle();
    rig.locale = 'ar';

    await rig.registrar.onLocaleChanged();

    expect(rig.api.activities.map((a) => a['locale']), ['en', 'ar']);
    expect(rig.api.starts.map((a) => a['locale']), ['en', 'ar']);
  });

  test('signing in twice listens once', () async {
    final rig = await _Rig.create();
    rig.registrar
      ..onSignedIn()
      ..onSignedIn();
    await rig.settle();

    expect(rig.platform.listeners, 1);
    await rig.registrar.dispose();
    expect(rig.platform.listeners, 0);
  });

  test('flush with nobody signed in does nothing', () async {
    final rig = await _Rig.create();
    await rig.registrar.flush();
    expect(rig.api.activities, isEmpty);
  });
}
