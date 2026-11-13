import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/html_content.dart';
import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/support/data/support_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Opens a content page (terms, privacy, FAQ, returns…) in a side window, so reading it never costs the person the
/// form or page they came from. `/pages/:slug` stays a full page for links.
Future<void> showCmsPage(BuildContext context, String slug, {String? title}) => showSidePanel<void>(
  context: context,
  title: title ?? '',
  builder: (_) => CmsPageScreen(slug: slug, embedded: true),
);

/// `GET /pages/{slug}` — terms, privacy, faq, about, contact, returns, auction-terms.
class CmsPageScreen extends ConsumerWidget {
  const CmsPageScreen({super.key, required this.slug, this.embedded = false});
  final String slug;

  /// True inside a side window ([showCmsPage]): no app bar, and the page text lays out in the window's own scroll.
  final bool embedded;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final page = ref.watch(cmsPageProvider(slug));
    if (embedded) {
      return page.when(
        loading: () => const SizedBox(height: 320, child: ListSkeleton(count: 6, leading: false)),
        error: (e, _) => ErrorState(error: e, compact: true, onRetry: () => ref.invalidate(cmsPageProvider(slug))),
        data: (p) => Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Semantics(header: true, child: Text(p.title, style: context.text.headlineSmall)),
            if (p.updatedAt != null)
              Text(QatarTime.date(p.updatedAt, context.localeCode), style: context.text.bodySmall),
            const Gap(AppSpace.md),
            HtmlContent(p.bodyHtml),
          ],
        ),
      );
    }
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
