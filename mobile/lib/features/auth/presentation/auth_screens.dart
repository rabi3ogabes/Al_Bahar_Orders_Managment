import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/utils/phone.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/app_text_field.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/auth/data/auth_repository.dart';
import 'package:albahar_customer/features/auth/domain/auth_models.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

@immutable
class OtpArgs {
  const OtpArgs({required this.phone, required this.result, this.from, this.purpose = 'login'});
  final String phone;
  final OtpRequestResult result;
  final String? from;
  final String purpose;
}

@immutable
class RegisterArgs {
  const RegisterArgs({required this.phone, required this.registrationToken, this.from});
  final String phone;
  final String registrationToken;
  final String? from;
}

String _q(String? from) => from == null ? '' : '?from=${Uri.encodeComponent(from)}';

/// Shared frame for the auth flow: brand mark, title, scrollable form.
class _AuthScaffold extends StatelessWidget {
  const _AuthScaffold({required this.title, required this.subtitle, required this.children, this.showBack = true});

  final String title;
  final String subtitle;
  final List<Widget> children;
  final bool showBack;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(automaticallyImplyLeading: showBack),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(AppSpace.lg, AppSpace.xs, AppSpace.lg, AppSpace.xl),
          child: ContentWidth(
            maxWidth: 480,
            child: AutofillGroup(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Center(child: BrandMark(size: 0.8)),
                  const Gap(AppSpace.xl),
                  Semantics(header: true, child: Text(title, style: context.text.headlineLarge)),
                  const Gap(AppSpace.xs),
                  Text(subtitle, style: context.text.bodyMedium?.copyWith(color: context.palette.muted)),
                  const Gap(AppSpace.lg),
                  ...children,
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Step 1 — phone number. One flow for sign-in and registration.
class PhoneLoginScreen extends ConsumerStatefulWidget {
  const PhoneLoginScreen({super.key, this.from});
  final String? from;

  @override
  ConsumerState<PhoneLoginScreen> createState() => _PhoneLoginScreenState();
}

class _PhoneLoginScreenState extends ConsumerState<PhoneLoginScreen> {
  final _phone = TextEditingController();
  String? _error;
  bool _busy = false;

  @override
  void dispose() {
    _phone.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final l10n = context.l10n;
    final e164 = QatarPhone.toE164(_phone.text);
    if (e164 == null) {
      setState(() => _error = l10n.phoneInvalid);
      Haptics.warning();
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await ref.read(authRepositoryProvider).requestOtp(e164);
      if (!mounted) return;
      unawaited(context.push('/login/otp${_q(widget.from)}', extra: OtpArgs(phone: e164, result: result, from: widget.from)));
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _error = e.fieldError('phone') ?? describeError(l10n, e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    return _AuthScaffold(
      title: l10n.loginTitle,
      subtitle: l10n.loginSubtitle,
      showBack: context.canPop(),
      children: [
        AppTextField(
          controller: _phone,
          label: l10n.phoneLabel,
          hint: l10n.phoneHint,
          prefix: '+974  ',
          forceLtr: true,
          keyboardType: TextInputType.phone,
          textInputAction: TextInputAction.done,
          autofillHints: const [AutofillHints.telephoneNumberNational],
          inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9٠-٩۰-۹+ ]')), LengthLimitingTextInputFormatter(16)],
          errorText: _error,
          onSubmitted: (_) => _submit(),
          autofocus: true,
        ),
        const Gap(AppSpace.lg),
        AppButton(label: l10n.sendCode, loading: _busy, onPressed: _submit),
        const Gap(AppSpace.sm),
        AppButton.secondary(
          label: l10n.loginWithPassword,
          onPressed: () => context.push('/login/password${_q(widget.from)}'),
        ),
        const Gap(AppSpace.lg),
        Center(
          child: TextButton(
            onPressed: () => context.go(widget.from ?? '/home'),
            child: Text(l10n.continueAsGuest),
          ),
        ),
        const Gap(AppSpace.md),
        const _TermsNote(),
      ],
    );
  }
}

class _TermsNote extends StatelessWidget {
  const _TermsNote();

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    return Text.rich(
      TextSpan(
        style: context.text.bodySmall,
        children: [
          TextSpan(text: '${l10n.termsAgreementPrefix} '),
          TextSpan(
            text: l10n.termsAndPrivacy,
            style: TextStyle(color: context.palette.brass, decoration: TextDecoration.underline),
            recognizer: TapGestureRecognizer()..onTap = () => context.push('/pages/terms'),
          ),
        ],
      ),
      textAlign: TextAlign.center,
    );
  }
}

/// Step 2 — verify the SMS code.
class OtpScreen extends ConsumerStatefulWidget {
  const OtpScreen({super.key, required this.args});
  final OtpArgs args;

  @override
  ConsumerState<OtpScreen> createState() => _OtpScreenState();
}

class _OtpScreenState extends ConsumerState<OtpScreen> {
  final _code = TextEditingController();
  Timer? _timer;
  late int _resendIn = widget.args.result.resendIn;
  String? _error;
  bool _busy = false;
  bool _resending = false;

  @override
  void initState() {
    super.initState();
    _startTimer();
  }

  void _startTimer() {
    _timer?.cancel();
    _timer = Timer.periodic(const Duration(seconds: 1), (t) {
      if (!mounted) return;
      setState(() => _resendIn = (_resendIn - 1).clamp(0, 9999));
      if (_resendIn == 0) t.cancel();
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    _code.dispose();
    super.dispose();
  }

  Future<void> _verify() async {
    final l10n = context.l10n;
    final code = QatarPhone.normalizeDigits(_code.text);
    if (code.length < 4) {
      setState(() => _error = l10n.fieldRequired);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await ref.read(authRepositoryProvider).verifyOtp(widget.args.phone, code);
      if (!mounted) return;
      switch (result) {
        case Authenticated():
          Haptics.success();
          await ref.read(sessionControllerProvider.notifier).signIn(result);
        // Router redirect moves us to `from` once authenticated.
        case RegistrationRequired(:final registrationToken):
          unawaited(
            context.push(
              '/login/register${_q(widget.args.from)}',
              extra: RegisterArgs(phone: widget.args.phone, registrationToken: registrationToken, from: widget.args.from),
            ),
          );
      }
    } on ApiException catch (e) {
      if (!mounted) return;
      Haptics.warning();
      setState(() => _error = e.fieldError('code') ?? describeError(l10n, e));
      if (e.retryAfter != null) {
        setState(() => _resendIn = e.retryAfter!.inSeconds);
        _startTimer();
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _resend() async {
    setState(() => _resending = true);
    try {
      final r = await ref.read(authRepositoryProvider).requestOtp(widget.args.phone, purpose: widget.args.purpose);
      if (!mounted) return;
      setState(() => _resendIn = r.resendIn);
      _startTimer();
      showInfoSnack(context, context.l10n.codeSent);
    } on ApiException catch (e) {
      if (!mounted) return;
      if (e.retryAfter != null) {
        setState(() => _resendIn = e.retryAfter!.inSeconds);
        _startTimer();
      }
      showErrorSnack(context, e);
    } finally {
      if (mounted) setState(() => _resending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final debug = widget.args.result.debugCode;
    final showDebug = debug != null && kDebugMode && !ref.watch(appConfigProvider).isProd;
    return _AuthScaffold(
      title: l10n.otpTitle,
      subtitle: l10n.otpSubtitle('⁦${QatarPhone.display(widget.args.phone)}⁩'),
      children: [
        AppTextField(
          controller: _code,
          label: l10n.otpCodeLabel,
          forceLtr: true,
          keyboardType: TextInputType.number,
          autofillHints: const [AutofillHints.oneTimeCode],
          inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9٠-٩]')), LengthLimitingTextInputFormatter(6)],
          errorText: _error,
          autofocus: true,
          onChanged: (v) {
            if (QatarPhone.normalizeDigits(v).length == 6 && !_busy) _verify();
          },
          onSubmitted: (_) => _verify(),
        ),
        if (showDebug) ...[
          const Gap(AppSpace.xs),
          Text(l10n.debugCodeHint(debug), style: context.text.bodySmall?.copyWith(color: context.palette.info)),
        ],
        const Gap(AppSpace.lg),
        AppButton(label: l10n.verify, loading: _busy, onPressed: _verify),
        const Gap(AppSpace.sm),
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            TextButton(onPressed: () => context.pop(), child: Text(l10n.changeNumber)),
            TextButton(
              onPressed: _resendIn > 0 || _resending ? null : _resend,
              child: Text(_resendIn > 0 ? l10n.resendIn(_resendIn) : l10n.resendCode),
            ),
          ],
        ),
      ],
    );
  }
}

/// Step 3 (new customers) — profile details.
class RegisterScreen extends ConsumerStatefulWidget {
  const RegisterScreen({super.key, required this.args});
  final RegisterArgs args;

  @override
  ConsumerState<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends ConsumerState<RegisterScreen> {
  final _form = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _referral = TextEditingController();
  bool _marketing = false;
  bool _busy = false;
  ApiException? _error;

  @override
  void dispose() {
    for (final c in [_name, _email, _password, _referral]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_form.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await ref.read(authRepositoryProvider).register(
        RegistrationRequest(
          registrationToken: widget.args.registrationToken,
          name: _name.text.trim(),
          email: _email.text.trim(),
          password: _password.text,
          locale: ref.read(localeCodeProvider),
          referralCode: _referral.text.trim(),
          marketingConsent: _marketing,
        ),
      );
      Haptics.success();
      await ref.read(sessionControllerProvider.notifier).signIn(result);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _error = e);
      _form.currentState!.validate();
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final generalError = _error != null && _error!.errors.isEmpty ? describeError(l10n, _error) : null;
    return _AuthScaffold(
      title: l10n.registerTitle,
      subtitle: l10n.registerSubtitle,
      children: [
        Form(
          key: _form,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              AppTextField(
                controller: _name,
                label: l10n.nameLabel,
                autofillHints: const [AutofillHints.name],
                textCapitalization: TextCapitalization.words,
                textInputAction: TextInputAction.next,
                errorText: _error?.fieldError('name'),
                validator: (v) => (v == null || v.trim().length < 2) ? l10n.fieldRequired : null,
              ),
              const Gap(AppSpace.md),
              AppTextField(
                controller: _email,
                label: '${l10n.emailLabel} (${l10n.optional})',
                forceLtr: true,
                keyboardType: TextInputType.emailAddress,
                autofillHints: const [AutofillHints.email],
                textInputAction: TextInputAction.next,
                errorText: _error?.fieldError('email'),
                validator: (v) => (v != null && v.isNotEmpty && !RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(v.trim()))
                    ? l10n.emailInvalid
                    : null,
              ),
              const Gap(AppSpace.md),
              AppTextField(
                controller: _password,
                label: '${l10n.passwordLabel} (${l10n.optional})',
                helper: l10n.passwordOptionalHint,
                obscure: true,
                autofillHints: const [AutofillHints.newPassword],
                errorText: _error?.fieldError('password'),
                validator: (v) => (v != null && v.isNotEmpty && v.length < 8) ? l10n.passwordTooShort : null,
              ),
              const Gap(AppSpace.md),
              AppTextField(
                controller: _referral,
                label: '${l10n.referralCodeLabel} (${l10n.optional})',
                forceLtr: true,
                textCapitalization: TextCapitalization.characters,
                errorText: _error?.fieldError('referral_code'),
              ),
              const Gap(AppSpace.sm),
              CheckboxListTile(
                value: _marketing,
                onChanged: (v) => setState(() => _marketing = v ?? false),
                title: Text(l10n.marketingConsent, style: context.text.bodyMedium),
                controlAffinity: ListTileControlAffinity.leading,
                contentPadding: EdgeInsets.zero,
              ),
              if (generalError != null) ...[
                const Gap(AppSpace.xs),
                Text(generalError, style: context.text.bodySmall?.copyWith(color: context.palette.danger)),
              ],
              const Gap(AppSpace.lg),
              AppButton(label: l10n.createAccount, loading: _busy, onPressed: _submit),
              const Gap(AppSpace.md),
              const _TermsNote(),
            ],
          ),
        ),
      ],
    );
  }
}

/// Password sign-in (phone or email).
class PasswordLoginScreen extends ConsumerStatefulWidget {
  const PasswordLoginScreen({super.key, this.from});
  final String? from;

  @override
  ConsumerState<PasswordLoginScreen> createState() => _PasswordLoginScreenState();
}

class _PasswordLoginScreenState extends ConsumerState<PasswordLoginScreen> {
  final _id = TextEditingController();
  final _password = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _id.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final l10n = context.l10n;
    final raw = _id.text.trim();
    final identifier = raw.contains('@') ? raw : (QatarPhone.toE164(raw) ?? '');
    if (identifier.isEmpty || _password.text.isEmpty) {
      setState(() => _error = identifier.isEmpty ? l10n.phoneInvalid : l10n.fieldRequired);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await ref.read(authRepositoryProvider).login(identifier, _password.text);
      Haptics.success();
      await ref.read(sessionControllerProvider.notifier).signIn(result);
    } on ApiException catch (e) {
      if (!mounted) return;
      Haptics.warning();
      setState(() => _error = describeError(l10n, e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    return _AuthScaffold(
      title: l10n.loginWithPassword,
      subtitle: l10n.loginSubtitle,
      children: [
        AppTextField(
          controller: _id,
          label: l10n.emailOrPhoneLabel,
          forceLtr: true,
          keyboardType: TextInputType.emailAddress,
          autofillHints: const [AutofillHints.username],
          textInputAction: TextInputAction.next,
        ),
        const Gap(AppSpace.md),
        AppTextField(
          controller: _password,
          label: l10n.passwordLabel,
          obscure: true,
          autofillHints: const [AutofillHints.password],
          errorText: _error,
          onSubmitted: (_) => _submit(),
        ),
        Align(
          alignment: AlignmentDirectional.centerEnd,
          child: TextButton(onPressed: () => context.push('/login/reset'), child: Text(l10n.forgotPassword)),
        ),
        const Gap(AppSpace.sm),
        AppButton(label: l10n.signIn, loading: _busy, onPressed: _submit),
        const Gap(AppSpace.sm),
        AppButton.ghost(label: l10n.loginWithOtp, expand: true, onPressed: () => context.pop()),
      ],
    );
  }
}

/// Password reset: request code (purpose=password_reset) → code + new password.
class PasswordResetScreen extends ConsumerStatefulWidget {
  const PasswordResetScreen({super.key});

  @override
  ConsumerState<PasswordResetScreen> createState() => _PasswordResetScreenState();
}

class _PasswordResetScreenState extends ConsumerState<PasswordResetScreen> {
  final _phone = TextEditingController();
  final _code = TextEditingController();
  final _password = TextEditingController();
  final _confirm = TextEditingController();
  String? _e164;
  String? _debugCode;
  bool _busy = false;
  ApiException? _error;
  String? _localError;

  @override
  void dispose() {
    for (final c in [_phone, _code, _password, _confirm]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _requestCode() async {
    final l10n = context.l10n;
    final e164 = QatarPhone.toE164(_phone.text);
    if (e164 == null) {
      setState(() => _localError = l10n.phoneInvalid);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
      _localError = null;
    });
    try {
      final r = await ref.read(authRepositoryProvider).requestOtp(e164, purpose: 'password_reset');
      if (!mounted) return;
      setState(() {
        _e164 = e164;
        _debugCode = r.debugCode;
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _reset() async {
    final l10n = context.l10n;
    if (_password.text.length < 8) {
      setState(() => _localError = l10n.passwordTooShort);
      return;
    }
    if (_password.text != _confirm.text) {
      setState(() => _localError = l10n.passwordsDoNotMatch);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
      _localError = null;
    });
    try {
      await ref.read(authRepositoryProvider).resetPassword(
        phone: _e164!,
        code: QatarPhone.normalizeDigits(_code.text),
        password: _password.text,
      );
      if (!mounted) return;
      Haptics.success();
      showInfoSnack(context, l10n.passwordResetSuccess);
      context.pop();
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final general = _localError ?? (_error != null && _error!.errors.isEmpty ? describeError(l10n, _error) : null);
    final step2 = _e164 != null;
    return _AuthScaffold(
      title: l10n.resetPasswordTitle,
      subtitle: l10n.resetPasswordSubtitle,
      children: [
        AppTextField(
          controller: _phone,
          label: l10n.phoneLabel,
          prefix: '+974  ',
          forceLtr: true,
          enabled: !step2,
          keyboardType: TextInputType.phone,
          errorText: _error?.fieldError('phone'),
        ),
        if (step2) ...[
          const Gap(AppSpace.md),
          AppTextField(
            controller: _code,
            label: l10n.otpCodeLabel,
            forceLtr: true,
            keyboardType: TextInputType.number,
            autofillHints: const [AutofillHints.oneTimeCode],
            errorText: _error?.fieldError('code'),
          ),
          if (_debugCode != null && kDebugMode) Text(l10n.debugCodeHint(_debugCode!), style: context.text.bodySmall),
          const Gap(AppSpace.md),
          AppTextField(
            controller: _password,
            label: l10n.newPasswordLabel,
            obscure: true,
            autofillHints: const [AutofillHints.newPassword],
            errorText: _error?.fieldError('password'),
          ),
          const Gap(AppSpace.md),
          AppTextField(controller: _confirm, label: l10n.passwordConfirmLabel, obscure: true),
        ],
        if (general != null) ...[
          const Gap(AppSpace.sm),
          Text(general, style: context.text.bodySmall?.copyWith(color: context.palette.danger)),
        ],
        const Gap(AppSpace.lg),
        AppButton(
          label: step2 ? l10n.resetPassword : l10n.sendResetCode,
          loading: _busy,
          onPressed: step2 ? _reset : _requestCode,
        ),
      ],
    );
  }
}
