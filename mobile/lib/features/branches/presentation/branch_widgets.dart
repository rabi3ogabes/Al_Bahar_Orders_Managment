import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/map/al_bahar_map.dart';
import 'package:albahar_customer/core/map/map_layers.dart';
import 'package:albahar_customer/core/map/map_markers.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/features/branches/domain/branch_models.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:latlong2/latlong.dart';

/// Doha city centre — default map position.
const dohaCenter = LatLng(25.2854, 51.5310);

class OpeningChip extends StatelessWidget {
  const OpeningChip({super.key, required this.opening});
  final BranchOpening? opening;

  @override
  Widget build(BuildContext context) {
    final o = opening;
    if (o == null) return const SizedBox.shrink();
    final l10n = context.l10n;
    final locale = context.localeCode;
    final label = o.isOpen
        ? (o.closesAt != null ? '${l10n.openNow} · ${l10n.closesAt(QatarTime.time(o.closesAt, locale))}' : l10n.openNow)
        : (o.nextOpenAt != null
              ? '${l10n.closedNow} · ${l10n.opensAt(QatarTime.weekdayDate(o.nextOpenAt, locale))} ${QatarTime.time(o.nextOpenAt, locale)}'
              : l10n.closedNow);
    return StatusChip(label: label, tone: o.isOpen ? StatusTone.success : StatusTone.neutral, dense: true);
  }
}

class BranchTile extends StatelessWidget {
  const BranchTile({super.key, required this.branch, required this.selected, required this.onTap, this.onInfo});
  final Branch branch;
  final bool selected;
  final VoidCallback onTap;
  final VoidCallback? onInfo;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    return SurfaceCard(
      onTap: onTap,
      color: selected ? context.palette.brassTint : null,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(color: context.palette.surfaceMuted, borderRadius: BorderRadius.circular(12)),
            child: Icon(Icons.storefront_outlined, color: context.palette.brass),
          ),
          const Gap(AppSpace.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(child: Text(branch.name, style: context.text.titleMedium)),
                    if (branch.distanceKm != null)
                      Text(l10n.distanceKm(branch.distanceKm!.toStringAsFixed(1)), style: context.text.labelMedium),
                  ],
                ),
                if (branch.area != null || branch.address != null)
                  Text(
                    branch.address ?? branch.area!,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: context.text.bodySmall,
                  ),
                const Gap(AppSpace.xs),
                Wrap(
                  spacing: AppSpace.xs,
                  runSpacing: 4,
                  children: [
                    OpeningChip(opening: branch.opening),
                    if (branch.pickupEnabled)
                      StatusChip(label: l10n.pickup, icon: Icons.shopping_bag_outlined, dense: true),
                    if (branch.deliveryEnabled)
                      StatusChip(label: l10n.delivery, icon: Icons.local_shipping_outlined, dense: true),
                  ],
                ),
              ],
            ),
          ),
          if (onInfo != null)
            IconButton(tooltip: l10n.details, icon: const Icon(Icons.info_outline_rounded), onPressed: onInfo)
          else if (selected)
            Icon(Icons.check_circle_rounded, color: context.palette.brass),
        ],
      ),
    );
  }
}

/// The branches on a map: every branch with a location gets a pin (the chosen one is larger), the map frames all of them,
/// and choosing a branch elsewhere in the screen glides the map to it. Tiles, dark mode, credit line and controls come
/// from the shared Al Bahar map.
class BranchMap extends ConsumerStatefulWidget {
  const BranchMap({
    super.key,
    required this.branches,
    required this.onSelect,
    this.selectedId,
    this.interactive = true,
    this.bottomInset = 0,
  });
  final List<Branch> branches;
  final ValueChanged<Branch> onSelect;
  final String? selectedId;
  final bool interactive;

  /// Height of a card laid over the bottom of the map, so the controls and the framed pins stay clear of it.
  final double bottomInset;

  @override
  ConsumerState<BranchMap> createState() => _BranchMapState();
}

class _BranchMapState extends ConsumerState<BranchMap> {
  final _map = AlBaharMapController();

  List<Branch> get _located => widget.branches.where((b) => b.hasLocation).toList();

  List<LatLng> _points() => [for (final b in _located) LatLng(b.latitude!, b.longitude!)];

  @override
  void didUpdateWidget(BranchMap old) {
    super.didUpdateWidget(old);
    if (widget.interactive && widget.selectedId != old.selectedId) {
      final b = _located.where((b) => b.id == widget.selectedId).firstOrNull;
      if (b != null) _map.flyTo(LatLng(b.latitude!, b.longitude!), zoom: 15);
    } else if (!listEquals(_points(), [
      for (final b in old.branches.where((b) => b.hasLocation)) LatLng(b.latitude!, b.longitude!),
    ])) {
      _map.fit(_points(), maxZoom: 15);
    }
  }

  @override
  void dispose() {
    _map.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final located = _located;
    final points = _points();
    return AlBaharMap(
      controller: _map,
      initialFit: points,
      maxFitZoom: 15,
      interactive: widget.interactive,
      bottomInset: widget.bottomInset,
      controls: widget.interactive ? MapControlsConfig(recenter: located.length > 1) : MapControlsConfig.none,
      children: [
        MarkerLayer(
          markers: [
            for (final b in located)
              pinMarker(
                LatLng(b.latitude!, b.longitude!),
                kind: PinKind.branch,
                label: b.name,
                selected: b.id == widget.selectedId,
                onTap: widget.interactive ? () => widget.onSelect(b) : null,
              ),
          ],
        ),
      ],
    );
  }
}
