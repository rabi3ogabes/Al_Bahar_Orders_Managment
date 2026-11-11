import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/phone.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/features/notifications/presentation/state/notifications_controller.dart';
import 'package:albahar_customer/features/profile/presentation/state/profile_providers.dart';
import 'package:albahar_customer/features/wishlist/presentation/state/wishlist_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// Account hub — soft-card design: navy header with the avatar, an overlapping stats card and grouped settings rows.
/// Guests see an invitation to sign in; settings and legal pages stay reachable.
class AccountScreen extends ConsumerWidget {
  const AccountScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final session = ref.watch(sessionControllerProvider);
    final user = session.user;
    final unread = ref.watch(unreadCountProvider);
    final branch = ref.watch(preferredBranchProvider);
    final signedIn = session.isAuthenticated;

    Widget row(IconData icon, String title, String location, {String? subtitle, Widget? trailing}) => _SettingsRow(
      icon: icon,
      title: title,
      subtitle: subtitle,
      trailing: trailing,
      onTap: () => context.push(location),
    );

    final rows = <Widget>[
      if (signedIn) ...[
        row(Icons.receipt_long_outlined, l10n.myOrders, '/orders'),
        row(Icons.favorite_border_rounded, l10n.myWishlist, '/wishlist'),
        row(Icons.gavel_rounded, l10n.myAuctions, '/my-auctions'),
        row(
          Icons.notifications_none_rounded,
          l10n.notificationsTitle,
          '/notifications',
          trailing: unread > 0 ? Badge.count(count: unread, backgroundColor: context.palette.brass) : null,
        ),
        row(Icons.location_on_outlined, l10n.myAddresses, '/addresses'),
        row(Icons.workspace_premium_outlined, l10n.loyaltyAndReferral, '/loyalty'),
        row(Icons.support_agent_outlined, l10n.support, '/support'),
      ],
      row(
        Icons.storefront_outlined,
        l10n.preferredBranch,
        '/branches?pick=1',
        subtitle: branch?.name ?? l10n.chooseBranch,
      ),
      row(Icons.settings_outlined, l10n.settings, '/settings'),
    ];

