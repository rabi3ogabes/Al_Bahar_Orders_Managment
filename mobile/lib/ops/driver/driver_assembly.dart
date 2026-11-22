import 'dart:async';
import 'dart:io';

import 'package:albahar_customer/core/network/interceptors.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/dirty_form_guard.dart';
import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/ops/driver/driver_outbox.dart';
import 'package:albahar_customer/ops/driver/driver_outbox_provider.dart';
import 'package:albahar_customer/ops/driver/driver_outbox_strings.dart';
import 'package:albahar_customer/ops/driver/driver_providers.dart';
import 'package:albahar_customer/ops/driver/driver_sheets.dart';
import 'package:albahar_customer/ops/driver/driver_strings.dart';
import 'package:albahar_customer/ops/driver/driver_support.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_status_style.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/ops_widgets.dart';
import 'package:albahar_customer/ops/slide_to_confirm.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart';

/// "Assembly done": the driver's answer for the paid assembly and installation of a parcel (docs/guides/DELIVERY_FOLLOW_UPS.md). It sits on the job card
/// (and in the job window) while the goods are with him, one tap or slide for "Assembly done" (the dashboard's step style), a way to add a note or a photo,
/// and "Not done" with a reason. A delivery that includes paid assembly cannot be confirmed before he answered; the server enforces it too.

const _reasons = <(String, IconData)>[
  ('customer_not_ready', Icons.hourglass_empty_rounded),
  ('missing_parts', Icons.build_circle_outlined),
  ('not_possible', Icons.block_rounded),
  ('no_time', Icons.timer_off_outlined),
  ('other', Icons.more_horiz_rounded),
];

/// Is the strip shown for this job? Paid assembly on a parcel that is with the driver.
bool showsAssemblyStrip(DriverJob job) =>
    job.context.assembly != null && (job.status == 'picked_up' || job.status == 'out_for_delivery');

/// Opens the answer window. [startNotDone] opens it on the "not done" side; an answer given before is shown (to change it). Resolves to true once the
/// server recorded the answer.
Future<bool?> showAssemblySheet(BuildContext context, DriverJob job, {bool startNotDone = false}) {
  final t = OpsStrings.of(context);
  return showSidePanel<bool>(
    context: context,
    title: t.assemblySheetTitle,
    subtitle: '#${job.orderNumber} · ${job.customerName}',
    scrollable: false,
    isForm: true,
    barrierDismissible: false,
    builder: (_) => _AssemblyForm(job: job, startNotDone: startNotDone),
  );
}

class AssemblyStrip extends ConsumerStatefulWidget {
  const AssemblyStrip({super.key, required this.job});
  final DriverJob job;

  @override
  ConsumerState<AssemblyStrip> createState() => _AssemblyStripState();
}

class _AssemblyStripState extends ConsumerState<AssemblyStrip> {
  bool _busy = false;
  final _key = Idempotency.newKey();

  void _refresh() {
    ref
      ..invalidate(driverJobsProvider(false))
      ..invalidate(driverJobsProvider(true));
  }

  /// The one-tap answer: done, with no note and no photo (through the outbox: without signal it is kept and sent later).
  Future<void> _done() async {
    if (_busy) return;
    setState(() => _busy = true);
    var result = OutboxSubmit.sent;
    final ok = await driverGuarded(context, () async {
      result = await ref
          .read(driverOutboxProvider.notifier)
          .press(widget.job, 'assembly', key: _key, fields: const {'status': 'done'});
    });
    if (!mounted) return;
    if (ok) {
      Haptics.success();
      final t = OpsStrings.of(context);
      showInfoSnack(context, result == OutboxSubmit.queued ? t.savedForLater : t.assemblySaved);
    }
    _refresh();
    setState(() => _busy = false);
  }

