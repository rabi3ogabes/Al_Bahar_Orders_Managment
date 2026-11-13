import 'dart:async';
import 'dart:io';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/map/map_nav.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/dirty_form_guard.dart';
import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/ops/driver/driver_providers.dart';
import 'package:albahar_customer/ops/ops_input.dart';
import 'package:albahar_customer/ops/ops_live.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/ops_widgets.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart';

/// What the delivery window asks its opener to do after it has closed (the map is a full screen of its own, so the
/// window closes first and the opener pushes it).
enum JobPanelResult { openMap }

/// Remembers that the driver finished the delivery from inside the window, so the window says "Delivery recorded"
/// when the job leaves the list, instead of "no longer in your list".
class _DeliveryOutcome {
  bool delivered = false;
}

/// One delivery in a window beside the list: customer, address, payment and (for an active job) the step buttons
/// pinned at the bottom. The list behind keeps its place; one Back closes the window.
///
/// The record stays live: a step taken here, a dispatcher's change or a real-time ping updates the window, and when the
/// delivery leaves the active list the window closes itself and says why.
Future<JobPanelResult?> showJobPanel(BuildContext context, {required DriverJob job, required bool readOnly}) {
  final outcome = _DeliveryOutcome();
  return showSidePanel<JobPanelResult>(
    context: context,
    title: OpsStrings.of(context).jobTitle(job.orderNumber),
    scrollable: false,
    builder: (_) => LiveRecord<DriverJob>(
      initial: job,
      source: driverLiveJobsProvider(readOnly),
      isSame: (j) => j.id == job.id,
      goneMessage: (c) => outcome.delivered ? OpsStrings.of(c).deliveryRecorded : OpsStrings.of(c).jobGone,
      builder: (_, current) => SidePanelLayout(
        footer: readOnly
            ? null
            : JobActionButtons(
                key: ValueKey(current.id),
                job: current,
                inPanel: true,
                onDelivered: () => outcome.delivered = true,
              ),
        child: _JobDetails(job: current, readOnly: readOnly),
      ),
    ),
  );
}