    return Scaffold(
      body: ListView(
        padding: EdgeInsets.zero,
        children: [
          _Header(signedIn: signedIn, name: user?.name ?? '', phone: user?.phone, since: user?.createdAt),
          ContentWidth(
            child: Padding(
              padding: const EdgeInsetsDirectional.fromSTEB(AppSpace.gutter, 0, AppSpace.gutter, AppSpace.xl),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (signedIn) ...[
                    Transform.translate(offset: const Offset(0, -26), child: const _StatsCard()),
                  ] else
                    const Gap(AppSpace.md),
                  if (!signedIn) ...[
                    SurfaceCard(
                      color: context.palette.brassTint,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Text(l10n.guestAccountTitle, style: context.text.titleLarge),
                          const Gap(AppSpace.xs),
                          Text(l10n.guestAccountMessage, style: context.text.bodyMedium),
                          const Gap(AppSpace.md),
                          AppButton(label: l10n.signIn, onPressed: () => context.push('/login?from=%2Faccount')),
                        ],
                      ),
                    ),
                    const Gap(AppSpace.md),
                  ],
                  Card(
                    child: Padding(
                      padding: const EdgeInsetsDirectional.symmetric(horizontal: AppSpace.md, vertical: AppSpace.xxs),
                      child: Column(children: rows),
                    ),
                  ),
                  if (signedIn) ...[
                    const Gap(AppSpace.md),
                    _SignOutButton(
                      label: l10n.signOut,
                      onPressed: () async {
                        final ok = await confirmAction(
                          context,
                          title: l10n.signOut,
                          message: l10n.logoutConfirm,
                          confirmLabel: l10n.signOut,
                        );
                        if (!ok) return;
                        await ref.read(sessionControllerProvider.notifier).logout();
                        if (context.mounted) context.go('/home');
                      },
                    ),
                  ],
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Navy gradient with soft gold discs; the avatar and name sit on it.
class _Header extends StatelessWidget {
  const _Header({required this.signedIn, required this.name, required this.phone, required this.since});
  final bool signedIn;
  final String name;
  final String? phone;
  final DateTime? since;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final top = MediaQuery.paddingOf(context).top;
    return Container(
      padding: EdgeInsets.fromLTRB(AppSpace.gutter, top + AppSpace.lg, AppSpace.gutter, signedIn ? 56 : AppSpace.xl),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [context.colors.primary, context.palette.primarySoft],
        ),
        borderRadius: const BorderRadiusDirectional.vertical(bottom: Radius.circular(36)),
      ),
      child: ClipRRect(
        child: Stack(
          clipBehavior: Clip.none,
          children: [
            PositionedDirectional(
              end: -60,
              top: -80,
              child: Container(
                width: 200,
                height: 200,
                decoration: BoxDecoration(shape: BoxShape.circle, color: context.palette.brass.withValues(alpha: 0.3)),
              ),
            ),
            Center(
              child: Column(
                children: [
                  if (signedIn) ...[
                    Stack(
                      clipBehavior: Clip.none,
                      children: [
                        Container(
                          width: 88,
                          height: 88,
                          alignment: Alignment.center,
                          decoration: BoxDecoration(
                            color: context.palette.onPrimarySoft,
                            shape: BoxShape.circle,
                            border: Border.all(color: Colors.white.withValues(alpha: 0.9), width: 4),
                          ),
                          child: Text(
                            name.isNotEmpty ? name.characters.first.toUpperCase() : '•',
                            style: context.text.displaySmall?.copyWith(color: context.colors.primary),
                          ),
                        ),
                        PositionedDirectional(
                          end: -2,
                          bottom: -2,
                          child: Material(
                            color: Colors.white,
                            shape: const CircleBorder(),
                            elevation: 2,
                            child: InkWell(
                              customBorder: const CircleBorder(),
                              onTap: () => context.push('/profile/edit'),
                              child: SizedBox(
                                width: 30,
                                height: 30,
                                child: Icon(Icons.edit_outlined, size: 15, color: context.colors.primary),
                              ),
                            ),
                          ),
                        ),
                      ],
                    ),
                    const Gap(AppSpace.sm),
                    Text(name, style: context.text.headlineSmall?.copyWith(color: Colors.white)),
                    if (phone != null)
                      Text(
                        QatarPhone.display(phone!),
                        textDirection: TextDirection.ltr,
                        style: context.text.bodyMedium?.copyWith(color: Colors.white.withValues(alpha: 0.85)),
                      ),
                    if (since != null)
                      Text(
                        l10n.memberSince(QatarTime.date(since!, context.localeCode)),
                        style: context.text.bodySmall?.copyWith(color: Colors.white.withValues(alpha: 0.7)),
                      ),
                  ] else
                    Text(l10n.accountTitle, style: context.text.headlineMedium?.copyWith(color: Colors.white)),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Points balance and saved products in one white card that overlaps the header.
class _StatsCard extends ConsumerWidget {
  const _StatsCard();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final points = ref.watch(loyaltyProvider).value?.balance;
    final saved = ref.watch(wishlistIdsProvider).length;

    Widget stat(String value, String label, {Color? color}) => Expanded(
      child: Column(
        children: [
          Text(value, style: context.text.headlineSmall?.copyWith(color: color)),
          Text(label, style: context.text.bodySmall, textAlign: TextAlign.center),
        ],
      ),
    );

    return Card(
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: AppSpace.md),
        child: IntrinsicHeight(
          child: Row(
            children: [
              stat(points == null ? '—' : '$points', l10n.loyaltyPoints, color: context.palette.brassDeep),
              VerticalDivider(width: 1, color: context.palette.line),
              stat('$saved', l10n.myWishlist),
            ],
          ),
        ),
      ),
    );
  }
}

class _SettingsRow extends StatelessWidget {
  const _SettingsRow({required this.icon, required this.title, required this.onTap, this.subtitle, this.trailing});
  final IconData icon;
  final String title;
  final String? subtitle;
  final Widget? trailing;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => InkWell(
    borderRadius: AppRadius.imageAll,
    onTap: onTap,
    child: Padding(
      padding: const EdgeInsets.symmetric(vertical: 11),
      child: Row(
        children: [
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(color: context.palette.tint, borderRadius: BorderRadius.circular(12)),
            child: Icon(icon, size: 20, color: context.colors.primary),
          ),
          const Gap(AppSpace.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: context.text.titleSmall),
                if (subtitle != null) Text(subtitle!, style: context.text.bodySmall),
              ],
            ),
          ),
          trailing ?? Icon(Icons.chevron_right_rounded, color: context.palette.muted),
        ],
      ),
    ),
  );
}

class _SignOutButton extends StatelessWidget {
  const _SignOutButton({required this.label, required this.onPressed});
  final String label;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) => Material(
    color: AppPalette.dangerTint,
    borderRadius: AppRadius.inputAll,
    child: InkWell(
      borderRadius: AppRadius.inputAll,
      onTap: onPressed,
      child: SizedBox(
        height: 50,
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const Icon(Icons.logout_rounded, size: 18, color: AppPalette.danger),
            const Gap(AppSpace.xs),
            Text(label, style: context.text.labelLarge?.copyWith(color: AppPalette.danger)),
          ],
        ),
      ),
    ),
  );
}
