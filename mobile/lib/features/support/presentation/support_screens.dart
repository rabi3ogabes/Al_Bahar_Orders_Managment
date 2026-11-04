import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/app_text_field.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/paged.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/orders/domain/order_models.dart';
import 'package:albahar_customer/features/orders/presentation/state/order_providers.dart';
import 'package:albahar_customer/features/support/data/support_repository.dart';
import 'package:albahar_customer/features/support/domain/support_models.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

String ticketStatusLabel(AppLocalizations l10n, String status) => switch (status) {
  'pending' || 'awaiting_customer' || 'awaiting_reply' => l10n.ticketStatusPending,
  'resolved' => l10n.ticketStatusResolved,
  'closed' => l10n.ticketStatusClosed,
  _ => l10n.ticketStatusOpen,
};

String ticketCategoryLabel(AppLocalizations l10n, String code) => switch (code) {
  'order' => l10n.ticketCategoryOrder,
  'payment' => l10n.ticketCategoryPayment,
  'delivery' => l10n.ticketCategoryDelivery,
  'product' => l10n.ticketCategoryProduct,
  'auction' => l10n.ticketCategoryAuction,
  'account' => l10n.ticketCategoryAccount,
  _ => l10n.ticketCategoryOther,
};

class SupportScreen extends ConsumerWidget {
  const SupportScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final async = ref.watch(ticketsProvider);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.supportTitle)),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => context.push('/support/new'),
        icon: const Icon(Icons.add_comment_outlined),
        label: Text(l10n.newTicket),
      ),
      body: async.when(
        skipLoadingOnRefresh: true,
        loading: () => const ListSkeleton(leading: false),
        error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(ticketsProvider)),
        data: (state) => RefreshIndicator(
          onRefresh: () => ref.read(ticketsProvider.notifier).refresh(),
          child: ListView(
            padding: const EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.md, AppSpace.gutter, 96),
            children: [
              ContentWidth(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Wrap(
                      spacing: AppSpace.xs,
                      runSpacing: AppSpace.xs,
                      children: [
                        ActionChip(
                          avatar: const Icon(Icons.quiz_outlined, size: 18),
                          label: Text(l10n.faq),
                          onPressed: () => context.push('/pages/faq'),
                        ),
                        ActionChip(
                          avatar: const Icon(Icons.assignment_return_outlined, size: 18),
                          label: Text(l10n.returnsPolicy),
                          onPressed: () => context.push('/pages/returns'),
                        ),
                        ActionChip(
                          avatar: const Icon(Icons.call_outlined, size: 18),
                          label: Text(l10n.contactUs),
                          onPressed: () => context.push('/pages/contact'),
                        ),
                      ],
                    ),
                    const Gap(AppSpace.lg),
                    if (state.items.isEmpty)
                      EmptyState(
                        icon: Icons.support_agent_outlined,
                        title: l10n.noTickets,
                        message: l10n.noTicketsMessage,
                        compact: true,
                      )
                    else ...[
                      for (final t in state.items) ...[
                        SurfaceCard(
                          onTap: () => context.push('/support/${t.id}'),
                          child: Row(
                            children: [
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      t.subject,
                                      style: context.text.titleSmall,
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis,
                                    ),
                                    Text(
                                      [
                                        if (t.number != null) '#${t.number}',
                                        if (t.category != null) ticketCategoryLabel(l10n, t.category!),
                                        QatarTime.date(t.updatedAt ?? t.createdAt, context.localeCode),
                                      ].where((s) => s.isNotEmpty).join(' · '),
                                      style: context.text.bodySmall,
                                    ),
                                  ],
                                ),
                              ),
                              StatusChip(
                                label: ticketStatusLabel(l10n, t.status),
                                tone: toneForStatus(t.status),
                                dense: true,
                              ),
                            ],
                          ),
                        ),
                        const Gap(AppSpace.sm),
                      ],
                      LoadMoreFooter(state: state, onLoadMore: () => ref.read(ticketsProvider.notifier).loadMore()),
                    ],
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class NewTicketScreen extends ConsumerStatefulWidget {
  const NewTicketScreen({super.key, this.orderId});
  final String? orderId;

  @override
  ConsumerState<NewTicketScreen> createState() => _NewTicketScreenState();
}

