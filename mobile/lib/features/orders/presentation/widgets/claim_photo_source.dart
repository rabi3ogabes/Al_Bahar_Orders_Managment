import 'dart:typed_data';

import 'package:albahar_customer/features/orders/domain/claim_models.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart';

/// Where a claim's photos come from: the phone's camera or its gallery. The picker scales and compresses the picture, so a claim never uploads
/// a raw 12-megapixel file. A provider, so a test can hand in canned pictures instead of opening the phone's camera.
class ClaimPhotoSource {
  const ClaimPhotoSource();

  static const _edge = 1600.0;
  static const _quality = 85;

  /// One new picture from the camera; empty when the person backs out.
  Future<List<PickedPhoto>> camera() async {
    final f = await ImagePicker().pickImage(
      source: ImageSource.camera,
      maxWidth: _edge,
      maxHeight: _edge,
      imageQuality: _quality,
    );
    return f == null ? const [] : [await _read(f)];
  }

  /// Up to [limit] pictures from the gallery; empty when the person backs out.
  Future<List<PickedPhoto>> gallery(int limit) async {
    if (limit < 1) return const [];
    final picker = ImagePicker();
    if (limit == 1) {
      final f = await picker.pickImage(
        source: ImageSource.gallery,
        maxWidth: _edge,
        maxHeight: _edge,
        imageQuality: _quality,
      );
      return f == null ? const [] : [await _read(f)];
    }
    final files = await picker.pickMultiImage(maxWidth: _edge, maxHeight: _edge, imageQuality: _quality, limit: limit);
    return [for (final f in files.take(limit)) await _read(f)];
  }

  Future<PickedPhoto> _read(XFile f) async {
    final Uint8List bytes = await f.readAsBytes();
    return PickedPhoto(name: f.name, bytes: bytes, mimeType: f.mimeType ?? _mimeOf(f.name));
  }

  static String? _mimeOf(String name) {
    final ext = name.split('.').last.toLowerCase();
    return switch (ext) {
      'jpg' || 'jpeg' => 'image/jpeg',
      'png' => 'image/png',
      'webp' => 'image/webp',
      _ => null,
    };
  }
}

final claimPhotoSourceProvider = Provider<ClaimPhotoSource>((ref) => const ClaimPhotoSource());
