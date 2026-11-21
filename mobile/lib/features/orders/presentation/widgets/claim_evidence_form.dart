import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/app_text_field.dart';
import 'package:albahar_customer/core/widgets/dirty_form_guard.dart';
import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/features/orders/domain/claim_models.dart';
import 'package:albahar_customer/features/orders/presentation/widgets/claim_parts.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

/// More photos and/or a note for a claim that still waits for the team. Closes with what was added, or with null when the customer backs out.
class ClaimEvidenceForm extends StatefulWidget {
  const ClaimEvidenceForm({super.key, required this.already, required this.onDone});
  final int already;
  final void Function(({List<PickedPhoto> photos, String? note})) onDone;

  @override
  State<ClaimEvidenceForm> createState() => _ClaimEvidenceFormState();
}

class _ClaimEvidenceFormState extends State<ClaimEvidenceForm> {
  final _note = TextEditingController();
  var _photos = <PickedPhoto>[];

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final problem = claimPhotoProblemText(l10n, _photos, already: widget.already);
    final ready = (_photos.isNotEmpty || _note.text.trim().isNotEmpty) && problem == null;
    return DirtyFormGuard(
      controllers: [_note],
      isDirty: () => _photos.isNotEmpty || _note.text.trim().isNotEmpty,
      child: SidePanelLayout(
        footer: AppButton(
          key: const ValueKey('claim-add-submit'),
          label: l10n.claimAddSubmit,
          onPressed: ready
              ? () => widget.onDone((photos: _photos, note: _note.text.trim().isEmpty ? null : _note.text.trim()))
              : null,
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(l10n.claimAddHint, style: context.text.bodyMedium),
            const Gap(AppSpace.sm),
            ClaimPhotoPicker(
              photos: _photos,
              already: widget.already,
              error: problem,
              onChanged: (v) => setState(() => _photos = v),
            ),
            const Gap(AppSpace.sm),
            AppTextField(
              controller: _note,
              label: l10n.claimYourNote,
              maxLines: 4,
              minLines: 2,
              keyboardType: TextInputType.multiline,
              textCapitalization: TextCapitalization.sentences,
              inputFormatters: [LengthLimitingTextInputFormatter(maxClaimNote)],
              onChanged: (_) => setState(() {}),
            ),
          ],
        ),
      ),
    );
  }
}
