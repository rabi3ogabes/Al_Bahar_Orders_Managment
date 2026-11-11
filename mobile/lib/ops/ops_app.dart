import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';
import 'package:albahar_customer/ops/driver/driver_home.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_session.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/staff/staff_home.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:package_info_plus/package_info_plus.dart';

/// Root of both staff apps: the same shell, a different home screen per [OpsRole].
class OpsApp extends ConsumerWidget {
  const OpsApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final role = ref.watch(opsRoleProvider);
    final locale = Locale(ref.watch(localeCodeProvider));
    final auth = ref.watch(opsSessionProvider);
    return MaterialApp(
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
  bool _busy = false;
  bool _show = false;
  String? _error;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit(OpsStrings t, OpsRole role) async {
    if (_email.text.trim().isEmpty || _password.text.isEmpty) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    final err = await ref
        .read(opsSessionProvider.notifier)
        .signIn(_email.text, _password.text, notEligible: role == OpsRole.driver ? t.notDriver : t.notStaff);
    if (mounted) {
      setState(() {
        _busy = false;
        _error = err;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final role = ref.watch(opsRoleProvider);
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
                      keyboardType: TextInputType.emailAddress,
                      autofillHints: const [AutofillHints.username],
                      textInputAction: TextInputAction.next,
                      decoration: InputDecoration(
                        labelText: t.email,
                        prefixIcon: const Icon(Icons.alternate_email_rounded),
                      ),
                    ),
                    const Gap(AppSpace.sm),
                    TextField(
                      controller: _password,
                      obscureText: !_show,
                      autofillHints: const [AutofillHints.password],
                      onSubmitted: (_) => _submit(t, role),
                      decoration: InputDecoration(
                        labelText: t.password,
                        prefixIcon: const Icon(Icons.lock_outline_rounded),
                        suffixIcon: IconButton(
                          icon: Icon(_show ? Icons.visibility_off_outlined : Icons.visibility_outlined),
                          onPressed: () => setState(() => _show = !_show),
                        ),
                      ),
                    ),
                    if (_error != null) ...[
                      const Gap(AppSpace.sm),
                      Text(
                        _error!,
                        style: TextStyle(color: context.palette.danger),
                        textAlign: TextAlign.center,
                      ),
                    ],
                    const Gap(AppSpace.lg),
                    FilledButton(
                      onPressed: _busy ? null : () => _submit(t, role),
                      child: _busy
                          ? const SizedBox(
                              width: 22,
                              height: 22,
                              child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white),
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

  @override
  Widget build(BuildContext context) => FutureBuilder<PackageInfo>(
    future: PackageInfo.fromPlatform(),
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
