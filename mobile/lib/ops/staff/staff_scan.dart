import 'dart:async';

import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/scan/data/scan_source.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:albahar_customer/ops/ops_staff_models.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/ops_widgets.dart';
import 'package:albahar_customer/ops/staff/staff_errors.dart';
import 'package:albahar_customer/ops/staff/staff_providers.dart';
import 'package:albahar_customer/ops/staff/staff_rights.dart';
import 'package:albahar_customer/ops/staff/staff_strings.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Opens the pass scanner over the staff home (a full screen: the camera needs the room, and one Back returns to the orders).
Future<void> showStaffScan(BuildContext context) =>
    Navigator.of(context)
        .push(MaterialPageRoute<void>(fullscreenDialog: true, builder: (_) => const StaffScanScreen()));

/// The pickup counter: the camera reads the customer's QR pass (or the code under it is typed when the camera is not allowed or not there),
/// the server checks it for this branch, and the order opens with what to hand over and what to collect. A pass that is refused says why in
/// plain words and nothing can be handed over. The camera keeps running, so the next customer can be scanned straight away.
class StaffScanScreen extends ConsumerStatefulWidget {
  const StaffScanScreen({super.key});

  @override
  ConsumerState<StaffScanScreen> createState() => _StaffScanScreenState();
}

class _StaffScanScreenState extends ConsumerState<StaffScanScreen> with WidgetsBindingObserver {
  late final ProviderSubscription<ScanSource> _sourceLink;
  late final ScanSource _source;
  StreamSubscription<String>? _codes;
  bool _busy = false;
  String? _lastCode;
  DateTime? _ignoreUntil;

