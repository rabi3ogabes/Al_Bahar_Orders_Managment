import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/router/app_router.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/onboarding/presentation/state/onboarding_state.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// Branded splash with a short, calm reveal, then routes onward.
class SplashScreen extends ConsumerStatefulWidget {
  const SplashScreen({super.key});

  @override
  ConsumerState<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends ConsumerState<SplashScreen> with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(vsync: this, duration: const Duration(milliseconds: 1100));
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    unawaited(_c.forward());
    _timer = Timer(const Duration(milliseconds: 1500), _next);
  }

  void _next() {
    if (!mounted) return;
    final localeChosen = ref.read(localeControllerProvider) != null;
    final onboarded = ref.read(onboardingDoneProvider);
    final launch = ref.read(launchLocationProvider.notifier).take();
    if (!localeChosen) {
      context.go('/language');
    } else if (!onboarded) {
      context.go('/onboarding');
    } else {
      context.go('/home');
      if (launch != null) unawaited(context.push(launch));
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final fade = CurvedAnimation(parent: _c, curve: Curves.easeOutCubic);
    return Scaffold(
      body: DecoratedBox(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [context.theme.scaffoldBackgroundColor, context.palette.surfaceMuted],
          ),
        ),
        child: Center(
          child: FadeTransition(
            opacity: fade,
            child: SlideTransition(
              position: Tween(begin: const Offset(0, 0.06), end: Offset.zero).animate(fade),
              child: const BrandMark(size: 1.2),
            ),
          ),
        ),
      ),
    );
  }
}

/// First-launch language choice; also reachable from Settings.
class LanguageScreen extends ConsumerWidget {
  const LanguageScreen({super.key, this.from});
  final String? from;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final current = ref.watch(localeCodeProvider);

    Future<void> choose(String code) async {
      Haptics.tap();
      await ref.read(localeControllerProvider.notifier).setLocale(Locale(code));
      if (ref.read(isAuthenticatedProvider)) {
        unawaited(ref.read(sessionControllerProvider.notifier).updateProfile({'locale': code}).then((_) {}, onError: (Object _) {}));
      }
      if (!context.mounted) return;
      final done = ref.read(onboardingDoneProvider);
      context.go(done ? (from ?? '/home') : '/onboarding${from != null ? '?from=${Uri.encodeComponent(from!)}' : ''}');
    }

    Widget option(String code, String label, String sample) {
      final selected = current == code;
      return Semantics(
        selected: selected,
        button: true,
        child: SurfaceCard(
          onTap: () => choose(code),
          color: selected ? context.palette.brassTint : null,
          padding: const EdgeInsetsDirectional.symmetric(horizontal: AppSpace.lg, vertical: AppSpace.lg),
          child: Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(label, style: context.text.headlineSmall?.copyWith(fontFamily: code == 'ar' ? 'IBMPlexSansArabic' : 'PlayfairDisplay')),
                    const Gap(AppSpace.xxs),
                    Text(sample, style: context.text.bodySmall),
                  ],
                ),
              ),
              AnimatedSwitcher(
                duration: AppDurations.fast,
                child: selected
                    ? Icon(Icons.check_circle_rounded, key: const ValueKey(true), color: context.palette.brass)
                    : Icon(Icons.circle_outlined, key: const ValueKey(false), color: context.palette.line),
              ),
            ],
          ),
        ),
      );
    }

    return Scaffold(
      body: SafeArea(
        child: ContentWidth(
          maxWidth: 520,
          child: Padding(
            padding: const EdgeInsets.all(AppSpace.lg),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const Spacer(),
                const BrandMark(),
                const Gap(AppSpace.xxl),
                Text(l10n.chooseLanguageTitle, textAlign: TextAlign.center, style: context.text.headlineMedium),
                const Gap(AppSpace.xs),
                Text(l10n.chooseLanguageSubtitle, textAlign: TextAlign.center, style: context.text.bodyMedium?.copyWith(color: context.palette.muted)),
                const Gap(AppSpace.xl),
                option('ar', 'العربية', 'مرحبًا بك في البحر'),
                const Gap(AppSpace.md),
                option('en', 'English', 'Welcome to Al Bahar'),
                const Spacer(flex: 2),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class OnboardingScreen extends ConsumerStatefulWidget {
  const OnboardingScreen({super.key, this.from});
  final String? from;

  @override
  ConsumerState<OnboardingScreen> createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends ConsumerState<OnboardingScreen> {
  final _page = PageController();
  int _index = 0;

  @override
  void dispose() {
    _page.dispose();
    super.dispose();
  }

  Future<void> _finish({bool signIn = true}) async {
    await ref.read(onboardingDoneProvider.notifier).complete();
    if (!mounted) return;
    final target = widget.from ?? '/home';
    if (signIn && !ref.read(isAuthenticatedProvider)) {
      context.go('/login?from=${Uri.encodeComponent(target)}');
    } else {
      context.go(target);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final pages = [
      (Icons.weekend_outlined, l10n.onboardingTitle1, l10n.onboardingBody1),
      (Icons.storefront_outlined, l10n.onboardingTitle2, l10n.onboardingBody2),
      (Icons.gavel_rounded, l10n.onboardingTitle3, l10n.onboardingBody3),
    ];
    final last = _index == pages.length - 1;

    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            Align(
              alignment: AlignmentDirectional.centerEnd,
              child: TextButton(onPressed: () => _finish(signIn: false), child: Text(l10n.skip)),
            ),
            Expanded(
              child: PageView.builder(
                controller: _page,
                itemCount: pages.length,
                onPageChanged: (i) {
                  Haptics.tap();
                  setState(() => _index = i);
                },
                itemBuilder: (context, i) {
                  final (icon, title, body) = pages[i];
                  return ContentWidth(
                    maxWidth: 520,
                    child: Padding(
                      padding: const EdgeInsets.symmetric(horizontal: AppSpace.xl),
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Container(
                            width: 180,
                            height: 220,
                            decoration: BoxDecoration(
                              color: context.palette.brassTint,
                              borderRadius: const BorderRadius.vertical(top: Radius.circular(90), bottom: Radius.circular(AppRadius.lg)),
                            ),
                            child: Icon(icon, size: 72, color: context.palette.brass),
                          ),
                          const Gap(AppSpace.xxl),
                          Text(title, textAlign: TextAlign.center, style: context.text.displaySmall),
                          const Gap(AppSpace.md),
                          Text(body, textAlign: TextAlign.center, style: context.text.bodyLarge?.copyWith(color: context.palette.muted)),
                        ],
                      ),
                    ),
                  );
                },
              ),
            ),
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                for (var i = 0; i < pages.length; i++)
                  AnimatedContainer(
                    duration: AppDurations.medium,
                    margin: const EdgeInsets.symmetric(horizontal: 4),
                    width: i == _index ? 22 : 8,
                    height: 8,
                    decoration: BoxDecoration(
                      color: i == _index ? context.palette.brass : context.palette.line,
                      borderRadius: BorderRadius.circular(4),
                    ),
                  ),
              ],
            ),
            Padding(
              padding: const EdgeInsets.all(AppSpace.lg),
              child: ContentWidth(
                maxWidth: 520,
                child: AppButton(
                  label: last ? l10n.getStarted : l10n.next,
                  onPressed: last
                      ? _finish
                      : () => _page.nextPage(duration: AppDurations.slow, curve: Curves.easeOutCubic),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
