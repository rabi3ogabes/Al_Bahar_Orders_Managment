import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/live_order/notification_ask.dart';
import 'package:albahar_customer/core/theme/brand_theme.dart';
import 'package:albahar_customer/core/theme/design_tokens.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/countdown.dart';
import 'package:albahar_customer/core/widgets/fresh.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/auctions/domain/auction_models.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/catalog_widgets.dart';
import 'package:albahar_customer/features/deals/presentation/home_deals_strip.dart';
import 'package:albahar_customer/features/deals/presentation/state/deals_providers.dart';
import 'package:albahar_customer/features/home/data/home_repository.dart';
import 'package:albahar_customer/features/home/domain/home_models.dart';
import 'package:albahar_customer/features/home/presentation/buy_again_section.dart';
import 'package:albahar_customer/features/home/presentation/home_link_opener.dart';
import 'package:albahar_customer/features/home/presentation/home_slider.dart';
import 'package:albahar_customer/features/home/presentation/season_banner.dart';
import 'package:albahar_customer/features/notifications/presentation/notifications_screen.dart';
import 'package:albahar_customer/features/notifications/presentation/state/notifications_controller.dart';
import 'package:albahar_customer/features/orders/presentation/state/order_providers.dart';
import 'package:albahar_customer/features/orders/presentation/widgets/active_order_card.dart';
import 'package:albahar_customer/features/rooms/presentation/room_tiles.dart';
import 'package:albahar_customer/features/scan/presentation/widgets/scan_entry.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // Choose the nearest / available branch once, before the first feed (the feed reloads when the branch changes).
    ref.watch(autoBranchSelectionProvider);
    final feed = ref.watch(homeFeedProvider);
    final l10n = context.l10n;
    final fresh = context.design.isFresh;

    final page = FreshScaffold(
      body: RefreshIndicator(
        onRefresh: () async {
          unawaited(ref.read(brandThemeProvider.notifier).refresh());
          ref.invalidate(activeOrdersProvider);
          ref.invalidate(homeDealsProvider);
          ref.invalidate(homeFeedProvider);
          await ref.read(homeFeedProvider.future);
        },
        child: CustomScrollView(
          slivers: [
            if (fresh) const SliverToBoxAdapter(child: _FreshHomeHeader()) else const _HomeHeader(),
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
                if (fresh) {
                  // Fresh order: banner, categories, best sellers and new arrivals, the rest, promo strips, buy again.
                  return [
                    const SliverToBoxAdapter(child: SeasonBanner()),
                    const SliverToBoxAdapter(child: ActiveOrderCard()),
                    const SliverToBoxAdapter(child: HomeDealsStrip()),
                    for (final s in freshSectionOrder(data.sections))
                      SliverToBoxAdapter(
                        child: HomeSectionView(key: ValueKey(s.id), section: s),
                      ),
                    const SliverToBoxAdapter(child: BuyAgainSection()),
                    const SliverToBoxAdapter(child: Gap(AppSpace.xxl)),
                  ];
                }
                return [
                  const SliverToBoxAdapter(child: SeasonBanner()),
                  const SliverToBoxAdapter(child: ActiveOrderCard()),
                  const SliverToBoxAdapter(child: HomeDealsStrip()),
                  const SliverToBoxAdapter(child: BuyAgainSection()),
                  for (final s in data.sections)
                    SliverToBoxAdapter(
                      child: HomeSectionView(key: ValueKey(s.id), section: s),
                    ),
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
    // A few seconds after a signed-in customer reaches Home, NotificationAsk asks them to allow notifications; it adds nothing to the page.
    return NotificationAsk(child: page);
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
    final multiBranch = ref.watch(multipleBranchesProvider);
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
            decoration: BoxDecoration(color: context.colors.primary, borderRadius: BorderRadius.circular(12)),
            child: Icon(Icons.waves_rounded, color: context.palette.onPrimarySoft, size: 22),
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
              onPressed: () => unawaited(showNotificationsPanel(context)),
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
        preferredSize: Size.fromHeight(multiBranch ? 124 : 72),
        child: Padding(
          padding: const EdgeInsetsDirectional.fromSTEB(AppSpace.gutter, 0, AppSpace.gutter, AppSpace.sm),
          child: _HomeSearchRow(multiBranch: multiBranch),
        ),
      ),
    );
  }
}

/// Greeting header of the fresh design: brand mark, bold greeting, tagline, bell, then the search pill.
class _FreshHomeHeader extends ConsumerWidget {
  const _FreshHomeHeader();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final user = ref.watch(sessionControllerProvider).user;
    final unread = ref.watch(unreadCountProvider);
    final signedIn = ref.watch(isAuthenticatedProvider);
    final multiBranch = ref.watch(multipleBranchesProvider);
    final gutter = context.design.gutter;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        FreshHeader(
          title: user != null ? l10n.homeGreetingNamed(user.firstName) : l10n.homeGreeting,
          tagline: l10n.freshTaglineHome,
          trailing: [
            if (signedIn)
              FreshHeaderAction(
                icon: const Icon(Icons.notifications_none_rounded),
                tooltip: l10n.notificationsTitle,
                badge: unread,
                onPressed: () => unawaited(showNotificationsPanel(context)),
              ),
          ],
        ),
        Padding(
          padding: EdgeInsetsDirectional.fromSTEB(gutter, 0, gutter, AppSpace.xs),
          child: _HomeSearchRow(multiBranch: multiBranch),
        ),
      ],
    );
  }
}

