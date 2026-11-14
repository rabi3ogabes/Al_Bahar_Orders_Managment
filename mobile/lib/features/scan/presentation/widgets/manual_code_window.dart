import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/app_text_field.dart';
import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/features/scan/data/scan_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

/// "Enter a code manually": a small window with one field (the number under the barcode, a product code, or a pasted link).
/// Resolves with the trimmed text, or null when it was closed. This is also the way in for a phone without a usable camera.
Future<String?> showManualCodeWindow(BuildContext context) => showSidePanel<String>(
  context: context,
  title: context.l10n.scanManualTitle,
  isForm: true,
  scrollable: false,
  builder: (_) => const _ManualCodeBody(),
);

class _ManualCodeBody extends StatefulWidget {
  const _ManualCodeBody();

  @override
  State<_ManualCodeBody> createState() => _ManualCodeBodyState();
}

class _ManualCodeBodyState extends State<_ManualCodeBody> {
  final _controller = TextEditingController();
  String? _error;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _submit([String? _]) {
    final code = _controller.text.trim();
    if (code.isEmpty) {
      setState(() => _error = context.l10n.scanManualEmpty);
      return;
    }
    Navigator.of(context).pop(code);
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    return SidePanelLayout(
      footer: AppButton(label: l10n.scanManualAdd, icon: Icons.add_shopping_cart_rounded, onPressed: _submit),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(l10n.scanManualHint, style: context.text.bodyMedium?.copyWith(color: context.palette.muted)),
          const Gap(AppSpace.md),
          AppTextField(
            controller: _controller,
            label: l10n.scanManualLabel,
            errorText: _error,
            autofocus: true,
            forceLtr: true,
            prefixIcon: Icons.qr_code_2_rounded,
            keyboardType: TextInputType.text,
            textInputAction: TextInputAction.done,
            inputFormatters: [LengthLimitingTextInputFormatter(ScanRepository.maxCodeLength)],
            onChanged: (_) {
              if (_error != null) setState(() => _error = null);
            },
            onSubmitted: _submit,
          ),
        ],
      ),
    );
  }
}
