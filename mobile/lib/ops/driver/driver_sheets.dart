import 'dart:async';
import 'dart:io';

import 'package:albahar_customer/core/network/interceptors.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/dirty_form_guard.dart';
import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/ops/driver/driver_strings.dart';
import 'package:albahar_customer/ops/driver/driver_support.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:albahar_customer/ops/ops_status_style.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/ops_widgets.dart';
import 'package:albahar_customer/ops/slide_to_confirm.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart';

/// The two forms a driver fills when a job does not go as planned: handing a job back before he accepted it, and "could not deliver".
/// Both are the same shape: big tiles for the reason, a note, and one confirm (a button, or the slide when the dashboard asks for it). Each
/// keeps what was typed when the network fails, blocks a second tap while it works, and sends one idempotency key for all its retries.

/// A big tile that is one choice out of a few (a reason): at least 64 dp high, an icon, the words, and a tick when chosen.
class ReasonTile extends StatelessWidget {
  const ReasonTile({super.key, required this.icon, required this.label, required this.selected, required this.onTap});
  final IconData icon;
  final String label;
  final bool selected;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final p = context.palette;
    return Semantics(
      button: true,
      selected: selected,
      inMutuallyExclusiveGroup: true,
      label: label,
      excludeSemantics: true,
      child: Material(
        color: selected ? p.tint : scheme.surface,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(AppRadius.input),
          side: BorderSide(color: selected ? scheme.primary : scheme.outlineVariant, width: selected ? 2 : 1),
        ),
        child: InkWell(
          borderRadius: BorderRadius.circular(AppRadius.input),
          onTap: onTap,
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 64),
            child: Padding(
              padding: const EdgeInsetsDirectional.symmetric(horizontal: AppSpace.sm, vertical: AppSpace.xs),
              child: Row(
                children: [
                  Icon(icon, size: 24, color: selected ? scheme.primary : p.muted),
                  const Gap(AppSpace.xs),
                  Expanded(
                    child: Text(
                      label,
                      style: context.text.titleSmall?.copyWith(
                        fontWeight: selected ? FontWeight.w700 : FontWeight.w600,
                      ),
                    ),
                  ),
                  if (selected) Icon(Icons.check_circle_rounded, size: 22, color: scheme.primary),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// The confirm of a sheet: the slide when the dashboard asks for it, otherwise a big button that shows progress and ignores a second tap.
class SheetConfirm extends ConsumerWidget {
  const SheetConfirm({
    super.key,
    required this.label,
    required this.slideLabel,
    required this.busy,
    required this.onConfirm,
    this.destructive = false,
  });
  final String label;
  final String slideLabel;
  final bool busy;
  final VoidCallback onConfirm;
  final bool destructive;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = OpsStrings.of(context);
    if (ref.watch(opsStatusStyleProvider) == OpsStatusStyle.slide) {
      return SlideToConfirm(label: slideLabel, busyLabel: t.updating, loading: busy, onConfirmed: onConfirm);
    }
    final scheme = Theme.of(context).colorScheme;
    return FilledButton(
      style: destructive
          ? FilledButton.styleFrom(backgroundColor: scheme.error, foregroundColor: scheme.onError)
          : null,
      onPressed: busy ? null : onConfirm,
      child: busy
          ? SizedBox(
              width: 22,
              height: 22,
              child: CircularProgressIndicator(
                strokeWidth: 2.4,
                color: destructive ? scheme.onError : scheme.onPrimary,
              ),
            )
          : Text(label, textAlign: TextAlign.center),
    );
  }
}

// ── Handing a job back ─────────────────────────────────────────────────────────────────────────────────────────────────────

/// "I can't take this": the driver chooses why, and the job goes back to the branch to be given to someone else. Resolves to true once the
/// server took it back.
Future<bool?> showDeclineSheet(BuildContext context, DriverJob job) {
  final t = OpsStrings.of(context);
  return showSidePanel<bool>(
    context: context,
    title: t.declineTitle,
    subtitle: '#${job.orderNumber} · ${t.declineSubtitle}',
    scrollable: false,
    isForm: true,
    barrierDismissible: false,
    builder: (_) => _DeclineForm(job: job),
  );
}

const _declineReasons = <(String, IconData)>[
  ('too_far', Icons.near_me_disabled_outlined),
  ('vehicle', Icons.local_shipping_outlined),
  ('shift_ending', Icons.schedule_rounded),
  ('other', Icons.more_horiz_rounded),
];

class _DeclineForm extends ConsumerStatefulWidget {
  const _DeclineForm({required this.job});
  final DriverJob job;

  @override
  ConsumerState<_DeclineForm> createState() => _DeclineFormState();
}

class _DeclineFormState extends ConsumerState<_DeclineForm> {
  final _note = TextEditingController();
  String? _reason;
  String? _error;
  bool _busy = false;

  bool get _dirty => _reason != null || _note.text.trim().isNotEmpty;

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_busy) return;
    final t = OpsStrings.of(context);
    final reason = _reason;
    if (reason == null) {
      setState(() => _error = t.chooseWhyFirst);
      return;
    }
    FocusScope.of(context).unfocus();
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref.read(opsRepositoryProvider).decline(widget.job.id, reason, note: _note.text);
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
              label: t.declineVerb,
              slideLabel: t.slideDecline,
              busy: _busy,
              onConfirm: () => unawaited(_submit()),
            ),
          ],
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            for (final (code, icon) in _declineReasons) ...[
              ReasonTile(
                key: ValueKey('decline-$code'),
                icon: icon,
                label: t.declineReason(code),
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
            const Gap(AppSpace.xxs),
            TextField(
              controller: _note,
              enabled: !_busy,
              maxLength: 300,
              maxLines: 2,
              minLines: 1,
              textCapitalization: TextCapitalization.sentences,
              onChanged: (_) => setState(() {}),
              decoration: InputDecoration(labelText: t.declineNoteHint),
            ),
          ],
        ),
      ),
    );
  }
}

