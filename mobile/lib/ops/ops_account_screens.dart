import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/ops/ops_account.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_session.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// The server accepts at most this many characters for the current password.
const _maxPasswordLength = 200;

/// "Choose your own password": the first screen of an employee whose password an administrator set. The server refuses every other
/// call until it is done (`403 password_change_required`), so this is the only screen the session shows ([OpsAuth.passwordChange]).
///
/// It is the root screen, so the back button has nowhere to go (and is switched off, so it cannot even leave the app); the only
/// ways on are saving a password or signing out.
class OpsPasswordScreen extends ConsumerStatefulWidget {
  const OpsPasswordScreen({super.key});

  @override
  ConsumerState<OpsPasswordScreen> createState() => _OpsPasswordScreenState();
}

class _OpsPasswordScreenState extends ConsumerState<OpsPasswordScreen> {
  final _current = TextEditingController();
  final _password = TextEditingController();
  final _repeat = TextEditingController();
  final _currentFocus = FocusNode();
  final _passwordFocus = FocusNode();
  final _repeatFocus = FocusNode();
  bool _showCurrent = false;
  bool _showPassword = false;
  bool _showRepeat = false;
  bool _busy = false;
  bool _leaving = false;

  /// What the server said about each field (in plain words), cleared as soon as the person edits that field.
  String? _currentError;
  String? _passwordError;
  String? _repeatError;

  /// What went wrong that is not about one field (no network, too many tries, the server is down).
  String? _banner;

  @override
  void dispose() {
    for (final c in [_current, _password, _repeat]) {
      c.dispose();
    }
    for (final f in [_currentFocus, _passwordFocus, _repeatFocus]) {
      f.dispose();
    }
    super.dispose();
  }

  PasswordRules get _rules =>
      PasswordRules.check(current: _current.text, password: _password.text, repeat: _repeat.text);

  bool get _canSubmit => !_busy && !_leaving && _current.text.isNotEmpty && _rules.valid;

  /// The repeat field complains only when what was typed can no longer become the new password (not while still typing it).
  bool get _repeatDiffers => _repeat.text.isNotEmpty && !_password.text.startsWith(_repeat.text);

  Future<void> _submit() async {
    if (!_canSubmit) return;
    final l10n = context.l10n;
    final messenger = ScaffoldMessenger.maybeOf(context);
    FocusScope.of(context).unfocus();
    setState(() {
      _busy = true;
      _banner = _currentError = _passwordError = _repeatError = null;
    });
    try {
      await ref
          .read(opsSessionProvider.notifier)
          .changePassword(current: _current.text, password: _password.text, confirmation: _repeat.text);
      TextInput.finishAutofillContext();
      // The screen is replaced by the app now; the message outlives it (the messenger sits above the pages).
      messenger?.hideCurrentSnackBar();
      messenger?.showSnackBar(SnackBar(content: Text(l10n.passwordChanged)));
    } catch (e) {
      if (!mounted) return;
      _showError(l10n, e);
    }
  }

  void _showError(AppLocalizations l10n, Object error) {
    String? current;
    String? password;
    String? repeat;
    String? banner;
    if (error is ApiException && error.kind == ApiErrorKind.validation) {
      current = error.code == 'invalid_password' ? l10n.staffPwWrongCurrent : error.fieldError('current_password');
      password = error.fieldError('password');
      repeat = error.fieldError('password_confirmation');
      if (current == null && password == null && repeat == null) banner = describeError(l10n, error);
    } else {
      banner = describeError(l10n, error);
    }
    setState(() {
      _busy = false;
      _currentError = current;
      _passwordError = password;
      _repeatError = repeat;
      _banner = banner;
    });
    if (current != null) {
      _currentFocus.requestFocus();
    } else if (password != null) {
      _passwordFocus.requestFocus();
    } else if (repeat != null) {
      _repeatFocus.requestFocus();
    }
  }

