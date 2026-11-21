import 'dart:typed_data';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/features/orders/data/order_repository.dart';
import 'package:albahar_customer/features/orders/domain/claim_models.dart';
import 'package:albahar_customer/features/orders/presentation/widgets/claim_photo_source.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// The pieces a claim is drawn from: the label of a step, the timeline, the private photos and the photo picker.

/// The sentence for one step of a claim's timeline.
String claimStepLabel(AppLocalizations l10n, String key) => switch (key) {
  'received' => l10n.claimStepReceived,
  'under_review' => l10n.claimStepUnderReview,
  'decision' => l10n.claimStepDecision,
  'approved' => l10n.claimStepApproved,
  'rejected' => l10n.claimStepRejected,
  'handover' => l10n.claimStepHandover,
  'pickup' => l10n.claimStepPickup,
  'branch' => l10n.claimStepBranch,
  'item_received' => l10n.claimStepItemReceived,
  'refunded' => l10n.claimStepRefunded,
  'cancelled' => l10n.claimStepCancelled,
  _ => l10n.claimStepOther,
};

/// "to your original payment method", "in cash", "by bank transfer".
String claimRefundHow(AppLocalizations l10n, String method) => switch (method) {
  'original' => l10n.claimRefundOriginal,
  'manual_cash' => l10n.claimRefundCash,
  'manual_bank_transfer' => l10n.claimRefundBank,
  _ => l10n.claimRefundOther,
};

/// What a step says under its label: the team's words, where to bring the item, or the refund and how it goes back.
String? claimStepDetail(AppLocalizations l10n, ClaimStep s, ReturnRequestInfo claim) {
  if (s.key == 'branch') {
    final parts = [if (claim.branchName != null) l10n.claimStepBranchDetail(claim.branchName!), ?s.detail];
    return parts.isEmpty ? null : parts.join(' ');
  }
  final refund = claim.refund;
  if (s.key == 'refunded' && refund != null) {
    final how = claimRefundHow(l10n, refund.method);
    return refund.isProcessed
        ? l10n.claimStepRefundDone(refund.amount.formatted, how)
        : l10n.claimStepRefundWait(refund.amount.formatted, how);
  }
  return s.detail;
}