class _JobDetails extends ConsumerWidget {
  const _JobDetails({required this.job, required this.readOnly});
  final DriverJob job;
  final bool readOnly;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = OpsStrings.of(context);
    final p = context.palette;
    final when = job.scheduledFor == null
        ? null
        : QatarTime.dateTime(job.scheduledFor, Localizations.localeOf(context).languageCode);
    final address = t.address(job.address);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.only(bottom: AppSpace.md),
          child: Wrap(
            spacing: AppSpace.xs,
            runSpacing: AppSpace.xs,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              StatusChip(label: t.statusLabel(job.status), tone: toneForOpsStatus(job.status)),
              if (when != null && !readOnly)
                StatusChip(label: t.scheduledFor(when), icon: Icons.schedule_rounded, tone: StatusTone.neutral),
            ],
          ),
        ),
        PanelSection(
          title: t.customer,
          children: [
            Text(job.customerName, style: context.text.titleMedium),
            if (job.customerPhone != null)
              Padding(
                padding: const EdgeInsets.only(top: 2),
                child: Directionality(
                  textDirection: TextDirection.ltr,
                  child: Text(job.customerPhone!, style: context.text.bodyMedium),
                ),
              ),
            if (!readOnly && job.customerPhone != null) ...[
              const Gap(AppSpace.sm),
              SizedBox(
                width: double.infinity,
                child: OutlinedButton.icon(
                  onPressed: () => dial(context, job.customerPhone),
                  icon: const Icon(Icons.call_rounded, size: 18),
                  label: Text('${t.call} ${job.customerName}', maxLines: 1, overflow: TextOverflow.ellipsis),
                ),
              ),
            ],
          ],
        ),
        PanelSection(
          title: t.deliveryAddress,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(Icons.place_outlined, size: 20, color: p.muted),
                const Gap(AppSpace.xs),
                Expanded(child: Text(address, style: context.text.bodyLarge)),
              ],
            ),
            if (job.address.notes != null)
              Padding(
                padding: const EdgeInsetsDirectional.only(top: AppSpace.xs, start: 28),
                child: Text(job.address.notes!, style: context.text.bodyMedium?.copyWith(color: p.muted)),
              ),
            if (job.branchName.isNotEmpty)
              Padding(
                padding: const EdgeInsetsDirectional.only(top: AppSpace.xs, start: 28),
                child: Text(t.pickupFrom(job.branchName), style: context.text.bodySmall),
              ),
            if (!readOnly) ...[
              const Gap(AppSpace.sm),
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: () => openMaps(
                        context,
                        lat: job.address.lat,
                        lng: job.address.lng,
                        query: job.address.line,
                        launcher: ref.read(navigationLauncherProvider),
                      ),
                      icon: const Icon(Icons.navigation_rounded, size: 18),
                      label: Text(t.navigate, maxLines: 1, overflow: TextOverflow.ellipsis),
                    ),
                  ),
                  const Gap(AppSpace.xs),
                  Expanded(
                    child: CopyTextButton(text: address, label: t.copyAddress),
                  ),
                ],
              ),
              const Gap(AppSpace.xs),
              SizedBox(
                width: double.infinity,
                child: OutlinedButton.icon(
                  onPressed: () => Navigator.of(context).pop(JobPanelResult.openMap),
                  icon: const Icon(Icons.map_outlined, size: 18),
                  label: Text(t.openJobMap, maxLines: 1, overflow: TextOverflow.ellipsis),
                ),
              ),
            ],
          ],
        ),
        PanelItems(items: job.items),
        PanelSection(
          title: t.payment,
          children: [
            if (job.isCod) ...[
              Text(
                t.money(job.codAmount.toStringAsFixed(2)),
                style: context.text.headlineSmall?.copyWith(fontWeight: FontWeight.w700),
              ),
              Text(t.paymentCash, style: context.text.bodyMedium),
              if (!readOnly)
                Padding(
                  padding: const EdgeInsets.only(top: 2),
                  child: Text(t.collectCashHint, style: context.text.bodySmall),
                ),
            ] else
              Row(
                children: [
                  Icon(Icons.check_circle_outline_rounded, size: 20, color: p.success),
                  const Gap(AppSpace.xs),
                  Expanded(
                    child: Text(
                      job.paymentMethod == 'cod' ? t.nothingToCollect : t.paymentOnline,
                      style: context.text.bodyLarge,
                    ),
                  ),
                ],
              ),
            if (!readOnly && job.requiresProof)
              Padding(
                padding: const EdgeInsets.only(top: AppSpace.sm),
                child: Row(
                  children: [
                    Icon(Icons.photo_camera_outlined, size: 18, color: p.muted),
                    const Gap(AppSpace.xs),
                    Expanded(child: Text(t.proofNeeded, style: context.text.bodySmall)),
                  ],
                ),
              ),
          ],
        ),
      ],
    );
  }
}

/// The step buttons of one delivery: the next step (accept, picked up, on my way, mark delivered), "could not deliver"
/// and "return to branch". The same buttons sit on the job card, under the delivery map and pinned in the detail
/// window, so a step is one tap wherever the driver is.
///
/// One action at a time. The lists reload whether it worked or not: a refused step (the job was reassigned or already
/// moved) leaves the screen showing the truth instead of a stale button. The refusal shows as a toast inside a window
/// and as a snackbar on a page (the shared snack helpers pick the right one).
class JobActionButtons extends ConsumerStatefulWidget {
  const JobActionButtons({super.key, required this.job, this.inPanel = false, this.onDelivered});
  final DriverJob job;

  /// Drawn in a side window: stacked, full width.
  final bool inPanel;

