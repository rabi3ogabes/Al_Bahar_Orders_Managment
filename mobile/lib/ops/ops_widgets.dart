import 'dart:async';

import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:albahar_customer/ops/ops_session.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart';
import 'package:url_launcher/url_launcher.dart';

/// The camera / gallery picker (replaced in tests).
final opsImagePickerProvider = Provider<ImagePicker>((ref) => ImagePicker());

/// Re-runs [onTick] every [every] while the screen is open and the app is in the foreground, and once when the app
/// comes back to the foreground (the apps poll as a safety net; real-time pings do the rest).
mixin OpsPolling<T extends StatefulWidget> on State<T> {
  Timer? _timer;
  AppLifecycleListener? _lifecycle;
  bool _foreground = true;

  void startPolling(VoidCallback onTick, {Duration every = const Duration(seconds: 20)}) {
    _timer?.cancel();
    _lifecycle?.dispose();
    _lifecycle = AppLifecycleListener(
      onStateChange: (state) {
        final was = _foreground;
        _foreground = state == AppLifecycleState.resumed;
        if (_foreground && !was && mounted) onTick();
      },
    );
    _timer = Timer.periodic(every, (_) {
      if (mounted && _foreground) onTick();
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    _lifecycle?.dispose();
    super.dispose();
  }
}

/// Opens the phone dialler; tells the user when this phone cannot.
Future<void> dial(BuildContext context, String? phone) async {
  if (phone == null || phone.isEmpty) return;
  await _launch(context, Uri(scheme: 'tel', path: phone), LaunchMode.platformDefault);
}

/// Opens the phone's maps app on the coordinates, or on the address text when there are none.
Future<void> openMaps(BuildContext context, {double? lat, double? lng, required String query}) async {
  final uri = lat != null && lng != null
      ? Uri.https('www.google.com', '/maps/dir/', {'api': '1', 'destination': '$lat,$lng'})
      : Uri.https('www.google.com', '/maps/search/', {'api': '1', 'query': query});
  await _launch(context, uri, LaunchMode.externalApplication);
}

Future<void> _launch(BuildContext context, Uri uri, LaunchMode mode) async {
  var ok = false;
  try {
    ok = await launchUrl(uri, mode: mode);
  } catch (_) {}
  if (!ok && context.mounted) showInfoSnack(context, OpsStrings.of(context).couldNotOpen);
}

/// Runs an action and shows the localised error when it fails; returns true on success.
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
  'pending' || 'rescheduled' => StatusTone.warning,
  _ => StatusTone.accent,
};

/// Asks, then (for a driver) goes off duty and signs out. Going off duty is best effort: offline must not trap the user.
Future<void> confirmSignOut(BuildContext context, WidgetRef ref) async {
  final t = OpsStrings.of(context);
  final driver = ref.read(opsRoleProvider) == OpsRole.driver;
  final ok = await showDialog<bool>(
    context: context,
    builder: (c) => AlertDialog(
      title: Text(driver ? t.signOutDriverQ : t.signOutQ),
      content: driver ? Text(t.signOutDriverBody) : null,
      actions: [
        TextButton(autofocus: true, onPressed: () => Navigator.pop(c, false), child: Text(t.cancel)),
        TextButton(onPressed: () => Navigator.pop(c, true), child: Text(t.signOut)),
      ],
    ),
  );
  if (ok != true) return;
  if (driver) {
    try {
      await ref.read(opsRepositoryProvider).setDuty(false);
    } catch (_) {}
  }
  await ref.read(opsSessionProvider.notifier).signOut();
}

/// Language and sign-out, in one menu at the end of the app bar.
class OpsAccountMenu extends ConsumerWidget {
  const OpsAccountMenu({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = OpsStrings.of(context);
    return PopupMenuButton<String>(
      tooltip: t.menu,
      icon: const Icon(Icons.more_vert_rounded),
      onSelected: (v) {
        if (v == 'language') {
          unawaited(ref.read(localeControllerProvider.notifier).setLocale(Locale(t.ar ? 'en' : 'ar')));
        } else {
          unawaited(confirmSignOut(context, ref));
        }
      },
      itemBuilder: (_) => [
        PopupMenuItem(
          value: 'language',
          child: ListTile(leading: const Icon(Icons.translate_rounded), title: Text(t.language)),
        ),
        PopupMenuItem(
          value: 'signout',
          child: ListTile(leading: const Icon(Icons.logout_rounded), title: Text(t.signOut)),
        ),
      ],
    );
  }
}

/// "Could not refresh" strip shown above a list that keeps its last good data while the network is down.
class StaleBanner extends StatelessWidget {
  const StaleBanner({super.key, required this.onRetry});
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final scheme = Theme.of(context).colorScheme;
    return Material(
      color: scheme.secondaryContainer,
      child: Padding(
        padding: const EdgeInsetsDirectional.only(start: AppSpace.md, end: AppSpace.xs),
        child: Row(
          children: [
            Icon(Icons.cloud_off_rounded, size: 18, color: scheme.onSecondaryContainer),
            const Gap(AppSpace.xs),
            Expanded(
              child: Text(
                t.couldNotRefresh,
                style: Theme.of(context).textTheme.bodySmall?.copyWith(color: scheme.onSecondaryContainer),
              ),
            ),
            TextButton(onPressed: onRetry, child: Text(t.retry)),
          ],
        ),
      ),
    );
  }
}

/// A pull-to-refresh list of an async list value: it keeps the last good rows (with a [StaleBanner]) when a refresh
/// fails, shows a retry state when there is nothing to show, and stays pullable when empty.
class OpsAsyncList<T> extends StatelessWidget {
  const OpsAsyncList({
    super.key,
    required this.value,
    required this.onRefresh,
    required this.onRetry,
    required this.itemBuilder,
    required this.emptyIcon,
    required this.emptyTitle,
    this.emptyMessage = '',
  });

  final AsyncValue<List<T>> value;
  final Future<void> Function() onRefresh;
  final VoidCallback onRetry;
  final Widget Function(BuildContext context, T item) itemBuilder;
  final IconData emptyIcon;
  final String emptyTitle;
  final String emptyMessage;

  @override
  Widget build(BuildContext context) {
    final stale = value.hasError && value.hasValue;
    return Column(
      children: [
        if (stale) StaleBanner(onRetry: onRetry),
        Expanded(
          child: RefreshIndicator(
            onRefresh: onRefresh,
            child: value.when(
              skipLoadingOnRefresh: true,
              skipError: true,
              loading: () => const Center(child: CircularProgressIndicator()),
              error: (e, _) => ErrorState(error: e, onRetry: onRetry),
              data: (list) => list.isEmpty
                  ? ListView(
                      physics: const AlwaysScrollableScrollPhysics(),
                      children: [
                        const Gap(AppSpace.xxl),
                        EmptyState(icon: emptyIcon, title: emptyTitle, message: emptyMessage, compact: true),
                      ],
                    )
                  : ListView.separated(
                      physics: const AlwaysScrollableScrollPhysics(),
                      padding: const EdgeInsets.all(AppSpace.gutter),
                      itemCount: list.length,
                      separatorBuilder: (_, _) => const Gap(AppSpace.sm),
                      itemBuilder: (c, i) => itemBuilder(c, list[i]),
                    ),
            ),
          ),
        ),
      ],
    );
  }
}

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
    final onPrimary = Theme.of(context).colorScheme.onPrimary;
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
                      ?.copyWith(color: highlight ? onPrimary : primary, fontWeight: FontWeight.w700),
                ),
                const SizedBox(height: 2),
                Text(
                  label,
                  textAlign: TextAlign.center,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(color: highlight ? onPrimary : null),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
