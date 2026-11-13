import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/features/orders/domain/tracking_state.dart';
import 'package:albahar_customer/features/orders/presentation/tracking_view_model.dart';
import 'package:flutter/material.dart';

/// The panel at the bottom of the tracking map: who is bringing the order and when, how fresh that is, and (on
/// request) the four order steps. It is part of the page, not a modal sheet, so the map above it stays usable and the
/// map reports the panel's height to keep the markers clear of it.
class TrackingPanel extends StatefulWidget {
  const TrackingPanel({super.key, required this.model, required this.onCall, this.maxHeightFactor = 0.6});

  final TrackingPanelModel model;

  /// Null hides the call button (no phone number known).
  final VoidCallback? onCall;
  final double maxHeightFactor;

  @override
  State<TrackingPanel> createState() => _TrackingPanelState();
}

class _TrackingPanelState extends State<TrackingPanel> {
  bool _steps = false;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final m = widget.model;
    final scheme = Theme.of(context).colorScheme;
    final palette = context.palette;
    final screen = MediaQuery.sizeOf(context).height;
    return Material(
      color: scheme.surface,
      elevation: 8,
      shadowColor: Colors.black38,
      borderRadius: const BorderRadius.vertical(top: Radius.circular(AppRadius.sheet)),
      clipBehavior: Clip.antiAlias,
      child: SafeArea(
        top: false,
        child: ConstrainedBox(
          constraints: BoxConstraints(maxHeight: screen * widget.maxHeightFactor),
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.xs, AppSpace.gutter, AppSpace.sm),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Center(
                  child: ExcludeSemantics(
                    child: Container(
                      width: 36,
                      height: 4,
                      decoration: BoxDecoration(color: palette.line, borderRadius: BorderRadius.circular(2)),
                    ),
                  ),
                ),
                const Gap(AppSpace.sm),
                Row(
                  children: [
                    _Avatar(model: m),
                    const Gap(AppSpace.sm),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(m.title, style: context.text.titleMedium),
                          if (m.subtitle != null && m.subtitle!.isNotEmpty)
                            Text(m.subtitle!, style: context.text.bodySmall?.copyWith(color: palette.muted)),
                        ],
                      ),
                    ),
                    if (m.canCall && widget.onCall != null)
                      IconButton.filled(
                        tooltip: l10n.trackCall,
                        onPressed: widget.onCall,
                        icon: const Icon(Icons.call_rounded),
                        constraints: const BoxConstraints.tightFor(width: kMinTouchTarget, height: kMinTouchTarget),
                      ),
                  ],
                ),
                if (m.etaText != null || m.distanceText != null) ...[
                  const Gap(AppSpace.sm),
                  Semantics(
                    liveRegion: true,
                    child: Wrap(
                      spacing: AppSpace.xs,
                      runSpacing: AppSpace.xs,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        if (m.etaText != null) _EtaChip(text: m.etaText!, dim: m.stale),
                        if (m.distanceText != null)
                          Text(m.distanceText!, style: context.text.bodyMedium?.copyWith(color: palette.muted)),
                      ],
                    ),
                  ),
                ],
                if (m.note != null) ...[
                  const Gap(AppSpace.xs),
                  Row(
                    children: [
                      Icon(m.link == TrackingLink.offline ? Icons.cloud_off_rounded : Icons.schedule_rounded, size: 16, color: palette.muted),
                      const Gap(6),
                      Expanded(child: Text(m.note!, style: context.text.bodySmall?.copyWith(color: palette.muted))),
                    ],
                  ),
                ],
                const Gap(AppSpace.xs),
                Row(
                  children: [
                    if (m.link != TrackingLink.idle) _LinkPill(link: m.link),
                    const Spacer(),
                    TextButton.icon(
                      onPressed: () => setState(() => _steps = !_steps),
                      icon: Icon(_steps ? Icons.expand_more_rounded : Icons.expand_less_rounded),
                      label: Text(_steps ? l10n.trackStepsHide : l10n.trackStepsShow),
                    ),
                  ],
                ),
                AnimatedSize(
                  duration: MediaQuery.disableAnimationsOf(context) ? Duration.zero : AppDurations.medium,
                  alignment: Alignment.topCenter,
                  child: _steps ? TrackingSteps(current: m.step, closed: m.stage == TrackingStage.closed) : const SizedBox(width: double.infinity),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _Avatar extends StatelessWidget {
  const _Avatar({required this.model});
  final TrackingPanelModel model;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final palette = context.palette;
    final (IconData icon, Color bg, Color fg) = switch (model.stage) {
      TrackingStage.delivered => (Icons.check_circle_rounded, palette.success.withValues(alpha: 0.14), palette.success),
      TrackingStage.closed => (Icons.info_outline_rounded, palette.surfaceMuted, palette.muted),
      TrackingStage.waitingPickup => (Icons.storefront_rounded, palette.brassTint, palette.brassDeep),
      TrackingStage.notAssigned => (Icons.inventory_2_outlined, palette.brassTint, palette.brassDeep),
      TrackingStage.onTheWay => (Icons.two_wheeler_rounded, scheme.primary.withValues(alpha: 0.12), scheme.primary),
    };
    return ExcludeSemantics(
      child: CircleAvatar(
        radius: 24,
        backgroundColor: bg,
        child: model.initial != null && model.stage == TrackingStage.onTheWay
            ? Text(model.initial!, style: context.text.titleMedium?.copyWith(color: fg, fontWeight: FontWeight.w700))
            : Icon(icon, color: fg),
      ),
    );
  }
}