  @override
  void initState() {
    super.initState();
    // The source is auto-dispose: listening to it for as long as the screen is open is what keeps the camera open (see scanSourceProvider).
    _sourceLink = ref.listenManual(scanSourceProvider, (_, _) {});
    _source = _sourceLink.read();
    WidgetsBinding.instance.addObserver(this);
    _codes = _source.codes.listen(_onCode);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) unawaited(_source.start());
    });
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    unawaited(_codes?.cancel());
    unawaited(_source.stop());
    _sourceLink.close();
    super.dispose();
  }

  /// The camera is handed back in the background and taken again on return (which is also how a permission switched on in the settings is picked up).
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      unawaited(_source.start());
    } else {
      unawaited(_source.stop());
    }
  }

  void _onCode(String raw) {
    final code = raw.trim();
    if (code.isEmpty || _busy || !mounted) return;
    // A window is open above the camera: what the camera sees now is not meant for it.
    if (!(ModalRoute.of(context)?.isCurrent ?? true)) return;
    final now = ref.read(staffClockSourceProvider)();
    // The pass that was just dealt with is still in front of the camera for a moment: it is not read again.
    if (code == _lastCode && _ignoreUntil != null && now.isBefore(_ignoreUntil!)) return;
    unawaited(_check(code));
  }

  Future<void> _typeCode() async {
    if (_busy) return;
    final code = await showDialog<String>(context: context, builder: (_) => const _CodeDialog());
    if (code != null && code.isNotEmpty && mounted) unawaited(_check(code));
  }

  Future<void> _check(String code) async {
    if (_busy) return;
    final branchId = ref.read(staffRightsProvider).branchId ?? '';
    setState(() => _busy = true);
    Haptics.light();
    PickupCheck? check;
    try {
      check = await ref.read(opsRepositoryProvider).pickupVerify(code, branchId);
    } catch (e) {
      if (mounted) showStaffError(context, e);
    }
    if (!mounted) return;
    String? done;
    if (check != null) {
      check.ok ? Haptics.success() : Haptics.warning();
      done = await showPickupResult(context, check: check);
    }
    if (!mounted) return;
    _lastCode = code;
    _ignoreUntil = ref.read(staffClockSourceProvider)().add(const Duration(seconds: 4));
    setState(() => _busy = false);
    if (done != null && done.isNotEmpty) {
      refreshStaff(ref);
      final t = OpsStrings.of(context);
      showInfoSnack(context, done == 'collected' ? t.handOverDone : t.handOverPart);
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    return Scaffold(
      backgroundColor: Colors.black,
      body: Stack(
        fit: StackFit.expand,
        children: [
          _source.buildPreview(context),
          ValueListenableBuilder<ScanCameraState>(
            valueListenable: _source.camera,
            builder: (context, camera, _) => switch (camera) {
              ScanCameraState.ready ||
              ScanCameraState.starting => _Viewfinder(starting: camera == ScanCameraState.starting),
              ScanCameraState.permissionDenied || ScanCameraState.unavailable => _CameraProblem(
                denied: camera == ScanCameraState.permissionDenied,
                onSettings: () => unawaited(_source.openSettings()),
                onRetry: () => unawaited(_source.start()),
              ),
            },
          ),
          SafeArea(
            child: Padding(
              padding: const EdgeInsetsDirectional.fromSTEB(AppSpace.xs, AppSpace.xs, AppSpace.xs, 0),
              child: Row(
                children: [
                  IconButton(
                    tooltip: MaterialLocalizations.of(context).closeButtonTooltip,
                    color: Colors.white,
                    icon: const Icon(Icons.close_rounded),
                    onPressed: () => Navigator.of(context).maybePop(),
                  ),
                  Expanded(
                    child: Semantics(
                      header: true,
                      child: Text(
                        t.scanTitle,
                        style: context.text.titleMedium?.copyWith(color: Colors.white),
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                  ),
                  ValueListenableBuilder<ScanTorch>(
                    valueListenable: _source.torch,
                    builder: (context, torch, _) => torch == ScanTorch.unavailable
                        ? const SizedBox(width: kMinTouchTarget)
                        : IconButton(
                            tooltip: t.torch,
                            color: Colors.white,
                            icon: Icon(
                              torch == ScanTorch.on ? Icons.flashlight_off_rounded : Icons.flashlight_on_rounded,
                            ),
                            onPressed: () => unawaited(_source.toggleTorch()),
                          ),
                  ),
                ],
              ),
            ),
          ),
          Align(
            alignment: Alignment.bottomCenter,
            child: SafeArea(
              child: Padding(
                padding: const EdgeInsets.all(AppSpace.md),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      t.scanHint,
                      textAlign: TextAlign.center,
                      style: context.text.bodyMedium?.copyWith(color: Colors.white),
                    ),
                    const Gap(AppSpace.sm),
                    FilledButton.tonalIcon(
                      key: const ValueKey('scan-type-code'),
                      onPressed: _busy ? null : _typeCode,
                      icon: const Icon(Icons.keyboard_rounded, size: 20),
                      label: Text(t.typeCode),
                    ),
                  ],
                ),
              ),
            ),
          ),
          if (_busy)
            ColoredBox(
              color: Colors.black54,
              child: Center(
                child: Semantics(
                  liveRegion: true,
                  label: t.checking,
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const CircularProgressIndicator(),
                      const Gap(AppSpace.sm),
                      Text(t.checking, style: context.text.bodyMedium?.copyWith(color: Colors.white)),
                    ],
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

/// A rounded frame to aim the pass into (decoration only: the whole picture is read).
class _Viewfinder extends StatelessWidget {
  const _Viewfinder({required this.starting});
  final bool starting;

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    return Center(
      child: ExcludeSemantics(
        child: Container(
          width: 240,
          height: 240,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(AppRadius.card),
            border: Border.all(color: Colors.white.withValues(alpha: 0.85), width: 3),
          ),
          child: starting
              ? Center(
                  child: Text(
                    t.scanCameraStarting,
                    textAlign: TextAlign.center,
                    style: context.text.bodyMedium?.copyWith(color: Colors.white),
                  ),
                )
              : null,
        ),
      ),
    );
  }
}

/// The camera is not allowed or not there: what to do about it, and the way that always works (typing the code).
class _CameraProblem extends StatelessWidget {
  const _CameraProblem({required this.denied, required this.onSettings, required this.onRetry});
  final bool denied;
  final VoidCallback onSettings;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(AppSpace.lg),
        child: Semantics(
          liveRegion: true,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.no_photography_outlined, size: 48, color: Colors.white),
              const Gap(AppSpace.sm),
              Text(
                denied ? t.scanCameraDenied : t.scanCameraUnavailable,
                key: const ValueKey('scan-camera-problem'),
                textAlign: TextAlign.center,
                style: context.text.bodyLarge?.copyWith(color: Colors.white),
              ),
              const Gap(AppSpace.md),
              if (denied)
                OutlinedButton(
                  style: OutlinedButton.styleFrom(
                    foregroundColor: Colors.white,
                    side: const BorderSide(color: Colors.white),
                  ),
                  onPressed: onSettings,
                  child: Text(t.openSettings),
                )
              else
                OutlinedButton(
                  style: OutlinedButton.styleFrom(
                    foregroundColor: Colors.white,
                    side: const BorderSide(color: Colors.white),
                  ),
                  onPressed: onRetry,
                  child: Text(t.retry),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Type the code under the QR (the camera is not allowed, or the QR is scratched).
class _CodeDialog extends StatefulWidget {
  const _CodeDialog();

  @override
  State<_CodeDialog> createState() => _CodeDialogState();
}

class _CodeDialogState extends State<_CodeDialog> {
  final _code = TextEditingController();

  @override
  void dispose() {
    _code.dispose();
    super.dispose();
  }

  void _submit() {
    final text = _code.text.trim();
    if (text.isNotEmpty) Navigator.of(context).pop(text);
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    return AlertDialog(
      title: Text(t.typeCode),
      content: TextField(
        key: const ValueKey('scan-code-field'),
        controller: _code,
        autofocus: true,
        maxLength: 200,
        autocorrect: false,
        enableSuggestions: false,
        textInputAction: TextInputAction.done,
        textDirection: TextDirection.ltr,
        inputFormatters: [FilteringTextInputFormatter.deny(RegExp(r'\s'))],
        onSubmitted: (_) => _submit(),
        decoration: InputDecoration(labelText: t.passCode),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.of(context).pop(), child: Text(t.cancel)),
        FilledButton(onPressed: _submit, child: Text(t.checkCode)),
      ],
    );
  }
}

// ── What the check says, and handing over ───────────────────────────────────────────────────────────────────────────────────

/// Shows what the check of a pass found. A refused pass says why and nothing else can be done; a valid one opens the hand-over. Resolves to
/// the pass's new status (`collected` / `partially_collected`) when goods were handed over, else null.
Future<String?> showPickupResult(BuildContext context, {required PickupCheck check}) {
  final t = OpsStrings.of(context);
  return showSidePanel<String>(
    context: context,
    title: check.ok ? t.passValid : t.scanTitle,
    subtitle: check.ok ? t.orderTitle(check.orderNumber) : null,
    scrollable: false,
    isForm: check.ok,
    builder: (_) => check.ok ? PickupHandoverForm(check: check) : _Refusal(result: check.result),
  );
}

class _Refusal extends StatelessWidget {
  const _Refusal({required this.result});
  final String result;

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final p = context.palette;
    return Padding(
      padding: const EdgeInsets.all(AppSpace.lg),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Icon(Icons.block_rounded, size: 44, color: p.danger),
          const Gap(AppSpace.sm),
          Semantics(
            liveRegion: true,
            child: Text(
              t.scanRefusal(result),
              key: const ValueKey('scan-refusal'),
              textAlign: TextAlign.center,
              style: context.text.titleMedium?.copyWith(color: p.danger),
            ),
          ),
          const Gap(AppSpace.lg),
          FilledButton(onPressed: () => Navigator.of(context).pop(), child: Text(t.scanAnother)),
        ],
      ),
    );
  }
}

/// The hand-over of a valid pass: the items with what is left of each (all by default; fewer to hand over part now), the money to collect
/// first when the order is paid at pickup, and one button. The rest of a partial hand-over stays reserved for the next visit.
class PickupHandoverForm extends ConsumerStatefulWidget {
  const PickupHandoverForm({super.key, required this.check});
  final PickupCheck check;

  @override
  ConsumerState<PickupHandoverForm> createState() => _PickupHandoverFormState();
}

class _PickupHandoverFormState extends ConsumerState<PickupHandoverForm> {
  late final Map<String, int> _qty = {for (final i in widget.check.items) i.id: i.left};
  final _receipt = TextEditingController();
  String _tender = 'cash';
  bool _busy = false;

  @override
  void dispose() {
    _receipt.dispose();
    super.dispose();
  }

  int get _selected => _qty.values.fold(0, (a, b) => a + b);
  bool get _everything => widget.check.items.every((i) => (_qty[i.id] ?? 0) == i.left);

  Future<void> _handOver() async {
    if (_busy || _selected == 0 || !ref.read(staffRightsProvider).canPickupApprove) return;
    final check = widget.check;
    setState(() => _busy = true);
    String? status;
    final ok = await staffGuarded(context, () async {
      status = await ref
          .read(opsRepositoryProvider)
          .pickupHandover(
            check.reservationId!,
            items: {
              for (final i in check.items)
                if ((_qty[i.id] ?? 0) > 0) i.id: _qty[i.id]!,
            },
            collectorName: check.customerName,
            collection: check.collects
                ? PickupCollection(
                    tender: _tender,
                    amount: check.amountDue,
                    receiptReference: _tender == 'card_terminal' ? _receipt.text : null,
                  )
                : null,
          );
    });
    if (!mounted) return;
    if (ok) {
      Navigator.of(context).pop(status);
    } else {
      setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final check = widget.check;
    final p = context.palette;
    // Checking a pass is for everyone at the counter; handing over needs the right to (pickup.approve at this branch).
    final may = ref.watch(staffRightsProvider.select((r) => r.canPickupApprove));
    return SidePanelLayout(
      footer: FilledButton(
        key: const ValueKey('handover-confirm'),
        onPressed: _busy || _selected == 0 || !may ? null : _handOver,
        child: _busy
            ? SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(strokeWidth: 2.2, color: Theme.of(context).colorScheme.onPrimary),
              )
            : Text(
                check.collects
                    ? '${t.collectFirst} · ${_everything ? t.handOverAll : t.handOverSelected}'
                    : (_everything ? t.handOverAll : t.handOverSelected),
                textAlign: TextAlign.center,
              ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (!may)
            Padding(
              padding: const EdgeInsets.only(bottom: AppSpace.md),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(Icons.lock_outline_rounded, size: 18, color: p.muted),
                  const Gap(AppSpace.xs),
                  Expanded(
                    child: Text(
                      t.handOverNeedsApproval,
                      key: const ValueKey('handover-no-right'),
                      style: context.text.bodyMedium,
                    ),
                  ),
                ],
              ),
            ),
          PanelSection(
            title: t.customerLabel,
            children: [Text(check.customerName, style: context.text.titleMedium)],
          ),
          PanelSection(
            title: t.handOverItems,
            children: [
              for (final item in check.items) ...[
                _ItemRow(
                  item: item,
                  value: _qty[item.id] ?? 0,
                  onChanged: _busy ? null : (v) => setState(() => _qty[item.id] = v),
                ),
                if (item != check.items.last) const Divider(height: AppSpace.md),
              ],
              if (_selected == 0)
                Padding(
                  padding: const EdgeInsets.only(top: AppSpace.xs),
                  child: Text(t.nothingSelected, style: context.text.bodySmall?.copyWith(color: p.danger)),
                ),
            ],
          ),
          if (check.collects)
            PanelSection(
              title: t.collectFirst,
              children: [
                Text(
                  t.money(check.amountDue),
                  key: const ValueKey('handover-amount'),
                  style: context.text.headlineSmall?.copyWith(
                    color: context.colors.primary,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                Text(
                  t.amountToCollect(t.money(check.amountDue)),
                  style: context.text.bodySmall?.copyWith(color: p.muted),
                ),
                const Gap(AppSpace.sm),
                Text(t.tender, style: context.text.labelMedium?.copyWith(color: p.muted)),
                const Gap(AppSpace.xxs),
                SegmentedButton<String>(
                  showSelectedIcon: false,
                  style: SegmentedButton.styleFrom(minimumSize: const Size(0, kMinTouchTarget)),
                  segments: [
                    ButtonSegment(value: 'cash', icon: const Icon(Icons.payments_outlined), label: Text(t.tenderCash)),
                    ButtonSegment(
                      value: 'card_terminal',
                      icon: const Icon(Icons.credit_card_rounded),
                      label: Text(t.tenderCard),
                    ),
                  ],
                  selected: {_tender},
                  onSelectionChanged: _busy ? null : (s) => setState(() => _tender = s.first),
                ),
                if (_tender == 'card_terminal') ...[
                  const Gap(AppSpace.sm),
                  TextField(
                    controller: _receipt,
                    enabled: !_busy,
                    maxLength: 120,
                    textDirection: TextDirection.ltr,
                    decoration: InputDecoration(labelText: t.receiptReference),
                  ),
                ],
              ],
            ),
        ],
      ),
    );
  }
}

class _ItemRow extends StatelessWidget {
  const _ItemRow({required this.item, required this.value, required this.onChanged});
  final PickupItem item;
  final int value;
  final ValueChanged<int>? onChanged;

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final p = context.palette;
    final name = item.variant == null || item.variant!.isEmpty ? item.name : '${item.name} · ${item.variant}';
    return Row(
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(name, style: context.text.bodyLarge),
              Text(
                [t.leftToHand(item.left), if (item.handedOver > 0) t.alreadyHanded(item.handedOver)].join(' · '),
                style: context.text.bodySmall?.copyWith(color: p.muted),
              ),
            ],
          ),
        ),
        QuantityStepper(
          value: value,
          min: 0,
          max: item.left,
          onChanged: item.left == 0 ? null : onChanged,
          compact: true,
        ),
      ],
    );
  }
}
