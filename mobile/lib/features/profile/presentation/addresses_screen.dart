import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/profile/domain/profile_models.dart';
import 'package:albahar_customer/features/profile/presentation/state/profile_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// Saved addresses. In [pickMode] tapping an address returns its id.
class AddressesScreen extends ConsumerWidget {
  const AddressesScreen({super.key, this.pickMode = false});
  final bool pickMode;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final async = ref.watch(addressesProvider);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.addressesTitle)),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () async {
          final created = await context.push<Address>('/addresses/new');
          if (created != null && pickMode && context.mounted) context.pop(created.id);
        },
        icon: const Icon(Icons.add_location_alt_outlined),
        label: Text(l10n.addAddress),
      ),
      body: async.when(
        skipLoadingOnRefresh: true,
        loading: () => const ListSkeleton(count: 3, leading: false),
        error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(addressesProvider)),
        data: (list) => list.isEmpty
            ? EmptyState(icon: Icons.location_on_outlined, title: l10n.noAddresses, message: l10n.noAddressesMessage)
            : RefreshIndicator(
                onRefresh: () => ref.refresh(addressesProvider.future),
                child: ListView.separated(
                  padding: const EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.md, AppSpace.gutter, 96),
                  itemCount: list.length,
                  separatorBuilder: (_, _) => const Gap(AppSpace.sm),
                  itemBuilder: (context, i) => ContentWidth(
                    child: _AddressCard(address: list[i], pickMode: pickMode),
                  ),
                ),
              ),
      ),
    );
  }
}

class _AddressCard extends ConsumerWidget {
  const _AddressCard({required this.address, required this.pickMode});
  final Address address;
  final bool pickMode;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final controller = ref.read(addressesProvider.notifier);
    return SurfaceCard(
      onTap: pickMode ? () => context.pop(address.id) : () => context.push('/addresses/${address.id}/edit'),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(
            address.hasPin ? Icons.where_to_vote_outlined : Icons.location_on_outlined,
            color: context.palette.brass,
          ),
          const Gap(AppSpace.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Flexible(child: Text(address.label ?? address.recipientName, style: context.text.titleSmall)),
                    if (address.isDefault) ...[
                      const Gap(AppSpace.xs),
                      StatusChip(label: l10n.defaultAddress, tone: StatusTone.accent, dense: true),
                    ],
                  ],
                ),
                Text(
                  address.summary(
                    zone: l10n.zoneNumber,
                    street: l10n.streetNumber,
                    building: l10n.buildingNumber,
                    unit: l10n.unitNumber,
                    separator: context.localeCode == 'ar' ? '، ' : ', ',
                  ),
                  style: context.text.bodySmall,
                ),
                if (address.deliveryZone != null) Text(address.deliveryZone!.name, style: context.text.bodySmall),
                Text('${address.recipientName} · ${address.recipientPhone}', style: context.text.bodySmall),
              ],
            ),
          ),
          PopupMenuButton<String>(
            tooltip: l10n.menu,
            onSelected: (v) async {
              try {
                switch (v) {
                  case 'edit':
                    await context.push('/addresses/${address.id}/edit');
                  case 'default':
                    Haptics.tap();
                    await controller.makeDefault(address.id);
                  case 'delete':
                    final ok = await confirmAction(
                      context,
                      title: l10n.deleteAddressConfirm,
                      confirmLabel: l10n.delete,
                      destructive: true,
                    );
                    if (ok) await controller.delete(address.id);
                }
              } catch (e) {
                if (context.mounted) showErrorSnack(context, e);
              }
            },
            itemBuilder: (_) => [
              PopupMenuItem(value: 'edit', child: Text(l10n.edit)),
              if (!address.isDefault) PopupMenuItem(value: 'default', child: Text(l10n.setAsDefault)),
              PopupMenuItem(value: 'delete', child: Text(l10n.delete)),
            ],
          ),
        ],
      ),
    );
  }
}