  Future<void> _signOut() async {
    if (_leaving) return;
    setState(() => _leaving = true);
    await ref.read(opsSessionProvider.notifier).signOut();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final t = OpsStrings.of(context);
    final role = ref.watch(opsRoleProvider);
    final rules = _rules;
    final locked = _busy || _leaving;
    return PopScope(
      canPop: false,
      child: Scaffold(
        body: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
              padding: const EdgeInsets.all(AppSpace.lg),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 420),
                child: AutofillGroup(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      // The way out is at the top, in sight without scrolling (next to the language, for someone who does not read this one).
                      Wrap(
                        alignment: WrapAlignment.spaceBetween,
                        crossAxisAlignment: WrapCrossAlignment.center,
                        children: [
                          const OpsLanguageButton(),
                          TextButton.icon(
                            onPressed: locked ? null : () => unawaited(_signOut()),
                            icon: const Icon(Icons.logout_rounded, size: 18),
                            label: Text(t.signOut),
                          ),
                        ],
                      ),
                      Center(
                        child: OpsAccountEmblem(role: role, icon: Icons.lock_reset_rounded),
                      ),
                      const Gap(AppSpace.md),
                      Semantics(
                        header: true,
                        child: Text(l10n.staffPwTitle, textAlign: TextAlign.center, style: context.text.headlineMedium),
                      ),
                      const Gap(AppSpace.xs),
                      Text(l10n.staffPwIntro, textAlign: TextAlign.center, style: context.text.bodyMedium),
                      const Gap(AppSpace.lg),
                      _PasswordField(
                        controller: _current,
                        focusNode: _currentFocus,
                        label: l10n.staffPwCurrent,
                        name: l10n.staffPwCurrentName,
                        show: _showCurrent,
                        enabled: !locked,
                        errorText: _currentError,
                        autofillHints: const [AutofillHints.password],
                        textInputAction: TextInputAction.next,
                        onToggle: () => setState(() => _showCurrent = !_showCurrent),
                        onChanged: (_) => setState(() => _currentError = null),
                      ),
                      const Gap(AppSpace.sm),
                      _PasswordField(
                        controller: _password,
                        focusNode: _passwordFocus,
                        label: l10n.staffPwNew,
                        name: l10n.staffPwNewName,
                        show: _showPassword,
                        enabled: !locked,
                        errorText: _passwordError,
                        autofillHints: const [AutofillHints.newPassword],
                        textInputAction: TextInputAction.next,
                        onToggle: () => setState(() => _showPassword = !_showPassword),
                        onChanged: (_) => setState(() => _passwordError = null),
                      ),
                      const Gap(AppSpace.sm),
                      _RulesCard(rules: rules),
                      const Gap(AppSpace.sm),
                      _PasswordField(
                        controller: _repeat,
                        focusNode: _repeatFocus,
                        label: l10n.staffPwRepeat,
                        name: l10n.staffPwRepeatName,
                        show: _showRepeat,
                        enabled: !locked,
                        errorText: _repeatError ?? (_repeatDiffers ? l10n.passwordsDoNotMatch : null),
                        autofillHints: const [AutofillHints.newPassword],
                        textInputAction: TextInputAction.done,
                        onToggle: () => setState(() => _showRepeat = !_showRepeat),
                        onChanged: (_) => setState(() => _repeatError = null),
                        onSubmitted: (_) => unawaited(_submit()),
                      ),
                      if (_banner != null) ...[const Gap(AppSpace.sm), _Notice(message: _banner!)],
                      const Gap(AppSpace.lg),
                      FilledButton(
                        onPressed: _canSubmit ? () => unawaited(_submit()) : null,
                        child: _busy ? const _ButtonSpinner() : Text(l10n.staffPwSubmit),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// A signed-in employee whose account has no access to orders in this app (the manager did not tick it, or took it away later).
/// A plain explanation instead of a list of errors, a way to look again (the manager may have just changed it) and a way out.
class OpsNoAccessScreen extends ConsumerWidget {
  const OpsNoAccessScreen({super.key, required this.onCheckAgain, this.checking = false});

  /// Reads the account's permissions again.
  final VoidCallback onCheckAgain;

  /// The permissions are being read again: the button shows progress and cannot be pressed twice.
  final bool checking;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final t = OpsStrings.of(context);
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(AppSpace.lg),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Align(alignment: AlignmentDirectional.centerEnd, child: OpsLanguageButton()),
                  Center(
                    child: OpsAccountEmblem(role: ref.watch(opsRoleProvider), icon: Icons.lock_outline_rounded),
                  ),
                  const Gap(AppSpace.md),
                  Semantics(
                    header: true,
                    child: Text(
                      l10n.staffNoAccessTitle,
                      textAlign: TextAlign.center,
                      style: context.text.headlineMedium,
                    ),
                  ),
                  const Gap(AppSpace.xs),
                  Text(l10n.staffNoAccessMessage, textAlign: TextAlign.center, style: context.text.bodyMedium),
                  const Gap(AppSpace.lg),
                  FilledButton(
                    onPressed: checking ? null : onCheckAgain,
                    child: checking ? const _ButtonSpinner() : Text(l10n.staffNoAccessCheckAgain),
                  ),
                  const Gap(AppSpace.xs),
                  Center(
                    child: TextButton.icon(
                      onPressed: () => unawaited(ref.read(opsSessionProvider.notifier).signOut()),
                      icon: const Icon(Icons.logout_rounded, size: 18),
                      label: Text(t.signOut),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// The app's icon tile (the same look as the sign-in screen's) with a glyph that fits the screen.
class OpsAccountEmblem extends StatelessWidget {
  const OpsAccountEmblem({super.key, required this.role, required this.icon, this.size = 76});

  final OpsRole role;
  final IconData icon;
  final double size;

  @override
  Widget build(BuildContext context) {
    final driver = role == OpsRole.driver;
    return ExcludeSemantics(
      child: Container(
        width: size,
        height: size,
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(size * 0.3),
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: driver
                ? const [AppPalette.navy, AppPalette.navySoft]
                : const [Color(0xFF2A1A20), Color(0xFF4F3B43)],
          ),
          boxShadow: kSoftShadow,
        ),
        child: Icon(icon, size: size * 0.52, color: AppPalette.goldSoft),
      ),
    );
  }
}

/// "English / العربية" at the top of the screens that stand in for the sign-in (the person may not read the language the phone is in).
class OpsLanguageButton extends ConsumerWidget {
  const OpsLanguageButton({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = OpsStrings.of(context);
    return TextButton(
      onPressed: () => ref.read(localeControllerProvider.notifier).setLocale(Locale(t.ar ? 'en' : 'ar')),
      child: Text(t.language),
    );
  }
}

/// Progress inside a button that is switched off while it works (a switched-off button is pale, so the spinner is dark).
class _ButtonSpinner extends StatelessWidget {
  const _ButtonSpinner();

  @override
  Widget build(BuildContext context) =>
      SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.4, color: context.palette.muted));
}

class _PasswordField extends StatelessWidget {
  const _PasswordField({
    required this.controller,
    required this.focusNode,
    required this.label,
    required this.name,
    required this.show,
    required this.enabled,
    required this.autofillHints,
    required this.textInputAction,
    required this.onToggle,
    required this.onChanged,
    this.errorText,
    this.onSubmitted,
  });

  final TextEditingController controller;
  final FocusNode focusNode;
  final String label;

  /// What a screen reader calls the field in the show / hide button ("current password").
  final String name;
  final bool show;
  final bool enabled;
  final String? errorText;
  final List<String> autofillHints;
  final TextInputAction textInputAction;
  final VoidCallback onToggle;
  final ValueChanged<String> onChanged;
  final ValueChanged<String>? onSubmitted;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    return TextField(
      controller: controller,
      focusNode: focusNode,
      enabled: enabled,
      obscureText: !show,
      autocorrect: false,
      enableSuggestions: false,
      keyboardType: TextInputType.visiblePassword,
      autofillHints: autofillHints,
      textInputAction: textInputAction,
      inputFormatters: [LengthLimitingTextInputFormatter(_maxPasswordLength)],
      onChanged: onChanged,
      onSubmitted: onSubmitted,
      decoration: InputDecoration(
        labelText: label,
        errorText: errorText,
        errorMaxLines: 3,
        suffixIcon: IconButton(
          tooltip: show ? l10n.staffPwHideField(name) : l10n.staffPwShowField(name),
          icon: Icon(show ? Icons.visibility_off_outlined : Icons.visibility_outlined),
          onPressed: enabled ? onToggle : null,
        ),
      ),
    );
  }
}

/// The four rules of the new password with a tick for each one it already meets (nothing is shown in red while the person is typing).
class _RulesCard extends StatelessWidget {
  const _RulesCard({required this.rules});

  final PasswordRules rules;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final p = context.palette;
    return Semantics(
      container: true,
      label: l10n.staffPwRulesTitle,
      child: Container(
        padding: const EdgeInsets.all(AppSpace.sm),
        decoration: BoxDecoration(
          color: p.surfaceMuted,
          borderRadius: BorderRadius.circular(AppRadius.input),
          border: Border.all(color: p.line),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(l10n.staffPwRulesTitle, style: context.text.labelLarge),
            const Gap(AppSpace.xxs),
            _RuleRow(label: l10n.staffPwRuleLength, met: rules.longEnough),
            _RuleRow(label: l10n.staffPwRuleCase, met: rules.mixedCase),
            _RuleRow(label: l10n.staffPwRuleNumber, met: rules.hasNumber),
            _RuleRow(label: l10n.staffPwRuleDifferent, met: rules.differs),
          ],
        ),
      ),
    );
  }
}

class _RuleRow extends StatelessWidget {
  const _RuleRow({required this.label, required this.met});

  final String label;
  final bool met;

  @override
  Widget build(BuildContext context) {
    final p = context.palette;
    return Semantics(
      checked: met,
      label: label,
      excludeSemantics: true,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 3),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(
              met ? Icons.check_circle_rounded : Icons.radio_button_unchecked_rounded,
              key: ValueKey(met),
              size: 20,
              color: met ? p.success : p.muted,
            ),
            const Gap(AppSpace.xs),
            Expanded(
              child: Text(label, style: context.text.bodyMedium?.copyWith(color: met ? p.ink : p.muted)),
            ),
          ],
        ),
      ),
    );
  }
}

/// A problem that is not about one field, read out when it appears.
class _Notice extends StatelessWidget {
  const _Notice({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    final danger = context.palette.danger;
    return Semantics(
      liveRegion: true,
      child: Container(
        padding: const EdgeInsets.all(AppSpace.sm),
        decoration: BoxDecoration(
          color: danger.withValues(alpha: 0.1),
          borderRadius: BorderRadius.circular(AppRadius.input),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(Icons.error_outline_rounded, size: 20, color: danger),
            const Gap(AppSpace.xs),
            Expanded(
              child: Text(message, style: context.text.bodyMedium?.copyWith(color: danger)),
            ),
          ],
        ),
      ),
    );
  }
}
