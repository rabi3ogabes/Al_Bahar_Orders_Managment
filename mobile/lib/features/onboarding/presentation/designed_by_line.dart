import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/features/public_config/presentation/state/public_config_state.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// The quiet "Designed by ..." line at the foot of the intro. The words come from the dashboard (in the customer's language,
/// or the other language when that one is empty); with nothing to show the line is simply not there. The line has a fixed
/// slot, so it appearing a moment after the page never moves the button above it.
class DesignedByLine extends ConsumerWidget {
  const DesignedByLine({super.key});

  /// Room kept for one line and a little air below it.
  static const double slotHeight = 28;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final line = ref.watch(publicConfigProvider).designedBy(ref.watch(localeCodeProvider));
    final reduce = MediaQuery.disableAnimationsOf(context);
    return ConstrainedBox(
      constraints: const BoxConstraints(minHeight: slotHeight),
      child: Center(
        child: AnimatedSwitcher(
          duration: reduce ? Duration.zero : AppDurations.slow,
          child: line == null
              ? const SizedBox.shrink(key: ValueKey('none'))
              : Padding(
                  key: ValueKey(line),
                  padding: const EdgeInsets.symmetric(horizontal: AppSpace.lg),
                  child: Text(
                    line,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    textAlign: TextAlign.center,
                    style: context.text.labelMedium?.copyWith(
                      color: context.palette.muted,
                      fontWeight: FontWeight.w400,
                      letterSpacing: 0.4,
                    ),
                  ),
                ),
        ),
      ),
    );
  }
}
