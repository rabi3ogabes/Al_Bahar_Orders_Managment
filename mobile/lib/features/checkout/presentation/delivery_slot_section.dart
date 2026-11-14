import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/branches/domain/branch_models.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/features/checkout/domain/checkout_models.dart';
import 'package:albahar_customer/features/checkout/domain/slot_selection.dart';
import 'package:albahar_customer/features/checkout/presentation/state/checkout_controller.dart';
import 'package:albahar_customer/features/checkout/presentation/state/slot_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Shows "N left" only when capacity is getting tight.
const int kSlotLowStock = 5;

/// How many dates the strip shows; "More dates" opens a calendar for the rest of the month.
const int kSlotStripDays = 7;

/// One selectable time window: "4:00 PM – 6:00 PM", "2 left" / "Full".
/// Full or exhausted slots are disabled. At least 48dp high.
class SlotTile extends StatelessWidget {
  const SlotTile({super.key, required this.slot, required this.selected, this.onTap, this.badge, this.bookable = true});

  final DeliverySlot slot;
  final bool selected;
  final VoidCallback? onTap;

  /// A small calm label under the time, such as "Earliest available" on a time the app chose.
  final String? badge;

  /// False once the time's cutoff has passed on the phone's clock, even if the list still shows a place.
  final bool bookable;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final palette = context.palette;
    final locale = context.localeCode;
    final enabled = slot.selectable && bookable;
    final range = QatarTime.slotRange(slot.startsAt, slot.endsAt, locale);
    final note = !enabled
        ? l10n.slotFull
        : (slot.available <= kSlotLowStock ? l10n.slotRemaining(slot.available) : null);
    final fg = !enabled ? palette.muted : (selected ? palette.brass : context.colors.onSurface);
    final label = [range, if (selected && badge != null) badge!, ?note].join(', ');

