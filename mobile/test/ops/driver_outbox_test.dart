import 'dart:async';
import 'dart:io';

import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:albahar_customer/ops/driver/driver_outbox.dart';
import 'package:albahar_customer/ops/ops_read_cache.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../helpers/fakes.dart';
import 'ops_test_kit.dart' show apiError;

/// The driver app's outbox (docs/guides/OFFLINE_DRIVER_AND_STAFF_APPS.md): kept on the phone, sent in the order pressed per job, one at a time,
/// never twice, and a refusal stops only its own job.
/// A sender that records what went (key and whether the time went along) and answers what the test set for each key.
class Wire {
  final sent = <String>[];
  final withTime = <bool>[];
  final failures = <String, List<Object>>{};
  var inFlight = 0;
  var maxInFlight = 0;
  Completer<void>? gate;

  Future<void> call(OutboxItem i) async {
    sent.add(i.key);
    withTime.add(i.sendTime);
    inFlight++;
    maxInFlight = inFlight > maxInFlight ? inFlight : maxInFlight;
    try {
      await gate?.future;
      final queue = failures[i.key];
      if (queue != null && queue.isNotEmpty) throw queue.removeAt(0);
    } finally {
      inFlight--;
    }
  }
}

void main() {
  final pressed = DateTime.utc(2026, 11, 19, 9, 5);

  OutboxItem item(
    String key,
    String delivery,
    String action, {
    String? photo,
    Map<String, Object?> fields = const {},
  }) => OutboxItem(
    key: key,
    ownerId: 'u1',
    deliveryId: delivery,
    orderNumber: 'AB$delivery',
    action: action,
    actedAt: pressed,
    fields: fields,
    photoPath: photo,
  );

  final offline = apiError(ApiErrorKind.offline, 0);

  group('order and keys', () {
    test('sends strictly in the order pressed, one at a time, and the same key goes with every try', () async {
      final wire = Wire()..failures['a1'] = [offline];
      final box = DriverOutbox(store: MemoryOutboxStore(), send: wire.call);

      expect(await box.submit(item('a1', 'A', 'pickup')), OutboxSubmit.queued); // no signal
      expect(await box.submit(item('b1', 'B', 'accept')), OutboxSubmit.sent); // another job is not held back
      expect(await box.submit(item('a2', 'A', 'out-for-delivery')), OutboxSubmit.queued); // behind a1, never before it
      expect(wire.sent, ['a1', 'b1']);

      await box.flush(force: true);

      expect(wire.sent, ['a1', 'b1', 'a1', 'a2']);
      expect(wire.maxInFlight, 1);
      expect(box.items, isEmpty);
    });

    test('a second flush started while one runs does not send anything twice', () async {
      final wire = Wire()..gate = Completer<void>();
      final box = DriverOutbox(store: MemoryOutboxStore(), send: wire.call);
      await box.store.save([item('a1', 'A', 'pickup'), item('b1', 'B', 'pickup')]);
      await box.load();

      final first = box.flush();
      final second = box.flush();
      wire.gate!.complete();
      await Future.wait([first, second]);

      expect(wire.sent, ['a1', 'b1']);
      expect(wire.maxInFlight, 1);
    });

    test('waits longer after each failed try, at most five minutes', () async {
      var now = DateTime.utc(2026, 11, 19, 9);
      final wire = Wire()..failures['a1'] = [offline, offline, offline];
      final box = DriverOutbox(store: MemoryOutboxStore(), send: wire.call, now: () => now);

      await box.submit(item('a1', 'A', 'pickup'));
      expect(box.items.single.attempts, 1);
      expect(box.items.single.nextTryAt, now.add(const Duration(seconds: 5)));

      await box.flush(); // too early: nothing is tried
      expect(wire.sent, ['a1']);

      now = now.add(const Duration(seconds: 6));
      await box.flush();
      expect(box.items.single.nextTryAt, now.add(const Duration(seconds: 10)));
      expect(box.nextDue, now.add(const Duration(seconds: 10)));

      expect([for (var a = 1; a <= 9; a++) DriverOutbox.backoff(a).inSeconds], [5, 10, 20, 40, 80, 160, 300, 300, 300]);
    });

    test('a server fault waits for its next try while the other jobs go on', () async {
      final wire = Wire()..failures['a1'] = [apiError(ApiErrorKind.server, 503)];
      final box = DriverOutbox(store: MemoryOutboxStore(), send: wire.call);
      await box.store.save([item('a1', 'A', 'pickup'), item('b1', 'B', 'pickup')]);
      await box.load();

      await box.flush();

      expect(wire.sent, ['a1', 'b1']);
      expect(box.items.single.key, 'a1');
      expect(box.items.single.state, OutboxState.waiting);
    });
  });

  group('refusals', () {
    test('a refusal (4xx) stops only that job, keeps the reason, and the job goes on once it is discarded', () async {
      final deleted = <String>[];
      final wire = Wire()
        ..failures['a1'] = [
          apiError(ApiErrorKind.conflict, 409, code: 'delivery_cancelled', message: 'This order was cancelled.'),
        ];
      final box = DriverOutbox(store: MemoryOutboxStore(), send: wire.call, deletePhoto: (p) async => deleted.add(p));
      await box.store.save([
        item('a1', 'A', 'deliver', photo: '/outbox/a1.jpg'),
        item('a2', 'A', 'fail'),
        item('b1', 'B', 'pickup'),
      ]);
      await box.load();

      await box.flush();

      expect(wire.sent, ['a1', 'b1']); // a2 waits behind the refused a1; b1 goes
      expect(box.items.map((i) => i.key), ['a1', 'a2']);
      expect(box.items.first.state, OutboxState.refused);
      expect(box.items.first.errorCode, 'delivery_cancelled');
      expect(box.items.first.errorMessage, 'This order was cancelled.');

      await box.discard('a1');
      await box.flush();

      expect(deleted, ['/outbox/a1.jpg']); // the photo copy goes with it
      expect(wire.sent, ['a1', 'b1', 'a2']);
      expect(box.items, isEmpty);
    });

    test('Try again sends a refused update once more, with the same key', () async {
      final wire = Wire()..failures['a1'] = [apiError(ApiErrorKind.validation, 422, code: 'cancellation_pending')];
      final box = DriverOutbox(store: MemoryOutboxStore(), send: wire.call);
      await box.store.save([item('a1', 'A', 'pickup')]);
      await box.load();
      await box.flush();
      expect(box.items.single.state, OutboxState.refused);

      await box.retry('a1');

      expect(wire.sent, ['a1', 'a1']);
      expect(box.items, isEmpty);
    });

    test('a refusal of a fresh press is thrown to the form and nothing is kept, the photo copy neither', () async {
      final deleted = <String>[];
      final wire = Wire()
        ..failures['a1'] = [apiError(ApiErrorKind.validation, 422, code: 'collection_amount_mismatch')];
      final box = DriverOutbox(store: MemoryOutboxStore(), send: wire.call, deletePhoto: (p) async => deleted.add(p));

      await expectLater(box.submit(item('a1', 'A', 'deliver', photo: '/outbox/a1.jpg')), throwsA(isA<ApiException>()));

      expect(box.items, isEmpty);
      expect(deleted, ['/outbox/a1.jpg']);
    });

    test('a 401 keeps every update until the driver signs in again, then sends them', () async {
      final wire = Wire()..failures['a1'] = [apiError(ApiErrorKind.unauthorized, 401)];
      final box = DriverOutbox(store: MemoryOutboxStore(), send: wire.call);
      await box.store.save([item('a1', 'A', 'pickup'), item('b1', 'B', 'pickup')]);
      await box.load();

      await box.flush();
      expect(wire.sent, ['a1']); // nothing else is tried without a session
      expect(box.items.map((i) => i.state), [OutboxState.signIn, OutboxState.signIn]);
      expect(box.waitingForSignIn, isTrue);
      expect(await box.submit(item('c1', 'C', 'accept')), OutboxSubmit.queued);

      await box.resume();

      expect(wire.sent, ['a1', 'a1', 'b1', 'c1']);
      expect(box.items, isEmpty);
    });

    test('a phone clock that runs ahead: the same update goes again without its time', () async {
      final wire = Wire()..failures['a1'] = [apiError(ApiErrorKind.validation, 422, code: 'acted_at_in_future')];
      final box = DriverOutbox(store: MemoryOutboxStore(), send: wire.call);

      expect(await box.submit(item('a1', 'A', 'pickup')), OutboxSubmit.sent);

      expect(wire.sent, ['a1', 'a1']);
      expect(wire.withTime, [true, false]);
    });

    test('an update whose photo copy is gone is put to the driver, never dropped silently', () async {
      final box = DriverOutbox(store: MemoryOutboxStore(), send: (_) async => throw const FileSystemException('gone'));
      await box.store.save([item('a1', 'A', 'deliver', photo: '/outbox/a1.jpg')]);
      await box.load();

      await box.flush();

      expect(box.items.single.state, OutboxState.refused);
      expect(box.items.single.errorCode, 'not_sent');
    });
  });

  group('kept on the phone', () {
    test(
      'survives the app being closed: a new outbox on the same store finds every update as it was, and sends it',
      () async {
        final store = MemoryOutboxStore();
        final first = DriverOutbox(store: store, send: (_) async => throw offline);
        await first.submit(
          item(
            'a1',
            'A',
            'deliver',
            photo: '/outbox/a1.jpg',
            fields: {'recipient': 'Noura', 'cod': 303.5, 'lat': 25.3},
          ),
        );
        await first.submit(item('a2', 'A', 'assembly', fields: {'status': 'not_done', 'reason': 'customer_declined'}));
        expect(store.raw, isNot(contains('token'))); // nothing secret is kept

        final sent = <OutboxItem>[];
        final second = DriverOutbox(store: store, send: (i) async => sent.add(i));
        await second.load();
        expect(second.items.map((i) => i.key), ['a1', 'a2']);
        await second.flush(force: true);

        expect(sent.map((i) => i.key), ['a1', 'a2']);
        expect(sent.first.actedAt, pressed);
        expect(sent.first.photoPath, '/outbox/a1.jpg');
        expect(sent.first.fields, {'recipient': 'Noura', 'cod': 303.5, 'lat': 25.3});
        expect(sent.last.fields['reason'], 'customer_declined');
        expect(store.raw, isNull);
      },
    );

    test(
      'the phone storage keeps it in the preferences file under one key, and an unreadable entry is dropped',
      () async {
        SharedPreferences.setMockInitialValues({
          PrefsOutboxStore.storageKey:
              '[{"key":"x"},{"key":"a1","delivery_id":"A","action":"pickup","acted_at":"2026-11-19T09:05:00.000Z"}]',
        });
        final store = PrefsOutboxStore(await SharedPreferences.getInstance());
        final items = await store.load();
        expect(items.map((i) => i.key), ['a1']);
        expect(items.single.actedAt, pressed);

        await store.save(const []);
        expect((await SharedPreferences.getInstance()).getString(PrefsOutboxStore.storageKey), isNull);
      },
    );

    test(
      'another account signed in on this phone: the first driver\'s updates are dropped, never sent with this session',
      () async {
        final deleted = <String>[];
        final box = DriverOutbox(
          store: MemoryOutboxStore(),
          send: (_) async {},
          deletePhoto: (p) async => deleted.add(p),
        );
        await box.store.save([item('a1', 'A', 'deliver', photo: '/outbox/a1.jpg')]);
        await box.load();

        await box.keepOwner('u2');

        expect(box.items, isEmpty);
        expect(deleted, ['/outbox/a1.jpg']);
      },
    );

    test('signing out without sending drops everything, the photos too', () async {
      final deleted = <String>[];
      final box = DriverOutbox(
        store: MemoryOutboxStore(),
        send: (_) async => throw offline,
        deletePhoto: (p) async => deleted.add(p),
      );
      await box.submit(item('a1', 'A', 'fail', photo: '/outbox/a1.jpg'));

      await box.clear();

      expect(box.items, isEmpty);
      expect(deleted, ['/outbox/a1.jpg']);
    });
  });

  group('wire format of a step sent later', () {
    late Directory tmp;
    setUp(() => tmp = Directory.systemTemp.createTempSync('ops-outbox'));
    tearDown(() => tmp.deleteSync(recursive: true));

    Future<(OpsRepository, FakeAdapter)> repo(FakeHandler handler, {OpsReadCache? cache}) async {
      final h = await TestHarness.create(handler: handler, tokens: AuthTokens.fromJson(tokens('A1', 'R1')));
      return (OpsRepository(h.read(apiClientProvider), cache: cache), h.adapter);
    }

    test('a simple step carries its key as Idempotency-Key and the time pressed as acted_at (UTC)', () async {
      final (r, adapter) = await repo((_) => ok({}));
      await r.jobStep(
        'd1',
        'pickup',
        idempotencyKey: 'key-0000000000000001',
        actedAt: DateTime.utc(2026, 11, 19, 9, 5),
      );
      final req = adapter.where('POST', '/admin/deliveries/d1/pickup').single;
      expect(req.header('Idempotency-Key'), 'key-0000000000000001');
      expect((req.body as Map)['acted_at'], '2026-11-19T09:05:00.000Z');
    });

    test('an old-style step (no outbox) sends neither', () async {
      final (r, adapter) = await repo((_) => ok({}));
      await r.jobStep('d1', 'accept');
      final req = adapter.requests.single;
      expect(req.header('Idempotency-Key'), isNull);
      expect((req.body as Map).containsKey('acted_at'), isFalse);
    });

    test('delivered, could not deliver and the assembly answer carry acted_at in their forms', () async {
      final (r, adapter) = await repo((_) => ok({}));
      final photo = File('${tmp.path}/p.jpg')..writeAsBytesSync([1, 2, 3]);
      final at = DateTime.utc(2026, 11, 19, 9, 5);
      await r.deliver('d1', proof: photo, codAmount: 10, idempotencyKey: 'key-0000000000000002', actedAt: at);
      await r.fail('d1', 'customer_absent', photo: photo, idempotencyKey: 'key-0000000000000003', actedAt: at);
      await r.assembly('d1', 'done', idempotencyKey: 'key-0000000000000004', actedAt: at);
      final forms = [
        for (final path in ['deliver', 'fail'])
          adapter.where('POST', '/admin/deliveries/d1/$path').single.body as FormData,
      ];
      for (final form in forms) {
        expect(form.fields.where((f) => f.key == 'acted_at').single.value, '2026-11-19T09:05:00.000Z');
      }
      expect(
        (adapter.where('POST', '/admin/deliveries/d1/assembly').single.body as Map)['acted_at'],
        '2026-11-19T09:05:00.000Z',
      );
      expect(
        adapter.where('POST', '/admin/deliveries/d1/assembly').single.header('Idempotency-Key'),
        'key-0000000000000004',
      );
    });
  });

  group('the last good lists on the phone', () {
    Future<(OpsRepository, FakeAdapter, Map<String, DateTime?>)> cached(
      FakeHandler handler,
      DateTime Function() now,
    ) async {
      final marks = <String, DateTime?>{};
      final h = await TestHarness.create(handler: handler, tokens: AuthTokens.fromJson(tokens('A1', 'R1')));
      final cache = OpsReadCache(h.read(sharedPreferencesProvider), now: now, onStale: (k, at) => marks[k] = at);
      return (OpsRepository(h.read(apiClientProvider), cache: cache), h.adapter, marks);
    }

    Map<String, Object?> row(String id, {bool redacted = false}) => {
      'id': id,
      'status': 'out_for_delivery',
      'order_number': 'AB$id',
      'customer_name': 'Noura',
      'address': {'zone_number': '24'},
      'payment_method': 'cod',
      'cod_amount': '10.00',
      'redacted': redacted,
    };

    test('without signal the driver\'s jobs come from the phone, with the time they were read', () async {
      final readAt = DateTime.utc(2026, 11, 19, 11, 5);
      final (r, adapter, marks) = await cached((_) => ok([row('d1')]), () => readAt);
      expect((await r.jobs()).single.id, 'd1');
      expect(marks['driver.jobs.active'], isNull);

      adapter.failConnection = (_) => true;
      final shown = await r.jobs();

      expect(shown.single.id, 'd1');
      expect(shown.single.customerName, 'Noura');
      expect(marks['driver.jobs.active'], readAt.toLocal());
    });

    test('a finished job older than a day (the server already took the door and phone away) is not kept', () async {
      final (r, adapter, _) = await cached((_) => ok([row('h1'), row('h2', redacted: true)]), DateTime.now);
      await r.jobs(history: true);
      adapter.failConnection = (_) => true;

      expect((await r.jobs(history: true)).map((j) => j.id), ['h1']);
    });

    test('a refusal is never covered up by the kept list, and nothing kept means the error as before', () async {
      var refuse = false;
      final (r, adapter, _) = await cached((_) => refuse ? error(403, 'forbidden') : ok([row('d1')]), DateTime.now);
      await expectLater(r.jobs(history: true), completion(isNotEmpty));
      refuse = true;
      await expectLater(r.jobs(history: true), throwsA(isA<ApiException>()));

      adapter.failConnection = (_) => true;
      await expectLater(r.overview(), throwsA(isA<ApiException>())); // never read: nothing to show
    });

    test('the branch tablet keeps its counts and lanes, and signing out forgets them', () async {
      final (r, adapter, marks) = await cached(
        routes({
          'GET /admin/dispatch/overview': (_) => ok({
            'branch': {'id': 'b1', 'name': 'Al Markhiya'},
            'new': 3,
          }),
          'GET /admin/dispatch/queue': (_) => const FakeReply(200, {
            'data': [
              {'fulfillment_id': 'f1', 'number': 'AB1', 'status': 'pending'},
            ],
            'meta': {'has_more': false},
          }),
        }),
        DateTime.now,
      );
      await r.overview(branchId: 'b1');
      await r.queuePage('new', branchId: 'b1');
      adapter.failConnection = (_) => true;

      expect((await r.overview(branchId: 'b1')).fresh, 3);
      expect((await r.queuePage('new', branchId: 'b1')).rows.single.fulfillmentId, 'f1');
      expect(
        staleSince({
          for (final e in marks.entries)
            if (e.value != null) e.key: e.value!,
        }, 'staff.'),
        isNotNull,
      );

      await r.cache!.clear();
      await expectLater(r.overview(branchId: 'b1'), throwsA(isA<ApiException>()));
    });
  });
}
