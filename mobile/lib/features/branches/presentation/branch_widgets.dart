import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/features/branches/domain/branch_models.dart';
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

/// OpenStreetMap-compatible map (tile URL configurable via MAP_TILE_URL).
class BranchMap extends ConsumerWidget {
  const BranchMap({
    super.key,
    required this.branches,
    required this.onSelect,
    this.selectedId,
    this.interactive = true,
  });
  final List<Branch> branches;
  final ValueChanged<Branch> onSelect;
  final String? selectedId;
  final bool interactive;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final tiles = ref.watch(appConfigProvider).mapTileUrlTemplate;
    final located = branches.where((b) => b.hasLocation).toList();
    final center = located.isEmpty ? dohaCenter : LatLng(located.first.latitude!, located.first.longitude!);
    return FlutterMap(
      options: MapOptions(
        initialCenter: center,
        initialZoom: located.length == 1 ? 14 : 11,
        interactionOptions: InteractionOptions(
          flags: interactive ? InteractiveFlag.all & ~InteractiveFlag.rotate : InteractiveFlag.none,
        ),
      ),
      children: [
        TileLayer(urlTemplate: tiles, userAgentPackageName: 'qa.albahar.customer'),
        MarkerLayer(
          markers: [
            for (final b in located)
              Marker(
                point: LatLng(b.latitude!, b.longitude!),
                width: 48,
                height: 48,
                child: Semantics(
                  button: true,
                  label: b.name,
                  child: GestureDetector(
                    onTap: () => onSelect(b),
                    child: Icon(
                      Icons.location_on_rounded,
                      size: b.id == selectedId ? 46 : 38,
                      color: b.id == selectedId ? context.palette.brass : AppPalette.ink,
                    ),
                  ),
                ),
              ),
          ],
        ),
        RichAttributionWidget(attributions: [TextSourceAttribution(context.l10n.mapAttribution)]),
      ],
    );
  }
}
