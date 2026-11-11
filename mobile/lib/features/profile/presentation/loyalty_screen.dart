import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/profile/presentation/state/profile_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:share_plus/share_plus.dart';

class LoyaltyScreen extends ConsumerWidget {
  const LoyaltyScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final loyalty = ref.watch(loyaltyProvider);
    final referral = ref.watch(referralProvider);
    final locale = context.localeCode;

    return Scaffold(
      appBar: AppBar(title: Text(l10n.loyaltyAndReferral)),
      body: RefreshIndicator(
        onRefresh: () async {
          ref.invalidate(referralProvider);
          ref.invalidate(loyaltyProvider);
          await ref.read(loyaltyProvider.future);
        },
        child: ListView(
          padding: const EdgeInsets.all(AppSpace.gutter),
          children: [
            ContentWidth(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  loyalty.when(
                    loading: () => const Skeleton(height: 160, radius: AppRadius.lg),
                    error: (e, _) =>
                        ErrorState(error: e, compact: true, onRetry: () => ref.invalidate(loyaltyProvider)),
                    data: (l) => Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Container(
                          padding: const EdgeInsets.all(AppSpace.lg),
                          decoration: const BoxDecoration(
                            borderRadius: AppRadius.lgAll,
                            gradient: LinearGradient(colors: [AppPalette.navy, AppPalette.navySoft]),
                          ),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              if (l.tier != null)
                                Text(
                                  l10n.tierLabel(l.tier!).toUpperCase(),
                                  style: context.text.labelSmall?.copyWith(color: AppPalette.brass, letterSpacing: 2),
                                ),
                              const Gap(AppSpace.sm),
                              TweenAnimationBuilder<double>(
                                tween: Tween(begin: 0, end: l.balance.toDouble()),
                                duration: const Duration(milliseconds: 900),
                                curve: Curves.easeOutCubic,
                                builder: (_, v, _) => Text(
                                  l10n.pointsUnit(v.round()),
                                  style: context.text.displaySmall?.copyWith(color: Colors.white),
                                ),
                              ),
                              if (l.value != null)
                                Text(
                                  l10n.pointsWorth(l.value!.formatted),
                                  style: context.text.bodyMedium?.copyWith(color: Colors.white70),
                                ),
                              const Gap(AppSpace.xs),
                              Text(
                                l10n.lifetimeEarned(l.lifetimeEarned),
                                style: context.text.bodySmall?.copyWith(color: Colors.white60),
                              ),
                            ],
                          ),
                        ),
                        const Gap(AppSpace.lg),
                        Text(l10n.loyaltyHistory, style: context.text.titleLarge),
                        if (l.transactions.isEmpty)
                          Padding(
                            padding: const EdgeInsets.all(AppSpace.md),
                            child: Text(l10n.noLoyaltyHistory, style: context.text.bodySmall),
                          )
                        else
                          for (final t in l.transactions)
                            ListTile(
                              contentPadding: EdgeInsets.zero,
                              leading: Icon(
                                t.points >= 0 ? Icons.add_circle_outline_rounded : Icons.remove_circle_outline_rounded,
                                color: t.points >= 0 ? context.palette.success : context.palette.danger,
                              ),
                              title: Text(t.reason ?? t.type),
                              subtitle: Text(
                                [
                                  QatarTime.date(t.createdAt, locale),
                                  if (t.expiresAt != null) l10n.pointsExpire(QatarTime.date(t.expiresAt, locale)),
                                ].join(' · '),
                              ),
                              trailing: Text(
                                '${t.points >= 0 ? '+' : ''}${t.points}',
                                textDirection: TextDirection.ltr,
                                style: context.text.titleSmall?.copyWith(
                                  color: t.points >= 0 ? context.palette.success : context.palette.danger,
                                ),
                              ),
                            ),
                      ],
                    ),
                  ),
                  const Gap(AppSpace.xl),
                  referral.when(
                    loading: () => const Skeleton(height: 140, radius: AppRadius.card),
                    error: (e, _) =>
                        ErrorState(error: e, compact: true, onRetry: () => ref.invalidate(referralProvider)),
                    data: (r) => SurfaceCard(
                      color: context.palette.brassTint,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Text(l10n.referralTitle, style: context.text.headlineSmall),
                          const Gap(AppSpace.xxs),
                          Text(l10n.referralBody, style: context.text.bodyMedium),
                          const Gap(AppSpace.md),
                          Text(l10n.yourCode, style: context.text.labelMedium),
                          Row(
                            children: [
                              Expanded(
                                child: SelectableText(
                                  r.code,
                                  style: context.text.headlineMedium?.copyWith(letterSpacing: 3),
                                  textDirection: TextDirection.ltr,
                                ),
                              ),
                              IconButton(
                                tooltip: l10n.copy,
                                icon: const Icon(Icons.copy_rounded),
                                onPressed: () async {
                                  await Clipboard.setData(ClipboardData(text: r.code));
                                  Haptics.tap();
                                  if (context.mounted) showInfoSnack(context, l10n.copied);
                                },
                              ),
                            ],
                          ),
                          const Gap(AppSpace.sm),
                          Row(
                            children: [
                              Expanded(
                                child: _Stat(label: l10n.referredCount, value: r.referredCount),
                              ),
                              Expanded(
                                child: _Stat(label: l10n.rewardedCount, value: r.rewardedCount),
                              ),
                            ],
                          ),
                          const Gap(AppSpace.md),
                          AppButton(
                            label: l10n.shareReferral,
                            icon: Icons.ios_share_rounded,
                            onPressed: () => SharePlus.instance.share(
                              ShareParams(text: l10n.referralShareText(r.code, r.shareUrl ?? 'https://albahar.qa')),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Stat extends StatelessWidget {
  const _Stat({required this.label, required this.value});
  final String label;
  final int value;

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Text('$value', style: context.text.headlineSmall),
      Text(label, style: context.text.bodySmall),
    ],
  );
}
