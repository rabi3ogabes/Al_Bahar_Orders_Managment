import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/utils/validators.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/app_text_field.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/countdown.dart';
import 'package:albahar_customer/core/widgets/input_sheets.dart';
import 'package:albahar_customer/core/widgets/price_tag.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/auctions/domain/auction_models.dart';
import 'package:albahar_customer/features/auctions/presentation/lot_widgets.dart';
import 'package:albahar_customer/features/auctions/presentation/state/auction_providers.dart';
import 'package:albahar_customer/features/auth/presentation/require_auth.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// Live lot: authoritative REST state + realtime events, server-offset
/// countdown, bidding with idempotency, auto-bid and watchlist.
class LotScreen extends ConsumerWidget {
  const LotScreen({super.key, required this.lotId});
  final String lotId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final provider = lotControllerProvider(lotId);
    final async = ref.watch(provider);

    ref.listen(provider.select((v) => v.value?.lastEventAt), (prev, next) {
      final s = ref.read(provider).value;
      if (next == null || s == null) return;
      if (s.lastEvent == 'lot.extended') {
        Haptics.warning();
        showInfoSnack(context, l10n.lotExtended);
      }
    });
    ref.listen(provider.select((v) => v.value?.lot.myStatus), (prev, next) {
      if (prev == 'leading' && next == 'outbid') {
        Haptics.heavy();
        showInfoSnack(context, l10n.youWereOutbid);
      }
      if (next == 'won' && prev != 'won') Haptics.success();
    });

