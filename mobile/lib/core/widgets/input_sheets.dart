import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../l10n/l10n.dart';
import '../theme/tokens.dart';
import '../utils/validators.dart';
import 'app_button.dart';
import 'app_text_field.dart';
import 'common.dart';
import 'dirty_form_guard.dart';

/// Bottom sheet with one text field. Resolves to the trimmed text, or null
/// when dismissed. The sheet owns its controller, so nothing is disposed while
/// the closing animation still runs, and invalid input shows an error instead
/// of silently doing nothing.
Future<String?> showTextSheet(
  BuildContext context, {
  required String title,
  required String label,
  required String actionLabel,
  String? intro,
  String? helper,
  int minLength = 1,
  int maxLength = 1000,
  int maxLines = 4,
  bool forceLtr = false,
  TextInputType? keyboardType,
  List<TextInputFormatter>? inputFormatters,

  /// Extra check on the trimmed text; return an error message or null.
  String? Function(String value)? validate,
}) {
  return showAppBottomSheet<String>(
    context: context,
    title: title,
    isForm: true,
    builder: (_) => _TextSheet(
      label: label,
      actionLabel: actionLabel,
      intro: intro,
      helper: helper,
      minLength: minLength,
      maxLength: maxLength,
      maxLines: maxLines,
      forceLtr: forceLtr,
      keyboardType: keyboardType,
      inputFormatters: inputFormatters,
      validate: validate,
    ),
  );
}

class _TextSheet extends StatefulWidget {
  const _TextSheet({
    required this.label,
    required this.actionLabel,
    required this.minLength,
    required this.maxLength,
    required this.maxLines,
    required this.forceLtr,
    this.intro,
    this.helper,
    this.keyboardType,
    this.inputFormatters,
    this.validate,
  });

  final String label;
  final String actionLabel;
  final String? intro;
  final String? helper;
  final int minLength;
  final int maxLength;
  final int maxLines;
  final bool forceLtr;
  final TextInputType? keyboardType;
  final List<TextInputFormatter>? inputFormatters;
  final String? Function(String value)? validate;

  @override
  State<_TextSheet> createState() => _TextSheetState();
}

class _TextSheetState extends State<_TextSheet> {
  final _controller = TextEditingController();
  String? _error;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _submit() {
    final l10n = context.l10n;
    final text = _controller.text.trim();
    String? error;
    if (text.isEmpty) {
      error = l10n.fieldRequired;
    } else if (text.length < widget.minLength) {
      error = l10n.minLengthError(widget.minLength.toString());
    } else {
      error = widget.validate?.call(text);
    }
    if (error != null) {
      setState(() => _error = error);
      return;
    }
    Navigator.of(context).pop(text);
  }

  @override
  Widget build(BuildContext context) {
    return DirtyFormGuard(
      controllers: [_controller],
      isDirty: () => _controller.text.trim().isNotEmpty,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (widget.intro != null) ...[Text(widget.intro!, style: context.text.bodyMedium), const Gap(AppSpace.md)],
          AppTextField(
            controller: _controller,
            label: widget.label,
            helper: widget.helper,
            errorText: _error,
            autofocus: true,
            forceLtr: widget.forceLtr,
            keyboardType: widget.keyboardType ?? (widget.maxLines > 1 ? TextInputType.multiline : TextInputType.text),
            textInputAction: widget.maxLines > 1 ? TextInputAction.newline : TextInputAction.done,
            inputFormatters: [...?widget.inputFormatters, LengthLimitingTextInputFormatter(widget.maxLength)],
            maxLines: widget.maxLines,
            minLines: widget.maxLines > 1 ? 2 : 1,
            onChanged: (_) {
              if (_error != null) setState(() => _error = null);
            },
            onSubmitted: widget.maxLines > 1 ? null : (_) => _submit(),
          ),
          const Gap(AppSpace.md),
          AppButton(label: widget.actionLabel, onPressed: _submit),
        ],
      ),
    );
  }
}

/// New-password result from [showPasswordSheet].
typedef PasswordChange = ({String? current, String next});

/// Sheet to set or change the password. Checks the rules before closing.
Future<PasswordChange?> showPasswordSheet(BuildContext context, {required bool askCurrent}) {
  final l10n = context.l10n;
  return showAppBottomSheet<PasswordChange>(
    context: context,
    title: askCurrent ? l10n.changePassword : l10n.setPassword,
    isForm: true,
    builder: (ctx) => PasswordFormBody(askCurrent: askCurrent, onDone: (change) => Navigator.of(ctx).pop(change)),
  );
}

/// The set / change password form. [onDone] gets the new password, or is not called when the form is just closed.
/// It is a body, not a window, so a window can show it as one of its own steps (the profile window does).
class PasswordFormBody extends StatefulWidget {
  const PasswordFormBody({super.key, required this.askCurrent, required this.onDone});
  final bool askCurrent;
  final ValueChanged<PasswordChange> onDone;

  @override
  State<PasswordFormBody> createState() => _PasswordFormBodyState();
}

class _PasswordFormBodyState extends State<PasswordFormBody> {
  final _current = TextEditingController();
  final _next = TextEditingController();
  String? _currentError;
  String? _nextError;

  @override
  void dispose() {
    _current.dispose();
    _next.dispose();
    super.dispose();
  }

  void _submit() {
    final l10n = context.l10n;
    final currentError = widget.askCurrent && _current.text.isEmpty ? l10n.fieldRequired : null;
    final nextError = Validators.isStrongPassword(_next.text) ? null : l10n.passwordTooShort;
    if (currentError != null || nextError != null) {
      setState(() {
        _currentError = currentError;
        _nextError = nextError;
      });
      return;
    }
    widget.onDone((current: widget.askCurrent ? _current.text : null, next: _next.text));
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    return DirtyFormGuard(
      controllers: [_current, _next],
      isDirty: () => _current.text.isNotEmpty || _next.text.isNotEmpty,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (widget.askCurrent) ...[
            AppTextField(
              controller: _current,
              label: l10n.currentPassword,
              obscure: true,
              autofocus: true,
              autofillHints: const [AutofillHints.password],
              textInputAction: TextInputAction.next,
              errorText: _currentError,
            ),
            const Gap(AppSpace.md),
          ],
          AppTextField(
            controller: _next,
            label: l10n.newPasswordLabel,
            helper: l10n.passwordTooShort,
            obscure: true,
            autofocus: !widget.askCurrent,
            autofillHints: const [AutofillHints.newPassword],
            textInputAction: TextInputAction.done,
            errorText: _nextError,
            onSubmitted: (_) => _submit(),
          ),
          const Gap(AppSpace.lg),
          AppButton(label: l10n.save, onPressed: _submit),
        ],
      ),
    );
  }
}