/// Order of the server's sections in the fresh home: pictures first, then categories, the product rails, everything else in the
/// server's order, and the promotions last (they become promo strips). Nothing is added or removed.
///
/// A slider is the exception: the dashboard places it among the sections, so it stays where it was put. Each slider goes right after
/// the section it followed in the server's order (or first, when it came first), whatever the other sections are moved to.
List<HomeSection> freshSectionOrder(List<HomeSection> sections) {
  int rank(HomeSection s) => switch (s) {
    BannersSection() => 0,
    CategoriesSection() => 1,
    RoomsSection() => 1, // right after the categories (the server's order keeps them apart)
    ProductsSection(type: 'best_sellers') => 2,
    ProductsSection(type: 'new_arrivals') => 3,
    ProductsSection() => 4,
    AuctionsSection() => 5,
    BrandsSection() => 6,
    PromotionsSection() => 7,
  };
  final others = [
    for (var i = 0; i < sections.length; i++)
      if (sections[i] is! BannersSection) (i, sections[i]),
  ];
  others.sort((a, b) {
    final r = rank(a.$2).compareTo(rank(b.$2));
    return r != 0 ? r : a.$1.compareTo(b.$1);
  });
  // The sliders that sit before the first other section lead; every other slider follows the section it came after.
  final leading = <HomeSection>[];
  final after = <String, List<HomeSection>>{};
  HomeSection? anchor;
  for (final s in sections) {
    if (s is BannersSection) {
      if (anchor == null) {
        leading.add(s);
      } else {
        (after[anchor.id] ??= []).add(s);
      }
    } else {
      anchor = s;
    }
  }
  return [
    ...leading,
    for (final e in others) ...[e.$2, ...?after[e.$2.id]],
  ];
}