  /// Called when the delivery was recorded (the proof window finished). Without it a "Delivery recorded" message shows.
  final VoidCallback? onDelivered;

  @override
  ConsumerState<JobActionButtons> createState() => _JobActionButtonsState();
}

class _JobActionButtonsState extends ConsumerState<JobActionButtons> {
  bool _busy = false;

  void _refresh() {
    ref
      ..invalidate(driverJobsProvider(false))
      ..invalidate(driverJobsProvider(true))
      ..invalidate(driverMeProvider);
  }

  Future<void> _run(Future<void> Function() action) async {
    if (_busy) return;
    setState(() => _busy = true);
    await guarded(context, action);
    if (!mounted) return;
    _refresh();
    setState(() => _busy = false);
  }

  Future<void> _step(String action) async {
    final location = ref.read(locationServiceProvider);
    await _run(() async {
      final pos = action == 'out-for-delivery'
          ? await location.currentPosition().timeout(const Duration(seconds: 4), onTimeout: () => null)
          : null;
      await ref.read(opsRepositoryProvider).jobStep(widget.job.id, action, lat: pos?.lat, lng: pos?.lng);
    });
  }

  Future<void> _deliver() async {
    final done = await showDeliverPanel(context, widget.job);
    if (done != true || !mounted) return;
    // Tell the window first: when the job leaves the list it closes with the right message.
    final onDelivered = widget.onDelivered;
    if (onDelivered != null) {
      onDelivered();
    } else {
      showInfoSnack(context, OpsStrings.of(context).deliveryRecorded);
    }
    _refresh();
  }

  Future<void> _fail() async {
    final t = OpsStrings.of(context);
    final reason = await _pickReason(context);
    if (reason == null || !mounted) return;
    final sure = await opsConfirm(
      context,
      title: t.confirmFailQ,
      body: t.confirmFailBody(t.reasonLabel(reason)),
      verb: t.reportVerb,
    );
    if (!sure || !mounted) return;
    await _run(() => ref.read(opsRepositoryProvider).fail(widget.job.id, reason));
  }

  Future<void> _returnToBranch() async {
    final t = OpsStrings.of(context);
    final sure = await opsConfirm(context, title: t.confirmReturnQ, body: t.confirmReturnBody, verb: t.confirmReturn);
    if (!sure || !mounted) return;
    await _run(() => ref.read(opsRepositoryProvider).returnToBranch(widget.job.id));
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final job = widget.job;
    final next = job.nextAction;
    final retry = job.status == 'failed' || job.status == 'rescheduled';
    final label = switch (next) {
      'accept' => t.accept,
      'pickup' => t.pickedUp,
      'out-for-delivery' => retry ? t.retryDelivery : t.onTheWay,
      'deliver' => t.markDelivered,
      _ => null,
    };
    final panel = widget.inPanel;
    if (label == null && !job.canReturn) return const SizedBox.shrink();
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (label != null) ...[
          if (!panel) const Gap(AppSpace.sm),
          Row(
            children: [
              Expanded(
                child: FilledButton(
                  onPressed: _busy ? null : () => next == 'deliver' ? _deliver() : _step(next!),
                  child: _busy
                      ? SizedBox(
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(
                            strokeWidth: 2.2,
                            color: Theme.of(context).colorScheme.onPrimary,
                          ),
                        )
                      : Text(label, textAlign: TextAlign.center),
                ),
              ),
              if (job.canFail && !panel) ...[
                const Gap(AppSpace.xs),
                IconButton.outlined(
                  tooltip: t.failed,
                  onPressed: _busy ? null : _fail,
                  icon: const Icon(Icons.report_gmailerrorred_rounded),
                ),
              ],
            ],
          ),
        ],
        if (job.canFail && panel) ...[
          if (label != null) const Gap(AppSpace.xs),
          OutlinedButton.icon(
            onPressed: _busy ? null : _fail,
            icon: const Icon(Icons.report_gmailerrorred_rounded, size: 18),
            label: Text(t.failed),
          ),
        ],
        if (job.canReturn) ...[
          if (!panel || label != null) const Gap(AppSpace.xs),
          OutlinedButton.icon(
            onPressed: _busy ? null : _returnToBranch,
            icon: const Icon(Icons.assignment_return_rounded, size: 18),
            label: Text(t.returnToBranch),
          ),
        ],
      ],
    );
  }
}

