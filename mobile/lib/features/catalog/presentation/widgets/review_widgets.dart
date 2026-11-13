import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/input_sheets.dart';
import 'package:albahar_customer/core/widgets/paged.dart';
import 'package:albahar_customer/core/widgets/rating.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/auth/presentation/require_auth.dart';
import 'package:albahar_customer/features/catalog/data/catalog_repository.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/catalog/presentation/state/catalog_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// Rating summary, first reviews and entry points to the full list / form.
class ReviewsPreview extends ConsumerWidget {
  const ReviewsPreview({super.key, required this.productId, required this.productName, required this.rating});
  final String productId;
  final String productName;
  final Rating rating;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final reviews = ref.watch(reviewsProvider(productId));
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Divider(color: context.palette.line),
        const Gap(AppSpace.sm),
        Row(
          children: [
            Expanded(
              child: Semantics(header: true, child: Text(l10n.reviews, style: context.text.headlineSmall)),
            ),
            TextButton.icon(
              onPressed: () {
                if (!requireAuth(context, ref)) return;
                unawaited(context.push('/products/$productId/reviews/new?name=${Uri.encodeComponent(productName)}'));
              },
              icon: const Icon(Icons.rate_review_outlined, size: 18),
              label: Text(l10n.writeReview),
            ),
          ],
        ),
        if (rating.count > 0)
          Row(
            children: [
              Text(rating.average.toStringAsFixed(1), style: context.text.displaySmall),
              const Gap(AppSpace.sm),
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  RatingStars(average: rating.average, size: 18, showValue: false),
                  Text(l10n.reviewsCount(rating.count), style: context.text.bodySmall),
                ],
              ),
            ],
          ),
        const Gap(AppSpace.sm),
        reviews.when(
          loading: () => const SizedBox(height: 120, child: ListSkeleton(count: 2, leading: false)),
          error: (e, _) =>
              ErrorState(error: e, compact: true, onRetry: () => ref.invalidate(reviewsProvider(productId))),
          data: (state) {
            if (state.items.isEmpty) {
              return Padding(
                padding: const EdgeInsets.symmetric(vertical: AppSpace.md),
                child: Text(l10n.noReviews, style: context.text.bodyMedium?.copyWith(color: context.palette.muted)),
              );
            }
            return Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                for (final r in state.items.take(3)) ReviewTile(review: r),
                if (state.items.length > 3 || state.hasMore)
                  AppButton.secondary(
                    label: l10n.seeAll,
                    onPressed: () => showAppBottomSheet<void>(
                      context: context,
                      title: l10n.reviews,
                      scrollable: false,
                      builder: (_) => SizedBox(
                        height: MediaQuery.sizeOf(context).height * 0.75,
                        child: _AllReviews(productId: productId),
                      ),
                    ),
                  ),
              ],
            );
          },
        ),
        const Gap(AppSpace.md),
        _QuestionsSection(productId: productId),
      ],
    );
  }
}

class _AllReviews extends ConsumerWidget {
  const _AllReviews({required this.productId});
  final String productId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final provider = reviewsProvider(productId);
    final state = ref.watch(provider).value;
    if (state == null) return const ListSkeleton(leading: false);
    return NotificationListener<ScrollNotification>(
      onNotification: (n) => handleLoadMoreScroll(n, () => ref.read(provider.notifier).loadMore()),
      child: ListView(
        children: [
          for (final r in state.items) ReviewTile(review: r),
          LoadMoreFooter(state: state, onLoadMore: () => ref.read(provider.notifier).loadMore()),
        ],
      ),
    );
  }
}

class ReviewTile extends ConsumerWidget {
  const ReviewTile({super.key, required this.review});
  final Review review;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: AppSpace.sm),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              RatingStars(average: review.rating.toDouble(), showValue: false),
              const Gap(AppSpace.xs),
              if (review.verifiedPurchase)
                StatusChip(
                  label: l10n.verifiedPurchase,
                  tone: StatusTone.success,
                  icon: Icons.verified_outlined,
                  dense: true,
                ),
              const Spacer(),
              PopupMenuButton<String>(
                tooltip: l10n.menu,
                icon: const Icon(Icons.more_horiz_rounded, size: 20),
                onSelected: (_) async {
                  if (!requireAuth(context, ref)) return;
                  try {
                    await ref.read(catalogRepositoryProvider).reportReview(review.id, 'offensive');
                    if (context.mounted) showInfoSnack(context, l10n.reviewReported);
                  } catch (e) {
                    if (context.mounted) showErrorSnack(context, e);
                  }
                },
                itemBuilder: (_) => [PopupMenuItem(value: 'report', child: Text(l10n.reportReview))],
              ),
            ],
          ),
          if (review.title != null) Text(review.title!, style: context.text.titleSmall),
          if (review.body != null) ...[const Gap(2), Text(review.body!, style: context.text.bodyMedium)],
          if (review.photos.isNotEmpty) ...[
            const Gap(AppSpace.xs),
            SizedBox(
              height: 72,
              child: ListView.separated(
                scrollDirection: Axis.horizontal,
                itemCount: review.photos.length,
                separatorBuilder: (_, _) => const Gap(AppSpace.xs),
                itemBuilder: (_, i) => AppNetworkImage(
                  url: review.photos[i].listUrl,
                  width: 72,
                  height: 72,
                  borderRadius: BorderRadius.circular(AppRadius.sm),
                ),
              ),
            ),
          ],
          const Gap(4),
          Text(
            [
              review.authorName,
              QatarTime.date(review.createdAt, context.localeCode),
            ].whereType<String>().where((s) => s.isNotEmpty).join(' · '),
            style: context.text.bodySmall,
          ),
        ],
      ),
    );
  }
}

class _QuestionsSection extends ConsumerWidget {
  const _QuestionsSection({required this.productId});
  final String productId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final questions = ref.watch(questionsProvider(productId));
    final items = questions.value?.items ?? const <ProductQuestion>[];
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Divider(color: context.palette.line),
        Row(
          children: [
            Expanded(child: Text(l10n.questions, style: context.text.titleMedium)),
            TextButton(
              onPressed: () async {
                if (!requireAuth(context, ref)) return;
                final text = await showTextSheet(
                  context,
                  title: l10n.askQuestion,
                  label: l10n.askQuestion,
                  actionLabel: l10n.send,
                  minLength: 5,
                  maxLength: 1000,
                );
                if (text == null) return;
                try {
                  await ref.read(catalogRepositoryProvider).askQuestion(productId, text);
                  if (context.mounted) showInfoSnack(context, l10n.questionSubmitted);
                } catch (e) {
                  if (context.mounted) showErrorSnack(context, e);
                }
              },
              child: Text(l10n.askQuestion),
            ),
          ],
        ),
        if (items.isEmpty)
          Text(l10n.noQuestions, style: context.text.bodySmall)
        else
          for (final q in items.take(3))
            Padding(
              padding: const EdgeInsets.symmetric(vertical: AppSpace.xs),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Icon(Icons.help_outline_rounded, size: 18, color: context.palette.brass),
                      const Gap(AppSpace.xs),
                      Expanded(child: Text(q.question, style: context.text.titleSmall)),
                    ],
                  ),
                  if (q.answer != null)
                    Padding(
                      padding: const EdgeInsetsDirectional.only(start: 26, top: 2),
                      child: Text(q.answer!, style: context.text.bodyMedium),
                    ),
                ],
              ),
            ),
      ],
    );
  }
}
