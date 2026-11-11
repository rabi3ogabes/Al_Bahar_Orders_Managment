import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/core/theme/brand_theme.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  Map<String, Object?> payload({String primary = '#112233'}) => {
    'key': 'national-day',
    'kind': 'event',
    'colors': {
      'primary': primary,
      'primary_soft': '#223344',
      'accent': '#C9A24D',
      'background': '#FFFFFF',
      'surface': '#FFFFFF',
      'ink': '#2A1A20',
    },
    'images': {'hero': 'https://x.test/h.webp', 'banner': null, 'logo': null, 'splash': null},
    'banner': {'title': 'Happy Qatar National Day', 'subtitle': 'Celebrate'},
  };

  test('reads the dashboard payload', () {
    final b = BrandTheme.fromJson(payload())!;
    expect(b.primary, const Color(0xFF112233));
    expect(b.isEvent, isTrue);
    expect(b.bannerTitle, 'Happy Qatar National Day');
    expect(b.heroUrl, 'https://x.test/h.webp');
  });

  test('ignores a payload with a broken colour instead of painting garbage', () {
    expect(BrandTheme.fromJson(payload(primary: 'red')), isNull);
    expect(BrandTheme.fromJson('nope'), isNull);
  });

  test('the light theme follows the brand and dark mode keeps its own palette', () {
    final b = BrandTheme.fromJson(payload())!;
    final light = AppTheme.light(const Locale('en'), brand: b);
    expect(light.colorScheme.primary, b.primary);
    expect(light.scaffoldBackgroundColor, b.background);
    expect(light.extension<AppColors>()!.brass, b.accent);
    expect(AppTheme.light(const Locale('en')).colorScheme.primary, AppPalette.navy);
    expect(AppTheme.dark(const Locale('en')).colorScheme.primary, AppPalette.nightNavy);
  });
}
