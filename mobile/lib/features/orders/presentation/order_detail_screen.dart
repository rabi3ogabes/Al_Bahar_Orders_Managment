import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/network/interceptors.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/app_text_field.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/dirty_form_guard.dart';
import 'package:albahar_customer/core/widgets/fresh.dart';
import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/cart/presentation/cart_panel.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:albahar_customer/features/checkout/data/checkout_repository.dart';
import 'package:albahar_customer/features/checkout/presentation/payment_flow.dart';
import 'package:albahar_customer/features/orders/data/invoice_delivery.dart';
import 'package:albahar_customer/features/orders/data/order_repository.dart';
import 'package:albahar_customer/features/orders/domain/order_models.dart';
import 'package:albahar_customer/features/orders/presentation/order_sections.dart';
import 'package:albahar_customer/features/orders/presentation/return_errors.dart';
import 'package:albahar_customer/features/orders/presentation/state/order_live.dart';
import 'package:albahar_customer/features/orders/presentation/state/order_providers.dart';
import 'package:albahar_customer/features/orders/presentation/widgets/order_live_badge.dart';
import 'package:albahar_customer/features/reviews/presentation/write_review_screen.dart';
import 'package:albahar_customer/features/support/presentation/support_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// Opens an order in a side window: status, live timeline, items, totals and the next action pinned at the bottom.
/// The full page (`/orders/:id`) stays for links, notifications and "Open full page".
Future<void> showOrderPanel(BuildContext context, String orderId, {String? number}) {
  final l10n = context.l10n;
  final host = context;
  return showSidePanel<void>(
    context: context,
    title: number != null ? l10n.orderNumber(number) : l10n.ordersTitle,
    scrollable: false,
    actions: [
      const OrderLiveBadge(showLabel: false),
      Builder(
        builder: (panelContext) => IconButton(
          tooltip: l10n.openFullPage,
          constraints: const BoxConstraints(minWidth: kMinTouchTarget, minHeight: kMinTouchTarget),
          icon: const Icon(Icons.open_in_full_rounded),
          onPressed: () {
            final router = GoRouter.of(panelContext);
            Navigator.of(panelContext).pop();
            unawaited(router.push('/orders/$orderId'));
          },
        ),
      ),
    ],
    builder: (_) => OrderPanelBody(orderId: orderId, host: host),
  );
}

class OrderDetailScreen extends ConsumerStatefulWidget {
  const OrderDetailScreen({super.key, required this.orderId, this.justPlaced = false});
  final String orderId;
  final bool justPlaced;

  @override
  ConsumerState<OrderDetailScreen> createState() => _OrderDetailScreenState();
}

