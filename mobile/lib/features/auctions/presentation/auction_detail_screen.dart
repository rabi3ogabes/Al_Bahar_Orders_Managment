import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/countdown.dart';
import 'package:albahar_customer/core/widgets/html_content.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/auctions/data/auction_repository.dart';
import 'package:albahar_customer/features/auctions/domain/auction_models.dart';
import 'package:albahar_customer/features/auctions/presentation/lot_widgets.dart';
import 'package:albahar_customer/features/auctions/presentation/state/auction_providers.dart';
import 'package:albahar_customer/features/auth/presentation/require_auth.dart';
import 'package:albahar_customer/features/checkout/presentation/payment_flow.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

class AuctionDetailScreen extends ConsumerWidget {
  const AuctionDetailScreen({super.key, required this.auctionId});
  final String auctionId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final auction = ref.watch(auctionDetailProvider(auctionId));
    return Scaffold(
      body: auction.when(
        skipLoadingOnRefresh: true,
        loading: () => Scaffold(appBar: AppBar(), body: const ListSkeleton(count: 4)),
        error: (e, _) => Scaffold(
          appBar: AppBar(),
          body: ErrorState(error: e, onRetry: () => ref.invalidate(auctionDetailProvider(auctionId))),
        ),
        data: (a) {
          final s = a.summary;
          final offset = s.serverTime == null ? Duration.zero : s.serverTime!.difference(DateTime.now());
          final target = s.isUpcoming ? s.startsAt : s.endsAt;
          return RefreshIndicator(
            onRefresh: () => ref.refresh(auctionDetailProvider(auctionId).future),
            child: CustomScrollView(
              slivers: [
                SliverAppBar(
                  pinned: true,
                  expandedHeight: 260,
                  flexibleSpace: FlexibleSpaceBar(background: AppNetworkImage(url: s.imageUrl)),
                ),
                SliverToBoxAdapter(
                  child: ContentWidth(
                    maxWidth: 900,
                    child: Padding(
                      padding: const EdgeInsets.all(AppSpace.gutter),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Row(
                            children: [
                              StatusChip(
                                label: s.isLive
                                    ? l10n.auctionLive
                                    : (s.isUpcoming ? l10n.auctionUpcoming : l10n.auctionCompleted),
                                tone: toneForStatus(s.isLive ? 'live' : s.status),
                              ),
                              const Gap(AppSpace.sm),
                              if (target != null && !(s.status == 'completed'))
                                Expanded(
                                  child: Align(
                                    alignment: AlignmentDirectional.centerEnd,
                                    child: CountdownText(
                                      target: target,
                                      serverOffset: offset,
                                      onElapsed: () => ref.invalidate(auctionDetailProvider(auctionId)),
                                      builder: (context, _, f) => Text(
                                        s.isUpcoming ? l10n.startsIn(f) : l10n.endsIn(f),
                                        maxLines: 2,
                                        overflow: TextOverflow.ellipsis,
                                        textAlign: TextAlign.end,
                                        style: context.text.titleMedium?.copyWith(color: context.palette.brass),
                                      ),
                                    ),
                                  ),
                                ),
                            ],
                          ),
                          const Gap(AppSpace.sm),
                          Text(s.title, style: context.text.headlineLarge),
                          if (s.startsAt != null)
                            Text(
                              s.status == 'completed'
                                  ? l10n.auctionEndedAt(QatarTime.dateTime(s.endsAt, context.localeCode))
                                  : l10n.auctionStartsAt(QatarTime.dateTime(s.startsAt, context.localeCode)),
                              style: context.text.bodySmall,
                            ),
                          if (s.description != null) ...[
                            const Gap(AppSpace.sm),
                            Text(s.description!, style: context.text.bodyMedium),
                          ],
                          const Gap(AppSpace.lg),
                          _RegistrationCard(auction: a),
                          const Gap(AppSpace.lg),
                          Text('${l10n.lots} · ${l10n.lotsCount(a.lots.length)}', style: context.text.titleLarge),
                          const Gap(AppSpace.sm),
                        ],
                      ),
                    ),
                  ),
                ),
                SliverPadding(
                  padding: const EdgeInsets.symmetric(horizontal: AppSpace.gutter),
                  sliver: SliverList.separated(
                    itemCount: a.lots.length,
                    separatorBuilder: (_, _) => const Gap(AppSpace.sm),
                    itemBuilder: (_, i) => ContentWidth(
                      maxWidth: 900,
                      child: LotCard(lot: a.lots[i], serverOffset: offset),
                    ),
                  ),
                ),
                const SliverToBoxAdapter(child: Gap(AppSpace.xxl)),
              ],
            ),
          );
        },
      ),
    );
  }
}

