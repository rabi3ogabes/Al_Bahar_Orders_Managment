import 'dart:async';

import 'package:albahar_customer/features/scan/data/mobile_scanner_source.dart';
import 'package:albahar_customer/features/scan/data/scan_source.dart';
import 'package:fake_async/fake_async.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

/// A camera controller that never touches the phone: the test decides what its start does. It keeps the real controller's
/// behaviour where the source depends on it (a start clears an old error, a running camera ignores a second start, a stop only
/// counts while the camera runs).
class _FakeController extends MobileScannerController {
  _FakeController({this.onStart}) : super(autoStart: false);

  /// What a start does; null makes it open the camera at once.
  final Future<void> Function(_FakeController camera)? onStart;

  /// What the camera "sees": push a capture here as the plugin would.
  final frames = StreamController<BarcodeCapture>.broadcast();
  int starts = 0;
  int stops = 0;
  bool released = false;

  @override
  Stream<BarcodeCapture> get barcodes => frames.stream;

  /// The camera is opening (the platform call is on its way).
  void begin() => value = value.copyWith(isStarting: true);

  /// The camera opened.
  void goLive() => value = value.copyWith(
    isInitialized: true,
    isStarting: false,
    isRunning: true,
    torchState: TorchState.off,
  );

  /// The platform said no, the way the real controller records it (it does not throw for these).
  void failWith(MobileScannerException error) => value = value.copyWith(
    isInitialized: true,
    isStarting: false,
    isRunning: false,
    error: error,
    torchState: TorchState.unavailable,
  );

  @override
  Future<void> start({CameraFacing? cameraDirection, CameraLensType? cameraLensType}) async {
    starts++;
    if (value.isRunning) return;
    final run = onStart;
    if (run == null) {
      goLive();
      return;
    }
    await run(this);
  }

  @override
  Future<void> stop() async {
    if (!value.isRunning) return;
    stops++;
    value = value.copyWith(isRunning: false, torchState: TorchState.off);
  }

  // The real dispose hands the phone's camera back through the plugin; this one only records that it was asked to.
  @override
  // ignore: must_call_super
  Future<void> dispose() async => released = true;
}

const _notAttached = MobileScannerException(errorCode: MobileScannerErrorCode.controllerNotAttached);
const _denied = MobileScannerException(errorCode: MobileScannerErrorCode.permissionDenied);

