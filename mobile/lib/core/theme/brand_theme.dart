import 'dart:async';
import 'dart:convert';

import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/router/footer_config.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// The look chosen in the dashboard (Appearance): colours, artwork and a seasonal banner.
@immutable
class BrandTheme {
  const BrandTheme({
    required this.key,
    required this.kind,
    required this.primary,
    required this.primarySoft,
    required this.accent,
    required this.background,
    required this.surface,
    required this.ink,
    this.heroUrl,
    this.bannerUrl,
    this.logoUrl,
    this.splashUrl,
    this.bannerTitle,
    this.bannerSubtitle,
    this.footer,
  });

  /// Qatar maroon: what the app shows before the server answers and when it is offline.
  static const fallback = BrandTheme(
    key: 'default',
    kind: 'base',
    primary: Color(0xFF8A1538),
    primarySoft: Color(0xFFA3294E),
    accent: Color(0xFFB3254F),
    background: Color(0xFFFCF9FA),
    surface: Color(0xFFFFFFFF),
    ink: Color(0xFF2A1A20),
  );

  static Color? _hex(Object? v) {
    final s = v is String ? v.trim() : '';
    if (!RegExp(r'^#[0-9a-fA-F]{6}$').hasMatch(s)) return null;
    return Color(int.parse('FF${s.substring(1)}', radix: 16));
  }

  /// Returns null when the payload has no usable colours (the app then keeps its current look).
  static BrandTheme? fromJson(Object? raw) {
    if (raw is! Map) return null;
    final j = asMap(raw);
    final c = asMap(j['colors']);
    final primary = _hex(c['primary']);
    final soft = _hex(c['primary_soft']);
    final accent = _hex(c['accent']);
    final bg = _hex(c['background']);
    final surface = _hex(c['surface']);
    final ink = _hex(c['ink']);
    if (primary == null || soft == null || accent == null || bg == null || surface == null || ink == null) return null;
    final img = asMap(j['images']);
    final banner = asMap(j['banner']);
    return BrandTheme(
      key: asString(j['key'], 'default'),
      kind: asString(j['kind'], 'base'),
      primary: primary,
      primarySoft: soft,
      accent: accent,
      background: bg,
      surface: surface,
      ink: ink,
      heroUrl: asStringOrNull(img['hero']),
      bannerUrl: asStringOrNull(img['banner']),
      logoUrl: asStringOrNull(img['logo']),
      splashUrl: asStringOrNull(img['splash']),
      bannerTitle: asStringOrNull(banner['title']),
      bannerSubtitle: asStringOrNull(banner['subtitle']),
      footer: FooterConfig.fromJson(j['footer']),
    );
  }

  final String key;
  final String kind;
  final Color primary;
  final Color primarySoft;
  final Color accent;
  final Color background;
  final Color surface;
  final Color ink;
  final String? heroUrl;
  final String? bannerUrl;
  final String? logoUrl;
  final String? splashUrl;
  final String? bannerTitle;
  final String? bannerSubtitle;

  /// The bottom menu chosen in the dashboard; null means the built-in five-tab footer.
  final FooterConfig? footer;

  bool get isEvent => kind != 'base';

  @override
  bool operator ==(Object other) =>
      other is BrandTheme &&
      other.key == key &&
      other.primary == primary &&
      other.primarySoft == primarySoft &&
      other.accent == accent &&
      other.background == background &&
      other.surface == surface &&
      other.ink == ink &&
      other.heroUrl == heroUrl &&
      other.bannerTitle == bannerTitle &&
      other.logoUrl == logoUrl &&
      other.footer == footer;

  @override
  int get hashCode =>
      Object.hash(key, primary, primarySoft, accent, background, surface, ink, heroUrl, bannerTitle, logoUrl, footer);
}

const _kBrandCache = 'brand_theme_payload';

/// Loads the cached look at start (no flash), then asks the server; refreshes whenever the app comes back to the
/// foreground and on pull-to-refresh on Home.
class BrandThemeController extends Notifier<BrandTheme> {
  @override
  BrandTheme build() {
    final cached = _readCache(ref.read(sharedPreferencesProvider));
    unawaited(Future.microtask(refresh));
    return cached ?? BrandTheme.fallback;
  }

  BrandTheme? _readCache(SharedPreferences prefs) {
    try {
      final raw = prefs.getString(_kBrandCache);
      return raw == null ? null : BrandTheme.fromJson(jsonDecode(raw));
    } catch (_) {
      return null;
    }
  }

  Future<void> refresh() async {
    try {
      final api = ref.read(apiClientProvider);
      final data = await api.get('/theme');
      final next = BrandTheme.fromJson(data);
      if (next == null || !ref.mounted) return;
      await ref.read(sharedPreferencesProvider).setString(_kBrandCache, jsonEncode(data));
      if (next != state) state = next;
    } catch (_) {
      // Offline or server hiccup: keep the look we have.
    }
  }
}

final brandThemeProvider = NotifierProvider<BrandThemeController, BrandTheme>(BrandThemeController.new);
