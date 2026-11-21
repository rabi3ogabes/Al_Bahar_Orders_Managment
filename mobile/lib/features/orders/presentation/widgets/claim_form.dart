import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/app_text_field.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/dirty_form_guard.dart';
import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/features/orders/domain/order_models.dart';
import 'package:albahar_customer/features/orders/presentation/widgets/claim_parts.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:uuid/uuid.dart';

/// What the customer sends: the lines, the kind of problem, the photos and the note, with the key that makes a second tap harmless.
typedef ClaimDraft = ({
  List<({String orderItemId, int quantity})> items,
  String code,
  String? note,
  List<PickedPhoto> photos,
  String key,
});

/// "Report a problem or return": what happened, which pieces, photos (needed for damage and a wrong item) and a short note. Closes with the
/// claim to send, or with null when the customer backs out.
class ClaimForm extends StatefulWidget {
  const ClaimForm({super.key, required this.order, required this.onDone});
  final Order order;
  final void Function(ClaimDraft?) onDone;

  @override
  State<ClaimForm> createState() => _ClaimFormState();
}

class _ClaimFormState extends State<ClaimForm> {
  /// How many of each line are going back (a line that is not in the map, or at 0, stays).
  final _quantity = <String, int>{};
  final _note = TextEditingController();
  final _key = const Uuid().v4();
  String _type = claimTypes.first;
  var _photos = <PickedPhoto>[];

  /// Only what can still be reported: delivered or collected, inside the claim window, not already in a claim.
  late final _lines = [
    for (final i in widget.order.items)
      if (!i.isGone && i.returnableNow > 0) i,
  ];

  int get _picked => _quantity.values.fold(0, (a, b) => a + b);

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  String _label(AppLocalizations l10n, String c) => switch (c) {
    'damaged' => l10n.claimTypeDamaged,
    'wrong_item' => l10n.claimTypeWrong,
    'missing_item' => l10n.claimTypeMissing,
    'quality' => l10n.claimTypeQuality,
    _ => l10n.claimTypeChangedMind,
  };

  String _hint(AppLocalizations l10n, String c) => switch (c) {
    'damaged' => l10n.claimTypeDamagedHint,
    'wrong_item' => l10n.claimTypeWrongHint,
    'missing_item' => l10n.claimTypeMissingHint,
    'quality' => l10n.claimTypeQualityHint,
    _ => l10n.claimTypeChangedMindHint,
  };

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final p = context.palette;
    final needsPhoto = claimNeedsPhoto(_type);
    final problem = claimPhotoProblemText(l10n, _photos);
    final ready = _picked > 0 && problem == null && (!needsPhoto || _photos.isNotEmpty);
    final until = widget.order.returnWindowUntil;
    return DirtyFormGuard(
      controllers: [_note],
      isDirty: () => _picked > 0 || _photos.isNotEmpty || _note.text.trim().isNotEmpty,
      child: SidePanelLayout(
        footer: AppButton(
          key: const ValueKey('claim-submit'),
          label: l10n.requestReturn,
          onPressed: !ready
              ? null
              : () => widget.onDone((
                  items: [
                    for (final e in _quantity.entries)
                      if (e.value > 0) (orderItemId: e.key, quantity: e.value),
                  ],
                  code: _type,
                  note: _note.text.trim().isEmpty ? null : _note.text.trim(),
                  photos: _photos,
                  key: _key,
                )),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(l10n.returnFormIntro, style: context.text.bodyMedium),
            if (until != null) ...[
              const Gap(AppSpace.xxs),
              Text(
                l10n.claimWindowUntil(QatarTime.date(until, context.localeCode)),
                key: const ValueKey('claim-window-note'),
                style: context.text.bodySmall?.copyWith(color: p.brassDeep, fontWeight: FontWeight.w600),
              ),
            ],
            const Gap(AppSpace.sm),
            if (_lines.isEmpty)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: AppSpace.md),
                child: Text(l10n.returnNothing, textAlign: TextAlign.center),
              )
            else ...[
              Text(l10n.claimTypeLabel, style: context.text.titleSmall),
              const Gap(AppSpace.xs),
              RadioGroup<String>(
                groupValue: _type,
                onChanged: (v) => setState(() => _type = v ?? _type),
                child: Column(
                  children: [
                    for (final c in claimTypes)
                      Padding(
                        padding: const EdgeInsets.only(bottom: AppSpace.xs),
                        child: _TypeTile(
                          key: ValueKey('claim-type-$c'),
                          code: c,
                          label: _label(l10n, c),
                          hint: _hint(l10n, c),
                          selected: _type == c,
                          onTap: () => setState(() => _type = c),
                        ),
                      ),
                  ],
                ),
              ),
              const Gap(AppSpace.xs),
              Text(l10n.selectItemsToReturn, style: context.text.titleSmall),
              for (final i in _lines)
                Padding(
                  key: ValueKey('return-line-${i.id}'),
                  padding: const EdgeInsets.symmetric(vertical: AppSpace.xs),
                  child: Row(
                    children: [
                      AppNetworkImage(url: i.imageUrl, width: 48, height: 56, borderRadius: BorderRadius.circular(8)),
                      const Gap(AppSpace.sm),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(i.name, maxLines: 2, overflow: TextOverflow.ellipsis, style: context.text.titleSmall),
                            if (i.variantName != null) Text(i.variantName!, style: context.text.bodySmall),
                            Text(
                              l10n.returnUpTo(i.returnableNow),
                              style: context.text.bodySmall?.copyWith(color: p.muted),
                            ),
                          ],
                        ),
                      ),
                      QuantityStepper(
                        value: _quantity[i.id] ?? 0,
                        min: 0,
                        max: i.returnableNow,
                        compact: true,
                        onChanged: (v) => setState(() => _quantity[i.id] = v),
                      ),
                    ],
                  ),
                ),
              const Gap(AppSpace.sm),
              ClaimPhotoPicker(
                photos: _photos,
                required: needsPhoto,
                error: problem,
                onChanged: (v) => setState(() => _photos = v),
              ),
              const Gap(AppSpace.sm),
              AppTextField(
                controller: _note,
                label: l10n.returnNoteLabel,
                maxLines: 4,
                minLines: 2,
                keyboardType: TextInputType.multiline,
                textCapitalization: TextCapitalization.sentences,
                inputFormatters: [LengthLimitingTextInputFormatter(maxClaimNote)],
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _TypeTile extends StatelessWidget {
  const _TypeTile({
    super.key,
    required this.code,
    required this.label,
    required this.hint,
    required this.selected,
    required this.onTap,
  });
  final String code;
  final String label;
  final String hint;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final p = context.palette;
    return Semantics(
      inMutuallyExclusiveGroup: true,
      selected: selected,
      button: true,
      label: '$label. $hint',
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: onTap,
        child: Container(
          constraints: const BoxConstraints(minHeight: 56),
          padding: const EdgeInsets.symmetric(horizontal: AppSpace.md, vertical: AppSpace.sm),
          decoration: BoxDecoration(
            color: selected ? p.tint : null,
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: selected ? p.brass : p.line, width: selected ? 2 : 1),
          ),
          child: Row(
            children: [
              Expanded(
                child: ExcludeSemantics(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(label, style: context.text.titleSmall),
                      Text(hint, style: context.text.bodySmall?.copyWith(color: p.muted)),
                    ],
                  ),
                ),
              ),
              if (selected) Icon(Icons.check_circle_rounded, color: p.brass),
            ],
          ),
        ),
      ),
    );
  }
}
