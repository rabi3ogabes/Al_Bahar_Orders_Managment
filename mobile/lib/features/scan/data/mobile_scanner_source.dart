import 'dart:async';

import 'package:albahar_customer/features/scan/data/scan_source.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

/// [ScanSource] on top of the `mobile_scanner` plugin (CameraX + ML Kit on Android, AVFoundation + Vision on iOS).
///
/// Only the formats a shop actually prints are read: retail barcodes (EAN-13/8, UPC-A/E), the labels the dashboard prints
/// (Code 128) and QR codes (store and app links). Reading fewer formats is faster and avoids false reads.
///
/// How the camera is brought up (each rule exists because a real phone showed what happens without it):
/// - The controller is created with `autoStart: false`. The `MobileScanner` preview widget would otherwise start it by itself
///   while [start] starts it too, and a second start while the first is running throws `controllerInitializing`.
/// - [start] runs once the preview is in the tree (the screen calls it after its first frame). Should the controller still say
///   it is not attached, one more frame is given and the start is tried once more.
/// - Nothing a start throws is swallowed: any error becomes [ScanCameraState.unavailable] (or [ScanCameraState.permissionDenied]
///   when the camera permission is the reason), is kept in [lastError] and is written to the debug log.
/// - A start that neither succeeds nor fails within [startTimeout] is reported as unavailable too, so "starting" never lasts forever.
/// - Starting again after a failure builds a fresh controller (and releases the old one): a camera that failed halfway is not trusted.
class MobileScannerSource implements ScanSource {
  MobileScannerSource({
    MobileScannerController Function()? createController,
    this.startTimeout = const Duration(seconds: 12),
    Future<void> Function()? nextFrame,
  }) : _create = createController ?? newController,
       _nextFrame = nextFrame ?? _endOfFrame {
    _preview = ValueNotifier<MobileScannerController>(_adopt());
  }

  /// The controller the app uses: the back camera, the formats a shop prints and no automatic start (see the class comment).
  static MobileScannerController newController() => MobileScannerController(
    autoStart: false,
    facing: CameraFacing.back,
    detectionTimeoutMs: 300,
    formats: const [
      BarcodeFormat.ean13,
      BarcodeFormat.ean8,
      BarcodeFormat.upcA,
      BarcodeFormat.upcE,
      BarcodeFormat.code128,
      BarcodeFormat.code39,
      BarcodeFormat.code93,
      BarcodeFormat.itf14,
      BarcodeFormat.qrCode,
    ],
  );

  static Future<void> _endOfFrame() => WidgetsBinding.instance.endOfFrame;

  /// How long a start may take before the camera is reported as unavailable.
  final Duration startTimeout;

  final MobileScannerController Function() _create;
  final Future<void> Function() _nextFrame;

  /// The controller in use. It is replaced after a failure, and the preview widget is rebuilt with the new one (a `MobileScanner`
  /// widget reads its controller only when it is created).
  late final ValueNotifier<MobileScannerController> _preview;
  StreamSubscription<BarcodeCapture>? _barcodes;
  final _codes = StreamController<String>.broadcast();
  final _camera = ValueNotifier<ScanCameraState>(ScanCameraState.starting);
  final _torch = ValueNotifier<ScanTorch>(ScanTorch.unavailable);
  Timer? _watchdog;
  Object? _failure;
  int _attempt = 0;
  bool _starting = false;
  bool _disposed = false;

  MobileScannerController get _controller => _preview.value;

  /// Why the camera is not working (the last error, or null while it works or has not failed).
  Object? get lastError => _failure ?? _controller.value.error;

  @override
  Stream<String> get codes => _codes.stream;

  @override
  ValueListenable<ScanCameraState> get camera => _camera;

  @override
  ValueListenable<ScanTorch> get torch => _torch;

  /// Makes [controller] the one this source listens to (its state and its barcodes).
  MobileScannerController _adopt() {
    final controller = _create();
    controller.addListener(_publish);
    _barcodes = controller.barcodes.listen(
      _onCapture,
      // A frame the reader could not make sense of: the next one will do.
      onError: (Object _) {},
    );
    return controller;
  }

  void _onCapture(BarcodeCapture capture) {
    for (final barcode in capture.barcodes) {
      final text = barcode.rawValue ?? barcode.displayValue;
      if (text != null && text.trim().isNotEmpty) {
        _codes.add(text);
        return; // one code per frame: the first the camera read
      }
    }
  }

  /// Works out what the screen shows from what the controller says and from what this class saw fail (a thrown error, the watchdog).
  void _publish() {
    if (_disposed) return;
    final v = _controller.value;
    final ScanCameraState state;
    if (v.error == null && v.isRunning) {
      // A camera that is live is not failing, whatever the watchdog said a moment ago (a slow start that made it after all).
      _failure = null;
      state = ScanCameraState.ready;
    } else if (v.error ?? _failure case final problem?) {
      state = _isPermissionDenied(problem) ? ScanCameraState.permissionDenied : ScanCameraState.unavailable;
    } else {
      state = ScanCameraState.starting;
    }
    _camera.value = state;
    _torch.value = switch (v.torchState) {
      TorchState.on => ScanTorch.on,
      TorchState.off => ScanTorch.off,
      _ => ScanTorch.unavailable,
    };
  }

