import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/phone.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/features/notifications/presentation/state/notifications_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// Account hub (guest view invites sign-in; settings & legal stay reachable).
class AccountScreen extends ConsumerWidget {
  const AccountScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final session = ref.watch(sessionControllerProvider);
    final user = session.user;
    final unread = ref.watch(unreadCountProvider);
    final branch = ref.watch(preferredBranchProvider);

    Widget tile(IconData icon, String title, String location, {String? subtitle, Widget? trailing}) => ListTile(
      leading: Icon(icon),
      title: Text(title),
      subtitle: subtitle == null ? null : Text(subtitle),
      trailing: trailing ?? const Icon(Icons.chevron_right_rounded),
      onTap: () => context.push(location),
    );

    return Scaffold(
      appBar: AppBar(title: Text(l10n.accountTitle)),
      body: ListView(
        children: [
          ContentWidth(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Padding(
                  padding: const EdgeInsets.all(AppSpace.gutter),
                  child: session.isAuthenticated
                      ? SurfaceCard(
                          onTap: () => context.push('/profile/edit'),
                          child: Row(
                            children: [
                              CircleAvatar(
                                radius: 28,
                                backgroundColor: context.palette.brassTint,
                                child: Text(
                                  (user?.name.isNotEmpty ?? false) ? user!.name.characters.first.toUpperCase() : '•',
                                  style: context.text.headlineSmall?.copyWith(color: context.palette.brass),
                                ),
                              ),
                              const Gap(AppSpace.md),
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(user?.name ?? '', style: context.text.titleLarge),
                                    if (user != null)
                                      Text(
                                        QatarPhone.display(user.phone),
                                        style: context.text.bodySmall,
                                        textDirection: TextDirection.ltr,
                                      ),
                                    if (user?.createdAt != null)
                                      Text(
                                        l10n.memberSince(QatarTime.date(user!.createdAt, context.localeCode)),
                                        style: context.text.bodySmall,
                                      ),
                                  ],
                                ),
                              ),
                              const Icon(Icons.edit_outlined, size: 20),
                            ],
                          ),
                        )
                      : SurfaceCard(
                          color: context.palette.brassTint,
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              Text(l10n.guestAccountTitle, style: context.text.headlineSmall),
                              const Gap(AppSpace.xs),
                              Text(l10n.guestAccountMessage, style: context.text.bodyMedium),
                              const Gap(AppSpace.md),
                              AppButton(label: l10n.signIn, onPressed: () => context.push('/login?from=%2Faccount')),
                            ],
                          ),
                        ),
                ),
                if (session.isAuthenticated) ...[
                  tile(Icons.receipt_long_outlined, l10n.myOrders, '/orders'),
                  tile(Icons.favorite_border_rounded, l10n.myWishlist, '/wishlist'),
                  tile(Icons.gavel_rounded, l10n.myAuctions, '/my-auctions'),
                  tile(
                    Icons.notifications_none_rounded,
                    l10n.notificationsTitle,
                    '/notifications',
                    trailing: unread > 0
                        ? Badge.count(count: unread, backgroundColor: context.palette.brass)
                        : const Icon(Icons.chevron_right_rounded),
                  ),
                  tile(Icons.location_on_outlined, l10n.myAddresses, '/addresses'),
                  tile(Icons.workspace_premium_outlined, l10n.loyaltyAndReferral, '/loyalty'),
                  tile(Icons.support_agent_outlined, l10n.support, '/support'),
                  Divider(color: context.palette.line),
                ],
                tile(
                  Icons.storefront_outlined,
                  l10n.preferredBranch,
                  '/branches?pick=1',
                  subtitle: branch?.name ?? l10n.chooseBranch,
                ),
                tile(Icons.settings_outlined, l10n.settings, '/settings'),
                const Gap(AppSpace.xl),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
