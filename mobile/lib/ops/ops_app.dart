import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/push/push_service.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/update/app_update.dart';
import 'package:albahar_customer/ops/driver/driver_home.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_session.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/staff/staff_home.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:package_info_plus/package_info_plus.dart';

/// Root of both staff apps: the same shell, a different home screen per [OpsRole].
class OpsApp extends ConsumerStatefulWidget {
  const OpsApp({super.key});

  @override
  ConsumerState<OpsApp> createState() => _OpsAppState();
}

class _OpsAppState extends ConsumerState<OpsApp> {
  final _navigator = GlobalKey<NavigatorState>();

  @override
  void initState() {
    super.initState();
    // A saved session starts signed in: the push token must be (re)registered on every launch, not only after a sign-in.
    if (ref.read(opsSessionProvider) == OpsAuth.signedIn) _registerPush();
  }

  void _registerPush() => unawaited(ref.read(pushServiceProvider).register().catchError((Object _) {}));

  @override
  Widget build(BuildContext context) {
    final role = ref.watch(opsRoleProvider);
    final locale = Locale(ref.watch(localeCodeProvider));
    final auth = ref.watch(opsSessionProvider);
    ref.listen(opsSessionProvider, (_, next) {
      if (next == OpsAuth.signedIn) _registerPush();
      // Signed out (or expired) while a pushed screen or sheet is open: close them so only the sign-in screen is left.
      if (next == OpsAuth.signedOut) _navigator.currentState?.popUntil((r) => r.isFirst);
    });
    return MaterialApp(
      navigatorKey: _navigator,
      debugShowCheckedModeBanner: false,
      onGenerateTitle: (c) => role == OpsRole.driver ? OpsStrings.of(c).driverApp : OpsStrings.of(c).staffApp,
      locale: locale,
      supportedLocales: supportedLocales,
      localizationsDelegates: const [
        AppLocalizations.delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      theme: AppTheme.light(locale),
      darkTheme: AppTheme.dark(locale),
      themeMode: ref.watch(themeModeProvider),
      builder: (context, child) =>
          UpdateGate(baseUrl: ref.read(appConfigProvider).apiBaseUrl, child: child ?? const SizedBox.shrink()),
      home: auth == OpsAuth.signedIn
          ? (role == OpsRole.driver ? const DriverHome() : const StaffHome())
          : const OpsLoginScreen(),
    );
  }
}

/// App icon tile: a different glyph and tone per app, so the two apps are easy to tell apart.
class OpsEmblem extends StatelessWidget {
  const OpsEmblem({super.key, required this.role, this.size = 76});
  final OpsRole role;
  final double size;

  @override
  Widget build(BuildContext context) {
    final driver = role == OpsRole.driver;
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(size * 0.3),
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: driver ? const [AppPalette.navy, AppPalette.navySoft] : const [Color(0xFF2A1A20), Color(0xFF4F3B43)],
        ),
        boxShadow: kSoftShadow,
      ),
      child: Icon(
        driver ? Icons.two_wheeler_rounded : Icons.storefront_rounded,
        size: size * 0.52,
        color: AppPalette.goldSoft,
      ),
    );
  }
}

class OpsLoginScreen extends ConsumerStatefulWidget {
  const OpsLoginScreen({super.key});

  @override
  ConsumerState<OpsLoginScreen> createState() => _OpsLoginScreenState();
}

