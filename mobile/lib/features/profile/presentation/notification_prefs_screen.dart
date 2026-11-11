import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/profile/domain/profile_models.dart';
import 'package:albahar_customer/features/profile/presentation/state/profile_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Channel × category matrix from `GET /me/notification-preferences`.
class NotificationPrefsScreen extends ConsumerWidget {
  const NotificationPrefsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final async = ref.watch(notificationPrefsProvider);
    String channel(String c) => switch (c) {
      'push' => l10n.channelPush,
      'sms' => l10n.channelSms,
      'whatsapp' => l10n.channelWhatsapp,
      'email' => l10n.channelEmail,
      _ => c,
    };
    (String, String) category(String c) => switch (c) {
      'transactional' => (l10n.categoryTransactional, l10n.categoryTransactionalDesc),
      'marketing' => (l10n.categoryMarketing, l10n.categoryMarketingDesc),
      'auction' => (l10n.categoryAuction, l10n.categoryAuctionDesc),
      _ => (c, ''),
    };

    return Scaffold(
      appBar: AppBar(title: Text(l10n.notificationPreferences)),
      body: async.when(
        loading: () => const ListSkeleton(leading: false),
        error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(notificationPrefsProvider)),
        data: (prefs) {
          final categories = <String>{for (final p in prefs) p.category}.toList();
          return ListView(
            padding: const EdgeInsets.all(AppSpace.gutter),
            children: [
              for (final cat in categories)
                ContentWidth(
                  child: Padding(
                    padding: const EdgeInsets.only(bottom: AppSpace.md),
                    child: SurfaceCard(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Text(category(cat).$1, style: context.text.titleMedium),
                          if (category(cat).$2.isNotEmpty) Text(category(cat).$2, style: context.text.bodySmall),
                          const Gap(AppSpace.xs),
                          for (final NotificationPreference p in prefs.where((p) => p.category == cat))
                            SwitchListTile(
                              contentPadding: EdgeInsets.zero,
                              value: p.isEnabled,
                              title: Text(channel(p.channel)),
                              onChanged: (v) async {
                                try {
                                  await ref.read(notificationPrefsProvider.notifier).toggle(p, v);
                                } catch (e) {
                                  if (context.mounted) showErrorSnack(context, e);
                                }
                              },
                            ),
                        ],
                      ),
                    ),
                  ),
                ),
              Text(l10n.transactionalNote, style: context.text.bodySmall, textAlign: TextAlign.center),
            ],
          );
        },
      ),
    );
  }
}
