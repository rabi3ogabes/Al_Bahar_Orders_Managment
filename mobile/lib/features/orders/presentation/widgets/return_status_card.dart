import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/orders/data/order_repository.dart';
import 'package:albahar_customer/features/orders/domain/order_models.dart';
import 'package:albahar_customer/features/orders/presentation/return_errors.dart';
import 'package:albahar_customer/features/orders/presentation/state/order_providers.dart';
import 'package:albahar_customer/features/orders/presentation/widgets/claim_evidence_form.dart';
import 'package:albahar_customer/features/orders/presentation/widgets/claim_parts.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Where the customer's return or damage claim stands, in plain words and as a compact timeline: received, under review, approved or not
/// (with the team's reply), how the item comes back, item received, refunded. While the team has not decided, the customer can add photos or
/// a note, or cancel. Shown on the order instead of the "Report a problem" button while a claim is open.
class ReturnStatusCard extends ConsumerWidget {
  const ReturnStatusCard({super.key, required this.request, required this.orderId});
  final ReturnRequestInfo request;
  final String orderId;

  /// The sentence for where the request stands.
  static String sentence(AppLocalizations l10n, ReturnRequestInfo r, String locale) {
    final asked = QatarTime.date(r.requestedAt, locale);
    final decided = QatarTime.date(r.decidedAt ?? r.requestedAt, locale);
    return switch (r.status) {
      'requested' => l10n.returnStatusRequested(asked),
      'under_review' => l10n.returnStatusUnderReview,
      'approved' => l10n.returnStatusApproved(decided),
      'rejected' => l10n.returnStatusRejected(decided),
      'received' => l10n.returnStatusReceived,
      'refunded' => l10n.returnStatusRefunded,
      'closed' => l10n.returnStatusClosed,
      'cancelled' => l10n.returnStatusCancelled,
      _ => l10n.returnStatusOther,
    };
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final p = context.palette;
    final r = request;
    final (icon, color) = switch (r.status) {
      'rejected' => (Icons.info_outline_rounded, p.warning),
      'received' || 'refunded' || 'closed' => (Icons.check_circle_outline_rounded, p.success),
      'cancelled' => (Icons.do_not_disturb_on_outlined, p.muted),
      _ => (Icons.assignment_return_outlined, p.brassDeep),
    };
    return Semantics(
      container: true,
      child: Container(
        key: const ValueKey('return-status-card'),
        margin: const EdgeInsets.only(bottom: AppSpace.sm),
        padding: const EdgeInsets.all(AppSpace.md),
        decoration: BoxDecoration(
          color: p.tint,
          borderRadius: AppRadius.cardAll,
          border: Border.all(color: color.withValues(alpha: 0.35)),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  width: 36,
                  height: 36,
                  decoration: BoxDecoration(shape: BoxShape.circle, color: color.withValues(alpha: 0.14)),
                  child: Icon(icon, size: 22, color: color),
                ),
                const Gap(AppSpace.sm),
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.only(top: 6),
                    child: Semantics(
                      header: true,
                      child: Text(
                        l10n.returnStatusTitle(r.number),
                        style: context.text.titleSmall?.copyWith(fontWeight: FontWeight.w700),
                      ),
                    ),
                  ),
                ),
              ],
            ),
            const Gap(AppSpace.xs),
            if (r.itemsText != null)
              Padding(
                padding: const EdgeInsets.only(bottom: AppSpace.xs),
                child: Text(r.itemsText!, style: context.text.bodySmall?.copyWith(color: p.muted)),
              ),
            if (r.steps.isNotEmpty)
              ClaimTimeline(claim: r)
            else
              Text(sentence(l10n, r, context.localeCode), style: context.text.bodyMedium),
            if (r.steps.isEmpty && r.decisionNote != null) ...[
              const Gap(AppSpace.xs),
              Text(
                l10n.returnStatusNote(r.decisionNote!),
                style: context.text.bodyMedium?.copyWith(fontStyle: FontStyle.italic),
              ),
            ],
            if (r.isRejected) ...[
              const Gap(AppSpace.xs),
              Text(l10n.returnStatusRejectedHelp, style: context.text.bodySmall?.copyWith(color: p.muted)),
            ],
            if (r.note != null) ...[const Gap(AppSpace.sm), _Quote(title: l10n.claimYourNote, text: r.note!)],
            for (final m in r.messages) ...[const Gap(AppSpace.xs), _Quote(text: m)],
            if (r.photos.isNotEmpty) ...[const Gap(AppSpace.sm), ClaimPhotos(photos: r.photos)],
            if (r.isEditable) ...[
              const Gap(AppSpace.sm),
              if (r.canAddEvidence)
                AppButton.secondary(
                  key: const ValueKey('claim-add'),
                  label: l10n.claimAdd,
                  icon: Icons.add_a_photo_outlined,
                  onPressed: () => _add(context, ref),
                ),
              if (r.canCancel) ...[
                const Gap(AppSpace.xs),
                AppButton.ghost(
                  key: const ValueKey('claim-cancel'),
                  label: l10n.claimCancel,
                  onPressed: () => _cancel(context, ref),
                ),
              ],
            ],
          ],
        ),
      ),
    );
  }

  void _refresh(WidgetRef ref) {
    ref.invalidate(orderDetailProvider(orderId));
    ref.invalidate(orderTimelineProvider(orderId));
  }

  Future<void> _add(BuildContext context, WidgetRef ref) async {
    final l10n = context.l10n;
    final result = await showSidePanel<({List<PickedPhoto> photos, String? note})>(
      context: context,
      title: l10n.claimAdd,
      isForm: true,
      builder: (ctx) => ClaimEvidenceForm(already: request.photos.length, onDone: (r) => Navigator.of(ctx).pop(r)),
    );
    if (result == null || !context.mounted) return;
    try {
      await ref.read(orderRepositoryProvider).addClaimEvidence(request.id, photos: result.photos, note: result.note);
      if (!context.mounted) return;
      showInfoSnack(context, l10n.claimAdded);
    } on ApiException catch (e) {
      if (context.mounted) showInfoSnack(context, returnFailureMessage(l10n, e));
    } finally {
      _refresh(ref);
    }
  }

  Future<void> _cancel(BuildContext context, WidgetRef ref) async {
    final l10n = context.l10n;
    final yes = await confirmAction(
      context,
      title: l10n.claimCancel,
      message: l10n.claimCancelConfirm,
      confirmLabel: l10n.claimCancel,
      destructive: true,
    );
    if (!yes || !context.mounted) return;
    try {
      await ref.read(orderRepositoryProvider).cancelClaim(request.id);
      if (context.mounted) showInfoSnack(context, l10n.claimCancelled);
    } on ApiException catch (e) {
      if (context.mounted) showInfoSnack(context, returnFailureMessage(l10n, e));
    } finally {
      _refresh(ref);
    }
  }
}

class _Quote extends StatelessWidget {
  const _Quote({required this.text, this.title});
  final String? title;
  final String text;

  @override
  Widget build(BuildContext context) {
    final p = context.palette;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (title != null) Text(title!, style: context.text.labelMedium?.copyWith(color: p.muted)),
        Container(
          width: double.infinity,
          padding: const EdgeInsets.all(AppSpace.sm),
          decoration: BoxDecoration(color: p.surfaceMuted, borderRadius: BorderRadius.circular(12)),
          child: Text('“$text”', style: context.text.bodyMedium),
        ),
      ],
    );
  }
}

/// "You can report a problem until (date)": the last day the claim window is open for the pieces still reportable.
class ClaimWindowNote extends StatelessWidget {
  const ClaimWindowNote({super.key, required this.until});
  final DateTime until;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: AppSpace.xs),
    child: Text(
      context.l10n.claimWindowUntil(QatarTime.date(until, context.localeCode)),
      key: const ValueKey('claim-window-note'),
      textAlign: TextAlign.center,
      style: context.text.bodySmall?.copyWith(color: context.palette.muted),
    ),
  );
}
