import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/countdown.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/auctions/domain/auction_models.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/catalog_widgets.dart';
import 'package:albahar_customer/features/home/data/home_repository.dart';
import 'package:albahar_customer/features/home/domain/home_models.dart';
import 'package:albahar_customer/features/notifications/presentation/state/notifications_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final feed = ref.watch(homeFeedProvider);
    final l10n = context.l10n;

    return Scaffold(
      body: RefreshIndicator(
        onRefresh: () => ref.refresh(homeFeedProvider.future),
        child: CustomScrollView(
          slivers: [
            const _HomeHeader(),
            ...feed.when(
              skipLoadingOnRefresh: true,
              data: (data) {
                if (data.sections.isEmpty) {
                  return [
                    SliverFillRemaining(
                      hasScrollBody: false,
                      child: EmptyState(icon: Icons.weekend_outlined, title: l10n.appFullName, message: l10n.homeEmpty),
                    ),
                  ];
                }
                return [
                  for (final s in data.sections) SliverToBoxAdapter(child: HomeSectionView(section: s)),
                  const SliverToBoxAdapter(child: Gap(AppSpace.xxl)),
                ];
              },
              loading: () => [const SliverToBoxAdapter(child: _HomeSkeleton())],
              error: (e, _) => [
                SliverFillRemaining(
                  hasScrollBody: false,
                  child: ErrorState(error: e, onRetry: () => ref.invalidate(homeFeedProvider)),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _HomeHeader extends ConsumerWidget {
  const _HomeHeader();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final user = ref.watch(sessionControllerProvider).user;
    final unread = ref.watch(unreadCountProvider);
    final signedIn = ref.watch(isAuthenticatedProvider);
    return SliverAppBar(
      floating: true,
      snap: true,
      centerTitle: false,
      toolbarHeight: 64,
      titleSpacing: AppSpace.gutter,
      title: Row(
        children: [
          // Brand mark: navy rounded square with the gold wave.
          Container(
            width: 38,
            height: 38,
            decoration: BoxDecoration(color: AppPalette.navy, borderRadius: BorderRadius.circular(12)),
            child: const Icon(Icons.waves_rounded, color: AppPalette.goldSoft, size: 22),
          ),
          const Gap(AppSpace.sm),
          Expanded(
            child: Text(
              user != null ? l10n.homeGreetingNamed(user.firstName) : l10n.homeGreeting,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: context.text.headlineSmall,
            ),
          ),
        ],
      ),
      actions: [
        if (signedIn)
          Container(
            decoration: BoxDecoration(color: context.colors.surface, shape: BoxShape.circle, boxShadow: kSoftShadow),
            child: IconButton(
              tooltip: l10n.notificationsTitle,
              onPressed: () => context.push('/notifications'),
              icon: Badge.count(
                count: unread,
                isLabelVisible: unread > 0,
                backgroundColor: context.palette.brass,
                textColor: AppPalette.ink,
                child: const Icon(Icons.notifications_none_rounded),
              ),
            ),
          ),
        const Gap(AppSpace.xs),
      ],
      bottom: PreferredSize(
        preferredSize: const Size.fromHeight(124),
        child: Padding(
          padding: const EdgeInsetsDirectional.fromSTEB(AppSpace.gutter, 0, AppSpace.gutter, AppSpace.sm),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const BranchPill(),
              const Gap(AppSpace.xs),
              Row(
                children: [
                  Expanded(
                    child: Semantics(
                      button: true,
                      label: l10n.searchHint,
                      child: InkWell(
                        borderRadius: AppRadius.inputAll,
                        onTap: () => context.push('/search'),
                        child: Container(
                          height: 52,
                          padding: const EdgeInsetsDirectional.symmetric(horizontal: AppSpace.md),
                          decoration: BoxDecoration(
                            color: context.colors.surface,
                            borderRadius: AppRadius.inputAll,
                            boxShadow: kSoftShadow,
                          ),
                          child: Row(
                            children: [
                              Icon(Icons.search_rounded, color: context.palette.muted),
                              const Gap(AppSpace.sm),
                              Expanded(
                                child: Text(
                                  l10n.searchHint,
                                  overflow: TextOverflow.ellipsis,
                                  style: context.text.bodyMedium?.copyWith(color: context.palette.muted),
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ),
                  ),
                  const Gap(AppSpace.sm),
                  Semantics(
                    button: true,
                    label: l10n.searchHint,
                    child: Material(
                      color: context.colors.primary,
                      borderRadius: AppRadius.inputAll,
                      child: InkWell(
                        borderRadius: AppRadius.inputAll,
                        onTap: () => context.push('/search'),
                        child: SizedBox(
                          width: 52,
                          height: 52,
                          child: Icon(Icons.tune_rounded, color: context.colors.onPrimary),
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _HomeSkeleton extends StatelessWidget {
  const _HomeSkeleton();

  @override
  Widget build(BuildContext context) => const Padding(
    padding: EdgeInsets.only(top: AppSpace.md),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: EdgeInsets.symmetric(horizontal: AppSpace.gutter),
          child: AspectRatio(
            aspectRatio: 16 / 10,
            child: Skeleton(radius: AppRadius.lg),
          ),
        ),
        Gap(AppSpace.lg),
        Padding(
          padding: EdgeInsets.symmetric(horizontal: AppSpace.gutter),
          child: Skeleton(width: 160, height: 20),
        ),
        Gap(AppSpace.md),
        ProductCarouselSkeleton(),
      ],
    ),
  );
}

/// Renders one server-driven section. Unknown types never reach here.
class HomeSectionView extends StatelessWidget {
  const HomeSectionView({super.key, required this.section});
  final HomeSection section;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final s = section;
    return switch (s) {
      BannersSection() => Padding(
        padding: const EdgeInsets.only(top: AppSpace.sm),
        child: BannerCarousel(banners: s.banners),
      ),
      CategoriesSection() => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SectionHeader(title: s.title ?? l10n.categoriesTitle, onAction: () => context.go('/categories')),
          _CategoryChips(categories: s.categories),
        ],
      ),
      ProductsSection() => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SectionHeader(
            title: s.title ?? _defaultTitle(l10n, s.type),
            onAction: () {
              final q = {...s.listingQuery, 'title': s.title ?? _defaultTitle(l10n, s.type)};
              context.push(Uri(path: '/products', queryParameters: q).toString());
            },
          ),
          ProductCarousel(products: s.products, scope: s.id),
        ],
      ),
      BrandsSection() => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SectionHeader(title: s.title ?? l10n.brandFilter),
          _BrandStrip(brands: s.brands),
        ],
      ),
      PromotionsSection() => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (s.title != null) SectionHeader(title: s.title!),
          _Promotions(promotions: s.promotions),
        ],
      ),
      AuctionsSection() => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SectionHeader(
            title: s.title ?? (s.isLive ? l10n.auctionLive : l10n.auctionUpcoming),
            onAction: () => context.go('/auctions?status=${s.isLive ? 'live' : 'upcoming'}'),
          ),
          SizedBox(
            height: 248,
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsetsDirectional.symmetric(horizontal: AppSpace.gutter),
              itemCount: s.auctions.length,
              separatorBuilder: (_, _) => const Gap(AppSpace.md),
              itemBuilder: (_, i) => SizedBox(width: 280, child: AuctionCardView(auction: s.auctions[i])),
            ),
          ),
        ],
      ),
    };
  }

  static String _defaultTitle(AppLocalizations l10n, String type) => switch (type) {
    'new_arrivals' => l10n.badgeNew,
    'best_sellers' => l10n.badgeBestseller,
    'recommended' => l10n.relatedProducts,
    _ => l10n.productsTitle,
  };
}

Future<void> openHomeLink(BuildContext context, HomeLink? link) async {
  if (link == null) return;
  if (link.isExternal) {
    final uri = Uri.tryParse(link.value);
    if (uri != null) await launchUrl(uri, mode: LaunchMode.externalApplication);
    return;
  }
  final location = link.location;
  if (location != null && context.mounted) await context.push(location);
}

class BannerCarousel extends StatefulWidget {
  const BannerCarousel({super.key, required this.banners});
  final List<HomeBanner> banners;

  @override
  State<BannerCarousel> createState() => _BannerCarouselState();
}

class _BannerCarouselState extends State<BannerCarousel> {
  final _controller = PageController(viewportFraction: 0.92);
  Timer? _auto;
  int _index = 0;

  @override
  void initState() {
    super.initState();
    if (widget.banners.length > 1) {
      _auto = Timer.periodic(const Duration(seconds: 6), (_) {
        if (!mounted || !_controller.hasClients) return;
        final reduce = MediaQuery.maybeDisableAnimationsOf(context) ?? false;
        final next = (_index + 1) % widget.banners.length;
        if (reduce) {
          _controller.jumpToPage(next);
        } else {
          unawaited(_controller.animateToPage(next, duration: AppDurations.slow, curve: Curves.easeOutCubic));
        }
      });
    }
  }

  @override
  void dispose() {
    _auto?.cancel();
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final width = MediaQuery.sizeOf(context).width;
    final ratio = width >= 900 ? 21 / 8 : (width >= 600 ? 2.0 : 16 / 11);
    return Column(
      children: [
        AspectRatio(
          aspectRatio: ratio,
          child: PageView.builder(
            controller: _controller,
            itemCount: widget.banners.length,
            onPageChanged: (i) => setState(() => _index = i),
            itemBuilder: (context, i) {
              final b = widget.banners[i];
              return Padding(
                padding: const EdgeInsetsDirectional.symmetric(horizontal: 6),
                child: Semantics(
                  button: b.link != null,
                  label: [b.title, b.subtitle].whereType<String>().join('. '),
                  child: GestureDetector(
                    onTap: () => openHomeLink(context, b.link),
                    child: ClipRRect(
                      borderRadius: AppRadius.cardAll,
                      child: Stack(
                        fit: StackFit.expand,
                        children: [
                          if (b.imageUrl == null || b.imageUrl!.isEmpty)
                            // No picture: the brand banner — navy gradient with soft gold discs.
                            DecoratedBox(
                              decoration: const BoxDecoration(
                                gradient: LinearGradient(
                                  begin: Alignment.topLeft,
                                  end: Alignment.bottomRight,
                                  colors: [AppPalette.navy, AppPalette.navySoft],
                                ),
                              ),
                              child: Stack(
                                children: [
                                  PositionedDirectional(
                                    end: -40,
                                    top: -40,
                                    child: Container(
                                      width: 170,
                                      height: 170,
                                      decoration: BoxDecoration(
                                        shape: BoxShape.circle,
                                        color: AppPalette.brass.withValues(alpha: 0.28),
                                      ),
                                    ),
                                  ),
                                  PositionedDirectional(
                                    end: 30,
                                    bottom: -60,
                                    child: Container(
                                      width: 120,
                                      height: 120,
                                      decoration: BoxDecoration(
                                        shape: BoxShape.circle,
                                        color: Colors.white.withValues(alpha: 0.08),
                                      ),
                                    ),
                                  ),
                                ],
                              ),
                            )
                          else
                            AppNetworkImage(url: b.imageUrl),
                          if (b.title != null || b.subtitle != null)
                            DecoratedBox(
                              decoration: BoxDecoration(
                                gradient: LinearGradient(
                                  begin: Alignment.topCenter,
                                  end: Alignment.bottomCenter,
                                  colors: [Colors.transparent, Colors.black.withValues(alpha: 0.55)],
                                  stops: const [0.4, 1],
                                ),
                              ),
                            ),
                          PositionedDirectional(
                            start: AppSpace.lg,
                            end: AppSpace.lg,
                            bottom: AppSpace.lg,
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                if (b.title != null)
                                  Text(
                                    b.title!,
                                    maxLines: 2,
                                    overflow: TextOverflow.ellipsis,
                                    style: context.text.headlineMedium?.copyWith(color: Colors.white),
                                  ),
                                if (b.subtitle != null) ...[
                                  const Gap(4),
                                  Text(
                                    b.subtitle!,
                                    maxLines: 2,
                                    overflow: TextOverflow.ellipsis,
                                    style: context.text.bodyMedium?.copyWith(
                                      color: Colors.white.withValues(alpha: 0.9),
                                    ),
                                  ),
                                ],
                                if (b.link != null) ...[
                                  const Gap(AppSpace.sm),
                                  Container(
                                    padding: const EdgeInsetsDirectional.symmetric(horizontal: 14, vertical: 8),
                                    decoration: BoxDecoration(
                                      color: AppPalette.brass,
                                      borderRadius: BorderRadius.circular(AppRadius.pill),
                                    ),
                                    child: Row(
                                      mainAxisSize: MainAxisSize.min,
                                      children: [
                                        Text(
                                          context.l10n.shopNow,
                                          style: context.text.labelMedium?.copyWith(
                                            color: AppPalette.ink,
                                            fontWeight: FontWeight.w600,
                                            letterSpacing: 0,
                                          ),
                                        ),
                                        const Gap(4),
                                        const Icon(Icons.arrow_forward_rounded, size: 16, color: AppPalette.ink),
                                      ],
                                    ),
                                  ),
                                ],
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              );
            },
          ),
        ),
        if (widget.banners.length > 1) ...[
          const Gap(AppSpace.sm),
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              for (var i = 0; i < widget.banners.length; i++)
                AnimatedContainer(
                  duration: AppDurations.medium,
                  margin: const EdgeInsets.symmetric(horizontal: 3),
                  width: i == _index ? 18 : 6,
                  height: 6,
                  decoration: BoxDecoration(
                    color: i == _index ? context.colors.primary : context.palette.line,
                    borderRadius: BorderRadius.circular(3),
                  ),
                ),
            ],
          ),
        ],
      ],
    );
  }
}

/// Category tiles: soft white squares with a line icon (or the category picture) above the name.
class _CategoryChips extends StatelessWidget {
  const _CategoryChips({required this.categories});
  final List<CatalogCategory> categories;

  @override
  Widget build(BuildContext context) => SizedBox(
    height: 104,
    child: ListView.separated(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsetsDirectional.symmetric(horizontal: AppSpace.gutter),
      itemCount: categories.length,
      separatorBuilder: (_, _) => const Gap(10),
      itemBuilder: (context, i) {
        final c = categories[i];
        final image = c.imageUrl ?? c.iconUrl;
        return Semantics(
          button: true,
          label: c.name,
          excludeSemantics: true,
          child: Material(
            color: context.colors.surface,
            borderRadius: AppRadius.inputAll,
            elevation: 0,
            child: InkWell(
              borderRadius: AppRadius.inputAll,
              onTap: () => context.push('/categories/${c.id}'),
              child: Container(
                width: 88,
                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 10),
                decoration: BoxDecoration(
                  borderRadius: AppRadius.inputAll,
                  border: Border.all(color: context.palette.line),
                ),
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    if (image != null && image.isNotEmpty)
                      AppNetworkImage(url: image, width: 40, height: 40, borderRadius: BorderRadius.circular(12))
                    else
                      Container(
                        width: 40,
                        height: 40,
                        decoration: BoxDecoration(color: AppPalette.tint, borderRadius: BorderRadius.circular(12)),
                        child: Icon(Icons.category_outlined, size: 22, color: context.colors.primary),
                      ),
                    const Gap(AppSpace.xs),
                    Text(
                      c.name,
                      maxLines: 2,
                      textAlign: TextAlign.center,
                      overflow: TextOverflow.ellipsis,
                      style: context.text.labelMedium?.copyWith(fontSize: 12, height: 1.2),
                    ),
                  ],
                ),
              ),
            ),
          ),
        );
      },
    ),
  );
}

class _BrandStrip extends StatelessWidget {
  const _BrandStrip({required this.brands});
  final List<Brand> brands;

  @override
  Widget build(BuildContext context) => SizedBox(
    height: 84,
    child: ListView.separated(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsetsDirectional.symmetric(horizontal: AppSpace.gutter),
      itemCount: brands.length,
      separatorBuilder: (_, _) => const Gap(AppSpace.sm),
      itemBuilder: (context, i) {
        final b = brands[i];
        return Semantics(
          button: true,
          label: b.name,
          excludeSemantics: true,
          child: SurfaceCard(
            padding: const EdgeInsets.all(AppSpace.sm),
            onTap: () =>
                context.push(Uri(path: '/products', queryParameters: {'brand_id': b.id, 'title': b.name}).toString()),
            child: SizedBox(
              width: 112,
              child: Center(
                child: b.logoUrl != null
                    ? AppNetworkImage(url: b.logoUrl, fit: BoxFit.contain, height: 48)
                    : Text(b.name, textAlign: TextAlign.center, style: context.text.titleSmall),
              ),
            ),
          ),
        );
      },
    ),
  );
}

class _Promotions extends StatelessWidget {
  const _Promotions({required this.promotions});
  final List<Promotion> promotions;

  @override
  Widget build(BuildContext context) => SizedBox(
    height: 150,
    child: ListView.separated(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsetsDirectional.fromSTEB(AppSpace.gutter, AppSpace.md, AppSpace.gutter, 0),
      itemCount: promotions.length,
      separatorBuilder: (_, _) => const Gap(AppSpace.md),
      itemBuilder: (context, i) {
        final p = promotions[i];
        return SizedBox(
          width: 280,
          child: Card(
            color: context.colors.inverseSurface,
            clipBehavior: Clip.antiAlias,
            child: InkWell(
              onTap: p.link == null ? null : () => openHomeLink(context, p.link),
              child: Padding(
                padding: const EdgeInsets.all(AppSpace.md),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    if (p.badge != null)
                      Text(p.badge!, style: context.text.headlineMedium?.copyWith(color: context.palette.brass)),
                    Text(
                      p.name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: context.text.titleMedium?.copyWith(color: context.colors.onInverseSurface),
                    ),
                    if (p.description != null)
                      Expanded(
                        child: Text(
                          p.description!,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: context.text.bodySmall?.copyWith(
                            color: context.colors.onInverseSurface.withValues(alpha: 0.75),
                          ),
                        ),
                      )
                    else
                      const Spacer(),
                    if (p.endsAt != null)
                      CountdownText(
                        target: p.endsAt!,
                        builder: (context, _, f) => Text(
                          context.l10n.endsIn(f),
                          style: context.text.labelSmall?.copyWith(color: context.palette.brass),
                        ),
                      ),
                  ],
                ),
              ),
            ),
          ),
        );
      },
    ),
  );
}

/// Auction card shared by home and the auctions list.
class AuctionCardView extends StatelessWidget {
  const AuctionCardView({super.key, required this.auction});
  final AuctionSummary auction;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final offset = auction.serverTime == null ? Duration.zero : auction.serverTime!.difference(DateTime.now());
    final target = auction.isUpcoming ? auction.startsAt : auction.endsAt;
    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => context.push('/auctions/${auction.id}'),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Expanded(
              child: Stack(
                fit: StackFit.expand,
                children: [
                  AppNetworkImage(url: auction.imageUrl),
                  PositionedDirectional(
                    top: AppSpace.sm,
                    start: AppSpace.sm,
                    child: StatusChip(
                      label: auction.isLive
                          ? l10n.auctionLive
                          : (auction.isUpcoming ? l10n.auctionUpcoming : l10n.auctionCompleted),
                      tone: auction.isLive
                          ? StatusTone.danger
                          : (auction.isUpcoming ? StatusTone.info : StatusTone.neutral),
                      icon: auction.isLive ? Icons.circle : null,
                      dense: true,
                    ),
                  ),
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.all(AppSpace.sm),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(auction.title, maxLines: 1, overflow: TextOverflow.ellipsis, style: context.text.titleMedium),
                  const Gap(2),
                  Row(
                    children: [
                      Text(l10n.lotsCount(auction.lotsCount), style: context.text.bodySmall),
                      const Spacer(),
                      if (target != null && auction.status != 'completed')
                        CountdownText(
                          target: target,
                          serverOffset: offset,
                          builder: (context, remaining, f) => Text(
                            auction.isUpcoming ? l10n.startsIn(f) : l10n.endsIn(f),
                            style: context.text.labelMedium?.copyWith(color: context.palette.brass),
                          ),
                        )
                      else if (auction.endsAt != null)
                        Text(QatarTime.date(auction.endsAt, context.localeCode), style: context.text.bodySmall),
                    ],
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
