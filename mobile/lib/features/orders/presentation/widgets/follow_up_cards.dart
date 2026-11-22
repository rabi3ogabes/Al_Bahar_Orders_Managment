import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/network/interceptors.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/orders/data/order_repository.dart';
import 'package:albahar_customer/features/orders/domain/order_models.dart';
import 'package:albahar_customer/features/orders/presentation/state/order_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// What to say when picking a time failed: plain words for the likely reasons (never the server's bare sentence).
String slotOfferFailureMessage(AppLocalizations l10n, Object error) {
  if (error is ApiException) {
    if (error.kind == ApiErrorKind.notFound) return l10n.slotOfferErrGone;
    return switch (error.code) {
      'offer_expired' => l10n.slotOfferExpired,
      'offer_already_chosen' => l10n.slotOfferErrChosen,
      'offer_closed' => l10n.slotOfferErrClosed,
      'slot_full' || 'slot_unavailable' => l10n.slotOfferErrTaken,
      _ => describeError(l10n, error),
    };
  }
  return describeError(l10n, error);
}

String _timeOf(BuildContext context, OfferedTime t) {
  final locale = context.localeCode;
  return '${QatarTime.slotDay(t.date, locale)} · ${QatarTime.slotRange(t.startsAt, t.endsAt, locale)}';
}

/// "We could not deliver. Choose a new time": one card per part whose delivery failed and was offered new times. It sits at the top of the order, like
/// a replacement question, because it waits for the customer. Open: the times with Confirm (one primary action); no time left free, an offer that ran
/// out, and a time already chosen are calm lines. The server decides which times are offered and whether one is still free.
class SlotOfferCards extends StatelessWidget {
  const SlotOfferCards({super.key, required this.order});
  final Order order;

  @override
  Widget build(BuildContext context) {
    final parts = [
      for (final f in order.fulfillments)
        if (f.slotOffer != null) f,
    ];
    if (parts.isEmpty) return const SizedBox.shrink();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final f in parts)
          _SlotOfferCard(key: ValueKey('slot-offer-${f.id}'), orderId: order.id, offer: f.slotOffer!),
      ],
    );
  }
}

class _SlotOfferCard extends ConsumerStatefulWidget {
  const _SlotOfferCard({super.key, required this.orderId, required this.offer});
  final String orderId;
  final SlotOfferInfo offer;

  @override
  ConsumerState<_SlotOfferCard> createState() => _SlotOfferCardState();
}

class _SlotOfferCardState extends ConsumerState<_SlotOfferCard> {
  String? _selected;
  String? _key;
  var _busy = false;

  void _select(OfferedTime t) {
    if (_selected == t.key) return;
    // A new choice is a new request: its own key. The same choice sent again after a lost answer keeps its key, so it never books twice.
    setState(() {
      _selected = t.key;
      _key = Idempotency.newKey();
    });
  }

  Future<void> _confirm(OfferedTime t) async {
    final l10n = context.l10n;
    setState(() => _busy = true);
    try {
      await ref
          .read(orderRepositoryProvider)
          .chooseDeliveryTime(
            widget.orderId,
            widget.offer.id,
            slotId: t.slotId,
            date: t.date,
            idempotencyKey: _key ?? Idempotency.newKey(),
          );
      if (mounted) showInfoSnack(context, l10n.slotOfferChosenToast(_timeOf(context, t)));
    } on ApiException catch (e) {
      if (mounted) showInfoSnack(context, slotOfferFailureMessage(l10n, e));
    } finally {
      // Whatever happened, the order is read again: the offer may have moved on or a time may have been taken.
      ref.invalidate(orderDetailProvider(widget.orderId));
      ref.invalidate(orderTimelineProvider(widget.orderId));
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final p = context.palette;
    final o = widget.offer;
    final chosen = o.chosen;
    if (o.isChosen && chosen != null) {
      return _Calm(
        key: const ValueKey('slot-offer-chosen'),
        icon: Icons.event_available_rounded,
        title: l10n.slotOfferChosen,
        body: _timeOf(context, chosen),
      );
    }
    if (o.isExpired) {
      return _Calm(
        key: const ValueKey('slot-offer-expired'),
        icon: Icons.event_busy_outlined,
        title: l10n.slotOfferTitle,
        body: l10n.slotOfferExpired,
      );
    }
    if (!o.canChoose) {
      return _Calm(
        key: const ValueKey('slot-offer-all-taken'),
        icon: Icons.event_busy_outlined,
        title: l10n.slotOfferTitle,
        body: l10n.slotOfferAllTaken,
      );
    }
    final selected = o.options.where((t) => t.key == _selected && t.available).firstOrNull;
    return Semantics(
      container: true,
      liveRegion: true,
      child: Container(
        key: const ValueKey('slot-offer-open'),
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
              child: Text(l10n.slotOfferTitle, style: context.text.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
            ),
            const Gap(AppSpace.xxs),
            Text(l10n.slotOfferIntro, style: context.text.bodyMedium),
            if (o.expiresAt != null) ...[
              const Gap(AppSpace.xxs),
              Text(
                l10n.slotOfferUntil(QatarTime.dateTime(o.expiresAt, context.localeCode)),
                style: context.text.bodySmall?.copyWith(color: p.muted),
              ),
            ],
            const Gap(AppSpace.sm),
            for (final t in o.options) ...[
              _TimeTile(time: t, selected: t.key == _selected, enabled: t.available && !_busy, onTap: () => _select(t)),
              const Gap(AppSpace.xs),
            ],
            const Gap(AppSpace.xs),
            AppButton(
              key: const ValueKey('slot-offer-confirm'),
              label: l10n.slotOfferConfirm,
              icon: Icons.check_rounded,
              loading: _busy,
              onPressed: selected == null || _busy ? null : () => _confirm(selected),
            ),
          ],
        ),
      ),
    );
  }
}