// ── Could not deliver ────────────────────────────────────────────────────────────────────────────────────────────────────

/// "Could not deliver": big tiles for what happened, a note (required when it is "Damaged" or "Other"), an optional photo of the door or the
/// damage, and one confirm. The driver's position goes along when the phone knows it. Resolves to true once the server recorded the attempt.
Future<bool?> showFailSheet(BuildContext context, DriverJob job) {
  final t = OpsStrings.of(context);
  return showSidePanel<bool>(
    context: context,
    title: t.failed,
    subtitle: '#${job.orderNumber} · ${job.customerName}',
    scrollable: false,
    isForm: true,
    barrierDismissible: false,
    builder: (_) => _FailForm(job: job),
  );
}

const _failReasons = <(String, IconData)>[
  ('customer_absent', Icons.person_off_outlined),
  ('customer_unreachable', Icons.phone_disabled_outlined),
  ('wrong_address', Icons.wrong_location_outlined),
  ('refused', Icons.do_not_disturb_on_outlined),
  ('access_denied', Icons.lock_outline_rounded),
  ('damaged', Icons.report_problem_outlined),
  ('other', Icons.more_horiz_rounded),
];

/// The reasons that need a few words from the driver, so the branch can tell what happened.
bool failNeedsNote(String? reason) => reason == 'other' || reason == 'damaged';

class _FailForm extends ConsumerStatefulWidget {
  const _FailForm({required this.job});
  final DriverJob job;

  @override
  ConsumerState<_FailForm> createState() => _FailFormState();
}

class _FailFormState extends ConsumerState<_FailForm> {
  final _note = TextEditingController();
  final _noteFocus = FocusNode();

  /// One key for every try of this report: a tap repeated after a lost answer is the same report, not a second one.
  final _key = Idempotency.newKey();
  String? _reason;
  File? _photo;
  String? _error;
  bool _busy = false;

  bool get _dirty => _reason != null || _photo != null || _note.text.trim().isNotEmpty;

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
    final reason = _reason;
    if (reason == null) {
      setState(() => _error = t.chooseWhyFirst);
      return;
    }
    if (failNeedsNote(reason) && _note.text.trim().isEmpty) {
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
      final spot = await currentSpot(ref);
      await ref
          .read(opsRepositoryProvider)
          .fail(
            widget.job.id,
            reason,
            notes: _note.text,
            photo: _photo,
            lat: spot?.lat,
            lng: spot?.lng,
            accuracy: spot?.accuracy,
            idempotencyKey: _key,
          );
      if (mounted) Navigator.of(context).pop(true);
    } catch (e) {
      // The window cannot close while this runs, so it is still here: say why and keep everything that was typed and the photo.
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
    final needsNote = failNeedsNote(_reason);
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
              label: t.failVerb,
              slideLabel: t.slideReport,
              busy: _busy,
              onConfirm: () => unawaited(_submit()),
              destructive: true,
            ),
          ],
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsetsDirectional.only(start: 2, bottom: AppSpace.xs),
              child: Semantics(header: true, child: Text(t.failWhat, style: context.text.titleMedium)),
            ),
            LayoutBuilder(
              builder: (context, box) {
                final wide = box.maxWidth >= 340;
                final width = wide ? (box.maxWidth - AppSpace.xs) / 2 : box.maxWidth;
                return Wrap(
                  spacing: AppSpace.xs,
                  runSpacing: AppSpace.xs,
                  children: [
                    for (final (code, icon) in _failReasons)
                      SizedBox(
                        width: width,
                        child: ReasonTile(
                          key: ValueKey('fail-$code'),
                          icon: icon,
                          label: t.reasonLabel(code),
                          selected: _reason == code,
                          onTap: _busy
                              ? null
                              : () => setState(() {
                                  _reason = code;
                                  _error = null;
                                }),
                        ),
                      ),
                  ],
                );
              },
            ),
            const Gap(AppSpace.md),
            TextField(
              controller: _note,
              focusNode: _noteFocus,
              enabled: !_busy,
              maxLength: 500,
              maxLines: 3,
              minLines: 2,
              textCapitalization: TextCapitalization.sentences,
              onChanged: (_) => setState(() {}),
              decoration: InputDecoration(labelText: needsNote ? t.failNoteLabel : t.failNoteOptional),
            ),
            const Gap(AppSpace.xs),
            OutlinedButton.icon(
              key: const ValueKey('fail-photo'),
              onPressed: _busy ? null : _shoot,
              icon: Icon(_photo == null ? Icons.photo_camera_outlined : Icons.check_circle_rounded),
              label: Text(_photo == null ? t.addPhoto : t.failPhotoAdded),
            ),
            if (_photo == null)
              Padding(
                padding: const EdgeInsets.only(top: AppSpace.xs),
                child: Text(t.failPhotoHint, style: context.text.bodySmall?.copyWith(color: p.muted)),
              )
            else
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
