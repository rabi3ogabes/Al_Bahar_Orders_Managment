import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/features/orders/domain/order_models.dart';
import 'package:flutter/material.dart';

/// Where the customer's return request stands, in plain words: asked, approved, refused (with the team's note), back with us, refunded.
/// Shown on the order instead of the "Request a return" button while a request is open.
class ReturnStatusCard extends StatelessWidget {
  const ReturnStatusCard({super.key, required this.request});
  final ReturnRequestInfo request;

  /// The sentence for where the request stands.
  static String sentence(AppLocalizations l10n, ReturnRequestInfo r, String locale) {
    final asked = QatarTime.date(r.requestedAt, locale);
    final decided = QatarTime.date(r.decidedAt ?? r.requestedAt, locale);
    return switch (r.status) {
      'requested' => l10n.returnStatusRequested(asked),
      'approved' => l10n.returnStatusApproved(decided),
      'rejected' => l10n.returnStatusRejected(decided),
      'received' => l10n.returnStatusReceived,
      'refunded' => l10n.returnStatusRefunded,
      'closed' => l10n.returnStatusClosed,
      _ => l10n.returnStatusOther,
    };
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final p = context.palette;
    final r = request;
    final (icon, color) = switch (r.status) {
      'rejected' => (Icons.info_outline_rounded, p.warning),
      'received' || 'refunded' || 'closed' => (Icons.check_circle_outline_rounded, p.success),
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
            Text(sentence(l10n, r, context.localeCode), style: context.text.bodyMedium),
            if (r.decisionNote != null) ...[
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
          ],
        ),
      ),
    );
  }
}
