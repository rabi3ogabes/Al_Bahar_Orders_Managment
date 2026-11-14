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
class MobileScannerSource implements ScanSource {
  MobileScannerSource({MobileScannerController? controller})
    : _controller =
          controller ??
          MobileScannerController(
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
          ) {
    _controller.addListener(_onControllerChange);
    _barcodes = _controller.barcodes.listen(_onCapture, onError: (_) {});
  }

  final MobileScannerController _controller;
  late final StreamSubscription<BarcodeCapture> _barcodes;
  final _codes = StreamController<String>.broadcast();
  final _camera = ValueNotifier<ScanCameraState>(ScanCameraState.starting);
  final _torch = ValueNotifier<ScanTorch>(ScanTorch.unavailable);
  bool _disposed = false;

  @override
  Stream<String> get codes => _codes.stream;

  @override
  ValueListenable<ScanCameraState> get camera => _camera;

  @override
  ValueListenable<ScanTorch> get torch => _torch;

  void _onCapture(BarcodeCapture capture) {
    for (final barcode in capture.barcodes) {
      final text = barcode.rawValue ?? barcode.displayValue;
      if (text != null && text.trim().isNotEmpty) {
        _codes.add(text);
        return; // one code per frame: the first the camera read
      }
    }
  }

  void _onControllerChange() {
    if (_disposed) return;
    final v = _controller.value;
    _camera.value = switch (v) {
      MobileScannerState(error: final MobileScannerException e)
          when e.errorCode == MobileScannerErrorCode.permissionDenied =>
        ScanCameraState.permissionDenied,
      MobileScannerState(error: != null) => ScanCameraState.unavailable,
      MobileScannerState(isInitialized: true) => ScanCameraState.ready,
      _ => ScanCameraState.starting,
    };
    _torch.value = switch (v.torchState) {
      TorchState.on => ScanTorch.on,
      TorchState.off => ScanTorch.off,
      _ => ScanTorch.unavailable,
    };
  }

  @override
  Future<void> start() async {
    if (_disposed) return;
    try {
      await _controller.start();
    } on Object {
      // The controller reports failures through its value (permission denied, no camera); a start that overlaps another is harmless.
    }
  }

  @override
  Future<void> stop() async {
    if (_disposed) return;
    try {
      await _controller.stop();
    } on Object {
      // Already stopped.
    }
  }

  @override
  Future<void> toggleTorch() async {
    if (_disposed) return;
    try {
      await _controller.toggleTorch();
    } on Object {
      // No torch on this camera.
    }
  }

  @override
  Future<void> openSettings() async {
    try {
      await Geolocator.openAppSettings();
    } on Object {
      // Nothing to open on this platform.
    }
  }

  @override
  Widget buildPreview(BuildContext context) => MobileScanner(
    controller: _controller,
    // The screen draws its own explanations; the preview itself stays plain black until the camera is live.
    errorBuilder: (context, error) => const ColoredBox(color: Colors.black),
    placeholderBuilder: (context) => const ColoredBox(color: Colors.black),
  );

  @override
  Future<void> dispose() async {
    if (_disposed) return;
    _disposed = true;
    _controller.removeListener(_onControllerChange);
    await _barcodes.cancel();
    await _codes.close();
    _camera.dispose();
    _torch.dispose();
    await _controller.dispose();
  }
}
