import 'dart:convert';
import 'dart:io';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fakes.dart';

void main() {
  test('both locales are supported and load', () async {
    expect(AppLocalizations.supportedLocales.map((l) => l.languageCode), containsAll(['ar', 'en']));
    final ar = await AppLocalizations.delegate.load(const Locale('ar'));
    final en = await AppLocalizations.delegate.load(const Locale('en'));
    expect(ar.navHome, 'الرئيسية');
    expect(en.navHome, 'Home');
    expect(ar.itemsCount(2), 'قطعتان');
    expect(en.itemsCount(2), '2 items');
    expect(ar.errorRateLimited(30), contains('30'));
  });

  test('ARB files have identical keys and no empty Arabic strings', () {
    Map<String, dynamic> load(String f) => jsonDecode(File('lib/l10n/$f').readAsStringSync()) as Map<String, dynamic>;
    final en = load('app_en.arb')..removeWhere((k, _) => k.startsWith('@'));
    final ar = load('app_ar.arb')..removeWhere((k, _) => k.startsWith('@'));
    expect(ar.keys.toSet(), en.keys.toSet());
    final empty = ar.entries.where((e) => (e.value as String).trim().isEmpty).map((e) => e.key);
    expect(empty, isEmpty);
    // Arabic strings should actually be Arabic (allowing brand/latin exceptions).
    final latinOnly = ar.entries
        .where((e) => !RegExp(r'[؀-ۿ]').hasMatch(e.value as String))
        .map((e) => e.key)
        .toSet()
        .difference({'@@locale', 'languageEnglish', 'phoneHint', 'paymentMethodLabel', 'appVersion'});
    expect(latinOnly, isEmpty, reason: 'untranslated: $latinOnly');
  });

  testWidgets('Arabic is RTL, English is LTR', (t) async {
    late TextDirection arDir;
    late TextDirection enDir;
    await t.pumpWidget(
      wrapApp(
        Builder(
          builder: (c) {
            arDir = Directionality.of(c);
            return Text(c.l10n.cartTitle);
          },
        ),
        locale: const Locale('ar'),
      ),
    );
    expect(arDir, TextDirection.rtl);
    expect(find.text('سلّتك'), findsOneWidget);

    await t.pumpWidget(
      wrapApp(
        Builder(
          builder: (c) {
            enDir = Directionality.of(c);
            return Text(c.l10n.cartTitle);
          },
        ),
      ),
    );
    expect(enDir, TextDirection.ltr);
    expect(find.text('Your cart'), findsOneWidget);
  });

  testWidgets('directional padding and alignment flip in RTL', (t) async {
    const key = Key('box');
    Widget box() => const Align(
      alignment: AlignmentDirectional.centerStart,
      child: Padding(
        padding: EdgeInsetsDirectional.only(start: 24),
        child: SizedBox(key: key, width: 10, height: 10),
      ),
    );
    await t.pumpWidget(wrapApp(box(), locale: const Locale('ar')));
    final width = t.getSize(find.byType(Scaffold)).width;
    expect(t.getTopRight(find.byKey(key)).dx, width - 24);
    await t.pumpWidget(wrapApp(box()));
    expect(t.getTopLeft(find.byKey(key)).dx, 24);
  });

  test('typography uses Arabic font for ar and Playfair/Inter for en', () {
    final ar = AppTheme.light(const Locale('ar')).textTheme;
    final en = AppTheme.light(const Locale('en')).textTheme;
    expect(ar.headlineLarge!.fontFamily, AppFonts.arabic);
    expect(ar.bodyMedium!.fontFamily, AppFonts.arabic);
    expect(en.headlineLarge!.fontFamily, AppFonts.display);
    expect(en.bodyMedium!.fontFamily, AppFonts.body);
    expect(en.bodyMedium!.fontFamilyFallback, contains(AppFonts.arabic));
    expect(AppTheme.dark(const Locale('en')).brightness, Brightness.dark);
  });
}
