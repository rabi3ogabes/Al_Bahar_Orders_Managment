import 'package:albahar_customer/core/theme/contrast.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:flutter/material.dart';

/// What the dashboard draws behind every page (`backdrop` in `GET /theme`, see docs/api/PAGE_BACKDROP.md). `skyline` means
/// the built-in vector artwork; which one is the config's `scene`.
enum BackdropStyle { skyline, image, none }

/// How an uploaded picture sits on the page: `bottom` = full width at its natural height standing on the bottom edge,
/// `cover` = fills the page.
enum BackdropFit { bottom, cover }

/// The page background chosen in the dashboard (Appearance > Page background): a quiet picture at low opacity, and
/// optionally the page colour itself.
///
/// Parsing is strict about the allowed values and quiet about everything else: a payload without the block (an older
/// server), or with a value outside the allowed set, gives [off], and then nothing changes anywhere in the app.
@immutable
class PageBackdropConfig {
  const PageBackdropConfig({
    this.style = BackdropStyle.skyline,
    this.scene = defaultScene,
    this.imageUrl,
    this.fit = BackdropFit.bottom,
    this.opacity = defaultOpacity,
    this.color,
    this.tint,
  });

  /// The allowed opacity range and the server default.
  static const double minOpacity = 0.02;
  static const double maxOpacity = 0.5;
  static const double defaultOpacity = 0.08;

  /// A page colour whose contrast with the theme's text is below this is ignored (the text would be unreadable).
  static const double minInkContrast = 3;

  /// The built-in artwork drawn when the server sends no `scene` (an older server) or one this app does not know.
  static const String defaultScene = 'doha';

  /// The built-in artworks the dashboard can choose from, by the key the server sends as `scene`. The five after the Doha
  /// skyline are the ones generated into `qatar_art_paths.dart` (a test keeps the two lists in step).
  static const List<String> scenes = ['doha', 'dhow', 'souq', 'dunes', 'museum', 'arabesque'];

  /// No picture and no page colour: the page looks exactly as it did before the feature existed.
  static const off = PageBackdropConfig(style: BackdropStyle.none);

  static final _hex6 = RegExp(r'^#[0-9a-fA-F]{6}$');

  /// Reads the `backdrop` block; anything missing or invalid means [off].
  factory PageBackdropConfig.fromJson(Object? raw) {
    if (raw is! Map) return off;
    final j = asMap(raw);
    final style = _enum(BackdropStyle.values, j['style']);
    if (style == null) return off;
    // Unlike `style`, an unknown `scene` is not a reason to switch the feature off: a newer server may add artworks, and
    // an older one sends none. Either way the app still draws something sensible, the Doha skyline.
    final scene = _scene(j['scene']);
    final fit = j['fit'] == null ? BackdropFit.bottom : _enum(BackdropFit.values, j['fit']);
    if (fit == null) return off;

    final rawOpacity = j['opacity'];
    final opacity = rawOpacity == null ? defaultOpacity : asDoubleOrNull(rawOpacity);
    // A little slack, so a float that went through JSON (0.5000000001) is not mistaken for a value outside the range.
    if (opacity == null || opacity < minOpacity - 1e-6 || opacity > maxOpacity + 1e-6) return off;

    // A colour is either absent (null) or #RRGGBB; anything else is outside the allowed set.
    if (!_isHexOrAbsent(j['color']) || !_isHexOrAbsent(j['tint'])) return off;

    // `image` without a usable picture address is what the server itself answers as the built-in skyline.
    final url = style == BackdropStyle.image ? _httpsUrl(j['image_url']) : null;
    return PageBackdropConfig(
      style: style == BackdropStyle.image && url == null ? BackdropStyle.skyline : style,
      scene: scene,
      imageUrl: url,
      fit: fit,
      opacity: opacity.clamp(minOpacity, maxOpacity).toDouble(),
      color: _hex(j['color']),
      tint: _hex(j['tint']),
    );
  }

  static T? _enum<T extends Enum>(List<T> values, Object? raw) {
    if (raw is! String) return null;
    final key = raw.trim().toLowerCase();
    return values.where((v) => v.name == key).firstOrNull;
  }

  static String _scene(Object? raw) {
    if (raw is! String) return defaultScene;
    final key = raw.trim().toLowerCase();
    return scenes.contains(key) ? key : defaultScene;
  }

  static bool _isHexOrAbsent(Object? raw) => raw == null || _hex(raw) != null;

  static Color? _hex(Object? raw) {
    final s = raw is String ? raw.trim() : '';
    if (!_hex6.hasMatch(s)) return null;
    return Color(int.parse('FF${s.substring(1)}', radix: 16));
  }

  static String? _httpsUrl(Object? raw) {
    final s = asStringOrNull(raw)?.trim();
    final uri = s == null ? null : Uri.tryParse(s);
    return uri != null && uri.isScheme('https') && uri.host.isNotEmpty ? s : null;
  }

  final BackdropStyle style;

  /// Which built-in artwork [BackdropStyle.skyline] draws: one of [scenes], [defaultScene] when unknown. Kept for the other
  /// styles too, where it is not used.
  final String scene;

  /// Absolute https address of the uploaded picture; only with [BackdropStyle.image].
  final String? imageUrl;
  final BackdropFit fit;

  /// 0.02 to 0.50.
  final double opacity;

  /// The page colour for light mode, or null for the theme's own background.
  final Color? color;

  /// The colour of the built-in skyline, or null for the theme's primary colour.
  final Color? tint;

  /// Anything to draw or recolour. When false the app must look exactly as before.
  bool get isActive => style != BackdropStyle.none || color != null;

  /// A picture (skyline or upload) is drawn behind the pages.
  bool get hasPicture => style != BackdropStyle.none;

  /// The page colour to use under a theme whose text colour is [ink], or null to keep the theme's own background
  /// (nothing chosen, or the chosen colour would make [ink] hard to read).
  Color? pageColorFor(Color ink) {
    final c = color;
    return c != null && Contrast.ratio(ink, c) >= minInkContrast ? c : null;
  }

  @override
  bool operator ==(Object other) =>
      other is PageBackdropConfig &&
      other.style == style &&
      other.scene == scene &&
      other.imageUrl == imageUrl &&
      other.fit == fit &&
      other.opacity == opacity &&
      other.color == color &&
      other.tint == tint;

  @override
  int get hashCode => Object.hash(style, scene, imageUrl, fit, opacity, color, tint);

  @override
  String toString() =>
      'PageBackdropConfig($style, scene: $scene, opacity: $opacity, color: $color, tint: $tint, image: $imageUrl)';
}
