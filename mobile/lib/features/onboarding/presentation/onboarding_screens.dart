import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/router/app_router.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/language_switch.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/onboarding/presentation/art/doha_scene.dart';
import 'package:albahar_customer/features/onboarding/presentation/art/majlis_scene.dart';
import 'package:albahar_customer/features/onboarding/presentation/art/qatar_map_scene.dart';
import 'package:albahar_customer/features/onboarding/presentation/designed_by_line.dart';
import 'package:albahar_customer/features/onboarding/presentation/intro_page.dart';
import 'package:albahar_customer/features/onboarding/presentation/state/onboarding_state.dart';
import 'package:albahar_customer/features/public_config/domain/intro_slide_text.dart';
import 'package:albahar_customer/features/public_config/presentation/state/public_config_state.dart';
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
    // The dashboard's public settings (the intro's footer line, the alternative-number switch): ask while the splash is showing, so
    // the answer is usually there by the time the first screen opens. It never delays the splash.
    ref.read(publicConfigProvider);
    _timer = Timer(const Duration(milliseconds: 1500), _next);
  }

  void _next() {
    if (!mounted) return;
    final onboarded = ref.read(onboardingDoneProvider);
    final launch = ref.read(launchLocationProvider.notifier).take();
    if (!onboarded) {
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
            colors: [context.pageColor, context.palette.surfaceMuted],
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

/// The first-run intro: three illustrated pages about Qatar, the branches and the auctions. The language can be switched
/// from the corner at any time. The "Designed by" line and, when the dashboard has written them, the words of the slides come
/// from the dashboard; the app's own words (the language files) are what shows first, offline and for every empty field.
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
    final reduce = MediaQuery.disableAnimationsOf(context);
    // The dashboard's words when it has some for this language, else the app's own. Nothing here waits for the network: the
    // last answer is already in the state (or nothing is), and a newer one arriving later cross-fades in place on the page.
    final custom = ref.watch(publicConfigProvider.select((c) => c.introSlides));
    final code = Localizations.localeOf(context).languageCode == 'ar' ? 'ar' : 'en';
    IntroSlideText words(int i) => i < custom.length ? custom[i] : IntroSlideText.none;
    final slides = [
      IntroSlide(
        title: words(0).title(code) ?? l10n.onboardingTitle1,
        body: words(0).body(code) ?? l10n.onboardingBody1,
        artLabel: l10n.onboardingArtLabel1,
        layers: DohaScene.layers,
      ),
      IntroSlide(
        title: words(1).title(code) ?? l10n.onboardingTitle2,
        body: words(1).body(code) ?? l10n.onboardingBody2,
        artLabel: l10n.onboardingArtLabel2,
        layers: QatarMapScene.layers,
      ),
      IntroSlide(
        title: words(2).title(code) ?? l10n.onboardingTitle3,
        body: words(2).body(code) ?? l10n.onboardingBody3,
        artLabel: l10n.onboardingArtLabel3,
        layers: MajlisScene.layers,
      ),
    ];
    final last = _index == slides.length - 1;

    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            // The language switch sits at the start, Skip at the end.
            Padding(
              padding: const EdgeInsetsDirectional.only(start: AppSpace.md, end: AppSpace.xs, top: AppSpace.xxs),
              child: Row(
                children: [
                  const LanguageSwitch(),
                  const Spacer(),
                  TextButton(onPressed: () => _finish(signIn: false), child: Text(l10n.skip)),
                ],
              ),
            ),
            Expanded(
              child: PageView.builder(
                controller: _page,
                itemCount: slides.length,
                onPageChanged: (i) {
                  Haptics.tap();
                  setState(() => _index = i);
                },
                itemBuilder: (context, i) => IntroPage(slide: slides[i], index: i, controller: _page),
              ),
            ),
            Semantics(
              container: true,
              label: '${_index + 1} / ${slides.length}',
              excludeSemantics: true,
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  for (var i = 0; i < slides.length; i++)
                    AnimatedContainer(
                      duration: reduce ? Duration.zero : AppDurations.medium,
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
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(AppSpace.lg, AppSpace.md, AppSpace.lg, 0),
              child: ContentWidth(
                maxWidth: 520,
                child: AppButton(
                  label: last ? l10n.getStarted : l10n.next,
                  onPressed: last
                      ? _finish
                      : () => _page.nextPage(
                          duration: reduce ? Duration.zero : AppDurations.slow,
                          curve: Curves.easeOutCubic,
                        ),
                ),
              ),
            ),
            const DesignedByLine(),
          ],
        ),
      ),
    );
  }
}
