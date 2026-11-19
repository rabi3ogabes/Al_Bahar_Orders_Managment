import 'dart:async';

import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:albahar_customer/ops/ops_staff_models.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/staff/staff_errors.dart';
import 'package:albahar_customer/ops/staff/staff_providers.dart';
import 'package:albahar_customer/ops/staff/staff_strings.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// The manager's two answers to the customer's request to cancel: "Approve cancellation" (the order is cancelled) or "Keep the order" (the
/// request is declined). Each asks first, with an optional note, because neither can be undone. One at a time.
class CancellationDecision extends ConsumerStatefulWidget {
  const CancellationDecision({super.key, required this.request});
  final CancellationRequest request;

  @override
  ConsumerState<CancellationDecision> createState() => _CancellationDecisionState();
}

class _CancellationDecisionState extends ConsumerState<CancellationDecision> {
  bool _busy = false;

  Future<void> _decide({required bool approve}) async {
    if (_busy) return;
    final t = OpsStrings.of(context);
    final note = await showDialog<String>(
      context: context,
      builder: (_) => _DecisionDialog(approve: approve),
    );
    if (note == null || !mounted) return;
    setState(() => _busy = true);
    final ok = await staffGuarded(
      context,
      () => ref.read(opsRepositoryProvider).decideCancellation(widget.request.id, approve: approve, note: note),
    );
    if (!mounted) return;
    if (ok) showInfoSnack(context, approve ? t.cancellationApproved : t.cancellationDeclined);
    // Whether it worked or a colleague decided first, the lists show the real state.
    refreshStaff(ref);
    setState(() => _busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final p = context.palette;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        FilledButton.tonalIcon(
          key: const ValueKey('cancellation-keep'),
          onPressed: _busy ? null : () => unawaited(_decide(approve: false)),
          icon: const Icon(Icons.undo_rounded, size: 18),
          label: Text(t.keepOrder, maxLines: 1, overflow: TextOverflow.ellipsis),
        ),
        const Gap(AppSpace.xs),
        FilledButton.icon(
          key: const ValueKey('cancellation-approve'),
          style: FilledButton.styleFrom(backgroundColor: p.danger, foregroundColor: Colors.white),
          onPressed: _busy ? null : () => unawaited(_decide(approve: true)),
          icon: _busy
              ? const SizedBox(
                  width: 18,
                  height: 18,
                  child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                )
              : const Icon(Icons.cancel_outlined, size: 18),
          label: Text(t.approveCancellation, maxLines: 1, overflow: TextOverflow.ellipsis),
        ),
      ],
    );
  }
}

/// The question before a decision, with a note the manager may add. Resolves to the note (empty when none) or null when the manager backs out.
class _DecisionDialog extends StatefulWidget {
  const _DecisionDialog({required this.approve});
  final bool approve;

  @override
  State<_DecisionDialog> createState() => _DecisionDialogState();
}

class _DecisionDialogState extends State<_DecisionDialog> {
  final _note = TextEditingController();

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final scheme = Theme.of(context).colorScheme;
    final approve = widget.approve;
    return AlertDialog(
      title: Text(approve ? t.approveCancelQ : t.keepOrderQ),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(approve ? t.approveCancelBody : t.keepOrderBody),
          const Gap(AppSpace.sm),
          TextField(
            key: const ValueKey('decision-note'),
            controller: _note,
            maxLength: 500,
            minLines: 1,
            maxLines: 3,
            decoration: InputDecoration(labelText: t.decisionNote),
          ),
        ],
      ),
      actions: [
        TextButton(autofocus: true, onPressed: () => Navigator.of(context).pop(), child: Text(t.cancel)),
        FilledButton(
          key: const ValueKey('decision-confirm'),
          style: approve
              ? FilledButton.styleFrom(backgroundColor: scheme.error, foregroundColor: scheme.onError)
              : null,
          onPressed: () {
            if (approve) Haptics.heavy();
            Navigator.of(context).pop(_note.text.trim());
          },
          child: Text(approve ? t.approveCancellation : t.keepOrder),
        ),
      ],
    );
  }
}
