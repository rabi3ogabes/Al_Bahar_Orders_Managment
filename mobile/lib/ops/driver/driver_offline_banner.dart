import 'dart:async';

import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/ops/driver/driver_providers.dart';
import 'package:albahar_customer/ops/driver/driver_strings.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// "No connection. Check your signal." across the top of the driver's home while the phone has no network, with a Try again button that reads
/// the lists once more (and clears the banner when that works). The lists below keep their last good rows; nothing is queued for later.
class DriverOfflineBanner extends ConsumerStatefulWidget {
  const DriverOfflineBanner({super.key});

  @override
  ConsumerState<DriverOfflineBanner> createState() => _DriverOfflineBannerState();
}

class _DriverOfflineBannerState extends ConsumerState<DriverOfflineBanner> {
  bool _busy = false;

  Future<void> _retry() async {
    if (_busy) return;
    setState(() => _busy = true);
    ref
      ..invalidate(driverJobsProvider(false))
      ..invalidate(driverJobsProvider(true))
      ..invalidate(driverMeProvider);
    try {
      await ref.read(driverJobsProvider(false).future);
      if (mounted) ref.read(onlineProvider.notifier).set(true);
    } catch (_) {
      // Still no signal: the banner stays.
    }
    if (mounted) setState(() => _busy = false);
  }

  @override
  Widget build(BuildContext context) {
    if (ref.watch(onlineProvider)) return const SizedBox(width: double.infinity);
    final t = OpsStrings.of(context);
    final scheme = Theme.of(context).colorScheme;
    return Semantics(
      liveRegion: true,
      child: Material(
        key: const ValueKey('offline-banner'),
        color: scheme.errorContainer,
        child: Padding(
          padding: const EdgeInsetsDirectional.only(start: AppSpace.md, end: AppSpace.xs),
          child: Row(
            children: [
              Icon(Icons.signal_wifi_connected_no_internet_4_rounded, size: 20, color: scheme.onErrorContainer),
              const Gap(AppSpace.xs),
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.symmetric(vertical: AppSpace.xs),
                  child: Text(
                    t.noConnection,
                    style: Theme.of(context).textTheme.bodyMedium
                        ?.copyWith(color: scheme.onErrorContainer, fontWeight: FontWeight.w600),
                  ),
                ),
              ),
              TextButton(
                key: const ValueKey('offline-retry'),
                onPressed: _busy ? null : () => unawaited(_retry()),
                child: _busy
                    ? SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2, color: scheme.onErrorContainer),
                      )
                    : Text(t.retry),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