    return Scaffold(
      appBar: AppBar(
        title: Text(async.value != null ? l10n.lotNumber(async.value!.lot.lotNumber) : ''),
        actions: [
          if (async.value != null) _RealtimeBadge(state: async.value!.realtime),
          if (async.value != null) _WatchButton(lotId: lotId, watching: async.value!.lot.isWatching),
        ],
      ),
      body: async.when(
        skipLoadingOnRefresh: true,
        skipLoadingOnReload: true,
        loading: () => const ListSkeleton(count: 3, leading: false),
        error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(provider)),
        data: (s) => RefreshIndicator(
          onRefresh: () => ref.read(provider.notifier).resync(),
          child: ListView(
            padding: const EdgeInsets.only(bottom: AppSpace.xxl),
            children: [
              AspectRatio(
                aspectRatio: 4 / 3,
                child: AppNetworkImage(url: s.lot.imageUrl),
              ),
              ContentWidth(
                child: Padding(
                  padding: const EdgeInsets.all(AppSpace.gutter),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Text(s.lot.title, style: context.text.headlineMedium),
                      if (s.lot.description != null) ...[
                        const Gap(AppSpace.xs),
                        Text(
                          s.lot.description!,
                          style: context.text.bodyMedium?.copyWith(color: context.palette.muted),
                        ),
                      ],
                      const Gap(AppSpace.lg),
                      _PricePanel(state: s),
                      const Gap(AppSpace.md),
                      _MyStatusBanner(lot: s.lot),
                      if (s.lot.isOpen) ...[
                        const Gap(AppSpace.md),
                        _BidComposer(lotId: lotId, lot: s.lot),
                        const Gap(AppSpace.sm),
                        _AutoBidRow(lotId: lotId, lot: s.lot),
                      ] else if (s.lot.myStatus == 'won') ...[
                        const Gap(AppSpace.md),
                        AppButton.accent(
                          label: l10n.checkoutWin,
                          onPressed: () => context.push('/my-auctions?tab=wins'),
                        ),
                      ],
                      const Gap(AppSpace.lg),
                      Text(l10n.bidHistory, style: context.text.titleLarge),
                      const Gap(AppSpace.xs),
                      if (s.bids.isEmpty)
                        Text(l10n.noBids, style: context.text.bodyMedium?.copyWith(color: context.palette.muted))
                      else
                        for (final b in s.bids.take(30)) _BidRow(bid: b),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _RealtimeBadge extends StatelessWidget {
  const _RealtimeBadge({required this.state});
  final RealtimeState state;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final live = state == RealtimeState.connected;
    return Padding(
      padding: const EdgeInsetsDirectional.only(end: AppSpace.xs),
      child: Center(
        child: StatusChip(
          label: live ? l10n.realtimeLive : l10n.realtimeReconnecting,
          tone: live ? StatusTone.success : StatusTone.warning,
          icon: live ? Icons.circle : Icons.sync_rounded,
          dense: true,
        ),
      ),
    );
  }
}

class _WatchButton extends ConsumerWidget {
  const _WatchButton({required this.lotId, required this.watching});
  final String lotId;
  final bool watching;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    return IconButton(
      tooltip: watching ? l10n.watching : l10n.watch,
      icon: Icon(watching ? Icons.bookmark_rounded : Icons.bookmark_border_rounded),
      onPressed: () async {
        if (!requireAuth(context, ref)) return;
        try {
          final now = await ref.read(lotControllerProvider(lotId).notifier).toggleWatch();
          Haptics.tap();
          if (context.mounted) showInfoSnack(context, now ? l10n.watchAdded : l10n.watchRemoved);
        } catch (e) {
          if (context.mounted) showErrorSnack(context, e);
        }
      },
    );
  }
}

class _PricePanel extends StatelessWidget {
  const _PricePanel({required this.state});
  final LotViewState state;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final lot = state.lot;
    final target = lot.isOpen ? lot.endsAt : (lot.status == 'scheduled' ? lot.startsAt : null);
    return Container(
      padding: const EdgeInsets.all(AppSpace.lg),
      decoration: BoxDecoration(color: context.colors.inverseSurface, borderRadius: AppRadius.lgAll),
      child: DefaultTextStyle.merge(
        style: TextStyle(color: context.colors.onInverseSurface),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                StatusChip(label: lotStatusLabel(l10n, lot.status), tone: toneForStatus(lot.status), dense: true),
                const Spacer(),
                Text(
                  l10n.bidCount(lot.bidCount),
                  style: context.text.bodySmall?.copyWith(
                    color: context.colors.onInverseSurface.withValues(alpha: 0.7),
                  ),
                ),
              ],
            ),
            const Gap(AppSpace.md),
            Text(
              lot.currentPrice != null ? l10n.currentBid : l10n.startingPrice,
              style: context.text.labelMedium?.copyWith(color: context.colors.onInverseSurface.withValues(alpha: 0.7)),
            ),
            AnimatedMoney(
              money: lot.displayPrice,
              style: context.text.displaySmall?.copyWith(color: context.colors.onInverseSurface),
            ),
            const Gap(AppSpace.sm),
            if (target != null)
              CountdownText(
                target: target,
                serverOffset: state.serverOffset,
                builder: (context, remaining, f) {
                  final urgent = lot.isOpen && remaining.inSeconds <= 60;
                  return Row(
                    children: [
                      Icon(
                        Icons.timer_outlined,
                        size: 18,
                        color: urgent ? context.palette.danger : context.palette.brass,
                      ),
                      const Gap(AppSpace.xs),
                      Flexible(
                        child: Text(
                          lot.isOpen ? l10n.endsIn(f) : l10n.startsIn(f),
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: context.text.titleMedium?.copyWith(
                            color: urgent ? context.palette.danger : context.palette.brass,
                            fontFeatures: const [FontFeature.tabularFigures()],
                          ),
                        ),
                      ),
                      if (lot.extensionCount > 0) ...[
                        const Gap(AppSpace.xs),
                        Text(
                          '· ${l10n.lotExtended}',
                          style: context.text.bodySmall?.copyWith(
                            color: context.colors.onInverseSurface.withValues(alpha: 0.7),
                          ),
                        ),
                      ],
                    ],
                  );
                },
              )
            else if (lot.isFinished)
              Text(l10n.lotEnded, style: context.text.titleSmall?.copyWith(color: context.colors.onInverseSurface)),
            const Gap(AppSpace.md),
            Wrap(
              spacing: AppSpace.lg,
              runSpacing: AppSpace.xs,
              children: [
                _Metric(label: l10n.minNextBid, value: lot.minNextBid.formatted),
                if (lot.minIncrement != null) _Metric(label: l10n.minIncrement, value: lot.minIncrement!.formatted),
                if (lot.buyNowPrice != null) _Metric(label: l10n.buyNowPrice, value: lot.buyNowPrice!.formatted),
                if (lot.reserveMet != null)
                  _Metric(label: '', value: lot.reserveMet! ? l10n.reserveMet : l10n.reserveNotMet),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _Metric extends StatelessWidget {
  const _Metric({required this.label, required this.value});
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    final c = context.colors.onInverseSurface;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        if (label.isNotEmpty) Text(label, style: context.text.labelSmall?.copyWith(color: c.withValues(alpha: 0.6))),
        Text(value, style: context.text.titleSmall?.copyWith(color: c)),
      ],
    );
  }
}

class _MyStatusBanner extends StatelessWidget {
  const _MyStatusBanner({required this.lot});
  final Lot lot;

  @override
  Widget build(BuildContext context) {
    final text = myStatusLabel(context.l10n, lot.myStatus);
    if (text == null) return const SizedBox.shrink();
    final tone = toneForStatus(lot.myStatus);
    final color = switch (tone) {
      StatusTone.success => context.palette.success,
      StatusTone.warning => context.palette.warning,
      StatusTone.danger => context.palette.danger,
      _ => context.palette.info,
    };
    return AnimatedSwitcher(
      duration: AppDurations.medium,
      child: Container(
        key: ValueKey(lot.myStatus),
        padding: const EdgeInsets.all(AppSpace.md),
        decoration: BoxDecoration(color: color.withValues(alpha: 0.12), borderRadius: AppRadius.cardAll),
        child: Semantics(
          liveRegion: true,
          child: Row(
            children: [
              Icon(
                lot.myStatus == 'leading' || lot.myStatus == 'won'
                    ? Icons.emoji_events_outlined
                    : Icons.trending_up_rounded,
                color: color,
              ),
              const Gap(AppSpace.sm),
              Expanded(
                child: Text(text, style: context.text.titleSmall?.copyWith(color: color)),
              ),
              if (lot.myMaxAutoBid != null)
                Text(context.l10n.autoBidActive(lot.myMaxAutoBid!.formatted), style: context.text.bodySmall),
            ],
          ),
        ),
      ),
    );
  }
}

/// Bid entry with quick-pick amounts; amounts are sent as decimal strings
/// and validated by the server (the client only pre-checks the minimum).
class _BidComposer extends ConsumerStatefulWidget {
  const _BidComposer({required this.lotId, required this.lot});
  final String lotId;
  final Lot lot;

  @override
  ConsumerState<_BidComposer> createState() => _BidComposerState();
}

class _BidComposerState extends ConsumerState<_BidComposer> {
  late final _amount = TextEditingController(text: _plain(widget.lot.minNextBid.amount));
  bool _busy = false;
  bool _confirming = false;
  String? _error;
  bool _edited = false;

  static String _plain(String amount) {
    final v = double.tryParse(amount);
    if (v == null) return amount;
    return v == v.roundToDouble() ? v.toStringAsFixed(0) : v.toStringAsFixed(2);
  }

  @override
  void didUpdateWidget(covariant _BidComposer oldWidget) {
    super.didUpdateWidget(oldWidget);
    // Follow the authoritative minimum unless the customer typed a higher bid.
    final min = widget.lot.minNextBid.displayValue;
    final typed = double.tryParse(_amount.text) ?? 0;
    if (oldWidget.lot.minNextBid.amount != widget.lot.minNextBid.amount && (!_edited || typed < min)) {
      _amount.text = _plain(widget.lot.minNextBid.amount);
      _edited = false;
    }
  }

  @override
  void dispose() {
    _amount.dispose();
    super.dispose();
  }

  Future<void> _place() async {
    if (_busy || _confirming || !requireAuth(context, ref)) return;
    final l10n = context.l10n;
    final amount = _amount.text.trim();
    final value = double.tryParse(amount);
    if (value == null || value < widget.lot.minNextBid.displayValue) {
      setState(() => _error = l10n.bidTooLow(widget.lot.minNextBid.formatted));
      Haptics.warning();
      return;
    }
    _confirming = true;
    final confirmed = await confirmAction(
      context,
      title: l10n.confirmBidTitle,
      message: l10n.confirmBidMessage('${widget.lot.minNextBid.currency} $amount'),
      confirmLabel: l10n.placeBid,
    );
    _confirming = false;
    if (!confirmed || !mounted) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref.read(lotControllerProvider(widget.lotId).notifier).placeBid(amount);
      Haptics.success();
      _edited = false;
      if (mounted) showInfoSnack(context, l10n.bidPlaced);
    } on ApiException catch (e) {
      Haptics.warning();
      if (mounted) setState(() => _error = e.fieldError('amount') ?? describeError(l10n, e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final min = widget.lot.minNextBid.displayValue;
    final inc = widget.lot.minIncrement?.displayValue ?? 0;
    final quick = inc > 0 ? [min, min + inc, min + inc * 2] : [min];
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Wrap(
          spacing: AppSpace.xs,
          children: [
            for (final q in quick)
              ChoiceChip(
                label: Text(_plain(q.toString()), textDirection: TextDirection.ltr),
                selected: _amount.text == _plain(q.toString()),
                onSelected: (_) {
                  Haptics.tap();
                  setState(() {
                    _amount.text = _plain(q.toString());
                    _edited = q != min;
                    _error = null;
                  });
                },
              ),
          ],
        ),
        const Gap(AppSpace.sm),
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: AppTextField(
                controller: _amount,
                label: l10n.bidAmount,
                forceLtr: true,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                inputFormatters: const [AsciiDigitsFormatter(), _AmountFormatter()],
                textInputAction: TextInputAction.done,
                errorText: _error,
                onSubmitted: (_) => _place(),
                onChanged: (_) => _edited = true,
              ),
            ),
            const Gap(AppSpace.sm),
            SizedBox(
              height: 56,
              child: AppButton.accent(
                label: l10n.placeBid,
                icon: Icons.gavel_rounded,
                expand: false,
                loading: _busy,
                onPressed: _place,
              ),
            ),
          ],
        ),
      ],
    );
  }
}

class _AutoBidRow extends ConsumerWidget {
  const _AutoBidRow({required this.lotId, required this.lot});
  final String lotId;
  final Lot lot;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final active = lot.myMaxAutoBid != null;
    return SurfaceCard(
      child: Row(
        children: [
          Icon(Icons.auto_mode_rounded, color: context.palette.brass),
          const Gap(AppSpace.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(l10n.autoBid, style: context.text.titleSmall),
                Text(
                  active ? l10n.autoBidActive(lot.myMaxAutoBid!.formatted) : l10n.autoBidHint,
                  style: context.text.bodySmall,
                ),
              ],
            ),
          ),
          TextButton(
            onPressed: () async {
              if (!requireAuth(context, ref)) return;
              final controller = ref.read(lotControllerProvider(lotId).notifier);
              try {
                if (active) {
                  await controller.cancelAutoBid();
                } else {
                  final max = await _askMax(context, lot);
                  if (max == null) return;
                  await controller.setAutoBid(max);
                  Haptics.success();
                }
              } catch (e) {
                if (context.mounted) showErrorSnack(context, e);
              }
            },
            child: Text(active ? l10n.cancelAutoBid : l10n.setAutoBid),
          ),
        ],
      ),
    );
  }

  Future<String?> _askMax(BuildContext context, Lot lot) {
    final l10n = context.l10n;
    return showTextSheet(
      context,
      title: l10n.setAutoBid,
      intro: l10n.autoBidHint,
      label: l10n.autoBidMax,
      helper: l10n.bidTooLow(lot.minNextBid.formatted),
      actionLabel: l10n.setAutoBid,
      maxLength: 12,
      maxLines: 1,
      forceLtr: true,
      keyboardType: const TextInputType.numberWithOptions(decimal: true),
      inputFormatters: const [AsciiDigitsFormatter(), _AmountFormatter()],
      validate: (text) {
        final v = double.tryParse(text);
        if (v == null) return l10n.enterValidAmount;
        return v < lot.minNextBid.displayValue ? l10n.bidTooLow(lot.minNextBid.formatted) : null;
      },
    );
  }
}