class _EtaChip extends StatelessWidget {
  const _EtaChip({required this.text, required this.dim});
  final String text;
  final bool dim;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return DecoratedBox(
      decoration: BoxDecoration(
        color: dim ? context.palette.surfaceMuted : scheme.primary,
        borderRadius: BorderRadius.circular(AppRadius.pill),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: AppSpace.sm, vertical: 6),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.schedule_rounded, size: 18, color: dim ? context.palette.muted : scheme.onPrimary),
            const Gap(6),
            Flexible(
              child: Text(
                text,
                style: context.text.titleSmall?.copyWith(color: dim ? context.palette.muted : scheme.onPrimary, fontWeight: FontWeight.w700),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _LinkPill extends StatelessWidget {
  const _LinkPill({required this.link});
  final TrackingLink link;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final palette = context.palette;
    final (Color color, String text) = switch (link) {
      TrackingLink.live => (palette.success, l10n.trackLive),
      TrackingLink.reconnecting => (palette.warning, l10n.trackReconnecting),
      _ => (palette.muted, l10n.trackOfflineShort),
    };
    return Semantics(
      label: text,
      excludeSemantics: true,
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(width: 8, height: 8, decoration: BoxDecoration(color: color, shape: BoxShape.circle)),
          const Gap(6),
          Text(text, style: context.text.labelMedium?.copyWith(color: color, fontWeight: FontWeight.w600)),
        ],
      ),
    );
  }
}

/// The four order steps as a short vertical list: done steps are ticked, the current one is highlighted.
class TrackingSteps extends StatelessWidget {
  const TrackingSteps({super.key, required this.current, this.closed = false});
  final TrackingStep current;
  final bool closed;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final palette = context.palette;
    final scheme = Theme.of(context).colorScheme;
    final labels = {
      TrackingStep.confirmed: l10n.trackStepConfirmed,
      TrackingStep.preparing: l10n.trackStepPreparing,
      TrackingStep.onTheWay: l10n.trackStepOnTheWay,
      TrackingStep.delivered: l10n.trackStepDelivered,
    };
    return Padding(
      padding: const EdgeInsets.only(top: AppSpace.xs, bottom: AppSpace.xs),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final step in TrackingStep.values)
            Builder(
              builder: (context) {
                final done = !closed && step.index < current.index || (!closed && current == TrackingStep.delivered && step == current);
                final active = !closed && step == current && current != TrackingStep.delivered;
                final reached = done || active;
                return Semantics(
                  label: '${labels[step]}, ${done ? l10n.trackStepDone : (active ? l10n.trackStepCurrent : l10n.trackStepPending)}',
                  excludeSemantics: true,
                  child: SizedBox(
                    height: 40,
                    child: Row(
                      children: [
                        Container(
                          width: 24,
                          height: 24,
                          decoration: BoxDecoration(
                            shape: BoxShape.circle,
                            color: done ? scheme.primary : (active ? scheme.primary.withValues(alpha: 0.14) : Colors.transparent),
                            border: Border.all(color: reached ? scheme.primary : palette.line, width: 2),
                          ),
                          child: done
                              ? Icon(Icons.check_rounded, size: 16, color: scheme.onPrimary)
                              : (active ? Center(child: Container(width: 8, height: 8, decoration: BoxDecoration(color: scheme.primary, shape: BoxShape.circle))) : null),
                        ),
                        const Gap(AppSpace.sm),
                        Expanded(
                          child: Text(
                            labels[step]!,
                            style: context.text.bodyMedium?.copyWith(
                              fontWeight: active ? FontWeight.w700 : FontWeight.w500,
                              color: reached ? null : palette.muted,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                );
              },
            ),
        ],
      ),
    );
  }
}
