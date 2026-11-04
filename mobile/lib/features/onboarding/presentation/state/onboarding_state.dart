import 'package:albahar_customer/core/providers.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

class OnboardingController extends Notifier<bool> {
  @override
  bool build() => ref.watch(appPreferencesProvider).onboardingDone;

  Future<void> complete() async {
    state = true;
    await ref.read(appPreferencesProvider).setOnboardingDone();
  }
}

final onboardingDoneProvider = NotifierProvider<OnboardingController, bool>(OnboardingController.new);