/// The compact vertical timeline of a claim: a dot per step (done, current, upcoming, stopped), joined by a line, with its date and detail.
class ClaimTimeline extends StatelessWidget {
  const ClaimTimeline({super.key, required this.claim});
  final ReturnRequestInfo claim;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final p = context.palette;
    final steps = claim.steps;
    return Semantics(
      container: true,
      label: l10n.claimTimelineLabel,
      child: Column(
        key: const ValueKey('claim-timeline'),
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (var i = 0; i < steps.length; i++)
            IntrinsicHeight(
              key: ValueKey('claim-step-${steps[i].key}'),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  SizedBox(
                    width: 24,
                    child: Column(
                      children: [
                        _Dot(step: steps[i]),
                        if (i < steps.length - 1)
                          Expanded(
                            child: Container(
                              width: 1.5,
                              color: steps[i].isDone ? p.brass.withValues(alpha: 0.5) : p.line,
                            ),
                          ),
                      ],
                    ),
                  ),
                  const SizedBox(width: AppSpace.sm),
                  Expanded(
                    child: Padding(
                      padding: EdgeInsets.only(bottom: i < steps.length - 1 ? AppSpace.sm : 0),
                      child: _StepText(step: steps[i], detail: claimStepDetail(l10n, steps[i], claim)),
                    ),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

class _Dot extends StatelessWidget {
  const _Dot({required this.step});
  final ClaimStep step;

  @override
  Widget build(BuildContext context) {
    final p = context.palette;
    final (Color fill, Color? border, Widget? child) = switch (step.state) {
      'done' => (p.brass, null, const Icon(Icons.check_rounded, size: 15, color: Colors.white)),
      'current' => (p.surfaceMuted, p.brass, Icon(Icons.circle, size: 8, color: p.brass)),
      'stopped' => (p.ink, null, const Icon(Icons.close_rounded, size: 15, color: Colors.white)),
      _ => (Colors.transparent, p.line, null),
    };
    return Container(
      width: 24,
      height: 24,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: fill,
        border: border == null ? null : Border.all(color: border, width: 2),
      ),
      child: child,
    );
  }
}

class _StepText extends StatelessWidget {
  const _StepText({required this.step, required this.detail});
  final ClaimStep step;
  final String? detail;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final p = context.palette;
    final upcoming = step.state == 'upcoming';
    return Semantics(
      container: true,
      selected: step.isCurrent,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text.rich(
            TextSpan(
              children: [
                TextSpan(text: claimStepLabel(l10n, step.key)),
                if (step.at != null)
                  TextSpan(
                    text: '  ${QatarTime.date(step.at, context.localeCode)}',
                    style: context.text.bodySmall?.copyWith(color: p.muted, fontWeight: FontWeight.w400),
                  ),
              ],
            ),
            style: context.text.bodyLarge?.copyWith(
              fontWeight: upcoming ? FontWeight.w400 : FontWeight.w600,
              color: upcoming ? p.muted : p.ink,
            ),
          ),
          if (detail != null)
            Padding(
              padding: const EdgeInsets.only(top: 2),
              child: Text(detail!, style: context.text.bodyMedium?.copyWith(color: p.muted)),
            ),
        ],
      ),
    );
  }
}

/// A private claim photo, read with the customer's sign-in and kept only while it is on screen.
final claimPhotoBytesProvider = FutureProvider.autoDispose.family<Uint8List, String>(
  (ref, path) => ref.watch(orderRepositoryProvider).claimPhoto(path),
);

/// The photos the customer sent, as small tiles; a tap opens one full size, with pinch to zoom.
class ClaimPhotos extends ConsumerWidget {
  const ClaimPhotos({super.key, required this.photos});
  final List<ClaimPhoto> photos;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (photos.isEmpty) return const SizedBox.shrink();
    final l10n = context.l10n;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(l10n.claimPhotosTitle, style: context.text.titleSmall),
        const Gap(AppSpace.xs),
        Wrap(
          spacing: AppSpace.xs,
          runSpacing: AppSpace.xs,
          children: [
            for (var i = 0; i < photos.length; i++)
              _Tile(
                key: ValueKey('claim-photo-${photos[i].id}'),
                bytes: ref.watch(claimPhotoBytesProvider(photos[i].path)),
                label: l10n.claimPhotoAlt(i + 1),
                failed: l10n.claimPhotoFailed,
                onOpen: (b) => _open(context, b, l10n.claimPhotoAlt(i + 1)),
              ),
          ],
        ),
      ],
    );
  }

  void _open(BuildContext context, Uint8List bytes, String label) {
    showDialog<void>(
      context: context,
      builder: (ctx) => Dialog.fullscreen(
        backgroundColor: Colors.black,
        child: Stack(
          children: [
            Positioned.fill(
              child: InteractiveViewer(
                maxScale: 5,
                child: Center(child: Image.memory(bytes, semanticLabel: label)),
              ),
            ),
            PositionedDirectional(
              top: AppSpace.sm,
              end: AppSpace.sm,
              child: SafeArea(
                child: IconButton.filled(
                  tooltip: ctx.l10n.claimPhotoClose,
                  constraints: const BoxConstraints(minWidth: 48, minHeight: 48),
                  onPressed: () => Navigator.of(ctx).pop(),
                  icon: const Icon(Icons.close_rounded),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Tile extends StatelessWidget {
  const _Tile({super.key, required this.bytes, required this.label, required this.failed, required this.onOpen});
  final AsyncValue<Uint8List> bytes;
  final String label;
  final String failed;
  final void Function(Uint8List) onOpen;

  @override
  Widget build(BuildContext context) {
    final p = context.palette;
    return SizedBox(
      width: 72,
      height: 72,
      child: ClipRRect(
        borderRadius: BorderRadius.circular(12),
        child: bytes.when(
          data: (b) => Semantics(
            button: true,
            label: label,
            child: InkWell(
              onTap: () => onOpen(b),
              child: Image.memory(b, fit: BoxFit.cover, width: 72, height: 72, excludeFromSemantics: true),
            ),
          ),
          loading: () => ColoredBox(color: p.surfaceMuted),
          error: (_, _) => Semantics(
            label: failed,
            child: ColoredBox(
              color: p.surfaceMuted,
              child: Icon(Icons.broken_image_outlined, color: p.muted),
            ),
          ),
        ),
      ),
    );
  }
}

/// The chosen photos with a remove button each, and the buttons that add more (camera, gallery) while there is room.
class ClaimPhotoPicker extends ConsumerWidget {
  const ClaimPhotoPicker({
    super.key,
    required this.photos,
    required this.onChanged,
    this.already = 0,
    this.required = false,
    this.error,
  });
  final List<PickedPhoto> photos;
  final ValueChanged<List<PickedPhoto>> onChanged;

  /// Photos the claim already has (when adding to one).
  final int already;
  final bool required;
  final String? error;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final p = context.palette;
    final room = maxClaimPhotos - already - photos.length;
    final source = ref.watch(claimPhotoSourceProvider);
    Future<void> add(Future<List<PickedPhoto>> Function() pick) async {
      final more = await pick();
      if (more.isEmpty) return;
      onChanged([...photos, ...more].take(maxClaimPhotos - already).toList());
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Expanded(child: Text(l10n.claimPhotosTitle, style: context.text.titleSmall)),
            Text(
              l10n.claimPhotoCount(already + photos.length, maxClaimPhotos),
              style: context.text.bodySmall?.copyWith(color: p.muted),
            ),
          ],
        ),
        Text(
          required ? l10n.claimPhotosRequired : l10n.claimPhotosOptional,
          style: context.text.bodySmall?.copyWith(color: p.muted),
        ),
        if (photos.isNotEmpty) ...[
          const Gap(AppSpace.xs),
          Wrap(
            spacing: AppSpace.xs,
            runSpacing: AppSpace.xs,
            children: [
              for (var i = 0; i < photos.length; i++)
                SizedBox(
                  key: ValueKey('picked-photo-$i'),
                  width: 80,
                  height: 80,
                  child: Stack(
                    clipBehavior: Clip.none,
                    children: [
                      Positioned.fill(
                        child: ClipRRect(
                          borderRadius: BorderRadius.circular(12),
                          child: Image.memory(
                            photos[i].bytes,
                            fit: BoxFit.cover,
                            semanticLabel: l10n.claimPhotoAlt(already + i + 1),
                          ),
                        ),
                      ),
                      PositionedDirectional(
                        top: -10,
                        end: -10,
                        child: IconButton.filled(
                          tooltip: l10n.claimPhotoRemove,
                          style: IconButton.styleFrom(
                            minimumSize: const Size(48, 48),
                            tapTargetSize: MaterialTapTargetSize.padded,
                            iconSize: 16,
                          ),
                          onPressed: () => onChanged([...photos]..removeAt(i)),
                          icon: const Icon(Icons.close_rounded),
                        ),
                      ),
                    ],
                  ),
                ),
            ],
          ),
        ],
        if (room > 0) ...[
          const Gap(AppSpace.xs),
          Wrap(
            spacing: AppSpace.xs,
            runSpacing: AppSpace.xs,
            children: [
              AppButton.secondary(
                key: const ValueKey('claim-photo-camera'),
                label: l10n.claimPhotoCamera,
                icon: Icons.photo_camera_outlined,
                expand: false,
                onPressed: () => add(source.camera),
              ),
              AppButton.secondary(
                key: const ValueKey('claim-photo-gallery'),
                label: l10n.claimPhotoGallery,
                icon: Icons.photo_library_outlined,
                expand: false,
                onPressed: () => add(() => source.gallery(room)),
              ),
            ],
          ),
        ],
        if (error != null)
          Padding(
            padding: const EdgeInsets.only(top: AppSpace.xs),
            child: Text(error!, style: context.text.bodySmall?.copyWith(color: p.danger)),
          ),
      ],
    );
  }
}

/// The text that says why a set of pictures cannot be sent, or null.
String? claimPhotoProblemText(AppLocalizations l10n, List<PickedPhoto> photos, {int already = 0}) =>
    switch (photoProblem(photos, already: already)) {
      'type' => l10n.claimErrPhotoType,
      'size' => l10n.claimErrPhotoSize,
      'count' => l10n.claimErrPhotoCount,
      _ => null,
    };