/// Search pill (opens Search) with the filter button, and the branch pill above it when there are several branches.
class _HomeSearchRow extends StatelessWidget {
  const _HomeSearchRow({required this.multiBranch});
  final bool multiBranch;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final radius = context.design.controlBorderRadius;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (multiBranch) ...[const BranchPill(), const Gap(AppSpace.xs)],
        Row(
          children: [
            Expanded(
              child: Semantics(
                button: true,
                label: l10n.searchHint,
                child: InkWell(
                  borderRadius: radius,
                  onTap: () => context.push('/search'),
                  child: Container(
                    height: 52,
                    padding: const EdgeInsetsDirectional.only(start: AppSpace.md, end: AppSpace.xxs),
                    decoration: BoxDecoration(
                      color: context.colors.surface,
                      borderRadius: radius,
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
                        // Scan to add: its own tap target inside the pill, so a tap on it never opens Search.
                        const ScanIconButton(),
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
                borderRadius: radius,
                child: InkWell(
                  borderRadius: radius,
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
    final fresh = context.design.isFresh;
    return switch (s) {
      BannersSection() => Padding(
        padding: const EdgeInsets.only(top: AppSpace.sm),
        child: HomeSlider(section: s),
      ),
      CategoriesSection() => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SectionHeader(title: s.title ?? l10n.categoriesTitle, onAction: () => context.go('/categories')),
          if (fresh) _FreshCategoryRow(categories: s.categories) else _CategoryChips(categories: s.categories),
        ],
      ),
      RoomsSection() => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SectionHeader(title: s.title ?? l10n.roomsTitle),
          RoomTiles(rooms: s.rooms),
        ],
      ),
      ProductsSection() => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SectionHeader(
            title: s.title ?? _defaultTitle(l10n, s.type, fresh: fresh),
            onAction: () {
              final q = {...s.listingQuery, 'title': s.title ?? _defaultTitle(l10n, s.type, fresh: fresh)};
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
          if (fresh) _PromoStrips(promotions: s.promotions) else _Promotions(promotions: s.promotions),
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

  static String _defaultTitle(AppLocalizations l10n, String type, {bool fresh = false}) => switch (type) {
    'new_arrivals' => fresh ? l10n.freshNewArrivals : l10n.badgeNew,
    'best_sellers' => fresh ? l10n.freshBestSellers : l10n.badgeBestseller,
    'recommended' => l10n.relatedProducts,
    _ => l10n.productsTitle,
  };
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
                        decoration: BoxDecoration(color: context.palette.tint, borderRadius: BorderRadius.circular(12)),
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

/// Fresh category row: rounded cards with the picture on top, the name below and a small round arrow badge.
class _FreshCategoryRow extends StatelessWidget {
  const _FreshCategoryRow({required this.categories});
  final List<CatalogCategory> categories;

  @override
  Widget build(BuildContext context) {
    final design = context.design;
    final textScale = MediaQuery.textScalerOf(context).scale(1).clamp(1.0, 1.6);
    return SizedBox(
      height: 132 + 26 * textScale,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        clipBehavior: Clip.none,
        padding: EdgeInsetsDirectional.fromSTEB(design.gutter, 4, design.gutter, 16),
        itemCount: categories.length,
        separatorBuilder: (_, _) => const Gap(AppSpace.sm),
        itemBuilder: (context, i) {
          final c = categories[i];
          final image = c.imageUrl ?? c.iconUrl;
          return SizedBox(
            width: 124,
            child: Semantics(
              button: true,
              label: c.name,
              excludeSemantics: true,
              child: DecoratedBox(
                decoration: context.cardDecoration(),
                child: Material(
                  type: MaterialType.transparency,
                  child: InkWell(
                    borderRadius: design.cardBorderRadius,
                    onTap: () => context.push('/categories/${c.id}'),
                    child: Padding(
                      padding: const EdgeInsets.all(8),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Expanded(
                            child: Stack(
                              fit: StackFit.expand,
                              children: [
                                if (image != null && image.isNotEmpty)
                                  AppNetworkImage(url: image, borderRadius: design.controlBorderRadius)
                                else
                                  DecoratedBox(
                                    decoration: BoxDecoration(
                                      color: context.palette.tint,
                                      borderRadius: design.controlBorderRadius,
                                    ),
                                    child: Icon(Icons.category_outlined, size: 30, color: context.colors.primary),
                                  ),
                                PositionedDirectional(
                                  end: 6,
                                  bottom: 6,
                                  child: Container(
                                    width: 26,
                                    height: 26,
                                    decoration: BoxDecoration(color: context.colors.primary, shape: BoxShape.circle),
                                    child: Icon(Icons.arrow_forward_rounded, size: 15, color: context.colors.onPrimary),
                                  ),
                                ),
                              ],
                            ),
                          ),
                          const Gap(AppSpace.xs),
                          Text(
                            c.name,
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                            style: context.text.labelLarge?.copyWith(fontSize: 13, height: 1.2, letterSpacing: 0),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
            ),
          );
        },
      ),
    );
  }
}

/// Fresh promotions: a gradient strip per promotion (name, description, pill button when it has a link).
class _PromoStrips extends StatelessWidget {
  const _PromoStrips({required this.promotions});
  final List<Promotion> promotions;

  @override
  Widget build(BuildContext context) {
    final gutter = context.design.gutter;
    return Padding(
      padding: EdgeInsetsDirectional.fromSTEB(gutter, AppSpace.md, gutter, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final p in promotions.take(3)) ...[
            PromoStrip(
              title: p.badge == null ? p.name : '${p.badge} · ${p.name}',
              subtitle: p.description,
              actionLabel: context.l10n.shopNow,
              onTap: p.link == null ? null : () => openHomeLink(context, p.link),
            ),
            const Gap(AppSpace.sm),
          ],
        ],
      ),
    );
  }
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
                      Expanded(
                        child: Text(
                          l10n.lotsCount(auction.lotsCount),
                          style: context.text.bodySmall,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                      if (target != null && auction.status != 'completed')
                        Flexible(
                          child: CountdownText(
                            target: target,
                            serverOffset: offset,
                            builder: (context, remaining, f) => Text(
                              auction.isUpcoming ? l10n.startsIn(f) : l10n.endsIn(f),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: context.text.labelMedium?.copyWith(color: context.palette.brass),
                            ),
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
