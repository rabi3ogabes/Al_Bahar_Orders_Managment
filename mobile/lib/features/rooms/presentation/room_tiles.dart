import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/design_tokens.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/features/rooms/domain/room_models.dart';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

/// "Shop by room": one large tile per room with its picture, name and how many products it holds. A tap opens the shop filtered to that room. The tiles
/// scroll sideways and follow the text size (the strip grows with it); pictures load as they come into view and a room without one shows a soft tint.
class RoomTiles extends StatelessWidget {
  const RoomTiles({super.key, required this.rooms});

  final List<ShopRoom> rooms;

  /// The picture's share of the tile: tall, like the web tiles (4:5).
  static const double tileWidth = 156;
  static const double tileHeight = 196;

  @override
  Widget build(BuildContext context) {
    final design = context.design;
    final textScale = MediaQuery.textScalerOf(context).scale(1).clamp(1.0, 1.6);
    return SizedBox(
      height: tileHeight + 24 * (textScale - 1) + 8,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: EdgeInsetsDirectional.symmetric(horizontal: design.gutter, vertical: 4),
        itemCount: rooms.length,
        separatorBuilder: (_, _) => const Gap(AppSpace.sm),
        itemBuilder: (context, i) => RoomTile(room: rooms[i]),
      ),
    );
  }
}

class RoomTile extends StatelessWidget {
  const RoomTile({super.key, required this.room});

  final ShopRoom room;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final palette = context.palette;
    final count = l10n.roomProductsCount(room.productsCount);
    final image = room.imageUrl;
    return Semantics(
      button: true,
      label: '${room.name}, $count',
      excludeSemantics: true,
      child: SizedBox(
        width: RoomTiles.tileWidth,
        child: ClipRRect(
          borderRadius: AppRadius.lgAll,
          child: Material(
            color: palette.tint,
            child: InkWell(
              onTap: () => unawaited(context.push(room.listingLocation)),
              child: Stack(
                fit: StackFit.expand,
                children: [
                  if (image != null && image.isNotEmpty)
                    AppNetworkImage(url: image, memCacheWidth: 480)
                  else
                    Center(child: Icon(Icons.chair_outlined, size: 36, color: context.colors.primary)),
                  // The name stays readable on any picture.
                  const DecoratedBox(
                    decoration: BoxDecoration(
                      gradient: LinearGradient(
                        begin: Alignment.topCenter,
                        end: Alignment.bottomCenter,
                        colors: [Color(0x00000000), Color(0xB3000000)],
                        stops: [0.45, 1],
                      ),
                    ),
                  ),
                  PositionedDirectional(
                    start: AppSpace.sm,
                    end: AppSpace.sm,
                    bottom: AppSpace.sm,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(
                          room.name,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: context.text.titleMedium?.copyWith(color: Colors.white, height: 1.15),
                        ),
                        const Gap(2),
                        Text(
                          count,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: context.text.labelSmall?.copyWith(color: Colors.white.withValues(alpha: 0.85)),
                        ),
                      ],
                    ),
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
