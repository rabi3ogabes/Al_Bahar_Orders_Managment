import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

/// Body of the five tab roots. The footer menu itself is drawn once for the whole app by [FooterScope], so it also shows on
/// every other page (product, search, checkout, orders…).
class AppShell extends StatelessWidget {
  const AppShell({super.key, required this.shell});

  final StatefulNavigationShell shell;

  @override
  Widget build(BuildContext context) => Scaffold(
    body: Column(
      children: [
        const OfflineBanner(),
        Expanded(child: shell),
      ],
    ),
  );
}

class NotFoundScreen extends StatelessWidget {
  const NotFoundScreen({super.key});

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(),
    body: EmptyState(
      icon: Icons.explore_off_outlined,
      title: context.l10n.notFoundTitle,
      message: context.l10n.errorNotFound,
      actionLabel: context.l10n.navHome,
      onAction: () => context.go('/home'),
    ),
  );
}
