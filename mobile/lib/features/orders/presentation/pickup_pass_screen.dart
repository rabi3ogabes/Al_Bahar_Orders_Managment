import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/countdown.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/orders/presentation/state/order_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:qr_flutter/qr_flutter.dart';

/// QR pickup pass shown at the showroom counter (only when `pickup_ready`).
class PickupPassScreen extends ConsumerWidget {
  const PickupPassScreen({super.key, required this.orderId, required this.fulfillmentId});
  final String orderId;
  final String fulfillmentId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final args = (orderId: orderId, fulfillmentId: fulfillmentId);
    final pass = ref.watch(pickupPassProvider(args));
    return Scaffold(
      appBar: AppBar(title: Text(l10n.pickupPassTitle)),
      body: pass.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(pickupPassProvider(args))),
        data: (p) => RefreshIndicator(
          onRefresh: () => ref.refresh(pickupPassProvider(args).future),
          child: ListView(
            padding: const EdgeInsets.all(AppSpace.lg),
            children: [
              ContentWidth(
                maxWidth: 420,
                child: Column(
                  children: [
                    if (p.branchName != null)
                      Text(p.branchName!, style: context.text.headlineSmall, textAlign: TextAlign.center),
                    const Gap(AppSpace.md),
                    Container(
                      padding: const EdgeInsets.all(AppSpace.lg),
                      decoration: BoxDecoration(
                        // Always white for scanner contrast, even in dark mode.
                        color: Colors.white,
                        borderRadius: AppRadius.lgAll,
                        border: Border.all(color: context.palette.line),
                      ),
                      child: Semantics(
                        label: l10n.pickupPass,
                        image: true,
                        child: QrImageView(
                          data: p.qrPayload,
                          size: 260,
                          backgroundColor: Colors.white,
                          eyeStyle: const QrEyeStyle(eyeShape: QrEyeShape.square, color: AppPalette.ink),
                          dataModuleStyle: const QrDataModuleStyle(
                            dataModuleShape: QrDataModuleShape.square,
                            color: AppPalette.ink,
                          ),
                        ),
                      ),
                    ),
                    const Gap(AppSpace.lg),
                    Text(l10n.pickupPassInstructions, textAlign: TextAlign.center, style: context.text.bodyLarge),
                    if (p.expiresAt != null) ...[
                      const Gap(AppSpace.sm),
                      Text(
                        l10n.passExpires(QatarTime.dateTime(p.expiresAt, context.localeCode)),
                        style: context.text.bodySmall,
                      ),
                      CountdownText(
                        target: p.expiresAt!,
                        onElapsed: () => ref.invalidate(pickupPassProvider(args)),
                        style: context.text.titleMedium?.copyWith(color: context.palette.brass),
                      ),
                    ],
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
