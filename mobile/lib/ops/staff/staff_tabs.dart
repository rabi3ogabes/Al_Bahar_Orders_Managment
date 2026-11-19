import 'dart:math' as math;

import 'package:albahar_customer/core/theme/contrast.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:flutter/material.dart';

/// The tabs of the branch staff home, left to right. Three at most, all visible at once (no sideways scrolling): what the branch
/// has to do, what is on the road, and the drivers (list and map in one).
enum StaffTab { todo, way, drivers }

/// The tabs one account gets: the two order tabs always, the Drivers tab only with `deliveries.view`.
List<StaffTab> staffTabsFor({required bool canSeeDrivers}) => [
  StaffTab.todo,
  StaffTab.way,
  if (canSeeDrivers) StaffTab.drivers,
];

/// How loudly a tab's number speaks: quiet (nothing needs a person), attention (something new waits), danger (something failed).
enum StaffBadgeTone { quiet, attention, danger }

/// The tone of each tab's number from the branch counts: new orders light up To do, a failed delivery turns On the way red.
StaffBadgeTone staffTabTone(StaffTab tab, StaffOverview? o) => switch (tab) {
  StaffTab.todo => o != null && o.fresh + o.pickupNewVisible > 0 ? StaffBadgeTone.attention : StaffBadgeTone.quiet,
  StaffTab.way => o != null && o.failed > 0 ? StaffBadgeTone.danger : StaffBadgeTone.quiet,
  StaffTab.drivers => StaffBadgeTone.quiet,
};

/// The number on a tab (null while the counts are not read yet).
int? staffTabCount(StaffTab tab, StaffOverview? o) {
  if (o == null) return null;
  return switch (tab) {
    StaffTab.todo => o.fresh + o.preparing + o.ready + o.pickupVisible,
    StaffTab.way => o.out + o.failed,
    StaffTab.drivers => o.driversOnDuty,
  };
}

/// The tab bar for [tabs], or null when there is nothing to choose between: one group needs no bar. The tabs are never scrollable;
/// each takes an equal third of the width, with its icon, its number and its word, and the word shrinks rather than being cut.
PreferredSizeWidget? staffTabBar(BuildContext context, List<StaffTab> tabs, StaffOverview? o) {
  if (tabs.length < 2) return null;
  final t = OpsStrings.of(context);
  // The tab is as tall as its icon row plus one line of its word at the reader's text size.
  final scaler = MediaQuery.textScalerOf(context);
  final height = (10 + 24 + 2 + scaler.scale(_labelSize) * 1.25 + 8).ceilToDouble();
  Tab tab(StaffTab which) {
    final count = staffTabCount(which, o);
    final (icon, label, speech) = switch (which) {
      StaffTab.todo => (
        Icons.checklist_rounded,
        t.tabToDo,
        t.tabToDoLabel(count ?? 0, (o?.fresh ?? 0) + (o?.pickupNewVisible ?? 0)),
      ),
      StaffTab.way => (Icons.local_shipping_outlined, t.stageOut, t.tabWayLabel(count ?? 0, o?.failed ?? 0)),
      StaffTab.drivers => (Icons.two_wheeler_rounded, t.drivers, t.tabDriversLabel(count ?? 0)),
    };
    return Tab(
      height: height,
      child: Semantics(
        label: o == null ? label : speech,
        excludeSemantics: true,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(icon),
                if (count != null) ...[
                  const SizedBox(width: 4),
                  StaffCountBadge(
                    key: ValueKey('staff-tab-badge-${which.name}'),
                    count: count,
                    tone: staffTabTone(which, o),
                  ),
                ],
              ],
            ),
            const SizedBox(height: 2),
            FittedBox(
              fit: BoxFit.scaleDown,
              child: Text(label, maxLines: 1, style: const TextStyle(fontSize: _labelSize)),
            ),
          ],
        ),
      ),
    );
  }

  return TabBar(
    isScrollable: false,
    labelPadding: const EdgeInsets.symmetric(horizontal: 4),
    indicatorSize: TabBarIndicatorSize.tab,
    tabs: [for (final which in tabs) tab(which)],
  );
}

const double _labelSize = 13;

/// The number on a tab: a small rounded pill. Quiet is a soft grey, attention is filled with the brand colour and pulses a couple of
/// times when it lights up or the number grows, danger is red. With "reduce motion" on it never moves (the colour alone says it).
class StaffCountBadge extends StatefulWidget {
  const StaffCountBadge({super.key, required this.count, required this.tone});
  final int count;
  final StaffBadgeTone tone;

  @override
  State<StaffCountBadge> createState() => _StaffCountBadgeState();
}

class _StaffCountBadgeState extends State<StaffCountBadge> with SingleTickerProviderStateMixin {
  late final AnimationController _pulse = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1600),
  );
  bool _started = false;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (!_started) {
      _started = true;
      _maybePulse();
    }
  }

  @override
  void didUpdateWidget(StaffCountBadge old) {
    super.didUpdateWidget(old);
    final lit = widget.tone != StaffBadgeTone.quiet && old.tone == StaffBadgeTone.quiet;
    if (lit || (widget.tone != StaffBadgeTone.quiet && widget.count > old.count)) _maybePulse();
  }

  /// Two soft pulses, then it rests as a solid badge (never an endless animation).
  void _maybePulse() {
    if (widget.tone == StaffBadgeTone.quiet || MediaQuery.disableAnimationsOf(context)) return;
    _pulse.forward(from: 0);
  }

  @override
  void dispose() {
    _pulse.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final palette = context.palette;
    final (bg, fg) = switch (widget.tone) {
      StaffBadgeTone.quiet => (palette.surfaceMuted, palette.muted),
      StaffBadgeTone.attention => (context.colors.primary, context.colors.onPrimary),
      StaffBadgeTone.danger => (palette.danger, Contrast.onColor(palette.danger)),
    };
    final text = widget.count > 99 ? '99+' : '${widget.count}';
    return AnimatedBuilder(
      animation: _pulse,
      builder: (context, child) {
        // Two humps over the run: 0 -> 1 -> 0 -> 1 -> 0.
        final k = _pulse.isAnimating ? math.sin(_pulse.value * math.pi * 2).abs() : 0.0;
        return Transform.scale(
          scale: 1 + 0.22 * k,
          child: DecoratedBox(
            decoration: BoxDecoration(
              color: bg,
              borderRadius: BorderRadius.circular(AppRadius.pill),
              boxShadow: k > 0
                  ? [
                      BoxShadow(
                        color: bg.withValues(alpha: 0.45 * k),
                        blurRadius: 10 * k,
                        spreadRadius: 2 * k,
                      ),
                    ]
                  : null,
            ),
            child: child,
          ),
        );
      },
      child: ConstrainedBox(
        constraints: const BoxConstraints(minWidth: 22, minHeight: 22),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
          child: Center(
            widthFactor: 1,
            child: Text(
              text,
              maxLines: 1,
              style: context.text.labelSmall?.copyWith(
                color: fg,
                fontWeight: FontWeight.w700,
                letterSpacing: 0,
                height: 1.2,
              ),
            ),
          ),
        ),
      ),
    );
  }
}
