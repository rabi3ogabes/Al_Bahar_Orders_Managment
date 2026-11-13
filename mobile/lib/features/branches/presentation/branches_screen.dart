import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/map/measure_size.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/debouncer.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/branches/domain/branch_models.dart';
import 'package:albahar_customer/features/branches/presentation/branch_detail_screen.dart';
import 'package:albahar_customer/features/branches/presentation/branch_widgets.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// Branch selection: all (searchable), nearby (with location consent) and map.
/// Opens branch selection in a side window. Resolves to the chosen branch (and confirms it on [context]).
Future<Branch?> showBranchPicker(BuildContext context) async {
  final l10n = context.l10n;
  final chosen = await showSidePanel<Branch>(
    context: context,
    title: l10n.chooseBranch,
    scrollable: false,
    builder: (_) => const BranchesScreen(pickMode: true, embedded: true),
  );
  if (chosen != null && context.mounted) showInfoSnack(context, l10n.branchSelectedToast(chosen.name));
  return chosen;
}

class BranchesScreen extends ConsumerStatefulWidget {
  const BranchesScreen({super.key, this.pickMode = false, this.embedded = false});
  final bool pickMode;

  /// True inside a side window ([showBranchPicker]): no app bar; a branch's details open as a step of the window.
  final bool embedded;

  @override
  ConsumerState<BranchesScreen> createState() => _BranchesScreenState();
}

class _BranchesScreenState extends ConsumerState<BranchesScreen> {
  final _debouncer = Debouncer();
  bool _nearbyRequested = false;
  Branch? _detail;

  @override
  void dispose() {
    _debouncer.dispose();
    super.dispose();
  }

  Future<void> _select(Branch b) async {
    Haptics.success();
    await ref.read(preferredBranchProvider.notifier).select(b.ref);
    if (!mounted) return;
    if (widget.embedded) {
      Navigator.of(context).pop(b);
      return;
    }
    showInfoSnack(context, context.l10n.branchSelectedToast(b.name));
    if (widget.pickMode && context.canPop()) context.pop();
  }

  void _openDetail(Branch b) {
    if (widget.embedded) {
      setState(() => _detail = b);
    } else {
      unawaited(context.push('/branches/${b.id}'));
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final selected = ref.watch(preferredBranchIdProvider);
    final detail = _detail;
    if (widget.embedded && detail != null) {
      return PanelStep(
        title: detail.name,
        onBack: () => setState(() => _detail = null),
        child: BranchDetailScreen(branchId: detail.id, embedded: true),
      );
    }
    final tabs = TabBar(
      tabs: [
        Tab(text: l10n.allBranches),
        Tab(text: l10n.nearbyBranches),
        Tab(text: l10n.mapView),
      ],
      onTap: (i) {
        if (i == 1) setState(() => _nearbyRequested = true);
      },
    );
    final views = TabBarView(
      physics: const NeverScrollableScrollPhysics(),
      children: [
        _AllTab(
          selectedId: selected,
          onSelect: _select,
          onDetail: _openDetail,
          onSearch: (q) => _debouncer(() => ref.read(branchSearchProvider.notifier).set(q)),
        ),
        _nearbyRequested
            ? _NearbyTab(selectedId: selected, onSelect: _select, onDetail: _openDetail)
            : _NearbyIntro(onStart: () => setState(() => _nearbyRequested = true)),
        _MapTab(selectedId: selected, onSelect: _select, onDetail: _openDetail),
      ],
    );
    return DefaultTabController(
      length: 3,
      child: widget.embedded
          ? Column(
              children: [
                tabs,
                Expanded(child: views),
              ],
            )
          : Scaffold(
              appBar: AppBar(title: Text(widget.pickMode ? l10n.chooseBranch : l10n.branchesTitle), bottom: tabs),
              body: views,
            ),
    );
  }
}

class _AllTab extends ConsumerWidget {
  const _AllTab({required this.selectedId, required this.onSelect, required this.onDetail, required this.onSearch});
  final String? selectedId;
  final ValueChanged<Branch> onSelect;
  final ValueChanged<Branch> onDetail;
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
                : _BranchList(branches: list, selectedId: selectedId, onSelect: onSelect, onDetail: onDetail),
          ),
        ),
      ],
    );
  }
}

class _BranchList extends StatelessWidget {
  const _BranchList({required this.branches, required this.selectedId, required this.onSelect, required this.onDetail});
  final List<Branch> branches;
  final String? selectedId;
  final ValueChanged<Branch> onSelect;
  final ValueChanged<Branch> onDetail;

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
      return BranchTile(branch: b, selected: b.id == selectedId, onTap: () => onSelect(b), onInfo: () => onDetail(b));
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
  const _NearbyTab({required this.selectedId, required this.onSelect, required this.onDetail});
  final String? selectedId;
  final ValueChanged<Branch> onSelect;
  final ValueChanged<Branch> onDetail;

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
        return _BranchList(branches: result.branches, selectedId: selectedId, onSelect: onSelect, onDetail: onDetail);
      },
    );
  }
}

class _MapTab extends ConsumerStatefulWidget {
  const _MapTab({required this.selectedId, required this.onSelect, required this.onDetail});
  final String? selectedId;
  final ValueChanged<Branch> onSelect;
  final ValueChanged<Branch> onDetail;

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
                          onTap: () => widget.onDetail(_focused!),
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
