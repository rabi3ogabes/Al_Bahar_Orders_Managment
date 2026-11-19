import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:albahar_customer/ops/ops_staff_models.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/ops_widgets.dart';
import 'package:albahar_customer/ops/staff/staff_providers.dart';
import 'package:albahar_customer/ops/staff/staff_strings.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// "Hand over without the pass" for a manager (the pass expired, or the customer lost the phone): the order's number comes from the card, the
/// manager asks the customer for the last 4 digits of the phone number on the order and writes why. Everything is recorded with the reason.
/// Pay at pickup: the money is collected in the same step. The window closes when the goods are handed over.
Future<void> showManualHandover(BuildContext context, {required QueueOrder order, required String branchId}) async {
  final t = OpsStrings.of(context);
  final messenger = ScaffoldMessenger.maybeOf(context);
  final done = await showSidePanel<bool>(
    context: context,
    title: t.handOverWithoutPass,
    subtitle: t.orderTitle(order.number),
    scrollable: false,
    isForm: true,
    builder: (_) => _ManualHandoverForm(order: order, branchId: branchId),
  );
  if (done == true) {
    messenger
      ?..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(content: Text(t.manualDone)));
  }
}

class _ManualHandoverForm extends ConsumerStatefulWidget {
  const _ManualHandoverForm({required this.order, required this.branchId});
  final QueueOrder order;
  final String branchId;

  @override
  ConsumerState<_ManualHandoverForm> createState() => _ManualHandoverFormState();
}

class _ManualHandoverFormState extends ConsumerState<_ManualHandoverForm> {
  final _digits = TextEditingController();
  final _reason = TextEditingController();
  final _receipt = TextEditingController();
  String _tender = 'cash';
  bool _busy = false;
  bool _tried = false;
  String? _error;

  bool get _collects => widget.order.paymentMethod == 'pay_at_pickup' && widget.order.dueAmount > 0;
  bool get _digitsOk => RegExp(r'^\d{4}$').hasMatch(_digits.text);
  bool get _reasonOk => _reason.text.trim().length >= 3;

  @override
  void dispose() {
    _digits.dispose();
    _reason.dispose();
    _receipt.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_busy) return;
    setState(() {
      _tried = true;
      _error = null;
    });
    if (!_digitsOk || !_reasonOk) return;
    setState(() => _busy = true);
    try {
      await ref
          .read(opsRepositoryProvider)
          .pickupManualHandover(
            branchId: widget.branchId,
            orderNumber: widget.order.number,
            phoneLast4: _digits.text,
            reason: _reason.text.trim(),
            collection: _collects
                ? PickupCollection(
                    tender: _tender,
                    amount: widget.order.amountDue,
                    receiptReference: _tender == 'card_terminal' ? _receipt.text : null,
                  )
                : null,
          );
      if (!mounted) return;
      refreshStaff(ref);
      Navigator.of(context).pop(true);
    } catch (e) {
      if (!mounted) return;
      // The reason stays in the window, next to the fields, so the person can fix the detail that was wrong.
      setState(() {
        _busy = false;
        _error = OpsStrings.of(context).errorFor(e) ?? describeError(context.l10n, e);
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final p = context.palette;
    return SidePanelLayout(
      footer: FilledButton(
        key: const ValueKey('manual-confirm'),
        onPressed: _busy ? null : _submit,
        child: _busy
            ? SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(strokeWidth: 2.2, color: Theme.of(context).colorScheme.onPrimary),
              )
            : Text(
                _collects ? '${t.collectFirst} · ${t.handOverWithoutPass}' : t.handOverWithoutPass,
                textAlign: TextAlign.center,
              ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(t.manualHint, style: context.text.bodyMedium?.copyWith(color: p.muted)),
          const Gap(AppSpace.md),
          TextField(
            key: const ValueKey('manual-digits'),
            controller: _digits,
            enabled: !_busy,
            autofocus: true,
            maxLength: 4,
            keyboardType: TextInputType.number,
            textDirection: TextDirection.ltr,
            inputFormatters: [_LatinDigits(), FilteringTextInputFormatter.digitsOnly],
            onChanged: (_) => setState(() => _error = null),
            decoration: InputDecoration(
              labelText: t.manualPhone,
              counterText: '',
              errorText: _tried && !_digitsOk ? t.manualDigitsNeeded : null,
            ),
          ),
          const Gap(AppSpace.sm),
          TextField(
            key: const ValueKey('manual-reason'),
            controller: _reason,
            enabled: !_busy,
            maxLength: 500,
            minLines: 2,
            maxLines: 4,
            textInputAction: TextInputAction.newline,
            onChanged: (_) => setState(() => _error = null),
            decoration: InputDecoration(
              labelText: t.manualReason,
              hintText: t.manualReasonHint,
              errorText: _tried && !_reasonOk ? t.manualReasonNeeded : null,
            ),
          ),
          if (_collects) ...[
            const Gap(AppSpace.sm),
            PanelSection(
              title: t.collectFirst,
              children: [
                Text(
                  t.money(widget.order.amountDue),
                  style: context.text.headlineSmall?.copyWith(
                    color: context.colors.primary,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const Gap(AppSpace.sm),
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
          if (_error != null) ...[
            const Gap(AppSpace.sm),
            Semantics(
              liveRegion: true,
              child: Text(
                _error!,
                key: const ValueKey('manual-error'),
                style: context.text.bodyMedium?.copyWith(color: p.danger),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// A keyboard that types Arabic-Indic digits (٠-٩, ۰-۹) gives the ordinary digits the server reads.
class _LatinDigits extends TextInputFormatter {
  @override
  TextEditingValue formatEditUpdate(TextEditingValue oldValue, TextEditingValue newValue) {
    final out = StringBuffer();
    for (final r in newValue.text.runes) {
      if (r >= 0x0660 && r <= 0x0669) {
        out.writeCharCode(0x30 + r - 0x0660);
      } else if (r >= 0x06F0 && r <= 0x06F9) {
        out.writeCharCode(0x30 + r - 0x06F0);
      } else {
        out.writeCharCode(r);
      }
    }
    final text = out.toString();
    return text == newValue.text ? newValue : newValue.copyWith(text: text);
  }
}
