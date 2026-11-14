import 'package:albahar_customer/features/scan/data/mobile_scanner_source.dart';
import 'package:flutter/foundation.dart' show ValueListenable;
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// What the camera is doing, as far as the scanner screen cares.
enum ScanCameraState {
  /// Asking for permission / opening the camera.
  starting,

  /// The preview is live and barcodes are being read.
  ready,

  /// The person said no (or the system did): explain and offer the manual entry.
  permissionDenied,

  /// No usable camera (none on the device, taken by another app, or it failed to open).
  unavailable,
}

enum ScanTorch { unavailable, off, on }

/// The one place the app touches a camera for scanning. The screen and the controller only know this interface, so tests
/// drive them with a fake and never need a device; [MobileScannerSource] is the real implementation.
abstract class ScanSource {
  /// Every text a barcode / QR code was read as (raw, untrimmed; the same code arrives again and again while it stays in view).
  Stream<String> get codes;

  ValueListenable<ScanCameraState> get camera;
  ValueListenable<ScanTorch> get torch;

  /// Opens the camera (asks for permission the first time). Safe to call again; it never throws.
  Future<void> start();

  /// Releases the camera (screen hidden or app in the background). Safe to call again; it never throws.
  Future<void> stop();

  Future<void> toggleTorch();

  /// The system settings page of this app (where a refused camera permission can be switched on).
  Future<void> openSettings();

  /// The live preview, filling its parent.
  Widget buildPreview(BuildContext context);

  Future<void> dispose();
}

/// A new source per visit to the scanner: the camera is released the moment the screen is left.
final scanSourceProvider = Provider.autoDispose<ScanSource>((ref) {
  final source = MobileScannerSource();
  ref.onDispose(source.dispose);
  return source;
});