class _OrderDetailScreenState extends ConsumerState<OrderDetailScreen> with OrderActionsMixin<OrderDetailScreen> {
  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final orderId = widget.orderId;
    final justPlaced = widget.justPlaced;
    final order = ref.watch(orderDetailViewProvider(orderId));
    final title = order.value != null ? l10n.orderNumber(order.value!.number) : l10n.ordersTitle;
    return FreshScaffold(
      header: FreshHeader(
        title: title,
        tagline: l10n.freshTaglineOrder,
        trailing: [
          if (justPlaced)
            FreshHeaderAction(
              icon: const Icon(Icons.close_rounded),
              tooltip: l10n.close,
              onPressed: () => context.go('/home'),
            ),
        ],
      ),
      appBar: AppBar(
        title: Text(title),
        leading: justPlaced
            ? IconButton(
                tooltip: l10n.close,
                icon: const Icon(Icons.close_rounded),
                onPressed: () => context.go('/home'),
              )
            : null,
      ),
      body: order.when(
        skipLoadingOnRefresh: true,
        loading: () => const ListSkeleton(count: 4, leading: false),
        error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(orderDetailProvider(orderId))),
        data: (o) => RefreshIndicator(
          onRefresh: () async {
            ref.invalidate(orderTimelineProvider(orderId));
            ref.invalidate(orderDetailProvider(orderId));
            await ref.read(orderDetailProvider(orderId).future);
          },
          child: ListView(
            padding: const EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.sm, AppSpace.gutter, AppSpace.xxl),
            children: [
              ContentWidth(
                child: OrderSections(order: o, justPlaced: justPlaced, actions: buildActions(o)),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Runs a step inside the order window (swapping its content), or in a window of its own on the full page.
abstract interface class OrderStepRunner {
  Future<T?> runStep<T>(String title, Widget Function(void Function(T?) done) build);
}

/// Body of [showOrderPanel]: the order with its realtime updates, the actions, and the cancel / return steps.
class OrderPanelBody extends ConsumerStatefulWidget {
  const OrderPanelBody({super.key, required this.orderId, required this.host});
  final String orderId;

  /// The page that opened the window: follow-up windows (cart, review, support) open on it after this one closes.
  final BuildContext host;

  @override
  ConsumerState<OrderPanelBody> createState() => _OrderPanelBodyState();
}

class _OrderPanelBodyState extends ConsumerState<OrderPanelBody>
    with OrderActionsMixin<OrderPanelBody>
    implements OrderStepRunner {
  Widget? _step;
  Completer<Object?>? _stepDone;

  @override
  bool get inPanel => true;

  @override
  BuildContext get host => widget.host;

  @override
  Future<T?> runStep<T>(String title, Widget Function(void Function(T?) done) build) {
    final completer = Completer<Object?>();
    _stepDone = completer;
    void done(T? value) {
      if (!completer.isCompleted) completer.complete(value);
      if (mounted) setState(() => _step = null);
    }

    setState(() => _step = PanelStep(title: title, onBack: () => done(null), child: build(done)));
    return completer.future.then((v) => v as T?);
  }

  @override
  void dispose() {
    final pending = _stepDone;
    if (pending != null && !pending.isCompleted) pending.complete(null);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final id = widget.orderId;
    // The server's messages change the window at once (orderDetailViewProvider); the timeline is read again when this order's message
    // arrives (the orchestrator also reads everything after a burst, a reconnect and a return to the app).
    ref.listen(orderLiveProvider.select((s) => s.patches[id]?.seq), (prev, next) {
      if (next != null && next != prev) ref.invalidate(orderTimelineProvider(id));
    });
    final async = ref.watch(orderDetailViewProvider(id));
    return async.when(
      skipLoadingOnRefresh: true,
      skipLoadingOnReload: true,
      loading: () => const SizedBox(height: 320, child: ListSkeleton(count: 3, leading: false)),
      error: (e, _) => ErrorState(error: e, compact: true, onRetry: () => ref.invalidate(orderDetailProvider(id))),
      data: (o) {
        if (_step != null) return _step!;
        final primary = primaryFor(o);
        return SidePanelLayout(
          footer: buildPrimary(o, primary),
          child: OrderSections(
            order: o,
            actions: buildActions(o, hide: primary),
          ),
        );
      },
    );
  }
}

enum OrderPrimary { pay, track, pickup, reorder }

/// The one action an order window pins at the bottom: what the customer most likely wants next.
OrderPrimary primaryFor(Order o) {
  if (o.canPay) return OrderPrimary.pay;
  if (o.status == 'out_for_delivery') return OrderPrimary.track;
  if (o.fulfillments.any((f) => f.isPickupReady)) return OrderPrimary.pickup;
  return OrderPrimary.reorder;
}

/// The order actions, shared by the full page and the side window. The page is a screen state, the window is a panel
/// state; both only differ in where follow-up windows open ([then]) and how a form step is shown ([askStep]).
mixin OrderActionsMixin<T extends ConsumerStatefulWidget> on ConsumerState<T> {
  String? _busy;
  String? _payKey;

  /// True inside the order window.
  bool get inPanel => false;

  /// The page behind the window (the window's opener); on the full page, the page itself.
  BuildContext get host => context;

  /// Runs one action with its button showing progress. A second tap on the same button while it runs does nothing, so a double
  /// tap never starts the action twice. [messageFor] words a failure itself; without it the standard error message shows.
  Future<void> _run(String key, Future<void> Function() action, {String Function(Object error)? messageFor}) async {
    if (_busy == key) return;
    setState(() => _busy = key);
    try {
      await action();
    } catch (e) {
      if (mounted) {
        if (messageFor == null) {
          showErrorSnack(context, e);
        } else {
          showInfoSnack(context, messageFor(e));
        }
      }
    } finally {
      if (mounted) setState(() => _busy = null);
    }
  }

  void _refresh(String orderId) {
    ref.invalidate(orderDetailProvider(orderId));
    ref.invalidate(orderTimelineProvider(orderId));
  }

  /// Opens [open] on the page behind: closes the order window first when inside one.
  void _then(void Function(BuildContext page) open) {
    if (!inPanel) {
      open(context);
      return;
    }
    final page = host;
    Navigator.of(context).pop();
    if (page.mounted) open(page);
  }

  Future<R?> _askStep<R>(String title, Widget Function(void Function(R?) done) build) {
    if (this is OrderStepRunner) return (this as OrderStepRunner).runStep<R>(title, build);
    return showSidePanel<R>(
      context: context,
      title: title,
      scrollable: false,
      isForm: true,
      builder: (ctx) => build((v) => Navigator.of(ctx).pop(v)),
    );
  }

  Future<void> _cancel(Order o) async {
    final l10n = context.l10n;
    final result = await _askStep<({String code, String? reason})>(
      l10n.cancelOrderTitle,
      (done) => _CancelForm(onDone: done),
    );
    if (result == null) return;
    await _run('cancel', () async {
      final status = await ref
          .read(orderRepositoryProvider)
          .cancel(o.id, reasonCode: result.code, reason: result.reason);
      if (!mounted) return;
      Haptics.success();
      showInfoSnack(context, status == 'approved' ? l10n.cancelApproved : l10n.cancelRequested);
      _refresh(o.id);
    });
  }

  Future<void> _reorder(Order o) => _run('reorder', () async {
    final l10n = context.l10n;
    final result = await ref.read(orderRepositoryProvider).reorder(o.id);
    ref.read(cartControllerProvider.notifier).replace(result.cart);
    if (!mounted) return;
    Haptics.success();
    final notice = result.skipped.isEmpty
        ? l10n.reorderDone
        : '${l10n.reorderDone} ${l10n.reorderSkipped(result.skipped.length)}';
    // The cart opens beside the order: check it, then check out.
    _then((page) => unawaited(showCartPanel(page, notice: notice)));
  });

  /// Downloads the invoice as a PDF: the browser saves it on the web, and a phone opens its own sheet (Files, a PDF viewer, print,
  /// WhatsApp).
  Future<void> _invoice(Order o) => _run('invoice', () async {
    final l10n = context.l10n;
    final bytes = await ref.read(orderRepositoryProvider).invoice(o.id);
    final delivered = await ref.read(invoiceDeliveryProvider)(bytes, invoiceFileName(o.number));
    if (!mounted) return;
    Haptics.success();
    if (delivered == InvoiceDelivered.downloaded) showInfoSnack(context, l10n.invoiceSaved);
  }, messageFor: (e) => _invoiceMessage(context.l10n, e));

  /// Plain words for a failed invoice: not paid yet, offline, or "try again".
  static String _invoiceMessage(AppLocalizations l10n, Object error) {
    if (error is ApiException) {
      switch (error.kind) {
        case ApiErrorKind.conflict:
          return l10n.invoiceNotReady;
        case ApiErrorKind.offline:
        case ApiErrorKind.timeout:
        case ApiErrorKind.rateLimited:
        case ApiErrorKind.unauthorized:
          return describeError(l10n, error);
        case ApiErrorKind.forbidden:
        case ApiErrorKind.notFound:
        case ApiErrorKind.validation:
        case ApiErrorKind.server:
        case ApiErrorKind.cancelled:
        case ApiErrorKind.unknown:
          return l10n.invoiceFailed;
      }
    }
    return l10n.invoiceFailed;
  }

  Future<void> _pay(Order o) => _run('pay', () async {
    _payKey ??= Idempotency.newKey();
    final action = await ref.read(checkoutRepositoryProvider).retryPayment(o.id, idempotencyKey: _payKey!);
    _payKey = null;
    if (action.requiresRedirect) await openSecurePayment(action);
    if (!mounted) return;
    _then(
      (page) =>
          unawaited(GoRouter.of(page).push('/payments/${action.paymentId}/return?order_id=${o.id}&number=${o.number}')),
    );
  });

  Future<void> _return(Order o) async {
    final l10n = context.l10n;
    final result = await _askStep<({List<({String orderItemId, int quantity})> items, String code, String? note})>(
      l10n.requestReturn,
      (done) => _ReturnForm(order: o, onDone: done),
    );
    if (result == null || result.items.isEmpty) return;
    await _run('return', () async {
      try {
        await ref
            .read(orderRepositoryProvider)
            .requestReturn(o.id, items: result.items, reasonCode: result.code, reason: result.note);
      } on ApiException catch (e) {
        // The shop turned returns off (or this order no longer qualifies) after this page was read: say so, and read the order
        // again so the button goes away. Any other refusal is explained in plain words (see returnFailureMessage).
        if (e.kind == ApiErrorKind.forbidden || e.kind == ApiErrorKind.validation) _refresh(o.id);
        rethrow;
      }
      if (!mounted) return;
      showInfoSnack(context, l10n.returnRequested);
      _refresh(o.id);
    }, messageFor: (e) => returnFailureMessage(l10n, e));
  }

  /// "Write a review": the one product it can be about, or a list to choose from when the order holds several.
  Future<void> _review(Order o) async {
    final l10n = context.l10n;
    final choices = o.reviewableItems;
    if (choices.isEmpty) return;
    var chosen = choices.first;
    if (choices.length > 1) {
      final picked = await _askStep<OrderItem>(l10n.writeReview, (done) => _ReviewPicker(items: choices, onDone: done));
      if (picked == null || !mounted) return;
      chosen = picked;
    }
    _then((page) => unawaited(showReviewPanel(page, productId: chosen.productId!, productName: chosen.name)));
  }

  /// The pinned primary action of the order window.
  Widget buildPrimary(Order o, OrderPrimary primary) {
    final l10n = context.l10n;
    switch (primary) {
      case OrderPrimary.pay:
        return AppButton.accent(
          label: l10n.payNow,
          icon: Icons.credit_card_rounded,
          loading: _busy == 'pay',
          onPressed: () => _pay(o),
        );
      case OrderPrimary.track:
        return AppButton(
          label: l10n.trackOrder,
          icon: Icons.location_on_rounded,
          onPressed: () => _then((page) => unawaited(GoRouter.of(page).push('/orders/${o.id}/track'))),
        );
      case OrderPrimary.pickup:
        final f = o.fulfillments.firstWhere((f) => f.isPickupReady);
        return AppButton.accent(
          label: l10n.pickupPass,
          icon: Icons.qr_code_2_rounded,
          onPressed: () => _then((page) => unawaited(GoRouter.of(page).push('/orders/${o.id}/pickup/${f.id}'))),
        );
      case OrderPrimary.reorder:
        return AppButton(
          label: l10n.reorder,
          icon: Icons.replay_rounded,
          loading: _busy == 'reorder',
          onPressed: () => _reorder(o),
        );
    }
  }

  /// Every other action, as buttons; [hide] leaves out the one the window pins as its primary action.
  Widget buildActions(Order o, {OrderPrimary? hide}) {
    final l10n = context.l10n;
    final actions = <Widget>[
      if (o.canPay && hide != OrderPrimary.pay)
        AppButton.accent(
          label: l10n.payNow,
          icon: Icons.credit_card_rounded,
          loading: _busy == 'pay',
          onPressed: () => _pay(o),
        ),
      if (hide != OrderPrimary.reorder)
        AppButton.secondary(
          label: l10n.reorder,
          icon: Icons.replay_rounded,
          loading: _busy == 'reorder',
          onPressed: () => _reorder(o),
        ),
      if (o.hasInvoice)
        AppButton.secondary(
          // The server serves the newest revision: an order the branch changed says "updated".
          label: o.wasAdjusted ? l10n.downloadUpdatedInvoice : l10n.downloadInvoice,
          // The longer "updated" label wraps on a small phone instead of being cut.
          labelMaxLines: 2,
          icon: Icons.picture_as_pdf_outlined,
          loading: _busy == 'invoice',
          onPressed: () => _invoice(o),
        ),
      // The server decides whether this order can be returned (the shop can turn returns off): the button follows it.
      if (o.canReturn)
        AppButton.secondary(
          label: l10n.requestReturn,
          icon: Icons.assignment_return_outlined,
          loading: _busy == 'return',
          onPressed: () => _return(o),
        ),
      if (o.canReview && o.reviewableItems.isNotEmpty)
        AppButton.secondary(label: l10n.writeReview, icon: Icons.rate_review_outlined, onPressed: () => _review(o)),
      AppButton.ghost(
        label: l10n.contactSupport,
        icon: Icons.support_agent_outlined,
        expand: true,
        onPressed: () => _then((page) => unawaited(showNewTicketPanel(page, orderId: o.id))),
      ),
      if (o.canCancel)
        TextButton(
          style: TextButton.styleFrom(foregroundColor: context.palette.danger),
          onPressed: _busy == null ? () => _cancel(o) : null,
          child: Text(l10n.cancelOrder),
        ),
    ];
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (var i = 0; i < actions.length; i++) ...[if (i > 0) const Gap(AppSpace.sm), actions[i]],
      ],
    );
  }
}

/// Why the customer cancels. Closes with the answer, or with null when they keep the order.
class _CancelForm extends StatefulWidget {
  const _CancelForm({required this.onDone});
  final void Function(({String code, String? reason})?) onDone;

  @override
  State<_CancelForm> createState() => _CancelFormState();
}

class _CancelFormState extends State<_CancelForm> {
  String _code = cancelReasonCodes.first;
  final _details = TextEditingController();

  @override
  void dispose() {
    _details.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    String label(String code) => switch (code) {
      'changed_mind' => l10n.cancelReasonChangedMind,
      'ordered_by_mistake' => l10n.cancelReasonMistake,
      'delivery_too_long' => l10n.cancelReasonDelay,
      'found_better_price' => l10n.cancelReasonPrice,
      _ => l10n.cancelReasonOther,
    };
    return DirtyFormGuard(
      controllers: [_details],
      isDirty: () => _details.text.trim().isNotEmpty,
      child: SidePanelLayout(
        footer: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            AppButton(
              label: l10n.cancelOrder,
              variant: AppButtonVariant.destructive,
              onPressed: () => widget.onDone((code: _code, reason: _details.text.trim())),
            ),
            const Gap(AppSpace.xs),
            AppButton.ghost(label: l10n.keepOrder, expand: true, onPressed: () => widget.onDone(null)),
          ],
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            RadioGroup<String>(
              groupValue: _code,
              onChanged: (v) => setState(() => _code = v ?? _code),
              child: Column(
                children: [
                  for (final c in cancelReasonCodes)
                    RadioListTile<String>(value: c, title: Text(label(c)), contentPadding: EdgeInsets.zero),
                ],
              ),
            ),
            AppTextField(
              controller: _details,
              label: l10n.cancelDetails,
              maxLines: 3,
              minLines: 1,
              keyboardType: TextInputType.multiline,
              textCapitalization: TextCapitalization.sentences,
              inputFormatters: [LengthLimitingTextInputFormatter(500)],
            ),
          ],
        ),
      ),
    );
  }
}