/// Why the delivery failed: one tap on a reason, then the opener asks for a confirmation.
Future<String?> _pickReason(BuildContext context) {
  final t = OpsStrings.of(context);
  return showSidePanel<String>(
    context: context,
    title: t.failed,
    subtitle: t.chooseReason,
    builder: (ctx) => Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final r in const [
          'customer_unreachable',
          'customer_absent',
          'wrong_address',
          'refused',
          'access_denied',
          'other',
        ])
          ListTile(
            minTileHeight: 56,
            contentPadding: EdgeInsets.zero,
            title: Text(t.reasonLabel(r)),
            trailing: const Icon(Icons.chevron_right_rounded),
            onTap: () => Navigator.of(ctx).pop(r),
          ),
      ],
    ),
  );
}

/// Proof of delivery in a window: who received it, the cash (cash on delivery) and a photo, with "Confirm delivery"
/// pinned at the bottom. The upload happens inside the window, so a network failure keeps the photo and the typed text
/// for another try instead of losing them. It is a form: a tap outside or a swipe never closes it, and closing it with
/// input in it asks "Discard changes?". Resolves to true once the delivery is recorded.
Future<bool?> showDeliverPanel(BuildContext context, DriverJob job) {
  final t = OpsStrings.of(context);
  return showSidePanel<bool>(
    context: context,
    title: t.proofTitle,
    subtitle: '#${job.orderNumber} · ${job.customerName}',
    scrollable: false,
    isForm: true,
    barrierDismissible: false,
    builder: (_) => _DeliverForm(job: job),
  );
}

class _DeliverForm extends ConsumerStatefulWidget {
  const _DeliverForm({required this.job});
  final DriverJob job;

  @override
  ConsumerState<_DeliverForm> createState() => _DeliverFormState();
}

class _DeliverFormState extends ConsumerState<_DeliverForm> {
  final _recipient = TextEditingController();
  late final _cash = TextEditingController(text: widget.job.codAmount.toStringAsFixed(2));
  File? _photo;
  String? _error;
  bool _busy = false;

  bool get _dirty => _photo != null || _recipient.text.trim().isNotEmpty;

  @override
  void dispose() {
    _recipient.dispose();
    _cash.dispose();
    super.dispose();
  }

  Future<void> _shoot() async {
    final t = OpsStrings.of(context);
    try {
      final shot = await ref
          .read(opsImagePickerProvider)
          .pickImage(source: ImageSource.camera, maxWidth: 1600, imageQuality: 80);
      if (shot != null && mounted) {
        setState(() {
          _photo = File(shot.path);
          _error = null;
        });
      }
    } catch (_) {
      // Camera permission refused or no camera: say what to do instead of failing silently.
      if (mounted) setState(() => _error = t.photoFailed);
    }
  }

