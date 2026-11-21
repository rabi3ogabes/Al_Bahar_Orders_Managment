import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/orders/data/order_repository.dart';
import 'package:albahar_customer/features/orders/domain/order_models.dart';
import 'package:albahar_customer/features/orders/presentation/state/order_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// What to say when answering failed: plain words for the likely reasons (never the server's bare sentence).
String substitutionFailureMessage(AppLocalizations l10n, Object error) {
  if (error is ApiException) {
    if (error.kind == ApiErrorKind.notFound) return l10n.substErrGone;
    if (error.kind == ApiErrorKind.conflict) {
      return switch (error.code) {
        'substitution_already_answered' => l10n.substErrAnswered,
        'substitution_expired' => l10n.substErrExpired,
        _ => l10n.substErrClosed,
      };
    }
  }
  return describeError(l10n, error);
}

/// The sentence about how a question ended, or null while it waits.
String? substitutionOutcome(AppLocalizations l10n, String status) => switch (status) {
  'accepted' => l10n.substOutcomeAccepted,
  'declined' => l10n.substOutcomeDeclined,
  'expired_removed' => l10n.substOutcomeExpiredRemoved,
  'expired_kept' => l10n.substOutcomeExpiredKept,
  'cancelled' => l10n.substOutcomeCancelled,
  _ => null,
};

/// "We do not have X. Replace it with Y?" on the order: the open questions first (Accept, or "No, remove it and refund me", with the time left and
/// what happens if nobody answers), then a calm line for each one that was answered or settled. The price is never above what was ordered.
class SubstitutionCards extends ConsumerStatefulWidget {
  const SubstitutionCards({super.key, required this.order});
  final Order order;

  @override
  ConsumerState<SubstitutionCards> createState() => _SubstitutionCardsState();
}

class _SubstitutionCardsState extends ConsumerState<SubstitutionCards> {
  Timer? _tick;
  String? _busy;
  var _now = DateTime.now();

  @override
  void initState() {
    super.initState();
    _tick = Timer.periodic(const Duration(seconds: 20), (_) {
      if (mounted) setState(() => _now = DateTime.now());
    });
  }

  @override
  void dispose() {
    _tick?.cancel();
    super.dispose();
  }

  Future<void> _answer(SubstitutionOffer o, {required bool accept}) async {
    final l10n = context.l10n;
    setState(() => _busy = '${o.id}:${accept ? 'accept' : 'decline'}');
    try {
      await ref.read(orderRepositoryProvider).answerSubstitution(widget.order.id, o.id, accept: accept);
      if (mounted) showInfoSnack(context, accept ? l10n.substAcceptedToast : l10n.substDeclinedToast);
    } on ApiException catch (e) {
      if (mounted) showInfoSnack(context, substitutionFailureMessage(l10n, e));
    } finally {
      // Whatever happened, the order is read again: the question may have moved on.
      ref.invalidate(orderDetailProvider(widget.order.id));
      ref.invalidate(orderTimelineProvider(widget.order.id));
      if (mounted) setState(() => _busy = null);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final all = widget.order.substitutions;
    final waiting = waitingOffers(all);
    final done = [
      for (final o in all)
        if (substitutionOutcome(l10n, o.status) != null) o,
    ];
    if (waiting.isEmpty && done.isEmpty) return const SizedBox.shrink();
    final p = context.palette;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final o in waiting)
          _Question(
            offer: o,
            now: _now,
            busy: _busy,
            onAnswer: (a) => _answer(o, accept: a),
          ),
        if (done.isNotEmpty)
          Container(
            key: const ValueKey('substitution-history'),
            margin: const EdgeInsets.only(bottom: AppSpace.sm),
            padding: const EdgeInsets.all(AppSpace.sm),
            decoration: BoxDecoration(color: p.surfaceMuted, borderRadius: BorderRadius.circular(12)),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                for (final o in done)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 2),
                    child: Text(
                      '${o.original?.name ?? ''} → ${o.replacement?.name ?? ''} · ${substitutionOutcome(l10n, o.status)}',
                      style: context.text.bodySmall,
                    ),
                  ),
              ],
            ),
          ),
      ],
    );
  }
}

