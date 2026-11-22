import 'dart:async';
import 'dart:io';

import 'package:albahar_customer/core/network/interceptors.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/dirty_form_guard.dart';
import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/ops/driver/driver_assembly.dart';
import 'package:albahar_customer/ops/driver/driver_job_view.dart';
import 'package:albahar_customer/ops/driver/driver_outbox.dart';
import 'package:albahar_customer/ops/driver/driver_outbox_provider.dart';
import 'package:albahar_customer/ops/driver/driver_outbox_strings.dart';
import 'package:albahar_customer/ops/driver/driver_outbox_widgets.dart';
import 'package:albahar_customer/ops/driver/driver_providers.dart';
import 'package:albahar_customer/ops/driver/driver_sheets.dart';
import 'package:albahar_customer/ops/driver/driver_strings.dart';
import 'package:albahar_customer/ops/driver/driver_support.dart';
import 'package:albahar_customer/ops/ops_input.dart';
import 'package:albahar_customer/ops/ops_live.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:albahar_customer/ops/ops_status_style.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/ops_widgets.dart';
import 'package:albahar_customer/ops/slide_to_confirm.dart';
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
      goneMessage: (c) => _goneMessage(c, job.id, delivered: outcome.delivered),
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

/// What the window says when its job leaves the list: "Delivery recorded" after the driver's own delivery, nothing when the driver is about to be
/// told in a dialog that the order was cancelled or taken away (one message, not two), otherwise "no longer in your list".
String? _goneMessage(BuildContext c, String jobId, {required bool delivered}) {
  final t = OpsStrings.of(c);
  if (delivered) return t.deliveryRecorded;
  final told = ProviderScope.containerOf(c).read(driverLiveProvider).notices.any((n) => n.deliveryId == jobId);
  return told ? null : t.jobGone;
}

