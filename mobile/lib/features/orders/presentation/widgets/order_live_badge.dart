import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/realtime/live_link_badge.dart';
import 'package:albahar_customer/features/orders/presentation/state/order_live.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// "Live / Reconnecting… / Offline": whether the orders on this screen are following the server in real time. Also keeps the orders'
/// live orchestrator (orderLiveProvider) running while the screen is open.
class OrderLiveBadge extends ConsumerWidget {
  const OrderLiveBadge({super.key, this.showLabel = true});

  /// False in a window's title bar, where there is room for the dot only (the words are read aloud and shown as a tooltip).
  final bool showLabel;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final link = ref.watch(orderLiveProvider.select((s) => s.link));
    return LiveLinkBadge(
      link: link,
      showLabel: showLabel,
      labels: LiveLinkLabels(
        live: l10n.realtimeLive,
        reconnecting: l10n.realtimeReconnecting,
        offline: l10n.mapStatusOffline,
      ),
    );
  }
}