void main() {
  final logs = <String>[];
  late DebugPrintCallback savedPrint;

  setUp(() {
    logs.clear();
    savedPrint = debugPrint;
    debugPrint = (String? message, {int? wrapWidth}) => logs.add(message ?? '');
  });
  tearDown(() => debugPrint = savedPrint);

  /// A source whose controllers are [cameras], one after the other: the first for the first start, the next for every fresh start
  /// after a failure.
  MobileScannerSource sourceOf(
    List<_FakeController> cameras, {
    Duration timeout = const Duration(seconds: 12),
    Future<void> Function()? nextFrame,
  }) {
    var next = 0;
    return MobileScannerSource(
      createController: () => cameras[next++],
      startTimeout: timeout,
      nextFrame: nextFrame ?? () async {},
    );
  }

  group('MobileScannerSource', () {
    test('the app controller never starts by itself: only this class starts the camera', () {
      expect(MobileScannerSource.newController().autoStart, isFalse);
    });

    test('a start that works: the camera is ready, and it is started once however often it is asked', () {
      fakeAsync((async) {
        final camera = _FakeController();
        final source = sourceOf([camera]);
        expect(source.camera.value, ScanCameraState.starting, reason: 'before the first start');
        unawaited(source.start());
        unawaited(source.start());
        async.flushMicrotasks();
        expect(camera.starts, 1);
        expect(source.camera.value, ScanCameraState.ready);
        expect(source.torch.value, ScanTorch.off);
        expect(source.lastError, isNull);
        expect(logs, isEmpty);
      });
    });

    test('stopping and starting again follows the controller: starting, then ready', () {
      fakeAsync((async) {
        final camera = _FakeController();
        final source = sourceOf([camera]);
        unawaited(source.start());
        async.flushMicrotasks();
        unawaited(source.stop());
        async.flushMicrotasks();
        expect(camera.stops, 1);
        expect(source.camera.value, ScanCameraState.starting);

        unawaited(source.start());
        async.flushMicrotasks();
        expect(camera.starts, 2);
        expect(source.camera.value, ScanCameraState.ready);
      });
    });

    group('every failure is shown and logged, never swallowed', () {
      test('a plugin error thrown by the start: unavailable, the error kept', () {
        fakeAsync((async) {
          const error = MobileScannerException(errorCode: MobileScannerErrorCode.genericError);
          final camera = _FakeController(onStart: (_) async => throw error);
          final source = sourceOf([camera]);
          unawaited(source.start());
          async.flushMicrotasks();
          expect(source.camera.value, ScanCameraState.unavailable);
          expect(source.lastError, same(error));
          expect(logs.single, allOf(contains('[scan]'), contains('genericError')));
        });
      });

      test('an error that is not from the plugin at all: unavailable too', () {
        fakeAsync((async) {
          final camera = _FakeController(onStart: (_) async => throw StateError('no camera service'));
          final source = sourceOf([camera]);
          unawaited(source.start());
          async.flushMicrotasks();
          expect(source.camera.value, ScanCameraState.unavailable);
          expect(source.lastError, isA<StateError>());
          expect(logs.single, allOf(contains('[scan]'), contains('StateError')));
        });
      });

      test('a refused permission, thrown: permission denied', () {
        fakeAsync((async) {
          final camera = _FakeController(onStart: (_) async => throw _denied);
          final source = sourceOf([camera]);
          unawaited(source.start());
          async.flushMicrotasks();
          expect(source.camera.value, ScanCameraState.permissionDenied);
          expect(source.lastError, same(_denied));
        });
      });

      test('a refusal the controller records in its state (it does not throw for these): shown, logged and kept', () {
        fakeAsync((async) {
          final camera = _FakeController(onStart: (c) async => c.failWith(_denied));
          final source = sourceOf([camera]);
          unawaited(source.start());
          async.flushMicrotasks();
          expect(source.camera.value, ScanCameraState.permissionDenied);
          expect(source.torch.value, ScanTorch.unavailable);
          expect(source.lastError, same(_denied));
          expect(logs.single, allOf(contains('[scan]'), contains('permissionDenied')));
        });
      });

      test('another recorded failure (no camera, camera in use): unavailable', () {
        fakeAsync((async) {
          const unsupported = MobileScannerException(errorCode: MobileScannerErrorCode.unsupported);
          final camera = _FakeController(onStart: (c) async => c.failWith(unsupported));
          final source = sourceOf([camera]);
          unawaited(source.start());
          async.flushMicrotasks();
          expect(source.camera.value, ScanCameraState.unavailable);
        });
      });
    });

    group('a preview that is not attached yet', () {
      test('gets one more frame and then one more try', () {
        fakeAsync((async) {
          var frames = 0;
          final camera = _FakeController(
            onStart: (c) async {
              if (c.starts == 1) throw _notAttached;
              c.goLive();
            },
          );
          final source = sourceOf(
            [camera],
            nextFrame: () async {
              frames++;
            },
          );
          unawaited(source.start());
          async.flushMicrotasks();
          expect(frames, 1);
          expect(camera.starts, 2);
          expect(source.camera.value, ScanCameraState.ready);
          expect(logs, isEmpty);
        });
      });

      test('is tried only once more: a second refusal is a failure, not a loop', () {
        fakeAsync((async) {
          var frames = 0;
          final camera = _FakeController(onStart: (_) async => throw _notAttached);
          final source = sourceOf(
            [camera],
            nextFrame: () async {
              frames++;
            },
          );
          unawaited(source.start());
          async.flushMicrotasks();
          expect(frames, 1);
          expect(camera.starts, 2);
          expect(source.camera.value, ScanCameraState.unavailable);
          expect(source.lastError, same(_notAttached));
        });
      });
    });

    group('the start watchdog', () {
      test('a start that never answers becomes unavailable after the time-out, never "starting" for ever', () {
        fakeAsync((async) {
          final camera = _FakeController(
            onStart: (c) async {
              c.begin();
              await Completer<void>().future;
            },
          );
          final source = sourceOf([camera], timeout: const Duration(seconds: 3));
          unawaited(source.start());
          async.flushMicrotasks();
          expect(source.camera.value, ScanCameraState.starting);
          async.elapse(const Duration(seconds: 2));
          expect(source.camera.value, ScanCameraState.starting, reason: 'still within the time');
          async.elapse(const Duration(seconds: 1));
          expect(source.camera.value, ScanCameraState.unavailable);
          expect(source.lastError, isA<TimeoutException>());
          expect(logs.single, allOf(contains('[scan]'), contains('TimeoutException')));
        });
      });

      test('the default time is 12 seconds', () {
        fakeAsync((async) {
          final camera = _FakeController(
            onStart: (c) async {
              c.begin();
              await Completer<void>().future;
            },
          );
          final source = MobileScannerSource(createController: () => camera, nextFrame: () async {});
          unawaited(source.start());
          async.elapse(const Duration(seconds: 11));
          expect(source.camera.value, ScanCameraState.starting);
          async.elapse(const Duration(seconds: 1));
          expect(source.camera.value, ScanCameraState.unavailable);
        });
      });

      test('a camera that was only slow still wins: ready again, the failure forgotten', () {
        fakeAsync((async) {
          final gate = Completer<void>();
          final camera = _FakeController(
            onStart: (c) async {
              c.begin();
              await gate.future;
              c.goLive();
            },
          );
          final source = sourceOf([camera], timeout: const Duration(seconds: 3));
          unawaited(source.start());
          async.elapse(const Duration(seconds: 3));
          expect(source.camera.value, ScanCameraState.unavailable);

          gate.complete();
          async.flushMicrotasks();
          expect(source.camera.value, ScanCameraState.ready);
          expect(source.lastError, isNull);
          expect(async.pendingTimers, isEmpty, reason: 'the watchdog is switched off once the camera is live');
        });
      });

      test('a start that finishes in time switches the watchdog off', () {
        fakeAsync((async) {
          final camera = _FakeController();
          final source = sourceOf([camera], timeout: const Duration(seconds: 3));
          unawaited(source.start());
          async.flushMicrotasks();
          expect(source.camera.value, ScanCameraState.ready);
          expect(async.pendingTimers, isEmpty);
          async.elapse(const Duration(seconds: 30));
          expect(source.camera.value, ScanCameraState.ready, reason: 'a live camera is never failed by an old timer');
          expect(logs, isEmpty);
        });
      });
    });

    group('Try again', () {
      test('after a failure the start builds a fresh camera, releases the old one and listens to the new one', () {
        fakeAsync((async) {
          final broken = _FakeController(onStart: (_) async => throw StateError('boom'));
          final fresh = _FakeController();
          final source = sourceOf([broken, fresh]);
          final seen = <String>[];
          source.codes.listen(seen.add);

          unawaited(source.start());
          async.flushMicrotasks();
          expect(source.camera.value, ScanCameraState.unavailable);
          expect(broken.released, isFalse, reason: 'nothing is released before it is replaced');

          unawaited(source.start());
          async.flushMicrotasks();
          expect(broken.released, isTrue);
          expect(fresh.starts, 1);
          expect(source.camera.value, ScanCameraState.ready);
          expect(source.lastError, isNull);

          fresh.frames.add(const BarcodeCapture(barcodes: [Barcode(rawValue: '6291000000011')]));
          async.flushMicrotasks();
          expect(seen, ['6291000000011'], reason: 'codes come from the new camera');
        });
      });

      test('a refused permission that was switched on in Settings opens on the next start', () {
        fakeAsync((async) {
          final refused = _FakeController(onStart: (c) async => c.failWith(_denied));
          final allowed = _FakeController();
          final source = sourceOf([refused, allowed]);
          unawaited(source.start());
          async.flushMicrotasks();
          expect(source.camera.value, ScanCameraState.permissionDenied);

          unawaited(source.start());
          async.flushMicrotasks();
          expect(refused.released, isTrue);
          expect(source.camera.value, ScanCameraState.ready);
        });
      });

      test('after a time-out it works while the old start is still stuck', () {
        fakeAsync((async) {
          final stuck = _FakeController(
            onStart: (c) async {
              c.begin();
              await Completer<void>().future;
            },
          );
          final fresh = _FakeController();
          final source = sourceOf([stuck, fresh], timeout: const Duration(seconds: 3));
          unawaited(source.start());
          async.elapse(const Duration(seconds: 3));
          expect(source.camera.value, ScanCameraState.unavailable);

          unawaited(source.start());
          async.flushMicrotasks();
          expect(stuck.released, isTrue);
          expect(fresh.starts, 1);
          expect(source.camera.value, ScanCameraState.ready);
        });
      });

      test('a second failure is reported again (it can be tried as often as the shopper likes)', () {
        fakeAsync((async) {
          final cameras = [
            for (var i = 0; i < 3; i++) _FakeController(onStart: (_) async => throw StateError('still no camera')),
          ];
          final source = sourceOf(cameras);
          for (var i = 0; i < 3; i++) {
            unawaited(source.start());
            async.flushMicrotasks();
            expect(source.camera.value, ScanCameraState.unavailable);
          }
          expect(logs, hasLength(3));
          expect([for (final c in cameras) c.starts], [1, 1, 1]);
          expect(cameras.take(2).every((c) => c.released), isTrue);
        });
      });

      test('a live camera is not rebuilt when the app only comes back to the front', () {
        fakeAsync((async) {
          final camera = _FakeController();
          final source = sourceOf([camera]);
          unawaited(source.start());
          async.flushMicrotasks();
          unawaited(source.stop());
          async.flushMicrotasks();
          unawaited(source.start());
          async.flushMicrotasks();
          expect(camera.released, isFalse);
          expect(source.camera.value, ScanCameraState.ready);
        });
      });
    });

    group('letting go', () {
      test('stop asks the controller to stop; dispose releases it, closes the codes and ends the watchdog', () {
        fakeAsync((async) {
          final camera = _FakeController();
          final source = sourceOf([camera]);
          var closed = false;
          source.codes.listen((_) {}, onDone: () => closed = true);
          unawaited(source.start());
          async.flushMicrotasks();

          unawaited(source.dispose());
          async.flushMicrotasks();
          expect(camera.released, isTrue);
          expect(closed, isTrue);
          expect(async.pendingTimers, isEmpty);
        });
      });

      test('a start that finishes after the source was disposed changes nothing and throws nothing', () {
        fakeAsync((async) {
          final gate = Completer<void>();
          final camera = _FakeController(
            onStart: (c) async {
              c.begin();
              await gate.future;
              c.goLive();
            },
          );
          final source = sourceOf([camera], timeout: const Duration(seconds: 3));
          unawaited(source.start());
          async.flushMicrotasks();
          unawaited(source.dispose());
          async.flushMicrotasks();
          expect(camera.released, isTrue);

          gate.complete();
          async.elapse(const Duration(seconds: 30));
          expect(async.pendingTimers, isEmpty, reason: 'the watchdog died with the source');
          expect(logs, isEmpty, reason: 'a disposed source reports nothing');
        });
      });

      test('starting a disposed source does nothing', () {
        fakeAsync((async) {
          final camera = _FakeController();
          final source = sourceOf([camera]);
          unawaited(source.dispose());
          async.flushMicrotasks();
          unawaited(source.start());
          unawaited(source.stop());
          async.flushMicrotasks();
          expect(camera.starts, 0);
          expect(camera.stops, 0);
        });
      });
    });
  });
}
