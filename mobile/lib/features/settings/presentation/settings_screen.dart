import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:package_info_plus/package_info_plus.dart';

/// Short commit of the build (set by the release workflow with --dart-define=BUILD_SHA).
const _buildSha = String.fromEnvironment('BUILD_SHA');

final packageInfoProvider = FutureProvider<PackageInfo?>((ref) async {
  try {
    return await PackageInfo.fromPlatform();
  } catch (_) {
    return null;
  }
});

class SettingsScreen extends ConsumerWidget {
  const SettingsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final locale = ref.watch(localeCodeProvider);
    final themeMode = ref.watch(themeModeProvider);
    final signedIn = ref.watch(isAuthenticatedProvider);
    final info = ref.watch(packageInfoProvider).value;

    Widget header(String t) => Padding(
      padding: const EdgeInsetsDirectional.fromSTEB(
        AppSpace.md,
        AppSpace.lg,
        AppSpace.md,
        AppSpace.xs,
      ),
      child: Semantics(
        header: true,
        child: Text(
          t.toUpperCase(),
          style: context.text.labelSmall?.copyWith(letterSpacing: 1.4),
        ),
      ),
    );
    Widget page(IconData icon, String title, String slug) => ListTile(
      leading: Icon(icon),
      title: Text(title),
      trailing: const Icon(Icons.chevron_right_rounded),
      onTap: () => context.push('/pages/$slug'),
    );

    return Scaffold(
      appBar: AppBar(title: Text(l10n.settingsTitle)),
      body: ListView(
        children: [
          ContentWidth(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                header(l10n.language),
                RadioGroup<String>(
                  groupValue: locale,
                  onChanged: (code) async {
                    if (code == null) return;
                    Haptics.tap();
                    await ref
                        .read(localeControllerProvider.notifier)
                        .setLocale(Locale(code));
                    if (ref.read(isAuthenticatedProvider)) {
                      unawaited(
                        ref
                            .read(sessionControllerProvider.notifier)
                            .updateProfile({'locale': code})
                            .then((_) {}, onError: (Object _) {}),
                      );
                    }
                  },
                  child: const Column(
                    children: [
                      RadioListTile<String>(
                        value: 'ar',
                        title: Text('العربية'),
                      ),
                      RadioListTile<String>(
                        value: 'en',
                        title: Text('English'),
                      ),
                    ],
                  ),
                ),
                header(l10n.appearance),
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: AppSpace.md),
                  child: SegmentedButton<ThemeMode>(
                    segments: [
                      ButtonSegment(
                        value: ThemeMode.system,
                        label: Text(l10n.themeSystem),
                        icon: const Icon(Icons.brightness_auto_outlined),
                      ),
                      ButtonSegment(
                        value: ThemeMode.light,
                        label: Text(l10n.themeLight),
                        icon: const Icon(Icons.light_mode_outlined),
                      ),
                      ButtonSegment(
                        value: ThemeMode.dark,
                        label: Text(l10n.themeDark),
                        icon: const Icon(Icons.dark_mode_outlined),
                      ),
                    ],
                    selected: {themeMode},
                    onSelectionChanged: (s) =>
                        ref.read(themeModeProvider.notifier).set(s.first),
                  ),
                ),
                if (signedIn) ...[
                  header(l10n.privacy),
                  ListTile(
                    leading: const Icon(Icons.notifications_none_rounded),
                    title: Text(l10n.notificationPreferences),
                    trailing: const Icon(Icons.chevron_right_rounded),
                    onTap: () => context.push('/preferences/notifications'),
                  ),
                  ListTile(
                    leading: Icon(
                      Icons.delete_forever_outlined,
                      color: context.palette.danger,
                    ),
                    title: Text(
                      l10n.deleteAccount,
                      style: TextStyle(color: context.palette.danger),
                    ),
                    trailing: const Icon(Icons.chevron_right_rounded),
                    onTap: () => context.push('/settings/delete-account'),
                  ),
                ],
                header(l10n.legal),
                page(Icons.info_outline_rounded, l10n.aboutUs, 'about'),
                page(Icons.description_outlined, l10n.termsOfService, 'terms'),
                page(Icons.privacy_tip_outlined, l10n.privacyPolicy, 'privacy'),
                page(
                  Icons.assignment_return_outlined,
                  l10n.returnsPolicy,
                  'returns',
                ),
                page(Icons.gavel_outlined, l10n.auctionTerms, 'auction-terms'),
                page(Icons.quiz_outlined, l10n.faq, 'faq'),
                page(Icons.call_outlined, l10n.contactUs, 'contact'),
                if (signedIn) ...[
                  const Gap(AppSpace.lg),
                  ListTile(
                    leading: const Icon(Icons.logout_rounded),
                    title: Text(l10n.signOut),
                    onTap: () async {
                      final ok = await confirmAction(
                        context,
                        title: l10n.signOut,
                        message: l10n.logoutConfirm,
                        confirmLabel: l10n.signOut,
                      );
                      if (!ok) return;
                      await ref
                          .read(sessionControllerProvider.notifier)
                          .logout();
                      if (context.mounted) context.go('/home');
                    },
                  ),
                  ListTile(
                    leading: const Icon(Icons.devices_other_outlined),
                    title: Text(l10n.logoutAllDevices),
                    onTap: () async {
                      final ok = await confirmAction(
                        context,
                        title: l10n.logoutAllDevices,
                        confirmLabel: l10n.signOut,
                        destructive: true,
                      );
                      if (!ok) return;
                      await ref
                          .read(sessionControllerProvider.notifier)
                          .logout(allDevices: true);
                      if (context.mounted) context.go('/home');
                    },
                  ),
                ],
                const Gap(AppSpace.lg),
                Center(
                  child: Text(
                    '${l10n.appFullName} · ${l10n.appVersion(info == null ? '—' : '${info.version} (${info.buildNumber})')}'
                    '${_buildSha.isEmpty ? '' : ' · $_buildSha'}',
                    style: context.text.bodySmall,
                    textAlign: TextAlign.center,
                  ),
                ),
                const Gap(AppSpace.xl),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