class _NewTicketScreenState extends ConsumerState<NewTicketScreen> {
  final _form = GlobalKey<FormState>();
  final _subject = TextEditingController();
  final _message = TextEditingController();
  late String _category = widget.orderId != null ? 'order' : 'other';
  late String? _orderId = widget.orderId;
  bool _busy = false;
  ApiException? _error;

  @override
  void dispose() {
    _subject.dispose();
    _message.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_form.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final ticket = await ref
          .read(supportRepositoryProvider)
          .create(category: _category, subject: _subject.text.trim(), message: _message.text.trim(), orderId: _orderId);
      ref.invalidate(ticketsProvider);
      Haptics.success();
      if (!mounted) return;
      showInfoSnack(context, context.l10n.ticketCreated);
      context.pushReplacement('/support/${ticket.id}');
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final orders = ref.watch(ordersProvider(OrderFilter.all)).value?.items ?? const <OrderSummary>[];
    return Scaffold(
      appBar: AppBar(title: Text(l10n.newTicket)),
      body: Form(
        key: _form,
        child: ListView(
          padding: const EdgeInsets.all(AppSpace.gutter),
          children: [
            ContentWidth(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  DropdownButtonFormField<String>(
                    initialValue: _category,
                    decoration: InputDecoration(
                      labelText: l10n.ticketCategory,
                      errorText: _error?.fieldError('category'),
                    ),
                    items: [
                      for (final c in ticketCategories)
                        DropdownMenuItem(value: c, child: Text(ticketCategoryLabel(l10n, c))),
                    ],
                    onChanged: (v) => setState(() => _category = v ?? _category),
                  ),
                  const Gap(AppSpace.md),
                  DropdownButtonFormField<String?>(
                    initialValue: orders.any((o) => o.id == _orderId) || _orderId == null ? _orderId : null,
                    isExpanded: true,
                    decoration: InputDecoration(labelText: '${l10n.relatedOrder} (${l10n.optional})'),
                    items: [
                      DropdownMenuItem<String?>(child: Text(l10n.noRelatedOrder)),
                      for (final o in orders)
                        DropdownMenuItem<String?>(value: o.id, child: Text(l10n.orderNumber(o.number))),
                      if (_orderId != null && !orders.any((o) => o.id == _orderId))
                        DropdownMenuItem<String?>(value: _orderId, child: Text(l10n.orderNumber('…'))),
                    ],
                    onChanged: (v) => setState(() => _orderId = v),
                  ),
                  const Gap(AppSpace.md),
                  AppTextField(
                    controller: _subject,
                    label: l10n.ticketSubject,
                    maxLength: 150,
                    errorText: _error?.fieldError('subject'),
                    validator: (v) => (v == null || v.trim().isEmpty) ? l10n.fieldRequired : null,
                  ),
                  const Gap(AppSpace.xs),
                  AppTextField(
                    controller: _message,
                    label: l10n.ticketMessage,
                    maxLines: 8,
                    minLines: 5,
                    maxLength: 4000,
                    errorText: _error?.fieldError('message'),
                    validator: (v) => (v == null || v.trim().length < 5) ? l10n.fieldRequired : null,
                  ),
                  if (_error != null && _error!.errors.isEmpty)
                    Text(
                      describeError(l10n, _error),
                      style: context.text.bodySmall?.copyWith(color: context.palette.danger),
                    ),
                  const Gap(AppSpace.lg),
                  AppButton(label: l10n.send, loading: _busy, onPressed: _submit),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Ticket thread (internal notes are never returned by the API).
class TicketScreen extends ConsumerStatefulWidget {
  const TicketScreen({super.key, required this.ticketId});
  final String ticketId;

  @override
  ConsumerState<TicketScreen> createState() => _TicketScreenState();
}

class _TicketScreenState extends ConsumerState<TicketScreen> {
  final _reply = TextEditingController();
  final _local = <TicketMessage>[];
  bool _sending = false;

  @override
  void dispose() {
    _reply.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    final text = _reply.text.trim();
    if (text.isEmpty) return;
    setState(() => _sending = true);
    try {
      final msg = await ref.read(supportRepositoryProvider).reply(widget.ticketId, text);
      _reply.clear();
      Haptics.light();
      if (msg != null) {
        setState(() => _local.add(msg));
      } else {
        ref.invalidate(ticketProvider(widget.ticketId));
      }
    } catch (e) {
      if (mounted) showErrorSnack(context, e);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final async = ref.watch(ticketProvider(widget.ticketId));
    return Scaffold(
      appBar: AppBar(title: Text(async.value?.subject ?? l10n.supportTitle)),
      body: async.when(
        skipLoadingOnRefresh: true,
        loading: () => const ListSkeleton(leading: false),
        error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(ticketProvider(widget.ticketId))),
        data: (t) {
          final ids = t.messages.map((m) => m.id).toSet();
          final messages = [...t.messages, ..._local.where((m) => !ids.contains(m.id))];
          return Column(
            children: [
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: AppSpace.gutter, vertical: AppSpace.xs),
                child: Row(
                  children: [
                    StatusChip(label: ticketStatusLabel(l10n, t.status), tone: toneForStatus(t.status), dense: true),
                    const Gap(AppSpace.xs),
                    if (t.orderId != null)
                      TextButton(onPressed: () => context.push('/orders/${t.orderId}'), child: Text(l10n.relatedOrder)),
                  ],
                ),
              ),
              Expanded(
                child: RefreshIndicator(
                  onRefresh: () => ref.refresh(ticketProvider(widget.ticketId).future),
                  child: ListView.builder(
                    padding: const EdgeInsets.all(AppSpace.gutter),
                    itemCount: messages.length,
                    itemBuilder: (_, i) => _Bubble(message: messages[i]),
                  ),
                ),
              ),
              if (!t.isClosed)
                SafeArea(
                  top: false,
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(AppSpace.md, AppSpace.xs, AppSpace.xs, AppSpace.sm),
                    child: Row(
                      children: [
                        Expanded(
                          child: TextField(
                            controller: _reply,
                            minLines: 1,
                            maxLines: 4,
                            textInputAction: TextInputAction.newline,
                            decoration: InputDecoration(hintText: l10n.typeMessage),
                          ),
                        ),
                        IconButton(
                          tooltip: l10n.send,
                          onPressed: _sending ? null : () => unawaited(_send()),
                          icon: _sending
                              ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2))
                              : Icon(Icons.send_rounded, color: context.palette.brass),
                        ),
                      ],
                    ),
                  ),
                ),
            ],
          );
        },
      ),
    );
  }
}

class _Bubble extends StatelessWidget {
  const _Bubble({required this.message});
  final TicketMessage message;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final mine = message.fromCustomer;
    return Align(
      alignment: mine ? AlignmentDirectional.centerEnd : AlignmentDirectional.centerStart,
      child: ConstrainedBox(
        constraints: BoxConstraints(maxWidth: MediaQuery.sizeOf(context).width * 0.78),
        child: Container(
          margin: const EdgeInsets.symmetric(vertical: AppSpace.xxs),
          padding: const EdgeInsets.all(AppSpace.sm),
          decoration: BoxDecoration(
            color: mine ? context.colors.inverseSurface : context.colors.surface,
            border: mine ? null : Border.all(color: context.palette.line),
            borderRadius: BorderRadiusDirectional.only(
              topStart: const Radius.circular(AppRadius.card),
              topEnd: const Radius.circular(AppRadius.card),
              bottomStart: Radius.circular(mine ? AppRadius.card : 4),
              bottomEnd: Radius.circular(mine ? 4 : AppRadius.card),
            ),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                mine ? l10n.you : (message.authorName ?? l10n.supportTeam),
                style: context.text.labelSmall?.copyWith(color: mine ? context.palette.brass : context.palette.brass),
              ),
              const Gap(2),
              Text(
                message.body,
                style: context.text.bodyMedium?.copyWith(color: mine ? context.colors.onInverseSurface : null),
              ),
              const Gap(2),
              Text(
                QatarTime.dateTime(message.createdAt, context.localeCode),
                style: context.text.labelSmall?.copyWith(
                  color: mine ? context.colors.onInverseSurface.withValues(alpha: 0.6) : null,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
