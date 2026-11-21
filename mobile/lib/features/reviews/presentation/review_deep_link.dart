import 'dart:async';

import 'package:albahar_customer/features/auth/presentation/require_auth.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/reviews/presentation/write_review_screen.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Draws nothing. A product opened from the "How is your ...?" message (`albahar://products/{id}?review=1`) opens the review window once the page is
/// there; a visitor who is signed out is taken to sign in first and the window opens when they are back. It happens once per visit of the page.
class ReviewDeepLink extends ConsumerStatefulWidget {
  const ReviewDeepLink({super.key, required this.enabled, required this.productId, required this.productName});

  final bool enabled;
  final String productId;
  final String productName;

  @override
  ConsumerState<ReviewDeepLink> createState() => _ReviewDeepLinkState();
}

class _ReviewDeepLinkState extends ConsumerState<ReviewDeepLink> {
  late bool _pending = widget.enabled;
  bool _askedToSignIn = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _open());
  }

  void _open() {
    if (!mounted || !_pending) return;
    if (!ref.read(isAuthenticatedProvider)) {
      if (!_askedToSignIn) {
        _askedToSignIn = true;
        requireAuth(context, ref);
      }
      return;
    }
    _pending = false;
    unawaited(showReviewPanel(context, productId: widget.productId, productName: widget.productName));
  }

  @override
  Widget build(BuildContext context) {
    ref.listen<bool>(isAuthenticatedProvider, (previous, signedIn) {
      if (signedIn && previous != true) WidgetsBinding.instance.addPostFrameCallback((_) => _open());
    });
    return const SizedBox.shrink();
  }
}