class _BidRow extends StatelessWidget {
  const _BidRow({required this.bid});
  final BidEntry bid;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    return TweenAnimationBuilder<double>(
      key: ValueKey('${bid.bidNumber}-${bid.amount.amount}'),
      tween: Tween(begin: 0, end: 1),
      duration: AppDurations.medium,
      builder: (_, v, child) => Opacity(
        opacity: v,
        child: Transform.translate(offset: Offset(0, (1 - v) * -8), child: child),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: AppSpace.xs),
        child: Row(
          children: [
            CircleAvatar(
              radius: 16,
              backgroundColor: bid.isMine ? context.palette.brassTint : context.palette.surfaceMuted,
              child: Icon(
                bid.isMine ? Icons.person_rounded : Icons.person_outline_rounded,
                size: 18,
                color: context.palette.brass,
              ),
            ),
            const Gap(AppSpace.sm),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    bid.isMine ? l10n.you : bid.bidder,
                    style: context.text.bodyMedium?.copyWith(fontWeight: bid.isMine ? FontWeight.w700 : null),
                  ),
                  Text(QatarTime.dateTime(bid.placedAt, context.localeCode), style: context.text.bodySmall),
                ],
              ),
            ),
            MoneyText(bid.amount, emphasize: true),
          ],
        ),
      ),
    );
  }
}

/// Digits with at most one decimal point and two decimals (money amounts).
/// Pasted values with more decimals are cut instead of rejected.
class _AmountFormatter extends TextInputFormatter {
  const _AmountFormatter();

  static final _valid = RegExp(r'^\d*\.?\d*$');

  @override
  TextEditingValue formatEditUpdate(TextEditingValue oldValue, TextEditingValue newValue) {
    if (!_valid.hasMatch(newValue.text)) return oldValue;
    final dot = newValue.text.indexOf('.');
    if (dot < 0 || newValue.text.length - dot - 1 <= 2) return newValue;
    final cut = newValue.text.substring(0, dot + 3);
    return TextEditingValue(
      text: cut,
      selection: TextSelection.collapsed(offset: cut.length),
    );
  }
}
