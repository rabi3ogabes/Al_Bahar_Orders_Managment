import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

/// Opens the scanner. A push, so Back from the scanner returns to the page it was opened from.
void openScanner(BuildContext context) {
  Haptics.tap();
  context.push('/scan');
}

/// The scan icon that sits in search bars (home, search): a 48 dp target that opens the scanner.
class ScanIconButton extends StatelessWidget {
  const ScanIconButton({super.key, this.color});

  final Color? color;

  @override
  Widget build(BuildContext context) {
    final label = context.l10n.scanTitle;
    return Semantics(
      button: true,
      label: label,
      excludeSemantics: true,
      child: Tooltip(
        message: label,
        child: InkResponse(
          radius: 26,
          onTap: () => openScanner(context),
          child: SizedBox(
            width: kMinTouchTarget,
            height: kMinTouchTarget,
            child: Icon(Icons.qr_code_scanner_rounded, color: color ?? context.colors.primary),
          ),
        ),
      ),
    );
  }
}

/// "Scan to add" row for the cart tab: a quiet card with the icon, the words and a chevron.
class ScanToAddTile extends StatelessWidget {
  const ScanToAddTile({super.key});

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    return Semantics(
      button: true,
      label: '${l10n.scanTitle}. ${l10n.scanToAddSubtitle}',
      excludeSemantics: true,
      child: SurfaceCard(
        padding: const EdgeInsets.symmetric(horizontal: AppSpace.md, vertical: AppSpace.sm),
        onTap: () => openScanner(context),
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: kMinTouchTarget),
          child: Row(
            children: [
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(color: context.palette.tint, shape: BoxShape.circle),
                child: Icon(Icons.qr_code_scanner_rounded, color: context.colors.primary),
              ),
              const Gap(AppSpace.sm),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(l10n.scanTitle, style: context.text.titleSmall),
                    Text(l10n.scanToAddSubtitle, style: context.text.bodySmall?.copyWith(color: context.palette.muted)),
                  ],
                ),
              ),
              Icon(Icons.chevron_right_rounded, color: context.palette.muted),
            ],
          ),
        ),
      ),
    );
  }
}
