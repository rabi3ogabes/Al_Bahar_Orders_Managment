import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/dirty_form_guard.dart';
import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/ops_widgets.dart';
import 'package:albahar_customer/ops/staff/adjust_draft.dart';
import 'package:albahar_customer/ops/staff/adjust_parts.dart';
import 'package:albahar_customer/ops/staff/staff_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Opens "Missing an item?" for one order of the queue in a window of its own: the employee picks what is short, says why, checks
/// what the customer will see, and one tap updates the order and tells the customer. Resolves to true once the order was changed.
///
/// It is a form: a tap outside or a swipe never closes it, and leaving it with changes in it asks "Discard changes?".
Future<bool?> showAdjustFlow(BuildContext context, {required QueueOrder order, required AdjustContext data}) {
  final t = OpsStrings.of(context);
  return showSidePanel<bool>(
    context: context,
    title: t.missingItem,
    subtitle: '#${order.number} · ${order.customerName}',
    scrollable: false,
    isForm: true,
    barrierDismissible: false,
    builder: (_) => AdjustFlow(order: order, data: data),
  );
}

/// What the server refused, by its code. The contract names the codes of a refused correction (`adjustment_not_allowed`, `no_change`,
/// `quantity_exceeds`, `substitute_unavailable` ...); they are read from `code` first, and from the field errors and the context as
/// well in case the server wraps them as a validation error.
String? adjustErrorCode(Object error) {
  if (error is! ApiException) return null;
  const known = {
    'adjustment_not_allowed',
    'no_change',
    'quantity_exceeds',
    'duplicate_item',
    'substitute_unavailable',
    'item_not_in_fulfillment',
  };
  if (error.code != null && known.contains(error.code)) return error.code;
  final fromContext = error.context['code']?.toString();
  if (fromContext != null && known.contains(fromContext)) return fromContext;
  for (final entry in error.errors.entries) {
    for (final v in [entry.key, ...entry.value]) {
      for (final code in known) {
        if (v == code || v.endsWith('.$code')) return code;
      }
    }
  }
  return null;
}

/// The positions in `changes` the server's field errors point at (`changes.2.variant_id` -> 2), or null when it names none.
Set<int>? adjustErrorPositions(Object error) {
  if (error is! ApiException) return null;
  final found = <int>{};
  for (final key in error.errors.keys) {
    final m = RegExp(r'^changes\.(\d+)').firstMatch(key);
    if (m != null) found.add(int.parse(m.group(1)!));
  }
  return found.isEmpty ? null : found;
}

enum _Stage { items, replace, reason, review, done, blocked }

class AdjustFlow extends ConsumerStatefulWidget {
  const AdjustFlow({super.key, required this.order, required this.data});
  final QueueOrder order;
  final AdjustContext data;

  @override
  ConsumerState<AdjustFlow> createState() => _AdjustFlowState();
}

class _AdjustFlowState extends ConsumerState<AdjustFlow> {
  final _note = TextEditingController();
  final _search = TextEditingController();
  late AdjustContext _ctx = widget.data;
  late AdjustDraft _draft = AdjustDraft.of(widget.data.items);
  _Stage _stage = _Stage.items;
  String? _open;
  String? _reason;

  /// The line a replacement is being chosen for, and what has been picked so far.
  AdjustItem? _replacing;
  Substitute? _pick;
  int _pickQty = 1;
  List<Substitute>? _results;
  Object? _searchError;
  bool _searching = false;
  int _searchSeq = 0;
  Timer? _debounce;

  AdjustResult? _preview;
  AdjustResult? _result;
  bool _busy = false;
  String? _error;

  String get _fulfillmentId => widget.order.fulfillmentId;
  OpsRepository get _repo => ref.read(opsRepositoryProvider);

  @override
  void dispose() {
    _debounce?.cancel();
    _note.dispose();
    _search.dispose();
    super.dispose();
  }

  bool get _dirty => !_draft.isEmpty || _note.text.trim().isNotEmpty || _reason != null;

  void _go(_Stage s) => setState(() {
    _stage = s;
    _error = null;
  });