    return Semantics(
      button: true,
      selected: selected,
      enabled: enabled,
      label: label,
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
                    if (selected && badge != null)
                      Padding(
                        padding: const EdgeInsets.only(top: 2),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Icon(Icons.auto_awesome_rounded, size: 12, color: palette.brass),
                            const Gap(4),
                            Flexible(
                              child: Text(
                                badge!,
                                key: const ValueKey('slot-earliest-badge'),
                                textAlign: TextAlign.center,
                                style: context.text.bodySmall?.copyWith(
                                  color: palette.brass,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                            ),
                          ],
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

/// Delivery time step: the earliest bookable time is chosen as soon as the times load; dates (the next free ones + "More dates")
/// and a grid of times let the customer change it with one tap. Never replaces a time the customer chose themselves.
class DeliverySlotSection extends StatelessWidget {
  const DeliverySlotSection({
    super.key,
    required this.branchId,
    required this.selectedSlotId,
    required this.selectedDate,
    required this.onSelect,
    this.unavailable = false,
    this.slotRequired = false,
    this.auto = false,
    this.onSwitch,
  });

  final String branchId;
  final String? selectedSlotId;
  final String? selectedDate;
  final void Function(DeliverySlot slot, String date) onSelect;

  /// The server rejected the previous selection (`slot_unavailable`).
  final bool unavailable;

  /// The quote says a slot is still needed (`slot_required`).
  final bool slotRequired;

  /// The shown time was chosen by the app as the earliest available.
  final bool auto;

  /// "Choose pickup instead" in the empty state; null hides it.
  final VoidCallback? onSwitch;

  @override
  Widget build(BuildContext context) => SlotPickerSection(
    kind: FulfillmentType.delivery,
    branchId: branchId,
    selectedSlotId: selectedSlotId,
    selectedDate: selectedDate,
    onSelect: onSelect,
    unavailable: unavailable,
    slotRequired: slotRequired,
    auto: auto,
    onSwitch: onSwitch,
  );
}

/// Pickup time step: same behaviour as delivery, for the chosen showroom. A pickup time is optional for the server, but the
/// earliest one is still chosen for the customer to see and change.
class PickupSlotSection extends StatelessWidget {
  const PickupSlotSection({
    super.key,
    required this.branchId,
    required this.selectedSlotId,
    required this.selectedDate,
    required this.onSelect,
    this.unavailable = false,
    this.auto = false,
    this.onSwitch,
  });

  final String branchId;
  final String? selectedSlotId;
  final String? selectedDate;
  final void Function(PickupSlot slot, String date) onSelect;
  final bool unavailable;
  final bool auto;
  final VoidCallback? onSwitch;

  @override
  Widget build(BuildContext context) => SlotPickerSection(
    kind: FulfillmentType.pickup,
    branchId: branchId,
    selectedSlotId: selectedSlotId,
    selectedDate: selectedDate,
    onSelect: onSelect,
    unavailable: unavailable,
    auto: auto,
    onSwitch: onSwitch,
  );
}

/// The shared picker behind [DeliverySlotSection] and [PickupSlotSection].
class SlotPickerSection extends ConsumerStatefulWidget {
  const SlotPickerSection({
    super.key,
    required this.kind,
    required this.branchId,
    required this.selectedSlotId,
    required this.selectedDate,
    required this.onSelect,
    this.unavailable = false,
    this.slotRequired = false,
    this.auto = false,
    this.onSwitch,
  });

  final FulfillmentType kind;
  final String branchId;
  final String? selectedSlotId;
  final String? selectedDate;
  final void Function(BranchSlot slot, String date) onSelect;
  final bool unavailable;
  final bool slotRequired;
  final bool auto;
  final VoidCallback? onSwitch;

  @override
  ConsumerState<SlotPickerSection> createState() => _SlotPickerSectionState();
}

class _SlotPickerSectionState extends ConsumerState<SlotPickerSection> {
  /// The day being looked at; follows the selection when the app changes it.
  String? _date;

  /// A day from the calendar, beyond the strip.
  String? _extra;

  SlotWindow? _window;
  Timer? _tick;
  AppLifecycleListener? _lifecycle;

  bool get _delivery => widget.kind == FulfillmentType.delivery;
  String get _prefix => _delivery ? 'delivery' : 'pickup';

  AsyncValue<SlotWindow> get _windowValue =>
      ref.watch(_delivery ? deliverySlotWindowProvider(widget.branchId) : pickupSlotWindowProvider(widget.branchId));

  @override
  void initState() {
    super.initState();
    _date = widget.selectedDate;
    final provider = _delivery ? deliverySlotWindowProvider(widget.branchId) : pickupSlotWindowProvider(widget.branchId);
    ref.listenManual<AsyncValue<SlotWindow>>(provider, (_, next) {
      if (next.isLoading || !next.hasValue) return;
      _window = next.value;
      _reconcile();
    }, fireImmediately: true);
    // A time can pass its cutoff while the screen stays open: look again every half minute, and when the app comes back.
    _tick = Timer.periodic(const Duration(seconds: 30), (_) {
      if (!mounted) return;
      setState(() {});
      _reconcile();
    });
    _lifecycle = AppLifecycleListener(onResume: _refetch);
  }

  @override
  void didUpdateWidget(covariant SlotPickerSection old) {
    super.didUpdateWidget(old);
    // The selection was cleared (new address, a time the server refused, …): choose again from what is free.
    if (old.selectedSlotId != null && widget.selectedSlotId == null) _reconcile();
    if (widget.selectedDate != null && widget.selectedDate != old.selectedDate) _date = widget.selectedDate;
  }

  @override
  void dispose() {
    _tick?.cancel();
    _lifecycle?.dispose();
    super.dispose();
  }

  void _refetch() {
    if (!mounted) return;
    ref.invalidate(deliverySlotWindowProvider);
    ref.invalidate(pickupSlotWindowProvider);
    ref.invalidate(deliverySlotsProvider);
    ref.invalidate(pickupSlotsProvider);
  }

  void _reconcile() {
    final window = _window;
    if (window == null) return;
    // Never change another provider while the tree is building.
    Future.microtask(() {
      if (!mounted) return;
      unawaited(
        ref
            .read(checkoutControllerProvider.notifier)
            .reconcileSlots(kind: widget.kind, branchId: widget.branchId, window: window),
      );
    });
  }

  Future<void> _pickMoreDates() async {
    final today = QatarTime.today(ref.read(slotClockProvider)());
    final first = DateTime(today.year, today.month, today.day);
    final initial = QatarTime.parseDay(_date ?? widget.selectedDate);
    final picked = await showDatePicker(
      context: context,
      helpText: context.l10n.deliverySlotMoreDates,
      firstDate: first,
      lastDate: first.add(const Duration(days: 29)),
      initialDate: initial == null || initial.isBefore(first) ? first : DateTime(initial.year, initial.month, initial.day),
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
    final window = _windowValue;

    final Widget body = window.when(
      skipLoadingOnReload: true,
      loading: () => const _SlotSkeleton(),
      error: (e, _) => ErrorState(error: e, compact: true, onRetry: _refetch),
      data: _content,
    );

    final hasSelection = widget.selectedSlotId != null;
    // A time the app chose after the customer's one became unavailable says so; a cleared time asks for a new choice.
    final message = widget.unavailable
        ? (hasSelection
              ? (l10n.slotReplacedNotice, palette.warning)
              : (_delivery ? l10n.deliverySlotUnavailableError : l10n.pickupSlotUnavailableError, palette.danger))
        : (widget.slotRequired && !hasSelection && (window.value?.openDates.isNotEmpty ?? false)
              ? (l10n.deliverySlotRequiredError, palette.warning)
              : null);
    final showAutoHint = widget.auto && hasSelection && message == null;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(_delivery ? l10n.deliverySlotTitle : l10n.pickupSlot, style: context.text.titleSmall),
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
                      key: ValueKey('$_prefix-slot-message'),
                      style: context.text.bodySmall?.copyWith(color: message.$2),
                    ),
                  ),
                ],
              ),
            ),
          ),
        if (showAutoHint)
          Padding(
            padding: const EdgeInsets.only(bottom: AppSpace.xs),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(Icons.auto_awesome_rounded, size: 16, color: palette.brass),
                const Gap(AppSpace.xs),
                Expanded(
                  child: Text(
                    l10n.slotAutoHint,
                    key: ValueKey('$_prefix-slot-auto-hint'),
                    style: context.text.bodySmall?.copyWith(color: palette.muted),
                  ),
                ),
              ],
            ),
          ),
        body,
      ],
    );
  }

  Widget _content(SlotWindow window) {
    final l10n = context.l10n;
    final strip = window.openDates.take(kSlotStripDays).toList();
    final chips = <String>{
      ...strip,
      ?_extra,
      // A time chosen on a date beyond the strip stays visible.
      if (widget.selectedDate != null && widget.selectedSlotId != null) widget.selectedDate!,
    }.toList()..sort();
    if (chips.isEmpty) {
      return _SlotsEmpty(
        key: ValueKey('$_prefix-slots-empty'),
        delivery: _delivery,
        onRetry: _refetch,
        onSwitch: widget.onSwitch,
      );
    }
    final preferred = _date ?? widget.selectedDate;
    final active = chips.contains(preferred) ? preferred! : chips.first;
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
                  key: ValueKey('$_prefix-date-$d'),
                  iso: d,
                  selected: d == active,
                  onTap: () => setState(() => _date = d),
                ),
                const Gap(AppSpace.xs),
              ],
              OutlinedButton.icon(
                key: ValueKey('$_prefix-more-dates'),
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
        _SlotGrid(
          kind: widget.kind,
          branchId: widget.branchId,
          date: active,
          window: window,
          selectedSlotId: widget.selectedSlotId,
          selectedDate: widget.selectedDate,
          auto: widget.auto,
          prefix: _prefix,
          now: ref.read(slotClockProvider)(),
          onSelect: (s) => widget.onSelect(s, active),
        ),
      ],
    );
  }
}

