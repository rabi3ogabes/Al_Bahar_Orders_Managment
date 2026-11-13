import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/profile/domain/profile_models.dart';
import 'package:albahar_customer/features/profile/presentation/state/profile_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:share_plus/share_plus.dart';

/// Invite friends: the customer's code, the system share sheet, what each side earns and how invited friends are doing.
class InviteFriendsScreen extends ConsumerWidget {
  const InviteFriendsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final referral = ref.watch(referralProvider);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.referralTitle)),
      body: RefreshIndicator(
        onRefresh: () async {
          ref.invalidate(referralProvider);
          await ref.read(referralProvider.future);
        },
        child: ListView(
          padding: const EdgeInsets.all(AppSpace.gutter),
          children: [
            ContentWidth(
              child: referral.when(
                loading: () => const Skeleton(height: 240, radius: AppRadius.card),
                error: (e, _) => ErrorState(error: e, compact: true, onRetry: () => ref.invalidate(referralProvider)),
                data: (r) => r.enabled ? InviteBody(info: r) : const SizedBox.shrink(),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class InviteBody extends StatelessWidget {
  const InviteBody({super.key, required this.info});

  final ReferralInfo info;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final r = info;
    final steps = [l10n.inviteStep1, l10n.inviteStep2, l10n.inviteStep3];
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SurfaceCard(
          color: context.palette.brassTint,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(l10n.yourCode, style: context.text.labelMedium),
              Row(
                children: [
                  Expanded(
                    child: Semantics(
                      label: l10n.inviteCodeSemantics(r.code),
                      child: ExcludeSemantics(
                        child: SelectableText(
                          r.code,
                          style: context.text.displaySmall?.copyWith(letterSpacing: 4),
                          textDirection: TextDirection.ltr,
                        ),
                      ),
                    ),
                  ),
                  IconButton(
                    tooltip: l10n.copy,
                    constraints: const BoxConstraints(minWidth: 48, minHeight: 48),
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
              AppButton(
                label: l10n.shareReferral,
                icon: Icons.ios_share_rounded,
                onPressed: () => SharePlus.instance.share(
                  ShareParams(text: r.shareMessage ?? l10n.referralShareText(r.code, r.shareUrl ?? '')),
                ),
              ),
            ],
          ),
        ),
        const Gap(AppSpace.md),
        SurfaceCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (r.referrerRewardText != null) ...[
                Text(l10n.inviteYourReward, style: context.text.labelMedium),
                Text(r.referrerRewardText!, style: context.text.bodyLarge),
              ],
              if (r.friendRewardText != null) ...[
                const Gap(AppSpace.sm),
                Text(l10n.inviteFriendReward, style: context.text.labelMedium),
                Text(r.friendRewardText!, style: context.text.bodyMedium),
              ],
              const Gap(AppSpace.md),
              Row(
                children: [
                  Expanded(
                    child: _Stat(label: l10n.referredCount, value: '${r.referredCount}'),
                  ),
                  Expanded(
                    child: _Stat(label: l10n.inviteWaiting, value: '${r.pendingCount}'),
                  ),
                  Expanded(
                    child: _Stat(label: l10n.inviteEarned, value: '${r.rewardedCount}'),
                  ),
                ],
              ),
            ],
          ),
        ),
        const Gap(AppSpace.md),
        Text(l10n.inviteHowTitle, style: context.text.titleMedium),
        const Gap(AppSpace.xs),
        for (var i = 0; i < steps.length; i++)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: AppSpace.xxs),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                CircleAvatar(radius: 14, child: Text('${i + 1}', style: context.text.labelMedium)),
                const Gap(AppSpace.sm),
                Expanded(child: Text(steps[i], style: context.text.bodyMedium)),
              ],
            ),
          ),
        const Gap(AppSpace.md),
        Text(l10n.inviteFriendsTitle, style: context.text.titleMedium),
        const Gap(AppSpace.xs),
        if (r.friends.isEmpty)
          Text(l10n.inviteNoFriends, style: context.text.bodyMedium)
        else
          for (final f in r.friends)
            ListTile(
              contentPadding: EdgeInsets.zero,
              minTileHeight: 48,
              leading: const CircleAvatar(child: Icon(Icons.person_outline_rounded)),
              title: Text(f.name),
              trailing: StatusChip(
                label: switch (f.status) {
                  'rewarded' => l10n.inviteStatusRewarded,
                  'pending' => l10n.inviteStatusPending,
                  _ => l10n.inviteStatusClosed,
                },
                tone: switch (f.status) {
                  'rewarded' => StatusTone.success,
                  'pending' => StatusTone.warning,
                  _ => StatusTone.neutral,
                },
              ),
            ),
      ],
    );
  }
}

class _Stat extends StatelessWidget {
  const _Stat({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Text(value, style: context.text.headlineSmall),
      Text(label, style: context.text.bodySmall),
    ],
  );
}