  /// Back (the arrow, the system back button, Esc): one step back; from the first step leave, asking first when work would be lost.
  Future<void> _back() async {
    if (_busy) return;
    switch (_stage) {
      case _Stage.replace || _Stage.reason:
        _go(_Stage.items);
      case _Stage.review:
        _go(_Stage.reason);
      case _Stage.items:
        if (!_dirty || await confirmDiscard(context)) {
          if (mounted) Navigator.of(context).pop(false);
        }
      case _Stage.done:
        Navigator.of(context).pop(true);
      case _Stage.blocked:
        Navigator.of(context).pop(false);
    }
  }

  // ── Step 1: the lines ──────────────────────────────────────────────────────────────────────────────────────────────────────

  void _toggle(AdjustItem item) => setState(() => _open = _open == item.orderItemId ? null : item.orderItemId);

  void _reduce(AdjustItem item, int to) {
    Haptics.tap();
    setState(() => _draft = _draft.reduce(item, to));
  }

  void _remove(AdjustItem item) {
    Haptics.tap();
    setState(() {
      _draft = _draft.remove(item);
      _open = null;
    });
  }

  void _undo(AdjustItem item) {
    Haptics.tap();
    setState(() => _draft = _draft.undo(item.orderItemId));
  }

  void _toReplace(AdjustItem item) {
    final current = _draft.of(item.orderItemId);
    setState(() {
      _replacing = item;
      _pick = current is AdjustReplace ? current.substitute : null;
      _pickQty = current is AdjustReplace ? current.quantity : item.quantity;
      _results = null;
      _searchError = null;
      _search.clear();
      _stage = _Stage.replace;
      _error = null;
    });
    unawaited(_runSearch(''));
  }

  // ── Step 1b: choose the replacement ────────────────────────────────────────────────────────────────────────────────────────

  Future<void> _runSearch(String text) async {
    final seq = ++_searchSeq;
    setState(() {
      _searching = true;
      _searchError = null;
    });
    try {
      final list = await _repo.substitutes(_fulfillmentId, search: text);
      if (!mounted || seq != _searchSeq) return;
      setState(() {
        _results = list;
        _searching = false;
      });
    } catch (e) {
      if (!mounted || seq != _searchSeq) return;
      setState(() {
        _searchError = e;
        _searching = false;
      });
    }
  }