class _OpsLoginScreenState extends ConsumerState<OpsLoginScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _code = TextEditingController();
  bool _busy = false;
  bool _show = false;
  bool _needsCode = false;
  String? _error;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    _code.dispose();
    super.dispose();
  }

  String _describe(Object e) {
    if (e is ApiException && e.kind == ApiErrorKind.validation) {
      return e.fieldError('email') ?? e.fieldError('password') ?? describeError(context.l10n, e);
    }
    return describeError(context.l10n, e);
  }

  Future<void> _submit(OpsStrings t, OpsRole role) async {
    if (_busy) return;
    final email = _email.text.trim();
    if (email.isEmpty || _password.text.isEmpty || (_needsCode && _code.text.trim().isEmpty)) {
      setState(() => _error = _needsCode && _code.text.trim().isEmpty ? t.twoFactorHint : t.signInHintRequired);
      return;
    }
    FocusScope.of(context).unfocus();
    setState(() {
      _busy = true;
      _error = null;
    });
    String? error;
    try {
      final outcome = await ref
          .read(opsSessionProvider.notifier)
          .signIn(email, _password.text, twoFactorCode: _needsCode ? _code.text.trim() : null);
      switch (outcome) {
        case OpsSignIn.success:
          TextInput.finishAutofillContext();
        case OpsSignIn.notEligible:
          error = role == OpsRole.driver ? t.notDriver : t.notStaff;
        case OpsSignIn.twoFactorRequired:
          _needsCode = true;
          error = t.twoFactorHint;
      }
    } catch (e) {
      if (mounted) error = _describe(e);
    }
    if (mounted) {
      setState(() {
        _busy = false;
        _error = error;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final role = ref.watch(opsRoleProvider);
    final expired = ref.read(opsSessionProvider.notifier).expired;
    final message = _error ?? (expired ? t.sessionExpired : null);
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(AppSpace.lg),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: AutofillGroup(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Align(
                      alignment: AlignmentDirectional.centerEnd,
                      child: TextButton(
                        onPressed: () =>
                            ref.read(localeControllerProvider.notifier).setLocale(Locale(t.ar ? 'en' : 'ar')),
                        child: Text(t.language),
                      ),
                    ),
                    Center(child: OpsEmblem(role: role, size: 92)),
                    const Gap(AppSpace.md),
                    Text(
                      role == OpsRole.driver ? t.driverApp : t.staffApp,
                      textAlign: TextAlign.center,
                      style: context.text.headlineMedium,
                    ),
                    const Gap(AppSpace.xs),
                    Text(
                      role == OpsRole.driver ? t.signInHintDriver : t.signInHintStaff,
                      textAlign: TextAlign.center,
                      style: context.text.bodyMedium,
                    ),
                    const Gap(AppSpace.lg),
                    TextField(
                      controller: _email,
                      enabled: !_busy,
                      keyboardType: TextInputType.emailAddress,
                      autofillHints: const [AutofillHints.username, AutofillHints.email],
                      autocorrect: false,
                      enableSuggestions: false,
                      textInputAction: TextInputAction.next,
                      inputFormatters: [FilteringTextInputFormatter.deny(RegExp(r'\s'))],
                      decoration: InputDecoration(
                        labelText: t.email,
                        prefixIcon: const Icon(Icons.alternate_email_rounded),
                      ),
                    ),
                    const Gap(AppSpace.sm),
                    TextField(
                      controller: _password,
                      enabled: !_busy,
                      obscureText: !_show,
                      autocorrect: false,
                      enableSuggestions: false,
                      autofillHints: const [AutofillHints.password],
                      textInputAction: _needsCode ? TextInputAction.next : TextInputAction.done,
                      onSubmitted: _needsCode ? null : (_) => _submit(t, role),
                      decoration: InputDecoration(
                        labelText: t.password,
                        prefixIcon: const Icon(Icons.lock_outline_rounded),
                        suffixIcon: IconButton(
                          tooltip: _show ? t.hidePassword : t.showPassword,
                          icon: Icon(_show ? Icons.visibility_off_outlined : Icons.visibility_outlined),
                          onPressed: () => setState(() => _show = !_show),
                        ),
                      ),
                    ),
                    if (_needsCode) ...[
                      const Gap(AppSpace.sm),
                      TextField(
                        controller: _code,
                        enabled: !_busy,
                        autofocus: true,
                        maxLength: 32,
                        keyboardType: TextInputType.number,
                        autofillHints: const [AutofillHints.oneTimeCode],
                        textInputAction: TextInputAction.done,
                        inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                        onSubmitted: (_) => _submit(t, role),
                        decoration: InputDecoration(
                          labelText: t.twoFactorCode,
                          prefixIcon: const Icon(Icons.verified_user_outlined),
                        ),
                      ),
                    ],
                    if (message != null) ...[
                      const Gap(AppSpace.sm),
                      Semantics(
                        liveRegion: true,
                        child: Text(
                          message,
                          style: TextStyle(
                            color: _error == null && expired ? context.palette.muted : context.palette.danger,
                          ),
                          textAlign: TextAlign.center,
                        ),
                      ),
                    ],
                    const Gap(AppSpace.lg),
                    FilledButton(
                      onPressed: _busy ? null : () => _submit(t, role),
                      child: _busy
                          ? SizedBox(
                              width: 22,
                              height: 22,
                              child: CircularProgressIndicator(
                                strokeWidth: 2.4,
                                color: Theme.of(context).colorScheme.onPrimary,
                              ),
                            )
                          : Text(t.signIn),
                    ),
                    const Gap(AppSpace.lg),
                    const _VersionFooter(),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// "1.0.0 (12) · a1b2c3d": tells you exactly which build is installed.
class _VersionFooter extends StatelessWidget {
  const _VersionFooter();

  static const _sha = String.fromEnvironment('BUILD_SHA');
  static final Future<PackageInfo> _info = PackageInfo.fromPlatform();

  @override
  Widget build(BuildContext context) => FutureBuilder<PackageInfo>(
    future: _info,
    builder: (context, snap) {
      final info = snap.data;
      if (info == null) return const SizedBox.shrink();
      return Text(
        'v${info.version} (${info.buildNumber})${_sha.isEmpty ? '' : ' · $_sha'}',
        textAlign: TextAlign.center,
        style: Theme.of(context).textTheme.bodySmall,
      );
    },
  );
}
