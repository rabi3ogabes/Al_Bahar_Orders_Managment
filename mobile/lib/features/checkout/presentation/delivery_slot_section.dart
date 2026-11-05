import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/branches/domain/branch_models.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Shows "N left" only when capacity is getting tight.
const int kSlotLowStock = 5;

/// One selectable delivery window: "4:00 PM – 6:00 PM", "2 left" / "Full".
/// Full or exhausted slots are disabled. At least 48dp high.
class SlotTile extends StatelessWidget {
  const SlotTile({super.key, required this.slot, required this.selected, this.onTap});

  final DeliverySlot slot;
  final bool selected;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final palette = context.palette;
    final locale = context.localeCode;
    final enabled = slot.selectable;
    final range = QatarTime.slotRange(slot.startsAt, slot.endsAt, locale);
    final note = !enabled
        ? l10n.slotFull
        : (slot.available <= kSlotLowStock ? l10n.slotRemaining(slot.available) : null);
    final fg = !enabled ? palette.muted : (selected ? palette.brass : context.colors.onSurface);

    return Semantics(
      button: true,
      selected: selected,
      enabled: enabled,
      label: note == null ? range : '$range, $note',
      excludeSemantics: true,
      onTap: enabled ? onTap : null,
      child: Opacity(
        opacity: enabled ? 1 : 0.55,
        child: Material(
          color: selected ? palette.brassTint : context.colors.surface,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(AppRadius.input),
            side: BorderSide(color: selected ? palette.brass : palette.line, width: selected ? 1.5 : 1),
          ),
          child: InkWell(
            borderRadius: BorderRadius.circular(AppRadius.input),
            onTap: enabled
                ? () {
                    Haptics.tap();
                    onTap?.call();
                  }
                : null,
            child: ConstrainedBox(
              constraints: const BoxConstraints(minHeight: kMinTouchTarget + 8),
              child: Padding(
                padding: const EdgeInsetsDirectional.symmetric(horizontal: AppSpace.sm, vertical: AppSpace.xs),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Text(
                      range,
                      textAlign: TextAlign.center,
                      style: context.text.titleSmall?.copyWith(
                        color: fg,
                        decoration: enabled ? null : TextDecoration.lineThrough,
                      ),
                    ),
                    if (note != null)
                      Text(
                        note,
                        style: context.text.bodySmall?.copyWith(
                          color: !enabled ? palette.muted : palette.warning,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _DateChip extends StatelessWidget {
  const _DateChip({super.key, required this.iso, required this.selected, required this.onTap});
  final String iso;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final palette = context.palette;
    final label = QatarTime.slotDay(iso, context.localeCode);
    return Semantics(
      button: true,
      selected: selected,
      label: label,
      excludeSemantics: true,
      child: Material(
        color: selected ? palette.brass : context.colors.surface,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(AppRadius.pill),
          side: BorderSide(color: selected ? palette.brass : palette.line),
        ),
        child: InkWell(
          borderRadius: BorderRadius.circular(AppRadius.pill),
          onTap: () {
            Haptics.tap();
            onTap();
          },
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: kMinTouchTarget, minWidth: kMinTouchTarget),
            child: Padding(
              padding: const EdgeInsetsDirectional.symmetric(horizontal: AppSpace.md),
              child: Center(
                widthFactor: 1,
                child: Text(
                  label,
                  style: context.text.labelLarge?.copyWith(color: selected ? context.colors.onPrimary : null),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Delivery time step: date chips (next 7 days with free slots + "More dates")
/// and a grid of slot tiles for the chosen date. Nothing is auto-selected.
class DeliverySlotSection extends ConsumerStatefulWidget {
  const DeliverySlotSection({
    super.key,
    required this.branchId,
    required this.selectedSlotId,
    required this.selectedDate,
    required this.onSelect,
    this.unavailable = false,
    this.slotRequired = false,
  });

  final String branchId;
  final String? selectedSlotId;
  final String? selectedDate;
  final void Function(DeliverySlot slot, String date) onSelect;

  /// The server rejected the previous selection (`slot_unavailable`).
  final bool unavailable;

  /// The quote says a slot is still needed (`slot_required`).
  final bool slotRequired;

  @override
  ConsumerState<DeliverySlotSection> createState() => _DeliverySlotSectionState();
}

class _DeliverySlotSectionState extends ConsumerState<DeliverySlotSection> {
  String? _date;
  String? _extra;

  Future<void> _pickMoreDates() async {
    final today = QatarTime.today();
    final first = DateTime(today.year, today.month, today.day);
    final initial = QatarTime.parseDay(_date ?? widget.selectedDate);
    final picked = await showDatePicker(
      context: context,
      helpText: context.l10n.deliverySlotMoreDates,
      firstDate: first,
      lastDate: first.add(const Duration(days: 29)),
      initialDate: initial == null ? first : DateTime(initial.year, initial.month, initial.day),
    );
    if (picked == null || !mounted) return;
    setState(() {
      _extra = QatarTime.isoDate(picked);
      _date = _extra;
    });
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final palette = context.palette;
    final dates = ref.watch(deliveryDatesProvider(widget.branchId));

    final Widget body = dates.when(
      loading: () => const _SlotSkeleton(),
      error: (e, _) => ErrorState(
        error: e,
        compact: true,
        onRetry: () {
          ref.invalidate(deliverySlotsProvider);
          ref.invalidate(deliveryDatesProvider);
        },
      ),
      data: (free) {
        final chips = [...free, if (_extra != null && !free.contains(_extra)) _extra!]..sort();
        final preferred = _date ?? widget.selectedDate;
        final active = (preferred != null && chips.contains(preferred)) ? preferred : chips.firstOrNull;
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            SizedBox(
              height: kMinTouchTarget,
              child: ListView(
                scrollDirection: Axis.horizontal,
                children: [
                  for (final d in chips) ...[
                    _DateChip(
                      key: ValueKey('delivery-date-$d'),
                      iso: d,
                      selected: d == active,
                      onTap: () => setState(() => _date = d),
                    ),
                    const Gap(AppSpace.xs),
                  ],
                  OutlinedButton.icon(
                    key: const ValueKey('delivery-more-dates'),
                    onPressed: _pickMoreDates,
                    icon: const Icon(Icons.calendar_month_outlined, size: 18),
                    label: Text(l10n.deliverySlotMoreDates),
                    style: OutlinedButton.styleFrom(
                      minimumSize: const Size(kMinTouchTarget, kMinTouchTarget),
                      shape: const StadiumBorder(),
                    ),
                  ),
                ],
              ),
            ),
            const Gap(AppSpace.sm),
            if (active == null)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: AppSpace.sm),
                child: Text(l10n.deliverySlotNoDates, style: context.text.bodySmall?.copyWith(color: palette.muted)),
              )
            else
              _SlotGrid(
                branchId: widget.branchId,
                date: active,
                selectedSlotId: widget.selectedSlotId,
                onSelect: (s) => widget.onSelect(s, active),
              ),
          ],
        );
      },
    );

    final message = widget.unavailable
        ? (l10n.deliverySlotUnavailableError, palette.danger)
        : (widget.slotRequired && widget.selectedSlotId == null
              ? (l10n.deliverySlotRequiredError, palette.warning)
              : null);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(l10n.deliverySlotTitle, style: context.text.titleSmall),
        const Gap(AppSpace.xs),
        if (message != null)
          Padding(
            padding: const EdgeInsets.only(bottom: AppSpace.xs),
            child: Semantics(
              liveRegion: true,
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(Icons.info_outline_rounded, size: 18, color: message.$2),
                  const Gap(AppSpace.xs),
                  Expanded(
                    child: Text(
                      message.$1,
                      key: const ValueKey('delivery-slot-message'),
                      style: context.text.bodySmall?.copyWith(color: message.$2),
                    ),
                  ),
                ],
              ),
            ),
          ),
        body,
      ],
    );
  }
}

class _SlotGrid extends ConsumerWidget {
  const _SlotGrid({required this.branchId, required this.date, required this.selectedSlotId, required this.onSelect});
  final String branchId;
  final String date;
  final String? selectedSlotId;
  final ValueChanged<DeliverySlot> onSelect;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    return ref
        .watch(deliverySlotsProvider((branchId: branchId, date: date)))
        .when(
          loading: () => const _SlotSkeleton(chips: false),
          error: (e, _) => ErrorState(
            error: e,
            compact: true,
            onRetry: () => ref.invalidate(deliverySlotsProvider((branchId: branchId, date: date))),
          ),
          data: (slots) {
            if (slots.isEmpty) {
              return Padding(
                padding: const EdgeInsets.symmetric(vertical: AppSpace.sm),
                child: Text(l10n.deliverySlotNoSlotsDay, style: context.text.bodySmall),
              );
            }
            return LayoutBuilder(
              builder: (context, c) {
                const gap = AppSpace.xs;
                final width = (c.maxWidth - gap) / 2;
                return Wrap(
                  spacing: gap,
                  runSpacing: gap,
                  children: [
                    for (final s in slots)
                      SizedBox(
                        width: width,
                        child: SlotTile(
                          key: ValueKey('delivery-slot-${s.id}'),
                          slot: s,
                          selected: s.id == selectedSlotId,
                          onTap: () => onSelect(s),
                        ),
                      ),
                  ],
                );
              },
            );
          },
        );
  }
}

class _SlotSkeleton extends StatelessWidget {
  const _SlotSkeleton({this.chips = true});
  final bool chips;

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      if (chips) ...[
        Row(
          children: [
            for (var i = 0; i < 3; i++) ...[
              const Skeleton(width: 84, height: kMinTouchTarget, radius: AppRadius.pill),
              const Gap(AppSpace.xs),
            ],
          ],
        ),
        const Gap(AppSpace.sm),
      ],
      const Row(
        children: [
          Expanded(child: Skeleton(height: 56, radius: AppRadius.input)),
          Gap(AppSpace.xs),
          Expanded(child: Skeleton(height: 56, radius: AppRadius.input)),
        ],
      ),
      const Gap(AppSpace.xs),
      const Row(
        children: [
          Expanded(child: Skeleton(height: 56, radius: AppRadius.input)),
          Gap(AppSpace.xs),
          Expanded(child: Skeleton(height: 56, radius: AppRadius.input)),
        ],
      ),
    ],
  );
}