  void _onSearchChanged(String text) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 350), () => unawaited(_runSearch(text.trim())));
  }

  int _mostOf(Substitute s) {
    final most = s.available < _replacing!.quantity ? s.available : _replacing!.quantity;
    return most < 1 ? 1 : most;
  }

  void _choose(Substitute s) {
    Haptics.tap();
    setState(() {
      _pick = s;
      _pickQty = _pickQty.clamp(1, _mostOf(s));
    });
  }

  void _useReplacement() {
    final item = _replacing;
    final pick = _pick;
    if (item == null || pick == null) return;
    setState(() {
      _draft = _draft.replace(item, pick, _pickQty);
      _open = null;
      _stage = _Stage.items;
    });
  }

  // ── Step 2: why ────────────────────────────────────────────────────────────────────────────────────────────────────────────

  void _toReason() {
    if (_draft.isEmpty) return;
    _go(_Stage.reason);
  }

  // ── Step 3: check what the customer will see ───────────────────────────────────────────────────────────────────────────────

  Future<void> _toReview() async {
    if (_busy) return;
    final t = OpsStrings.of(context);
    if (_reason == null) {
      setState(() => _error = t.chooseReasonFirst);
      return;
    }
    FocusScope.of(context).unfocus();
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final r = await _repo.adjust(
        _fulfillmentId,
        changes: _draft.changes,
        reasonCode: _reason!,
        note: _note.text,
        preview: true,
      );
      if (!mounted) return;
      setState(() {
        _preview = r;
        _busy = false;
        _stage = _Stage.review;
      });
    } catch (e) {
      await _failed(e);
    }
  }

  // ── Step 4: do it ──────────────────────────────────────────────────────────────────────────────────────────────────────────

  Future<void> _apply() async {
    if (_busy) return;
    final t = OpsStrings.of(context);
    final p = _preview;
    if (p != null && (p.orderCancelled || p.fulfillmentCancelled)) {
      final sure = await opsConfirm(
        context,
        title: t.confirmCancelOrderQ,
        body: t.confirmCancelOrderBody,
        verb: t.cancelAndNotify,
        destructive: true,
      );
      if (!sure || !mounted) return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final r = await _repo.adjust(_fulfillmentId, changes: _draft.changes, reasonCode: _reason!, note: _note.text);
      if (!mounted) return;
      if (!r.applied) throw const FormatException('The correction was not applied.');
      Haptics.success();
      _refreshBoard(cancelled: r.fulfillmentCancelled || r.orderCancelled);
      setState(() {
        _result = r;
        _busy = false;
        _stage = _Stage.done;
      });
    } catch (e) {
      await _failed(e);
    }
  }

  /// The queue, the counts and what the window knows about this parcel are read again; a parcel that was cancelled leaves the queue at once.
  void _refreshBoard({bool cancelled = false}) {
    final live = ref.read(staffLiveProvider.notifier);
    if (cancelled) live.applyOwn(_fulfillmentId, status: 'cancelled');
    ref.invalidate(staffOverviewProvider);
    for (final s in kStaffStages) {
      ref.invalidate(staffQueueProvider(s));
    }
    ref.invalidate(adjustContextProvider(_fulfillmentId));
  }

  /// A refused or failed request: everything the employee chose stays; the message says what to do next.
  Future<void> _failed(Object e) async {
    if (!mounted) return;
    final t = OpsStrings.of(context);
    final l10n = context.l10n;
    final code = adjustErrorCode(e);
    switch (code) {
      case 'adjustment_not_allowed':
        // The order moved on (a colleague marked it ready, it was cancelled): nothing can be sent any more.
        _refreshBoard();
        setState(() {
          _busy = false;
          _stage = _Stage.blocked;
        });
        return;
      case 'substitute_unavailable':
        // The server names the failing change by its position in what was sent; without it every replacement is taken back.
        final kept = _draft.withoutReplacements(positions: adjustErrorPositions(e));
        final fresh = await _freshContext();
        if (!mounted) return;
        setState(() {
          _busy = false;
          _draft = fresh == null ? kept : kept.rebase(fresh.items);
          _stage = _Stage.items;
          _error = t.adjustSubstituteGone;
        });
        return;
      case 'quantity_exceeds' || 'duplicate_item' || 'item_not_in_fulfillment':
        final fresh = await _freshContext();
        if (!mounted) return;
        setState(() {
          _busy = false;
          if (fresh != null) _draft = _draft.rebase(fresh.items);
          _stage = _Stage.items;
          _error = code == 'quantity_exceeds' ? t.adjustQuantityExceeds : t.adjustChanged;
        });
        return;
      case 'no_change':
        setState(() {
          _busy = false;
          _stage = _Stage.items;
          _error = t.adjustNoChange;
        });
        return;
    }
    setState(() {
      _busy = false;
      _error = describeError(l10n, e);
    });
  }

  /// The lines as the server has them now (after a refusal), so the list shows what is really in the parcel.
  Future<AdjustContext?> _freshContext() async {
    try {
      final fresh = await _repo.adjustContext(_fulfillmentId);
      if (mounted) _ctx = fresh;
      return fresh;
    } catch (_) {
      return null;
    }
  }

  // ── Building ───────────────────────────────────────────────────────────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final (String? title, Widget body) = switch (_stage) {
      _Stage.items => (null, _buildItems(context, t)),
      _Stage.replace => (t.replaceTitle, _buildReplace(context, t)),
      _Stage.reason => (t.whyMissing, _buildReason(context, t)),
      _Stage.review => (t.reviewTitle, _buildReview(context, t)),
      _Stage.done => (null, _buildDone(context, t)),
      _Stage.blocked => (null, _buildBlocked(context, t)),
    };
    return PopScope(
      // Done and blocked close normally; every other step handles Back itself (one step back, or "Discard changes?").
      canPop: _stage == _Stage.done || _stage == _Stage.blocked,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) unawaited(_back());
      },
      child: title == null ? body : PanelStep(title: title, onBack: () => unawaited(_back()), child: body),
    );
  }

  Widget _errorLine(BuildContext context) {
    if (_error == null) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpace.xs),
      child: Semantics(
        liveRegion: true,
        child: Text(_error!, style: TextStyle(color: context.palette.danger)),
      ),
    );
  }

  Widget _progress(BuildContext context) => SizedBox(
    width: 22,
    height: 22,
    child: CircularProgressIndicator(strokeWidth: 2.4, color: Theme.of(context).colorScheme.onPrimary),
  );

  Widget _buildItems(BuildContext context, OpsStrings t) {
    final items = _draft.items;
    return SidePanelLayout(
      footer: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_error != null) _errorLine(context),
          if (_draft.isEmpty && _error == null)
            Padding(
              padding: const EdgeInsets.only(bottom: AppSpace.xs),
              child: Text(
                t.nothingChosenYet,
                textAlign: TextAlign.center,
                style: context.text.bodySmall?.copyWith(color: context.palette.muted),
              ),
            ),
          FilledButton(
            onPressed: _draft.isEmpty ? null : _toReason,
            child: Text(_draft.isEmpty ? t.reviewChanges : t.continueWithChanges(_draft.count)),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.only(bottom: AppSpace.sm),
            child: Text(t.missingIntro, style: context.text.bodyMedium?.copyWith(color: context.palette.muted)),
          ),
          for (final item in items)
            AdjustItemTile(
              key: ValueKey('adjust-item-${item.orderItemId}'),
              item: item,
              change: _draft.of(item.orderItemId),
              expanded: _open == item.orderItemId,
              onToggle: () => _toggle(item),
              onReduce: (q) => _reduce(item, q),
              onReplace: () => _toReplace(item),
              onRemove: () => _remove(item),
              onUndo: () => _undo(item),
            ),
        ],
      ),
    );
  }

  Widget _buildReplace(BuildContext context, OpsStrings t) {
    final item = _replacing!;
    final p = context.palette;
    final results = _results ?? const <Substitute>[];
    // The one already chosen stays on top even when a search does not list it.
    final shown = [?_pick, ...results.where((s) => s.variantId != _pick?.variantId)];
    return SidePanelLayout(
      footer: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [FilledButton(onPressed: _pick == null ? null : _useReplacement, child: Text(t.useThisItem))],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.only(bottom: AppSpace.sm),
            child: Text(
              t.replacing('${item.name}${item.variantName == null ? '' : ' (${item.variantName})'} × ${item.quantity}'),
              style: context.text.titleSmall,
            ),
          ),
          TextField(
            controller: _search,
            textInputAction: TextInputAction.search,
            onChanged: _onSearchChanged,
            onSubmitted: (v) {
              _debounce?.cancel();
              unawaited(_runSearch(v.trim()));
            },
            decoration: InputDecoration(
              hintText: t.searchSubstitutes,
              prefixIcon: const Icon(Icons.search_rounded),
              suffixIcon: ValueListenableBuilder<TextEditingValue>(
                valueListenable: _search,
                builder: (context, v, _) => v.text.isEmpty
                    ? const SizedBox.shrink()
                    : IconButton(
                        tooltip: context.l10n.close,
                        icon: const Icon(Icons.close_rounded),
                        onPressed: () {
                          _search.clear();
                          _debounce?.cancel();
                          unawaited(_runSearch(''));
                        },
                      ),
              ),
            ),
          ),
          const Gap(AppSpace.sm),
          if (_searching && _results == null)
            const Padding(
              padding: EdgeInsets.all(AppSpace.xl),
              child: Center(child: CircularProgressIndicator()),
            )
          else if (_searchError != null && shown.isEmpty)
            Padding(
              padding: const EdgeInsets.all(AppSpace.md),
              child: Column(
                children: [
                  Text(describeError(context.l10n, _searchError), textAlign: TextAlign.center),
                  TextButton(onPressed: () => unawaited(_runSearch(_search.text.trim())), child: Text(t.retry)),
                ],
              ),
            )
          else if (shown.isEmpty)
            Padding(
              padding: const EdgeInsets.all(AppSpace.lg),
              child: Text(
                t.noSubstitutes,
                textAlign: TextAlign.center,
                style: context.text.bodyMedium?.copyWith(color: p.muted),
              ),
            )
          else
            Opacity(
              opacity: _searching ? 0.55 : 1,
              child: Column(
                children: [
                  for (final s in shown)
                    SubstituteTile(
                      key: ValueKey('substitute-${s.variantId}'),
                      sub: s,
                      selected: _pick?.variantId == s.variantId,
                      original: item,
                      quantity: _pickQty,
                      maxQuantity: _mostOf(s),
                      onQuantity: (q) => setState(() => _pickQty = q),
                      onTap: () => _choose(s),
                    ),
                ],
              ),
            ),
        ],
      ),
    );
  }

  Widget _buildReason(BuildContext context, OpsStrings t) {
    return SidePanelLayout(
      footer: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _errorLine(context),
          FilledButton(onPressed: _busy ? null : _toReview, child: _busy ? _progress(context) : Text(t.reviewChanges)),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Wrap(
            spacing: AppSpace.xs,
            runSpacing: AppSpace.xs,
            children: [
              for (final code in _ctx.reasons)
                _ReasonChip(
                  label: t.adjustReason(code),
                  selected: _reason == code,
                  onTap: _busy
                      ? null
                      : () {
                          Haptics.tap();
                          setState(() {
                            _reason = code;
                            _error = null;
                          });
                        },
                ),
            ],
          ),
          const Gap(AppSpace.md),
          TextField(
            controller: _note,
            enabled: !_busy,
            maxLength: 300,
            minLines: 2,
            maxLines: 4,
            keyboardType: TextInputType.multiline,
            textCapitalization: TextCapitalization.sentences,
            inputFormatters: [LengthLimitingTextInputFormatter(300)],
            onChanged: (_) => setState(() {}),
            decoration: InputDecoration(labelText: t.adjustNoteLabel, helperText: t.adjustNoteHint, helperMaxLines: 2),
          ),
        ],
      ),
    );
  }

  Widget _buildReview(BuildContext context, OpsStrings t) {
    final p = context.palette;
    final r = _preview!;
    final cancels = r.orderCancelled || r.fulfillmentCancelled;
    final less = !r.difference.isZero;
    final refund = r.refund;
    return SidePanelLayout(
      footer: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _errorLine(context),
          FilledButton(
            style: cancels ? FilledButton.styleFrom(backgroundColor: p.danger, foregroundColor: Colors.white) : null,
            onPressed: _busy ? null : _apply,
            child: _busy
                ? _progress(context)
                : Text(cancels ? t.cancelAndNotify : t.updateAndNotify, textAlign: TextAlign.center),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (cancels)
            AdjustNotice(
              icon: Icons.warning_amber_rounded,
              tone: StatusTone.danger,
              text: r.orderCancelled ? t.cancelsOrder : t.cancelsParcel,
            ),
          PanelSection(
            title: t.itemsLabel,
            children: [
              for (var i = 0; i < r.lines.length; i++) ...[
                if (i > 0) const Divider(height: AppSpace.md * 2),
                AdjustReviewLine(line: r.lines[i]),
              ],
            ],
          ),
          PanelSection(
            title: t.orderTotal,
            children: [
              Wrap(
                spacing: AppSpace.xs,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  Directionality(
                    textDirection: TextDirection.ltr,
                    child: Text(
                      t.money(r.before.grandTotal.amount),
                      style: context.text.bodyLarge?.copyWith(
                        color: p.muted,
                        decoration: less ? TextDecoration.lineThrough : null,
                      ),
                    ),
                  ),
                  if (less) ...[
                    Icon(Icons.arrow_forward_rounded, size: 18, color: p.muted),
                    Directionality(
                      textDirection: TextDirection.ltr,
                      child: Text(
                        t.money(r.after.grandTotal.amount),
                        style: context.text.titleLarge?.copyWith(fontWeight: FontWeight.w700),
                      ),
                    ),
                  ],
                ],
              ),
              const Gap(AppSpace.xxs),
              Text(
                less ? t.customerPaysLess(r.difference.unsigned) : t.totalUnchanged,
                style: context.text.bodyMedium?.copyWith(
                  color: less ? p.success : p.muted,
                  fontWeight: less ? FontWeight.w700 : FontWeight.w500,
                ),
              ),
            ],
          ),
          if (refund != null && !refund.isZero)
            AdjustNotice(
              icon: Icons.account_balance_wallet_outlined,
              text: r.refundNeedsApproval ? t.refundNeedsApproval(refund.amount) : t.refundIssued(refund.amount),
            )
          else if (_ctx.isCash && less)
            AdjustNotice(icon: Icons.payments_outlined, text: t.codDrops(r.difference.unsigned)),
          AdjustNotice(icon: Icons.notifications_active_outlined, text: t.customerWillBeTold),
        ],
      ),
    );
  }

  Widget _buildDone(BuildContext context, OpsStrings t) {
    final p = context.palette;
    final r = _result!;
    return SidePanelLayout(
      footer: FilledButton(onPressed: () => Navigator.of(context).pop(true), child: Text(t.done)),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: AppSpace.lg),
        child: Column(
          children: [
            Container(
              width: 88,
              height: 88,
              decoration: BoxDecoration(shape: BoxShape.circle, color: p.success.withValues(alpha: 0.14)),
              child: Icon(
                r.customerNotified ? Icons.notifications_active_rounded : Icons.check_rounded,
                size: 44,
                color: p.success,
              ),
            ),
            const Gap(AppSpace.md),
            Semantics(
              liveRegion: true,
              header: true,
              child: Text(
                r.customerNotified ? t.customerNotified : t.adjustDone(widget.order.number),
                textAlign: TextAlign.center,
                style: context.text.headlineSmall,
              ),
            ),
            const Gap(AppSpace.xs),
            if (r.customerNotified)
              Text(t.adjustDone(widget.order.number), textAlign: TextAlign.center, style: context.text.bodyLarge),
            if (r.orderCancelled)
              Text(t.orderWasCancelled, textAlign: TextAlign.center, style: context.text.bodyLarge)
            else
              Directionality(
                textDirection: TextDirection.ltr,
                child: Text(
                  t.newTotalIs(r.after.grandTotal.amount),
                  textAlign: TextAlign.center,
                  style: context.text.titleMedium?.copyWith(fontWeight: FontWeight.w700),
                ),
              ),
            if (!r.customerNotified) ...[
              const Gap(AppSpace.md),
              AdjustNotice(icon: Icons.phone_in_talk_outlined, tone: StatusTone.warning, text: t.notNotified),
            ],
          ],
        ),
      ),
    );
  }

  Widget _buildBlocked(BuildContext context, OpsStrings t) {
    final p = context.palette;
    return SidePanelLayout(
      footer: FilledButton(onPressed: () => Navigator.of(context).pop(false), child: Text(t.adjustBackToOrder)),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: AppSpace.lg),
        child: Column(
          children: [
            Container(
              width: 88,
              height: 88,
              decoration: BoxDecoration(shape: BoxShape.circle, color: p.warning.withValues(alpha: 0.14)),
              child: Icon(Icons.lock_outline_rounded, size: 40, color: p.warning),
            ),
            const Gap(AppSpace.md),
            Semantics(
              liveRegion: true,
              child: Text(t.adjustNotAllowed, textAlign: TextAlign.center, style: context.text.titleMedium),
            ),
          ],
        ),
      ),
    );
  }
}

/// One reason to pick: a pill at least 48 high with a tick when chosen.
class _ReasonChip extends StatelessWidget {
  const _ReasonChip({required this.label, required this.selected, required this.onTap});
  final String label;
  final bool selected;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final scheme = context.colors;
    final p = context.palette;
    return Semantics(
      button: true,
      selected: selected,
      label: label,
      excludeSemantics: true,
      onTap: onTap,
      child: Material(
        color: selected ? scheme.primary : scheme.surface,
        shape: StadiumBorder(side: BorderSide(color: selected ? scheme.primary : p.line, width: 1.2)),
        child: InkWell(
          customBorder: const StadiumBorder(),
          onTap: onTap,
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: kMinTouchTarget),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: AppSpace.md),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  if (selected) ...[
                    Icon(Icons.check_rounded, size: 18, color: scheme.onPrimary),
                    const Gap(AppSpace.xxs),
                  ],
                  Text(
                    label,
                    style: context.text.titleSmall?.copyWith(color: selected ? scheme.onPrimary : scheme.onSurface),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