/// Nothing is open: say so plainly and offer the next step instead of a dead end.
class _SlotsEmpty extends StatelessWidget {
  const _SlotsEmpty({super.key, required this.delivery, required this.onRetry, this.onSwitch});
  final bool delivery;
  final VoidCallback onRetry;
  final VoidCallback? onSwitch;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final palette = context.palette;
    final prefix = delivery ? 'delivery' : 'pickup';
    return Container(
      padding: const EdgeInsets.all(AppSpace.md),
      decoration: BoxDecoration(
        color: palette.brassTint,
        borderRadius: AppRadius.cardAll,
        border: Border.all(color: palette.line),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(Icons.event_busy_outlined, color: palette.brass),
              const Gap(AppSpace.sm),
              Expanded(
                child: Semantics(
                  header: true,
                  liveRegion: true,
                  child: Text(
                    delivery ? l10n.slotsEmptyTitleDelivery : l10n.slotsEmptyTitlePickup,
                    style: context.text.titleSmall,
                  ),
                ),
              ),
            ],
          ),
          const Gap(AppSpace.xs),
          Text(delivery ? l10n.slotsEmptyBodyDelivery : l10n.slotsEmptyBodyPickup, style: context.text.bodySmall),
          const Gap(AppSpace.sm),
          Wrap(
            spacing: AppSpace.xs,
            runSpacing: AppSpace.xs,
            children: [
              OutlinedButton.icon(
                key: ValueKey('$prefix-slots-retry'),
                onPressed: onRetry,
                icon: const Icon(Icons.refresh_rounded, size: 18),
                label: Text(l10n.slotsCheckAgain),
                style: OutlinedButton.styleFrom(minimumSize: const Size(kMinTouchTarget, kMinTouchTarget)),
              ),
              if (onSwitch != null)
                FilledButton.tonalIcon(
                  key: ValueKey('$prefix-slots-switch'),
                  onPressed: onSwitch,
                  icon: Icon(delivery ? Icons.storefront_outlined : Icons.local_shipping_outlined, size: 18),
                  label: Text(delivery ? l10n.slotsSwitchToPickup : l10n.slotsSwitchToDelivery),
                  style: FilledButton.styleFrom(minimumSize: const Size(kMinTouchTarget, kMinTouchTarget)),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

class _SlotGrid extends ConsumerWidget {
  const _SlotGrid({
    required this.kind,
    required this.branchId,
    required this.date,
    required this.window,
    required this.selectedSlotId,
    required this.selectedDate,
    required this.auto,
    required this.prefix,
    required this.now,
    required this.onSelect,
  });

  final FulfillmentType kind;
  final String branchId;
  final String date;
  final SlotWindow window;
  final String? selectedSlotId;
  final String? selectedDate;
  final bool auto;
  final String prefix;
  final DateTime now;
  final ValueChanged<BranchSlot> onSelect;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final delivery = kind == FulfillmentType.delivery;
    final inWindow = date.compareTo(window.from) >= 0 && date.compareTo(window.to) <= 0;
    final key = (branchId: branchId, date: date);
    // Dates the window already covers need no second request; a date picked beyond it is fetched on its own.
    final AsyncValue<List<BranchSlot>> slots = inWindow
        ? AsyncData(window.days.where((d) => d.date == date).firstOrNull?.slots ?? const [])
        : ref.watch(delivery ? deliverySlotsProvider(key) : pickupSlotsProvider(key));

    return slots.when(
      skipLoadingOnReload: true,
      loading: () => const _SlotSkeleton(chips: false),
      error: (e, _) => ErrorState(
        error: e,
        compact: true,
        onRetry: () => ref.invalidate(delivery ? deliverySlotsProvider(key) : pickupSlotsProvider(key)),
      ),
      data: (list) {
        if (list.isEmpty) {
          return Padding(
            padding: const EdgeInsets.symmetric(vertical: AppSpace.sm),
            child: Text(delivery ? l10n.deliverySlotNoSlotsDay : l10n.noSlots, style: context.text.bodySmall),
          );
        }
        final ordered = [...list]..sort((a, b) => a.startsAt.compareTo(b.startsAt));
        return LayoutBuilder(
          builder: (context, c) {
            const gap = AppSpace.xs;
            final width = (c.maxWidth - gap) / 2;
            return Wrap(
              spacing: gap,
              runSpacing: gap,
              children: [
                for (final s in ordered)
                  SizedBox(
                    width: width,
                    child: SlotTile(
                      key: ValueKey('$prefix-slot-${s.id}'),
                      slot: s,
                      // The same time slot repeats every week: the date must match too.
                      selected: s.id == selectedSlotId && (selectedDate == null || selectedDate == date),
                      badge: auto ? l10n.slotEarliestBadge : null,
                      bookable: isSlotBookable(s, date, now),
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
