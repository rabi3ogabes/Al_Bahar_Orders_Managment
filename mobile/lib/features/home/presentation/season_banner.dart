import 'package:albahar_customer/core/theme/brand_theme.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Seasonal card from the dashboard (Qatar National Day, Ramadan, Eid…). Hidden on the normal look.
class SeasonBanner extends ConsumerWidget {
  const SeasonBanner({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final brand = ref.watch(brandThemeProvider);
    final title = brand.bannerTitle;
    if (!brand.isEvent || title == null || title.isEmpty) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.xs, AppSpace.gutter, AppSpace.xs),
      child: Semantics(
        container: true,
        label: [title, brand.bannerSubtitle].whereType<String>().join('. '),
        child: ClipRRect(
          borderRadius: AppRadius.cardAll,
          child: Container(
            constraints: const BoxConstraints(minHeight: 84),
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: AlignmentDirectional.topStart,
                end: AlignmentDirectional.bottomEnd,
                colors: [brand.primary, brand.primarySoft],
              ),
            ),
            child: Stack(
              children: [
                if (brand.bannerUrl != null || brand.heroUrl != null)
                  Positioned.fill(
                    child: Opacity(
                      opacity: 0.35,
                      child: AppNetworkImage(url: brand.bannerUrl ?? brand.heroUrl, fit: BoxFit.cover),
                    ),
                  ),
                Padding(
                  padding: const EdgeInsets.all(AppSpace.md),
                  child: Row(
                    children: [
                      Container(
                        width: 40,
                        height: 40,
                        decoration: BoxDecoration(color: brand.accent, shape: BoxShape.circle),
                        child: const Icon(Icons.auto_awesome_rounded, color: Colors.white, size: 20),
                      ),
                      const Gap(AppSpace.sm),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              title,
                              style: context.text.titleMedium?.copyWith(
                                color: Colors.white,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                            if (brand.bannerSubtitle != null && brand.bannerSubtitle!.isNotEmpty)
                              Text(
                                brand.bannerSubtitle!,
                                style: context.text.bodySmall?.copyWith(color: Colors.white.withValues(alpha: 0.9)),
                              ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
