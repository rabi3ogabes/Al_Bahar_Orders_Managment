import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/map/measure_size.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/debouncer.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/branches/domain/branch_models.dart';
import 'package:albahar_customer/features/branches/presentation/branch_widgets.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// Branch selection: all (searchable), nearby (with location consent) and map.
class BranchesScreen extends ConsumerStatefulWidget {
  const BranchesScreen({super.key, this.pickMode = false});
  final bool pickMode;

  @override
  ConsumerState<BranchesScreen> createState() => _BranchesScreenState();
}

class _BranchesScreenState extends ConsumerState<BranchesScreen> {
  final _debouncer = Debouncer();
  bool _nearbyRequested = false;

  @override
  void dispose() {
    _debouncer.dispose();
    super.dispose();
  }

  Future<void> _select(Branch b) async {
    Haptics.success();
    await ref.read(preferredBranchProvider.notifier).select(b.ref);
    if (!mounted) return;
    showInfoSnack(context, context.l10n.branchSelectedToast(b.name));
    if (widget.pickMode && context.canPop()) context.pop();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final selected = ref.watch(preferredBranchIdProvider);
    return DefaultTabController(
      length: 3,
      child: Scaffold(
        appBar: AppBar(
          title: Text(widget.pickMode ? l10n.chooseBranch : l10n.branchesTitle),
          bottom: TabBar(
            tabs: [
              Tab(text: l10n.allBranches),
              Tab(text: l10n.nearbyBranches),
              Tab(text: l10n.mapView),
            ],
            onTap: (i) {
              if (i == 1) setState(() => _nearbyRequested = true);
            },
          ),
        ),
        body: TabBarView(
          physics: const NeverScrollableScrollPhysics(),
          children: [
            _AllTab(
              selectedId: selected,
              onSelect: _select,
              onSearch: (q) => _debouncer(() => ref.read(branchSearchProvider.notifier).set(q)),
            ),
            _nearbyRequested
                ? _NearbyTab(selectedId: selected, onSelect: _select)
                : _NearbyIntro(onStart: () => setState(() => _nearbyRequested = true)),
            _MapTab(selectedId: selected, onSelect: _select),
          ],
        ),
      ),
    );
  }
}

class _AllTab extends ConsumerWidget {
  const _AllTab({required this.selectedId, required this.onSelect, required this.onSearch});
  final String? selectedId;
  final ValueChanged<Branch> onSelect;
  final ValueChanged<String> onSearch;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final branches = ref.watch(branchesProvider);
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.md, AppSpace.gutter, AppSpace.xs),
          child: TextField(
            onChanged: onSearch,
            textInputAction: TextInputAction.search,
            decoration: InputDecoration(hintText: l10n.branchSearchHint, prefixIcon: const Icon(Icons.search_rounded)),
          ),
        ),
        Expanded(
          child: branches.when(
            skipLoadingOnRefresh: true,
            loading: () => const ListSkeleton(),
            error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(branchesProvider)),
            data: (list) => list.isEmpty
                ? EmptyState(icon: Icons.storefront_outlined, title: l10n.noBranches)
                : _BranchList(branches: list, selectedId: selectedId, onSelect: onSelect),
          ),
        ),
      ],
    );
  }
}

class _BranchList extends StatelessWidget {
  const _BranchList({required this.branches, required this.selectedId, required this.onSelect});
  final List<Branch> branches;
  final String? selectedId;
  final ValueChanged<Branch> onSelect;

  @override
  Widget build(BuildContext context) => ListView.separated(
    padding: const EdgeInsets.all(AppSpace.gutter),
    itemCount: branches.length + 1,
    separatorBuilder: (_, _) => const Gap(AppSpace.sm),
    itemBuilder: (context, i) {
      if (i == branches.length) {
        return Padding(
          padding: const EdgeInsets.symmetric(vertical: AppSpace.sm),
          child: Text(context.l10n.branchChangeNote, style: context.text.bodySmall, textAlign: TextAlign.center),
        );
      }
      final b = branches[i];
      return BranchTile(
        branch: b,
        selected: b.id == selectedId,
        onTap: () => onSelect(b),
        onInfo: () => context.push('/branches/${b.id}'),
      );
    },
  );
}

class _NearbyIntro extends StatelessWidget {
  const _NearbyIntro({required this.onStart});
  final VoidCallback onStart;

  @override
  Widget build(BuildContext context) => EmptyState(
    icon: Icons.near_me_outlined,
    title: context.l10n.nearbyBranches,
    message: context.l10n.locationDeniedMessage,
    actionLabel: context.l10n.useMyLocation,
    onAction: onStart,
  );
}

class _NearbyTab extends ConsumerWidget {
  const _NearbyTab({required this.selectedId, required this.onSelect});
  final String? selectedId;
  final ValueChanged<Branch> onSelect;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final nearby = ref.watch(nearbyBranchesProvider);
    return nearby.when(
      loading: () => const ListSkeleton(),
      error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(nearbyBranchesProvider)),
      data: (result) {
        if (result.access != LocationAccess.granted) {
          return EmptyState(
            icon: Icons.location_off_outlined,
            title: l10n.locationDenied,
            message: l10n.locationDeniedMessage,
            actionLabel:
                result.access == LocationAccess.deniedForever || result.access == LocationAccess.serviceDisabled
                ? l10n.openSettings
                : l10n.retry,
            onAction: () async {
              if (result.access == LocationAccess.deniedForever || result.access == LocationAccess.serviceDisabled) {
                await ref.read(locationServiceProvider).openSettings();
              }
              ref.invalidate(nearbyBranchesProvider);
            },
          );
        }
        if (result.branches.isEmpty) return EmptyState(icon: Icons.storefront_outlined, title: l10n.noBranches);
        return _BranchList(branches: result.branches, selectedId: selectedId, onSelect: onSelect);
      },
    );
  }
}

class _MapTab extends ConsumerStatefulWidget {
  const _MapTab({required this.selectedId, required this.onSelect});
  final String? selectedId;
  final ValueChanged<Branch> onSelect;

  @override
  ConsumerState<_MapTab> createState() => _MapTabState();
}

class _MapTabState extends ConsumerState<_MapTab> {
  Branch? _focused;

  /// Height of the branch card laid over the bottom of the map; the map keeps its controls and pins clear of it.
  double _card = 0;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final branches = ref.watch(branchesProvider);
    return branches.when(
      loading: () => const Skeleton(radius: 0),
      error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(branchesProvider)),
      data: (list) => Stack(
        children: [
          Positioned.fill(
            child: BranchMap(
              branches: list,
              selectedId: _focused?.id ?? widget.selectedId,
              bottomInset: _focused == null ? 0 : _card + AppSpace.md,
              onSelect: (b) {
                Haptics.tap();
                setState(() => _focused = b);
              },
            ),
          ),
          if (_focused != null)
            PositionedDirectional(
              start: AppSpace.md,
              end: AppSpace.md,
              bottom: AppSpace.md,
              child: SafeArea(
                child: MeasureSize(
                  onChange: (size) {
                    if (mounted && (size.height - _card).abs() > 0.5) setState(() => _card = size.height);
                  },
                  child: Material(
                    color: Colors.transparent,
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        BranchTile(
                          branch: _focused!,
                          selected: _focused!.id == widget.selectedId,
                          onTap: () => context.push('/branches/${_focused!.id}'),
                        ),
                        const Gap(AppSpace.xs),
                        AppButton(
                          label: l10n.selectThisBranch,
                          onPressed: () => unawaited(Future(() => widget.onSelect(_focused!))),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}