/// Which items come back, how many of each, and why. Closes with the answer, or with null when the customer backs out.
class _ReturnForm extends StatefulWidget {
  const _ReturnForm({required this.order, required this.onDone});
  final Order order;
  final void Function(({List<({String orderItemId, int quantity})> items, String code, String? note})?) onDone;

  @override
  State<_ReturnForm> createState() => _ReturnFormState();
}

class _ReturnFormState extends State<_ReturnForm> {
  /// How many of each line are going back (a line that is not in the map, or at 0, stays).
  final _quantity = <String, int>{};
  final _note = TextEditingController();
  String _code = returnReasonCodes.first;

  /// Only what can still be sent back: delivered or collected, inside the return window, not already in a request.
  late final _lines = [
    for (final i in widget.order.items)
      if (!i.isGone && i.returnableNow > 0) i,
  ];

  int get _picked => _quantity.values.fold(0, (a, b) => a + b);

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    String label(String c) => switch (c) {
      'damaged' => l10n.returnReasonDamaged,
      'wrong_item' => l10n.returnReasonWrong,
      'not_as_described' => l10n.returnReasonNotAsDescribed,
      'changed_mind' => l10n.cancelReasonChangedMind,
      _ => l10n.cancelReasonOther,
    };
    return DirtyFormGuard(
      controllers: [_note],
      isDirty: () => _picked > 0 || _note.text.trim().isNotEmpty,
      child: SidePanelLayout(
        footer: AppButton(
          label: l10n.requestReturn,
          onPressed: _picked == 0
              ? null
              : () => widget.onDone((
                  items: [
                    for (final e in _quantity.entries)
                      if (e.value > 0) (orderItemId: e.key, quantity: e.value),
                  ],
                  code: _code,
                  note: _note.text.trim().isEmpty ? null : _note.text.trim(),
                )),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(l10n.returnFormIntro, style: context.text.bodyMedium),
            const Gap(AppSpace.sm),
            if (_lines.isEmpty)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: AppSpace.md),
                child: Text(l10n.returnNothing, textAlign: TextAlign.center),
              )
            else ...[
              Text(l10n.selectItemsToReturn, style: context.text.titleSmall),
              for (final i in _lines)
                Padding(
                  key: ValueKey('return-line-${i.id}'),
                  padding: const EdgeInsets.symmetric(vertical: AppSpace.xs),
                  child: Row(
                    children: [
                      AppNetworkImage(url: i.imageUrl, width: 48, height: 56, borderRadius: BorderRadius.circular(8)),
                      const Gap(AppSpace.sm),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(i.name, maxLines: 2, overflow: TextOverflow.ellipsis, style: context.text.titleSmall),
                            if (i.variantName != null) Text(i.variantName!, style: context.text.bodySmall),
                            Text(
                              l10n.returnUpTo(i.returnableNow),
                              style: context.text.bodySmall?.copyWith(color: context.palette.muted),
                            ),
                          ],
                        ),
                      ),
                      QuantityStepper(
                        value: _quantity[i.id] ?? 0,
                        min: 0,
                        max: i.returnableNow,
                        compact: true,
                        onChanged: (v) => setState(() => _quantity[i.id] = v),
                      ),
                    ],
                  ),
                ),
            ],
            const Gap(AppSpace.sm),
            DropdownButtonFormField<String>(
              initialValue: _code,
              isExpanded: true,
              decoration: InputDecoration(labelText: l10n.cancelReason),
              items: [for (final c in returnReasonCodes) DropdownMenuItem(value: c, child: Text(label(c)))],
              onChanged: (v) => setState(() => _code = v ?? _code),
            ),
            const Gap(AppSpace.sm),
            AppTextField(
              controller: _note,
              label: l10n.returnNoteLabel,
              maxLines: 3,
              minLines: 1,
              keyboardType: TextInputType.multiline,
              textCapitalization: TextCapitalization.sentences,
              inputFormatters: [LengthLimitingTextInputFormatter(1000)],
            ),
          ],
        ),
      ),
    );
  }
}

/// Which item of a many-item order the review is about. Closes with that item, or with null when the customer backs out.
class _ReviewPicker extends StatelessWidget {
  const _ReviewPicker({required this.items, required this.onDone});
  final List<OrderItem> items;
  final void Function(OrderItem?) onDone;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    return SidePanelLayout(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(l10n.reviewWhichTitle, style: context.text.titleSmall),
          const Gap(AppSpace.xs),
          for (final i in items)
            ListTile(
              key: ValueKey('review-item-${i.id}'),
              contentPadding: EdgeInsets.zero,
              leading: AppNetworkImage(url: i.imageUrl, width: 48, height: 56, borderRadius: BorderRadius.circular(8)),
              title: Text(i.name, maxLines: 2, overflow: TextOverflow.ellipsis),
              subtitle: i.variantName == null ? null : Text(i.variantName!),
              trailing: const Icon(Icons.chevron_right_rounded),
              onTap: () => onDone(i),
            ),
        ],
      ),
    );
  }
}
