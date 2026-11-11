import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/html_content.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/support/data/support_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// `GET /pages/{slug}` — terms, privacy, faq, about, contact, returns, auction-terms.
class CmsPageScreen extends ConsumerWidget {
  const CmsPageScreen({super.key, required this.slug});
  final String slug;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final page = ref.watch(cmsPageProvider(slug));
    return Scaffold(
      appBar: AppBar(title: Text(page.value?.title ?? '')),
      body: page.when(
        loading: () => const ListSkeleton(count: 6, leading: false),
        error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(cmsPageProvider(slug))),
        data: (p) => ListView(
          padding: const EdgeInsets.all(AppSpace.gutter),
          children: [
            ContentWidth(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(p.title, style: context.text.headlineLarge),
                  if (p.updatedAt != null)
                    Text(QatarTime.date(p.updatedAt, context.localeCode), style: context.text.bodySmall),
                  const Gap(AppSpace.lg),
                  HtmlContent(p.bodyHtml),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
