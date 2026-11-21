import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:flutter/material.dart';

/// "Add assembly and installation +QAR 75 each": one tick per cart line (or on the product page before adding). It is done at the door, so it is
/// for delivery orders only; the price is the server's and is worked out again at checkout. [fee] is for one unit, [total] what the line adds
/// while ticked (null when it does not, for example on a pickup cart). Passing a null [onChanged] shows it disabled (a change is on its way).
class AssemblyChoice extends StatelessWidget {
  const AssemblyChoice({super.key, required this.fee, required this.selected, required this.onChanged, this.total});

  final Money fee;
  final bool selected;
  final Money? total;
  final ValueChanged<bool>? onChanged;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final palette = context.palette;
    // The tick row paints its own ink on the nearest Material: the card is a Material too, so its colour and the tap feedback both show.
    return Material(
      key: const ValueKey('assembly-choice'),
      color: selected ? palette.brassTint : Colors.transparent,
      clipBehavior: Clip.antiAlias,
      shape: RoundedRectangleBorder(
        borderRadius: AppRadius.cardAll,
        side: BorderSide(color: selected ? palette.brass.withValues(alpha: 0.5) : palette.line),
      ),
      child: CheckboxListTile(
        value: selected,
        onChanged: onChanged == null ? null : (v) => onChanged!(v ?? false),
        controlAffinity: ListTileControlAffinity.leading,
        dense: true,
        contentPadding: const EdgeInsetsDirectional.only(start: AppSpace.xs, end: AppSpace.sm),
        shape: const RoundedRectangleBorder(borderRadius: AppRadius.cardAll),
        secondary: Icon(Icons.handyman_outlined, size: 20, color: palette.brassDeep),
        title: Text.rich(
          TextSpan(
            children: [
              TextSpan(text: l10n.assemblyAdd, style: context.text.titleSmall),
              TextSpan(
                text: '  ${l10n.assemblyFeeEach(fee.formatted)}',
                style: context.text.bodySmall?.copyWith(color: palette.brassDeep, fontWeight: FontWeight.w600),
              ),
            ],
          ),
        ),
        subtitle: Text(
          selected && total != null ? l10n.assemblyLineTotal(total!.formatted) : l10n.assemblyNote,
          style: context.text.bodySmall?.copyWith(color: palette.muted),
        ),
      ),
    );
  }
}