class _JobDetails extends ConsumerWidget {
  const _JobDetails({required this.job, required this.readOnly});
  final DriverJob job;
  final bool readOnly;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = OpsStrings.of(context);
    final p = context.palette;
    final when = jobWhen(context, job);
    final address = t.address(job.address);
    final c = job.context;
    final phone = primaryPhone(job);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // WHAT and WHEN first: where the job stands and the window it must be delivered in.
        Padding(
          padding: const EdgeInsets.only(bottom: AppSpace.md),
          child: Wrap(
            spacing: AppSpace.xs,
            runSpacing: AppSpace.xs,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              StatusChip(label: t.statusLabel(job.status), tone: toneForOpsStatus(job.status)),
              if (when != null && !readOnly)
                StatusChip(label: when, icon: Icons.schedule_rounded, tone: StatusTone.neutral),
            ],
          ),
        ),
        // WHERE: who, which address, and the three big buttons.
        PanelSection(
          title: t.deliveryAddress,
          children: [
            Text(receiverName(job), style: context.text.titleLarge),
            if (!readOnly && phone != null)
              Padding(
                padding: const EdgeInsets.only(top: 2),
                child: Directionality(
                  textDirection: TextDirection.ltr,
                  child: Text(phone, style: context.text.bodyMedium?.copyWith(color: p.muted)),
                ),
              ),
            const Gap(AppSpace.sm),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(Icons.place_outlined, size: 22, color: p.muted),
                const Gap(AppSpace.xs),
                Expanded(
                  child: Text(address, style: context.text.titleMedium?.copyWith(fontWeight: FontWeight.w600)),
                ),
              ],
            ),
            if (c.landmark != null)
              Padding(
                padding: const EdgeInsetsDirectional.only(top: AppSpace.xxs, start: 30),
                child: Text('${t.landmark}: ${c.landmark}', style: context.text.bodyMedium),
              ),
            if (job.address.notes != null && job.address.notes != c.landmark)
              Padding(
                padding: const EdgeInsetsDirectional.only(top: AppSpace.xxs, start: 30),
                child: Text(job.address.notes!, style: context.text.bodyMedium?.copyWith(color: p.muted)),
              ),
            if (job.branchName.isNotEmpty)
              Padding(
                padding: const EdgeInsetsDirectional.only(top: AppSpace.xs, start: 30),
                child: Text(t.pickupFrom(job.branchName), style: context.text.bodySmall),
              ),
            if (!readOnly) ...[
              const Gap(AppSpace.md),
              JobQuickActions(job: job, address: address),
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
        if (!readOnly) ...[BeforeYouGo(job: job), JobNotesBox(job: job), JobContacts(job: job)],
        DriverItems(job: job),
        if (job.items.isEmpty) PanelItems(items: job.items),
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
                  child: Text(c.paymentNote ?? t.collectCashHint, style: context.text.bodySmall),
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

  /// Runs one step. When the server accepted it, the job shows its new state at once ([becomes], read once the step ran); the reads that follow
  /// confirm it.
  Future<bool> _run(Future<void> Function() action, {String? Function()? becomes}) async {
    if (_busy) return false;
    setState(() => _busy = true);
    final ok = await driverGuarded(context, action);
    if (!mounted) return false;
    final status = ok ? becomes?.call() : null;
    if (status != null) ref.read(driverLiveProvider.notifier).applyOwnStep(widget.job.id, status);
    _refresh();
    setState(() => _busy = false);
    return ok;
  }

  /// Accept, picked up, on the way: through the outbox, so a step pressed without signal is kept and sent later (the card moves at once and says
  /// "Waiting to send"); with signal it goes straight away, as before.
  Future<void> _step(String action) async {
    if (action == 'arrived') return _arrived();
    final location = ref.read(locationServiceProvider);
    final t = OpsStrings.of(context);
    var result = OutboxSubmit.sent;
    final ok = await _run(
      () async {
        final pos = action == 'out-for-delivery'
            ? await location.currentPosition().timeout(const Duration(seconds: 4), onTimeout: () => null)
            : null;
        result = await ref
            .read(driverOutboxProvider.notifier)
            .press(widget.job, action, key: Idempotency.newKey(), fields: {'lat': pos?.lat, 'lng': pos?.lng});
      },
      becomes: () => result == OutboxSubmit.sent
          ? switch (action) {
              'accept' => 'accepted',
              'pickup' => 'picked_up',
              'out-for-delivery' => 'out_for_delivery',
              _ => null,
            }
          : null,
    );
    if (ok && result == OutboxSubmit.queued && mounted) showInfoSnack(context, t.savedForLater);
  }

  /// True while some step of this job waits in the outbox (it was pressed without signal).
  bool get _waiting => outboxFor(ref.read(driverOutboxProvider), widget.job.id).isNotEmpty;

  /// "I have arrived": the customer is told once, and the card moves on to the delivery form at once.
  Future<void> _arrived() async {
    final t = OpsStrings.of(context);
    DateTime? at;
    final ok = await _run(() async {
      final spot = await currentSpot(ref);
      at = await ref
          .read(opsRepositoryProvider)
          .arrived(widget.job.id, lat: spot?.lat, lng: spot?.lng, accuracy: spot?.accuracy);
    });
    if (!ok || !mounted) return;
    ref.read(driverLiveProvider.notifier).markArrived(widget.job.id, at);
    Haptics.success();
    showInfoSnack(context, t.arrivedDone);
  }

  Future<void> _deliver() async {
    // A parcel with paid assembly is not delivered before the driver says whether it was done: the answer window comes first, then the delivery form.
    if (widget.job.context.assembly?.needsAnswer ?? false) {
      final answered = await showAssemblySheet(context, widget.job);
      if (answered != true || !mounted) return;
      _refresh();
    }
    final done = await showDeliverPanel(context, widget.job);
    if (done != true || !mounted) return;
    if (_waiting) {
      // No signal: the delivery is kept on the phone and the card says "Waiting to send" until the server has it.
      showInfoSnack(context, OpsStrings.of(context).savedForLater);
      return;
    }
    ref.read(driverLiveProvider.notifier).applyOwnStep(widget.job.id, 'delivered');
    // Tell the window first: when the job leaves the list it closes with the right message.
    final onDelivered = widget.onDelivered;
    if (onDelivered != null) {
      onDelivered();
    } else {
      showInfoSnack(context, OpsStrings.of(context).deliveryRecorded);
    }
    _refresh();
  }

  /// "Could not deliver": the sheet records the attempt itself (reason, note, photo, position), so a failed upload keeps everything typed.
  Future<void> _fail() async {
    final done = await showFailSheet(context, widget.job);
    if (done != true || !mounted) return;
    if (_waiting) {
      showInfoSnack(context, OpsStrings.of(context).savedForLater);
      return;
    }
    ref.read(driverLiveProvider.notifier).applyOwnStep(widget.job.id, 'failed');
    showInfoSnack(context, OpsStrings.of(context).failRecorded);
    _refresh();
  }

  /// "I can't take this": a reason, then the job goes back to the branch and leaves the list at once.
  Future<void> _decline() async {
    final done = await showDeclineSheet(context, widget.job);
    if (done != true || !mounted) return;
    ref.read(driverLiveProvider.notifier).applyOwnRemoval(widget.job.id);
    showInfoSnack(context, OpsStrings.of(context).declineDone);
    _refresh();
  }

  Future<void> _returnToBranch() async {
    final t = OpsStrings.of(context);
    final sure = await opsConfirm(context, title: t.confirmReturnQ, body: t.confirmReturnBody, verb: t.confirmReturn);
    if (!sure || !mounted) return;
    await _run(() => ref.read(opsRepositoryProvider).returnToBranch(widget.job.id), becomes: () => 'returned');
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
      'arrived' => t.iHaveArrived,
      'deliver' => t.markDelivered,
      _ => null,
    };
    final panel = widget.inPanel;
    // Steps of this job that wait in the outbox (or that the server refused) are said first, on the card and in its window.
    final hasStrip = outboxFor(ref.watch(driverOutboxProvider), job.id).isNotEmpty;
    final strip = OutboxJobStrip(deliveryId: job.id);
    if (label == null && !job.canReturn) {
      return hasStrip
          ? Padding(
              padding: EdgeInsets.only(top: panel ? 0 : AppSpace.sm),
              child: strip,
            )
          : const SizedBox.shrink();
    }
    // The dashboard chooses how a step is taken. "Mark delivered" stays a button even then: it opens the proof window, whose last
    // action (Confirm delivery) is the one that is slid.
    final slide = ref.watch(opsStatusStyleProvider) == OpsStatusStyle.slide && next != null && next != 'deliver';
    final slideLabel = next == 'arrived' ? t.slideArrived : t.slideDriverStep(next ?? '', retry: retry);
    // Before he says he arrived, the delivery form stays one tap away: if the arrival cannot be sent (no signal at the door) he is never stuck.
    final skipArrival = next == 'arrived';
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (hasStrip) ...[if (!panel) const Gap(AppSpace.sm), strip],
        // The paid assembly is answered here, one tap, before the delivery is confirmed.
        if (showsAssemblyStrip(job)) ...[if (!panel && !hasStrip) const Gap(AppSpace.sm), AssemblyStrip(job: job)],
        if (label != null) ...[
          if (!panel && !showsAssemblyStrip(job) && !hasStrip) const Gap(AppSpace.sm),
          Row(
            children: [
              Expanded(
                child: slide
                    ? SlideToConfirm(
                        key: ValueKey('slide-${job.id}-${job.status}-$next'),
                        label: slideLabel,
                        busyLabel: t.updating,
                        loading: _busy,
                        onConfirmed: () => _step(next),
                      )
                    : FilledButton(
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
        if (skipArrival) ...[
          const Gap(AppSpace.xxs),
          TextButton(
            key: const ValueKey('skip-arrival'),
            onPressed: _busy ? null : _deliver,
            child: Text(t.markDelivered),
          ),
        ],
        if (job.canDecline) ...[
          const Gap(AppSpace.xxs),
          TextButton.icon(
            key: const ValueKey('decline-job'),
            onPressed: _busy ? null : _decline,
            icon: const Icon(Icons.undo_rounded, size: 18),
            label: Text(t.cantTakeThis),
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

  /// One key for every try of this delivery: a tap repeated after a lost answer is the same delivery, not a second one.
  final _key = Idempotency.newKey();

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
      // Where the driver stands goes with the proof when the phone knows it; the delivery never waits for GPS (3 seconds at most) or fails without it.
      // Through the outbox: without signal it is kept on the phone (the photo copied into the app's private files) and sent later with this key.
      final spot = await currentSpot(ref);
      await ref
          .read(driverOutboxProvider.notifier)
          .press(
            job,
            'deliver',
            key: _key,
            photo: _photo,
            fields: {
              'recipient': _recipient.text.trim(),
              'cod': cod,
              'lat': spot?.lat,
              'lng': spot?.lng,
              'accuracy': spot?.accuracy,
            },
          );
      if (mounted) Navigator.of(context).pop(true);
    } catch (e) {
      // The window cannot close while this runs, so it is still here: show why and keep everything that was typed.
      if (mounted) {
        setState(() {
          _busy = false;
          _error = driverErrorText(context, e);
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
            if (ref.watch(opsStatusStyleProvider) == OpsStatusStyle.slide)
              // Recording a delivery (and the cash) is the step that must not happen by a stray tap: slide it. A missing photo or
              // amount springs the thumb back with the message above.
              SlideToConfirm(label: t.slideConfirmDelivery, busyLabel: t.updating, loading: _busy, onConfirmed: _submit)
            else
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