class _Question extends StatelessWidget {
  const _Question({required this.offer, required this.now, required this.busy, required this.onAnswer});
  final SubstitutionOffer offer;
  final DateTime now;
  final String? busy;
  final void Function(bool accept) onAnswer;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final p = context.palette;
    final o = offer;
    final left = o.minutesLeft(now);
    final note = o.isCheaper
        ? l10n.substPriceLess(o.priceDifference!.formatted.replaceFirst('-', ''))
        : l10n.substPriceSame;
    final working = busy != null && busy!.startsWith(o.id);
    return Semantics(
      container: true,
      liveRegion: true,
      child: Container(
        key: ValueKey('substitution-${o.id}'),
        margin: const EdgeInsets.only(bottom: AppSpace.sm),
        padding: const EdgeInsets.all(AppSpace.md),
        decoration: BoxDecoration(
          color: p.tint,
          borderRadius: AppRadius.cardAll,
          border: Border.all(color: p.brass.withValues(alpha: 0.4)),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Semantics(
              header: true,
              child: Text(l10n.substTitle, style: context.text.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
            ),
            const Gap(AppSpace.xxs),
            Text(l10n.substAsk(o.original?.name ?? '', o.replacement?.name ?? ''), style: context.text.bodyMedium),
            const Gap(AppSpace.sm),
            _Side(label: l10n.substYouOrdered, line: o.original),
            const Gap(AppSpace.xs),
            _Side(label: l10n.substReplacement, line: o.replacement),
            const Gap(AppSpace.sm),
            Text(l10n.substPriceLine(note), style: context.text.bodyMedium),
            const Gap(AppSpace.xxs),
            Text(
              left > 0 ? l10n.substTimeLeft(left) : l10n.substTimeUp,
              key: const ValueKey('substitution-clock'),
              style: context.text.bodySmall?.copyWith(color: p.muted),
            ),
            Text(
              o.fallback == 'keep' ? l10n.substFallbackKeep : l10n.substFallbackRemove,
              style: context.text.bodySmall?.copyWith(color: p.muted),
            ),
            const Gap(AppSpace.sm),
            AppButton(
              key: const ValueKey('substitution-accept'),
              label: l10n.substAccept,
              icon: Icons.check_rounded,
              loading: busy == '${o.id}:accept',
              onPressed: working || !o.canAnswer ? null : () => onAnswer(true),
            ),
            const Gap(AppSpace.xs),
            AppButton.secondary(
              key: const ValueKey('substitution-decline'),
              label: l10n.substDecline,
              loading: busy == '${o.id}:decline',
              labelMaxLines: 2,
              onPressed: working || !o.canAnswer ? null : () => onAnswer(false),
            ),
          ],
        ),
      ),
    );
  }
}

class _Side extends StatelessWidget {
  const _Side({required this.label, required this.line});
  final String label;
  final SubstitutionLine? line;

  @override
  Widget build(BuildContext context) {
    final l = line;
    if (l == null) return const SizedBox.shrink();
    final p = context.palette;
    return Row(
      children: [
        AppNetworkImage(url: l.imageUrl, width: 48, height: 56, borderRadius: BorderRadius.circular(8)),
        const Gap(AppSpace.sm),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(label, style: context.text.labelSmall?.copyWith(color: p.muted)),
              Text(l.name, maxLines: 2, overflow: TextOverflow.ellipsis, style: context.text.titleSmall),
              if (l.variantName != null) Text(l.variantName!, style: context.text.bodySmall),
              Text(
                '${context.l10n.substQty(l.quantity)}${l.total == null ? '' : ' · ${l.total!.formatted}'}',
                style: context.text.bodySmall?.copyWith(color: p.muted),
              ),
            ],
          ),
        ),
      ],
    );
  }
}
