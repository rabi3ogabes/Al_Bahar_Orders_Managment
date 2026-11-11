import 'dart:async';

import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

/// Re-runs [onTick] every [every] while mounted (the apps poll; there is no push in these builds).
mixin OpsPolling<T extends StatefulWidget> on State<T> {
  Timer? _timer;

  void startPolling(VoidCallback onTick, {Duration every = const Duration(seconds: 20)}) {
    _timer?.cancel();
    _timer = Timer.periodic(every, (_) {
      if (mounted) onTick();
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }
}

Future<void> dial(String? phone) async {
  if (phone == null || phone.isEmpty) return;
  await launchUrl(Uri(scheme: 'tel', path: phone));
}

/// Opens the phone's maps app on the coordinates, or on the address text when there are none.
Future<void> openMaps({double? lat, double? lng, required String query}) async {
  final uri = lat != null && lng != null
      ? Uri.parse('https://www.google.com/maps/dir/?api=1&destination=$lat,$lng')
      : Uri.https('www.google.com', '/maps/search/', {'api': '1', 'query': query});
  await launchUrl(uri, mode: LaunchMode.externalApplication);
}

/// Runs an action with a spinner state and an error snack; returns true on success.
Future<bool> guarded(BuildContext context, Future<void> Function() action) async {
  try {
    await action();
    return true;
  } catch (e) {
    if (context.mounted) showErrorSnack(context, e);
    return false;
  }
}

StatusTone toneForOpsStatus(String s) => switch (s) {
  'delivered' => StatusTone.success,
  'failed' || 'cancelled' || 'returned' => StatusTone.danger,
  'out_for_delivery' || 'picked_up' => StatusTone.info,
  'pending' => StatusTone.warning,
  _ => StatusTone.accent,
};

/// Small count tile used on the staff overview.
class CountTile extends StatelessWidget {
  const CountTile({super.key, required this.label, required this.value, this.highlight = false, this.onTap});
  final String label;
  final int value;
  final bool highlight;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final primary = Theme.of(context).colorScheme.primary;
    return Expanded(
      child: Material(
        color: highlight ? primary : Theme.of(context).cardColor,
        borderRadius: AppRadius.inputAll,
        child: InkWell(
          borderRadius: AppRadius.inputAll,
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 8),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  '$value',
                  style: Theme.of(context).textTheme.headlineSmall
                      ?.copyWith(color: highlight ? Colors.white : primary, fontWeight: FontWeight.w700),
                ),
                const SizedBox(height: 2),
                Text(
                  label,
                  textAlign: TextAlign.center,
                  maxLines: 2,
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(color: highlight ? Colors.white70 : null),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