  Future<void> _submit() async {
    if (_busy) return;
    final t = OpsStrings.of(context);
    final job = widget.job;
    if (job.requiresProof && _photo == null) {
      setState(() => _error = t.photoRequired);
      return;
    }
    double? cod;
    if (job.isCod) {
      final text = _cash.text.trim();
      cod = parseAmount(text);
      if (text.isEmpty) {
        setState(() => _error = t.cashRequired);
        return;
      }
      if (cod == null) {
        setState(() => _error = t.cashInvalid);
        return;
      }
      if (cod > job.codAmount + 0.004) {
        setState(() => _error = t.cashTooMuch(job.codAmount.toStringAsFixed(2)));
        return;
      }
    }
    FocusScope.of(context).unfocus();
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref
          .read(opsRepositoryProvider)
          .deliver(job.id, recipient: _recipient.text.trim(), proof: _photo, codAmount: cod);
      if (mounted) Navigator.of(context).pop(true);
    } catch (e) {
      // The window cannot close while this runs, so it is still here: show why and keep everything that was typed.
      if (mounted) {
        setState(() {
          _busy = false;
          _error = describeError(context.l10n, e);
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final p = context.palette;
    final job = widget.job;
    return PopScope(
      canPop: !_dirty && !_busy,
      onPopInvokedWithResult: (didPop, _) async {
        if (didPop || _busy) return;
        if (await confirmDiscard(context) && context.mounted) Navigator.of(context).pop(false);
      },
      child: SidePanelLayout(
        footer: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (_error != null)
              Padding(
                padding: const EdgeInsets.only(bottom: AppSpace.xs),
                child: Semantics(
                  liveRegion: true,
                  child: Text(_error!, style: TextStyle(color: p.danger)),
                ),
              ),
            FilledButton(
              onPressed: _busy ? null : _submit,
              child: _busy
                  ? SizedBox(
                      width: 22,
                      height: 22,
                      child: CircularProgressIndicator(
                        strokeWidth: 2.4,
                        color: Theme.of(context).colorScheme.onPrimary,
                      ),
                    )
                  : Text(t.confirmDelivery),
            ),
          ],
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (job.isCod)
              Padding(
                padding: const EdgeInsets.only(bottom: AppSpace.md),
                child: Align(
                  alignment: AlignmentDirectional.centerStart,
                  child: StatusChip(
                    label: '${t.cod}: ${t.money(job.codAmount.toStringAsFixed(2))}',
                    tone: StatusTone.warning,
                    icon: Icons.payments_outlined,
                  ),
                ),
              ),
            TextField(
              controller: _recipient,
              enabled: !_busy,
              maxLength: 80,
              textCapitalization: TextCapitalization.words,
              textInputAction: job.isCod ? TextInputAction.next : TextInputAction.done,
              autofillHints: const [AutofillHints.name],
              onChanged: (_) => setState(() {}),
              decoration: InputDecoration(labelText: t.receivedBy),
            ),
            if (job.isCod) ...[
              const Gap(AppSpace.sm),
              TextField(
                controller: _cash,
                enabled: !_busy,
                maxLength: 12,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                inputFormatters: const [AmountInputFormatter()],
                textInputAction: TextInputAction.done,
                decoration: InputDecoration(labelText: '${t.cashCollected} (${t.currency})'),
              ),
            ],
            const Gap(AppSpace.sm),
            OutlinedButton.icon(
              onPressed: _busy ? null : _shoot,
              icon: Icon(_photo == null ? Icons.photo_camera_outlined : Icons.check_circle_rounded),
              label: Text(_photo == null ? t.takePhoto : t.photoTaken),
            ),
            if (job.requiresProof && _photo == null)
              Padding(
                padding: const EdgeInsets.only(top: AppSpace.xs),
                child: Text(t.proofNeeded, style: context.text.bodySmall?.copyWith(color: p.muted)),
              ),
            if (_photo != null)
              Padding(
                padding: const EdgeInsets.only(top: AppSpace.xs),
                child: Align(
                  alignment: AlignmentDirectional.centerStart,
                  child: ClipRRect(
                    borderRadius: BorderRadius.circular(AppRadius.input),
                    child: Image.file(
                      _photo!,
                      width: 96,
                      height: 96,
                      fit: BoxFit.cover,
                      cacheWidth: 288,
                      errorBuilder: (_, _, _) => SizedBox(
                        width: 96,
                        height: 96,
                        child: Center(child: Text(t.photoBroken, textAlign: TextAlign.center)),
                      ),
                    ),
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}
