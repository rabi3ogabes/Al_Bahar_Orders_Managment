import 'package:flutter_riverpod/flutter_riverpod.dart';

/// True while something is being sent that has to finish on its own screen (an order being placed: its page opens the payment or the
/// order when the server answers). The footer menu ignores taps meanwhile, so nobody leaves the screen half way.
class NavigationLock extends Notifier<bool> {
  @override
  bool build() => false;

  // ignore: use_setters_to_change_properties
  void set(bool locked) => state = locked;
}

final navigationLockProvider = NotifierProvider<NavigationLock, bool>(NavigationLock.new);
