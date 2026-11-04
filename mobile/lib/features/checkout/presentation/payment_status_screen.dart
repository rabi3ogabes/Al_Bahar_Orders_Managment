import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/network/interceptors.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/checkout/data/checkout_repository.dart';
import 'package:albahar_customer/features/checkout/domain/checkout_models.dart';
import 'package:albahar_customer/features/checkout/presentation/payment_flow.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

/// Polls `GET /payments/{id}` until a terminal status. Reached after the
/// SADAD browser returns (`albahar://payments/{id}/return`) or directly
/// after placing an order.
class PaymentPoller {
  PaymentPoller({
    required this.fetch,
    this.interval = const Duration(seconds: 3),
    this.timeout = const Duration(minutes: 3),
  });

  final Future<PaymentStatus> Function() fetch;
  final Duration interval;
  final Duration timeout;

  /// Emits each observed status; completes on terminal status or timeout.
  Stream<PaymentStatus> watch() async* {
    final deadline = DateTime.now().add(timeout);
    while (true) {
      try {
        final s = await fetch();
        yield s;
        if (s.isTerminal) return;
      } catch (_) {
        // Transient errors: keep polling until the deadline.
      }
      if (DateTime.now().isAfter(deadline)) return;
      await Future<void>.delayed(interval);
    }
  }
}

class PaymentStatusScreen extends ConsumerStatefulWidget {
  const PaymentStatusScreen({super.key, required this.paymentId, this.orderId, this.orderNumber});
  final String paymentId;
  final String? orderId;
  final String? orderNumber;

  @override
  ConsumerState<PaymentStatusScreen> createState() => _PaymentStatusScreenState();
}

class _PaymentStatusScreenState extends ConsumerState<PaymentStatusScreen> {
  StreamSubscription<PaymentStatus>? _sub;
  late String _paymentId = widget.paymentId;
  PaymentStatus? _status;
  bool _polling = false;
  bool _retrying = false;
  String? _retryKey;

  @override
  void initState() {
    super.initState();
    if (!kIsWeb && defaultTargetPlatform == TargetPlatform.iOS) {
      // Dismiss SFSafariViewController when the return URL brings us back.
      unawaited(closeInAppWebView().catchError((Object _) {}));
    }
    _start();
  }

  void _start() {
    if (_paymentId == 'none') return;
    unawaited(_sub?.cancel());
    setState(() => _polling = true);
    final repo = ref.read(checkoutRepositoryProvider);
    _sub = PaymentPoller(fetch: () => repo.payment(_paymentId)).watch().listen(
      (s) {
        if (!mounted) return;
        setState(() => _status = s);
        if (s.isPaid) Haptics.success();
        if (s.isTerminal && !s.isPaid) Haptics.warning();
      },
      onDone: () {
        if (mounted) setState(() => _polling = false);
      },
    );
  }

  Future<void> _retry() async {
    final orderId = widget.orderId ?? _status?.orderId;
    if (orderId == null) return;
    setState(() => _retrying = true);
    _retryKey ??= Idempotency.newKey();
    try {
      final action = await ref.read(checkoutRepositoryProvider).retryPayment(orderId, idempotencyKey: _retryKey!);
      _retryKey = null;
      _paymentId = action.paymentId;
      if (action.requiresRedirect) await openSecurePayment(action);
      if (mounted) _start();
    } catch (e) {
      if (mounted) showErrorSnack(context, e);
    } finally {
      if (mounted) setState(() => _retrying = false);
    }
  }

  @override
  void dispose() {
    unawaited(_sub?.cancel());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final palette = context.palette;
    final s = _status;
    final orderId = widget.orderId ?? s?.orderId;

    final (icon, color, title, message) = switch (s?.status) {
      'paid' || 'authorized' => (
        Icons.check_circle_rounded,
        palette.success,
        l10n.paymentPaid,
        widget.orderNumber != null ? l10n.orderPlacedMessage(widget.orderNumber!) : null,
      ),
      'failed' => (Icons.cancel_rounded, palette.danger, l10n.paymentFailed, s?.failureReason),
      'expired' => (Icons.timer_off_rounded, palette.warning, l10n.paymentExpired, null),
      'cancelled' => (Icons.do_not_disturb_on_rounded, palette.muted, l10n.paymentCancelled, null),
      _ => (
        Icons.hourglass_top_rounded,
        palette.brass,
        _polling ? l10n.paymentPending : l10n.paymentStillPending,
        _polling ? l10n.paymentPendingHint : null,
      ),
    };
    final terminal = s?.isTerminal ?? false;
    final failed = terminal && !(s?.isPaid ?? false);

    return PopScope(
      canPop: terminal || !_polling,
      child: Scaffold(
        appBar: AppBar(title: Text(l10n.paymentTitle), automaticallyImplyLeading: false),
        body: SafeArea(
          child: ContentWidth(
            maxWidth: 520,
            child: Padding(
              padding: const EdgeInsets.all(AppSpace.lg),
              child: Column(
                children: [
                  const Spacer(),
                  Semantics(
                    liveRegion: true,
                    child: Column(
                      children: [
                        AnimatedSwitcher(
                          duration: AppDurations.slow,
                          transitionBuilder: (c, a) => ScaleTransition(scale: a, child: c),
                          child: Icon(icon, key: ValueKey(icon), size: 84, color: color),
                        ),
                        const Gap(AppSpace.lg),
                        Text(title, textAlign: TextAlign.center, style: context.text.headlineMedium),
                        if (message != null) ...[
                          const Gap(AppSpace.xs),
                          Text(
                            message,
                            textAlign: TextAlign.center,
                            style: context.text.bodyMedium?.copyWith(color: palette.muted),
                          ),
                        ],
                        if (s?.amount != null) ...[
                          const Gap(AppSpace.md),
                          Text(s!.amount!.formatted, style: context.text.titleLarge),
                        ],
                        if (_polling && !terminal) ...[
                          const Gap(AppSpace.lg),
                          const SizedBox(width: 160, child: LinearProgressIndicator()),
                        ],
                      ],
                    ),
                  ),
                  const Spacer(),
                  if (failed && orderId != null) ...[
                    AppButton(label: l10n.retryPayment, loading: _retrying, onPressed: _retry),
                    const Gap(AppSpace.sm),
                  ],
                  if (!terminal && !_polling) ...[
                    AppButton(label: l10n.checkAgain, onPressed: _start),
                    const Gap(AppSpace.sm),
                  ],
                  if (orderId != null)
                    AppButton.secondary(label: l10n.viewOrder, onPressed: () => context.go('/orders/$orderId')),
                  const Gap(AppSpace.sm),
                  AppButton.ghost(label: l10n.continueShopping, expand: true, onPressed: () => context.go('/home')),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