class _RegistrationCard extends ConsumerStatefulWidget {
  const _RegistrationCard({required this.auction});
  final Auction auction;

  @override
  ConsumerState<_RegistrationCard> createState() => _RegistrationCardState();
}

class _RegistrationCardState extends ConsumerState<_RegistrationCard> {
  bool _accepted = false;
  bool _busy = false;

  Future<void> _register() async {
    if (!requireAuth(context, ref)) return;
    setState(() => _busy = true);
    try {
      final reg = await ref.read(auctionRepositoryProvider).register(widget.auction.id);
      Haptics.success();
      if (reg.depositPayment != null && reg.depositPayment!.requiresRedirect) {
        await openSecurePayment(reg.depositPayment!);
        if (mounted) {
          await context.push('/payments/${reg.depositPayment!.paymentId}/return');
        }
      }
      ref.invalidate(auctionDetailProvider(widget.auction.id));
    } catch (e) {
      if (mounted) showErrorSnack(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final a = widget.auction;
    final reg = a.registration;
    if (!a.requiresRegistration || a.summary.status == 'completed') return const SizedBox.shrink();
    if (reg != null && reg.canBid) {
      return SurfaceCard(
        color: context.palette.brassTint,
        child: Row(
          children: [
            Icon(Icons.verified_user_outlined, color: context.palette.brass),
            const Gap(AppSpace.sm),
            Expanded(child: Text(l10n.registeredPaddle(reg.paddleNumber ?? '—'), style: context.text.titleSmall)),
          ],
        ),
      );
    }
    final deposit = reg?.depositRequired ?? a.depositRequired;
    return SurfaceCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(reg != null ? l10n.registrationPending : l10n.registerToBid, style: context.text.titleMedium),
          if (deposit != null && !deposit.isZero) ...[
            const Gap(AppSpace.xxs),
            Text(l10n.depositRequired(deposit.formatted), style: context.text.bodySmall),
          ],
          if (reg?.depositPayment != null) ...[
            const Gap(AppSpace.sm),
            AppButton.accent(label: l10n.payDeposit, onPressed: () => openSecurePayment(reg!.depositPayment!)),
          ],
          if (reg == null) ...[
            const Gap(AppSpace.xs),
            if (a.termsHtml != null)
              TextButton(
                onPressed: () => showAppBottomSheet<void>(
                  context: context,
                  title: l10n.auctionTerms,
                  builder: (_) => HtmlContent(a.termsHtml!),
                ),
                child: Text(l10n.auctionTerms),
              )
            else
              TextButton(onPressed: () => context.push('/pages/auction-terms'), child: Text(l10n.auctionTerms)),
            CheckboxListTile(
              contentPadding: EdgeInsets.zero,
              controlAffinity: ListTileControlAffinity.leading,
              value: _accepted,
              onChanged: (v) => setState(() => _accepted = v ?? false),
              title: Text(l10n.acceptTerms, style: context.text.bodyMedium),
            ),
            AppButton(label: l10n.registerToBid, loading: _busy, onPressed: _accepted ? _register : null),
          ],
        ],
      ),
    );
  }
}