  Future<void> _sheet({bool notDone = false}) async {
    final saved = await showAssemblySheet(context, widget.job, startNotDone: notDone);
    if (saved != true || !mounted) return;
    final t = OpsStrings.of(context);
    final waiting = outboxFor(ref.read(driverOutboxProvider), widget.job.id).isNotEmpty;
    showInfoSnack(context, waiting ? t.savedForLater : t.assemblySaved);
    _refresh();
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final p = context.palette;
    final a = widget.job.context.assembly;
    if (a == null) return const SizedBox.shrink();
    final slide = ref.watch(opsStatusStyleProvider) == OpsStatusStyle.slide;
    final answered = a.isDone || a.isNotDone;
    return Container(
      key: const ValueKey('assembly-strip'),
      margin: const EdgeInsets.only(bottom: AppSpace.xs),
      padding: const EdgeInsets.all(AppSpace.sm),
      decoration: BoxDecoration(
        color: p.tint,
        borderRadius: BorderRadius.circular(AppRadius.input),
        border: Border.all(color: Theme.of(context).colorScheme.outlineVariant),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Icon(
                a.isNotDone ? Icons.report_gmailerrorred_outlined : Icons.handyman_outlined,
                size: 22,
                color: p.brass,
              ),
              const Gap(AppSpace.xs),
              Expanded(
                child: Semantics(
                  header: true,
                  liveRegion: true,
                  child: Text(
                    answered
                        ? (a.isDone
                              ? t.assemblyAnsweredDone
                              : t.assemblyAnsweredNotDone(a.reasonLabel ?? t.assemblyReason(a.reason ?? 'other')))
                        : t.assemblyStripTitle,
                    style: context.text.titleSmall?.copyWith(fontWeight: FontWeight.w700),
                  ),
                ),
              ),
              if (answered)
                TextButton(
                  key: const ValueKey('assembly-change'),
                  onPressed: _busy ? null : () => _sheet(notDone: a.isNotDone),
                  child: Text(t.assemblyChange),
                ),
            ],
          ),
          if (!answered) ...[
            const Gap(AppSpace.xxs),
            Text(t.assemblyStripHint, style: context.text.bodySmall?.copyWith(color: p.muted)),
            const Gap(AppSpace.xs),
            Row(
              children: [
                Expanded(
                  child: slide
                      ? SlideToConfirm(
                          key: const ValueKey('assembly-slide'),
                          label: t.slideAssemblyDone,
                          busyLabel: t.updating,
                          loading: _busy,
                          onConfirmed: _done,
                        )
                      : FilledButton.icon(
                          key: const ValueKey('assembly-done'),
                          onPressed: _busy ? null : _done,
                          icon: const Icon(Icons.check_rounded, size: 20),
                          label: Text(t.assemblyDoneVerb),
                        ),
                ),
                const Gap(AppSpace.xs),
                IconButton.outlined(
                  key: const ValueKey('assembly-details'),
                  tooltip: t.assemblyAddDetails,
                  onPressed: _busy ? null : () => _sheet(),
                  icon: const Icon(Icons.edit_note_rounded),
                ),
              ],
            ),
            Align(
              alignment: AlignmentDirectional.centerStart,
              child: TextButton(
                key: const ValueKey('assembly-not-done'),
                onPressed: _busy ? null : () => _sheet(notDone: true),
                child: Text(t.assemblyNotDoneVerb),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _AssemblyForm extends ConsumerStatefulWidget {
  const _AssemblyForm({required this.job, required this.startNotDone});
  final DriverJob job;
  final bool startNotDone;

  @override
  ConsumerState<_AssemblyForm> createState() => _AssemblyFormState();
}

class _AssemblyFormState extends ConsumerState<_AssemblyForm> {
  final _note = TextEditingController();
  final _noteFocus = FocusNode();

  /// One key for every try of this answer: a tap repeated after a lost answer is the same answer.
  final _key = Idempotency.newKey();
  late bool? _done = widget.startNotDone ? false : null;
  String? _reason;
  File? _photo;
  String? _error;
  bool _busy = false;

  bool get _dirty => _done != null || _photo != null || _note.text.trim().isNotEmpty;

  @override
  void initState() {
    super.initState();
    final a = widget.job.context.assembly;
    if (a != null && (a.isDone || a.isNotDone)) {
      _done = a.isDone;
      _reason = a.reason;
      _note.text = a.note ?? '';
    }
  }

  @override
  void dispose() {
    _note.dispose();
    _noteFocus.dispose();
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
      if (mounted) setState(() => _error = t.photoFailed);
    }
  }

  Future<void> _submit() async {
    if (_busy) return;
    final t = OpsStrings.of(context);
    final done = _done;
    if (done == null || (!done && _reason == null)) {
      setState(() => _error = t.assemblyChooseFirst);
      return;
    }
    if (!done && _reason == 'other' && _note.text.trim().isEmpty) {
      setState(() => _error = t.failNoteRequired);
      _noteFocus.requestFocus();
      return;
    }
    FocusScope.of(context).unfocus();
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final note = _note.text.trim();
      await ref
          .read(driverOutboxProvider.notifier)
          .press(
            widget.job,
            'assembly',
            key: _key,
            photo: _photo,
            fields: {
              'status': done ? 'done' : 'not_done',
              'reason': done ? null : _reason,
              'note': note.isEmpty ? null : note,
            },
          );
      if (mounted) Navigator.of(context).pop(true);
    } catch (e) {
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
    final done = _done;
    final needsNote = done == false && _reason == 'other';
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
            SheetConfirm(
              label: t.assemblySave,
              slideLabel: t.slideAssemblySave,
              busy: _busy,
              onConfirm: () => unawaited(_submit()),
            ),
          ],
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsetsDirectional.only(start: 2, bottom: AppSpace.xs),
              child: Semantics(header: true, child: Text(t.assemblyQuestion, style: context.text.titleMedium)),
            ),
            Row(
              children: [
                Expanded(
                  child: ReasonTile(
                    key: const ValueKey('assembly-choice-done'),
                    icon: Icons.check_circle_outline_rounded,
                    label: t.assemblyDoneVerb,
                    selected: done == true,
                    onTap: _busy
                        ? null
                        : () => setState(() {
                            _done = true;
                            _error = null;
                          }),
                  ),
                ),
                const Gap(AppSpace.xs),
                Expanded(
                  child: ReasonTile(
                    key: const ValueKey('assembly-choice-not-done'),
                    icon: Icons.highlight_off_rounded,
                    label: t.assemblyNotDoneVerb,
                    selected: done == false,
                    onTap: _busy
                        ? null
                        : () => setState(() {
                            _done = false;
                            _error = null;
                          }),
                  ),
                ),
              ],
            ),
            if (done == false) ...[
              const Gap(AppSpace.md),
              Padding(
                padding: const EdgeInsetsDirectional.only(start: 2, bottom: AppSpace.xs),
                child: Semantics(header: true, child: Text(t.assemblyWhyNot, style: context.text.titleSmall)),
              ),
              for (final (code, icon) in _reasons) ...[
                ReasonTile(
                  key: ValueKey('assembly-reason-$code'),
                  icon: icon,
                  label: t.assemblyReason(code),
                  selected: _reason == code,
                  onTap: _busy
                      ? null
                      : () => setState(() {
                          _reason = code;
                          _error = null;
                        }),
                ),
                const Gap(AppSpace.xs),
              ],
            ],
            if (done != null) ...[
              const Gap(AppSpace.sm),
              TextField(
                controller: _note,
                focusNode: _noteFocus,
                enabled: !_busy,
                maxLength: 300,
                maxLines: 3,
                minLines: 2,
                textCapitalization: TextCapitalization.sentences,
                onChanged: (_) => setState(() {}),
                decoration: InputDecoration(labelText: needsNote ? t.assemblyNoteRequiredLabel : t.assemblyNoteLabel),
              ),
              const Gap(AppSpace.xs),
              OutlinedButton.icon(
                key: const ValueKey('assembly-photo'),
                onPressed: _busy ? null : _shoot,
                icon: Icon(_photo == null ? Icons.photo_camera_outlined : Icons.check_circle_rounded),
                label: Text(_photo == null ? t.addPhoto : t.failPhotoAdded),
              ),
              if (_photo == null)
                Padding(
                  padding: const EdgeInsets.only(top: AppSpace.xs),
                  child: Text(t.assemblyPhotoHint, style: context.text.bodySmall?.copyWith(color: p.muted)),
                ),
            ],
          ],
        ),
      ),
    );
  }
}
