import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Switches the whole app to [code] (`ar` or `en`) in place: the screen, its text direction and the data behind it follow at
/// once, nothing typed is lost and the page stays where it is. The choice is remembered, and a signed-in customer's profile
/// follows it.
Future<void> changeAppLanguage(WidgetRef ref, String code) async {
  await ref.read(localeControllerProvider.notifier).setLocale(Locale(code));
  if (ref.read(isAuthenticatedProvider)) {
    unawaited(
      ref.read(sessionControllerProvider.notifier).updateProfile({'locale': code}).then((_) {}, onError: (Object _) {}),
    );
  }
}

/// A small pill that offers the OTHER language in its own script ("English" while the app is Arabic, "العربية" while it is
/// English), for every screen a customer sees before signing in.
class LanguageSwitch extends ConsumerWidget {
  const LanguageSwitch({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final palette = context.palette;
    final current = ref.watch(localeCodeProvider);
    final target = current == 'ar' ? 'en' : 'ar';
    final label = target == 'ar' ? l10n.languageArabic : l10n.languageEnglish;
    final reduce = MediaQuery.disableAnimationsOf(context);

    return Semantics(
      button: true,
      label: l10n.switchLanguageTo(label),
      excludeSemantics: true,
      child: Tooltip(
        message: l10n.switchLanguageTo(label),
        excludeFromSemantics: true,
        child: ConstrainedBox(
          constraints: const BoxConstraints(minWidth: kMinTouchTarget, minHeight: kMinTouchTarget),
          child: Center(
            child: Material(
              color: context.colors.surface,
              shape: StadiumBorder(side: BorderSide(color: palette.line)),
              child: InkWell(
                customBorder: const StadiumBorder(),
                onTap: () {
                  Haptics.tap();
                  unawaited(changeAppLanguage(ref, target));
                },
                child: Padding(
                  padding: const EdgeInsetsDirectional.fromSTEB(AppSpace.sm, AppSpace.xs, AppSpace.md, AppSpace.xs),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(Icons.language_rounded, size: 18, color: palette.brassDeep),
                      const Gap(AppSpace.xs),
                      AnimatedSwitcher(
                        duration: reduce ? Duration.zero : AppDurations.medium,
                        transitionBuilder: (child, animation) => FadeTransition(
                          opacity: animation,
                          child: SlideTransition(
                            position: Tween(begin: const Offset(0, 0.35), end: Offset.zero).animate(animation),
                            child: child,
                          ),
                        ),
                        child: Text(
                          label,
                          key: ValueKey(target),
                          // The name is always written in its own script, whatever the app's language is.
                          style: context.text.labelLarge?.copyWith(
                            fontFamily: target == 'ar' ? AppFonts.arabic : AppFonts.body,
                            letterSpacing: 0,
                          ),
                        ),
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

/// Fades a screen's content in when the app's language changes under it, so the swap reads as one calm movement instead of a
/// jump. The content stays mounted (the same widgets, the same State), so typed text, focus and the current step are kept.
/// Does nothing for people who asked for reduced motion.
class LanguageTransition extends StatefulWidget {
  const LanguageTransition({super.key, required this.child});
  final Widget child;

  @override
  State<LanguageTransition> createState() => _LanguageTransitionState();
}

class _LanguageTransitionState extends State<LanguageTransition> with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(vsync: this, duration: AppDurations.slow, value: 1);
  late final Animation<double> _curve = CurvedAnimation(parent: _controller, curve: Curves.easeOutCubic);
  Locale? _locale;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final locale = Localizations.localeOf(context);
    if (_locale != null && _locale != locale && !MediaQuery.disableAnimationsOf(context)) {
      unawaited(_controller.forward(from: 0));
    }
    _locale = locale;
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final towardsEnd = context.isRtl ? -1.0 : 1.0;
    return FadeTransition(
      opacity: Tween(begin: 0.25, end: 1.0).animate(_curve),
      child: SlideTransition(
        position: Tween(begin: Offset(0.025 * towardsEnd, 0), end: Offset.zero).animate(_curve),
        child: widget.child,
      ),
    );
  }
}

/// For a screen's State: runs [onLanguageChanged] when the app's language changes, so a message written in the old language
/// (a form error, a hint kept from the last attempt) is dropped instead of staying on screen in the wrong language.
/// Typed text is never touched.
mixin ClearsOnLanguageChange<T extends StatefulWidget> on State<T> {
  Locale? _languageSeen;

  /// Forget the messages that were written in the previous language. Runs before the screen builds again.
  void onLanguageChanged();

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final locale = Localizations.localeOf(context);
    if (_languageSeen != null && _languageSeen != locale) onLanguageChanged();
    _languageSeen = locale;
  }
}