  static bool _isPermissionDenied(Object error) =>
      error is MobileScannerException && error.errorCode == MobileScannerErrorCode.permissionDenied;

  @override
  Future<void> start() async {
    if (_disposed) return;
    // One start at a time: the one that is running reports how it went. After a failure there is nothing worth waiting for.
    if (_starting && _failure == null) return;
    final attempt = ++_attempt;
    _starting = true;
    try {
      await _open(attempt);
    } finally {
      if (attempt == _attempt) _starting = false;
    }
  }

  Future<void> _open(int attempt) async {
    // A camera that failed to open is not trusted again: it is released and a fresh one is built.
    if (_failure != null || _controller.value.error != null) _replaceController();
    _failure = null;
    final controller = _controller;
    _publish();
    _armWatchdog();
    try {
      await _startOnce(controller);
    } on Object catch (error) {
      if (attempt == _attempt) _fail(error);
      return;
    }
    // A newer start, or a disposed source, has taken over: this one has nothing left to report.
    if (_disposed || attempt != _attempt) return;
    if (controller.value.error case final error?) _log('The camera did not open', error);
    _publish();
    if (_camera.value != ScanCameraState.starting) _disarmWatchdog();
  }

  Future<void> _startOnce(MobileScannerController controller) async {
    try {
      await controller.start();
    } on MobileScannerException catch (error) {
      if (error.errorCode != MobileScannerErrorCode.controllerNotAttached) rethrow;
      // The preview widget is not in the tree yet: it is built by the next frame, so give it that frame and try once more.
      await _nextFrame();
      if (_disposed || !identical(controller, _controller)) return;
      await controller.start();
    }
  }

  /// Swaps in a fresh controller and releases the old one in the background (the platform serialises the release before the new start).
  void _replaceController() {
    final old = _controller;
    old.removeListener(_publish);
    unawaited(_barcodes?.cancel());
    _preview.value = _adopt();
    unawaited(_release(old));
  }

  Future<void> _release(MobileScannerController controller) async {
    try {
      await controller.dispose();
    } on Object catch (error) {
      _log('The old camera could not be released', error);
    }
  }

  void _armWatchdog() {
    _watchdog?.cancel();
    _watchdog = Timer(startTimeout, () {
      _watchdog = null;
      if (_disposed || _camera.value != ScanCameraState.starting) return;
      _fail(TimeoutException('The camera did not open in time', startTimeout));
    });
  }

  void _disarmWatchdog() {
    _watchdog?.cancel();
    _watchdog = null;
  }

  void _fail(Object error) {
    if (_disposed) return;
    _disarmWatchdog();
    _failure = error;
    _log('The camera could not be opened', error);
    _publish();
  }

  /// Camera problems only: no names, numbers or anything else about the shopper is in them.
  void _log(String what, Object error) => debugPrint('[scan] $what: ${_describe(error)}');

  static String _describe(Object error) =>
      error is MobileScannerException ? error.toString() : '${error.runtimeType}: $error';

  @override
  Future<void> stop() async {
    if (_disposed) return;
    try {
      await _controller.stop();
    } on Object catch (error) {
      _log('The camera could not be stopped', error);
    }
  }

  @override
  Future<void> toggleTorch() async {
    if (_disposed) return;
    try {
      await _controller.toggleTorch();
    } on Object catch (error) {
      // No torch on this camera, or the camera is not running.
      _log('The light could not be switched', error);
    }
  }

  @override
  Future<void> openSettings() async {
    try {
      await Geolocator.openAppSettings();
    } on Object catch (error) {
      // Nothing to open on this platform.
      _log('The settings could not be opened', error);
    }
  }

  @override
  Widget buildPreview(BuildContext context) => ValueListenableBuilder<MobileScannerController>(
    valueListenable: _preview,
    builder: (context, controller, _) => MobileScanner(
      // A new controller is a new preview: the widget takes its controller once, when it is created.
      key: ObjectKey(controller),
      controller: controller,
      // The screen draws its own explanations; the preview itself stays plain black until the camera is live.
      errorBuilder: (context, error) => const ColoredBox(color: Colors.black),
      placeholderBuilder: (context) => const ColoredBox(color: Colors.black),
    ),
  );

  @override
  Future<void> dispose() async {
    if (_disposed) return;
    _disposed = true;
    _disarmWatchdog();
    final controller = _controller;
    controller.removeListener(_publish);
    // The camera goes back first: whatever else is slow to close, the phone's camera is handed back at once.
    final released = controller.dispose();
    // Neither needs waiting for: a subscription is cancelled and a stream closed the moment they are asked to.
    unawaited(_barcodes?.cancel());
    unawaited(_codes.close());
    _camera.dispose();
    _torch.dispose();
    _preview.dispose();
    await released;
  }
}