class _TimeTile extends StatelessWidget {
  const _TimeTile({required this.time, required this.selected, required this.enabled, required this.onTap});
  final OfferedTime time;
  final bool selected;
  final bool enabled;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final p = context.palette;
    final label = _timeOf(context, time);
    return Semantics(
      button: true,
      selected: selected,
      enabled: enabled,
      label: time.available ? label : '$label, ${l10n.slotOfferTaken}',
      child: ExcludeSemantics(
        child: InkWell(
          key: ValueKey('slot-offer-time-${time.key}'),
          onTap: enabled ? onTap : null,
          borderRadius: BorderRadius.circular(14),
          child: Container(
            constraints: const BoxConstraints(minHeight: kMinTouchTarget),
            padding: const EdgeInsetsDirectional.symmetric(horizontal: AppSpace.sm, vertical: AppSpace.xs),
            decoration: BoxDecoration(
              color: selected ? p.brass.withValues(alpha: 0.12) : Theme.of(context).colorScheme.surface,
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: selected ? p.brass : p.line, width: selected ? 1.5 : 1),
            ),
            child: Row(
              children: [
                Icon(
                  selected ? Icons.radio_button_checked_rounded : Icons.radio_button_unchecked_rounded,
                  size: 22,
                  color: enabled || selected ? p.brass : p.muted,
                ),
                const Gap(AppSpace.sm),
                Expanded(
                  child: Text(
                    label,
                    style: context.text.bodyMedium?.copyWith(
                      fontWeight: selected ? FontWeight.w600 : FontWeight.w400,
                      color: time.available ? null : p.muted,
                      decoration: time.available ? null : TextDecoration.lineThrough,
                    ),
                  ),
                ),
                if (!time.available)
                  Text(l10n.slotOfferTaken, style: context.text.labelSmall?.copyWith(color: p.muted)),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _Calm extends StatelessWidget {
  const _Calm({super.key, required this.icon, required this.title, required this.body});
  final IconData icon;
  final String title;
  final String body;

  @override
  Widget build(BuildContext context) {
    final p = context.palette;
    return Semantics(
      container: true,
      child: Container(
        margin: const EdgeInsets.only(bottom: AppSpace.sm),
        padding: const EdgeInsets.all(AppSpace.md),
        decoration: BoxDecoration(color: p.surfaceMuted, borderRadius: AppRadius.cardAll),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(icon, color: p.brass),
            const Gap(AppSpace.sm),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title, style: context.text.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
                  const Gap(AppSpace.xxs),
                  Text(body, style: context.text.bodyMedium),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// "Delivered on (date, time), received by (name)" with the photo the driver took. The photo link is private and short-lived (the server makes a fresh one
/// every time the order is read); without a photo (an older delivery, or the shop hides it) only the line is shown.
class DeliveredProofCard extends StatelessWidget {
  const DeliveredProofCard({super.key, required this.proof});
  final DeliveryProofInfo proof;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final p = context.palette;
    final when = QatarTime.dateTime(proof.deliveredAt, context.localeCode);
    final by = proof.receivedBy;
    final line = when.isEmpty ? null : (by == null ? l10n.proofDelivered(when) : l10n.proofDeliveredBy(when, by));
    if (line == null && proof.photoUrl == null) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(top: AppSpace.sm),
      child: Column(
        key: const ValueKey('delivery-proof'),
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (line != null)
            Row(
              children: [
                Icon(Icons.check_circle_outline_rounded, size: 18, color: p.brass),
                const Gap(AppSpace.xs),
                Expanded(child: Text(line, style: context.text.bodyMedium)),
              ],
            ),
          if (proof.photoUrl != null) ...[
            const Gap(AppSpace.xs),
            AppNetworkImage(
              key: const ValueKey('delivery-proof-photo'),
              url: proof.photoUrl,
              height: 190,
              semanticLabel: l10n.proofPhotoLabel,
              borderRadius: BorderRadius.circular(14),
            ),
          ],
        ],
      ),
    );
  }
}

/// "Assembled on (date)" once the driver said it was done, or a calm line saying it was not and the branch will be in touch.
class AssemblyLine extends StatelessWidget {
  const AssemblyLine({super.key, required this.answer});
  final AssemblyAnswer answer;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final p = context.palette;
    final text = answer.isDone
        ? l10n.assemblyDoneOn(QatarTime.dateTime(answer.doneAt, context.localeCode))
        : answer.isNotDone
        ? l10n.assemblyNotDone(answer.reasonLabel ?? '')
        : null;
    if (text == null) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(top: AppSpace.xs),
      child: Row(
        key: const ValueKey('assembly-line'),
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(answer.isDone ? Icons.handyman_outlined : Icons.report_gmailerrorred_outlined, size: 18, color: p.brass),
          const Gap(AppSpace.xs),
          Expanded(child: Text(text, style: context.text.bodyMedium)),
        ],
      ),
    );
  }
}
