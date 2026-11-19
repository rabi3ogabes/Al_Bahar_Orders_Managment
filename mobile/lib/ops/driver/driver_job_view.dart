import 'package:albahar_customer/core/map/map_nav.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/ops/driver/driver_models.dart';
import 'package:albahar_customer/ops/driver/driver_strings.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_status_style.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/ops_widgets.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// The pieces of the driver's job card, calm and in the order a driver needs them: WHAT and WHERE and WHEN first, then "Before you go" (only the
/// facts that are true for this parcel), the customer's note, who to call, what is in the parcel, and the money. Shared by the delivery window
/// and (in a smaller form) the job card of the list.

/// "Today · 2:00 PM – 4:00 PM" (the window the customer chose), or "Scheduled: Nov 14, 3:00 PM" for a job without a slot; null when it has neither.
String? jobWhen(BuildContext context, DriverJob job) {
  final t = OpsStrings.of(context);
  final lang = Localizations.localeOf(context).languageCode;
  final start = job.context.slotStart;
  final end = job.context.slotEnd;
  if (start != null && end != null) {
    final sameDay = QatarTime.isoDate(QatarTime.today()) == QatarTime.isoDate(QatarTime.today(start));
    final day = sameDay ? t.today : QatarTime.weekdayDate(start, lang);
    return '$day · ${QatarTime.time(start, lang)} – ${QatarTime.time(end, lang)}';
  }
  final at = job.scheduledFor;
  return at == null ? null : t.scheduledFor(QatarTime.dateTime(at, lang));
}

/// The number to call first: the person at the door, else the account holder.
String? primaryPhone(DriverJob job) => job.context.recipient?.phone ?? job.customerPhone;

/// The name of the person at the door (the recipient when the order names one, else the account holder).
String receiverName(DriverJob job) {
  final r = job.context.recipient;
  final name = r?.name;
  // The Call tile dials the recipient's own number, so only then is the recipient the one being called.
  return name != null && r!.phone != null && !r.isAccountHolder ? name : job.customerName;
}

/// The three big buttons under the address: Call, Navigate, Copy address. Each is a tall tile with an icon over its word, so a thumb finds it
/// in a car park, in sunlight.
class JobQuickActions extends ConsumerWidget {
  const JobQuickActions({super.key, required this.job, required this.address});
  final DriverJob job;
  final String address;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = OpsStrings.of(context);
    final phone = primaryPhone(job);
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Expanded(
            child: _Tile(
              key: const ValueKey('quick-call'),
              icon: Icons.call_rounded,
              label: t.call,
              semantics: t.callPerson(receiverName(job)),
              onTap: phone == null ? null : () => dial(context, phone),
            ),
          ),
          const Gap(AppSpace.xs),
          Expanded(
            child: _Tile(
              key: const ValueKey('quick-navigate'),
              icon: Icons.navigation_rounded,
              label: t.navigate,
              onTap: () => openMaps(
                context,
                lat: job.address.lat,
                lng: job.address.lng,
                query: job.address.line,
                launcher: ref.read(navigationLauncherProvider),
              ),
            ),
          ),
          const Gap(AppSpace.xs),
          Expanded(
            child: CopyTile(text: address, label: t.copyAddress),
          ),
        ],
      ),
    );
  }
}

/// A tall tappable tile: an icon over a short word (at least 64 dp high, so also easy with one thumb and with gloves).
class _Tile extends StatelessWidget {
  const _Tile({super.key, required this.icon, required this.label, required this.onTap, this.semantics});
  final IconData icon;
  final String label;
  final VoidCallback? onTap;
  final String? semantics;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final enabled = onTap != null;
    return Semantics(
      button: true,
      enabled: enabled,
      label: semantics ?? label,
      excludeSemantics: true,
      child: Material(
        color: enabled ? scheme.primaryContainer : scheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(AppRadius.input),
        child: InkWell(
          borderRadius: BorderRadius.circular(AppRadius.input),
          onTap: onTap,
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 72),
            child: Padding(
              padding: const EdgeInsetsDirectional.symmetric(horizontal: AppSpace.xxs, vertical: AppSpace.xs),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Icon(icon, size: 26, color: enabled ? scheme.onPrimaryContainer : scheme.onSurfaceVariant),
                  const Gap(4),
                  Text(
                    label,
                    maxLines: 2,
                    textAlign: TextAlign.center,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.labelLarge?.copyWith(
                      color: enabled ? scheme.onPrimaryContainer : scheme.onSurfaceVariant,
                      fontWeight: FontWeight.w600,
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

/// The "Copy address" tile: it says "Copied" on the tile itself, where the finger is.
class CopyTile extends StatefulWidget {
  const CopyTile({super.key, required this.text, required this.label});
  final String text;
  final String label;

  @override
  State<CopyTile> createState() => _CopyTileState();
}

class _CopyTileState extends State<CopyTile> {
  bool _copied = false;

  Future<void> _copy() async {
    await Clipboard.setData(ClipboardData(text: widget.text));
    Haptics.tap();
    if (!mounted) return;
    setState(() => _copied = true);
    await Future<void>.delayed(const Duration(seconds: 2));
    if (mounted) setState(() => _copied = false);
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    return Semantics(
      liveRegion: true,
      child: _Tile(
        key: const ValueKey('quick-copy'),
        icon: _copied ? Icons.check_rounded : Icons.copy_rounded,
        label: _copied ? t.copied : widget.label,
        onTap: _copy,
      ),
    );
  }
}

/// "Before you go": the facts that are TRUE for this parcel, as chips (needs two people, assembly requested, oversized, cash to collect).
/// Nothing is drawn for a plain parcel, so a household order never carries a furniture hint.
class BeforeYouGo extends StatelessWidget {
  const BeforeYouGo({super.key, required this.job});
  final DriverJob job;

  /// True when this job has anything to say.
  static bool hasAny(DriverJob job) => job.context.hasHeadsUp || job.isCod;

  @override
  Widget build(BuildContext context) {
    if (!hasAny(job)) return const SizedBox.shrink();
    final t = OpsStrings.of(context);
    final c = job.context;
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpace.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsetsDirectional.only(start: 2, bottom: AppSpace.xs),
            child: Semantics(
              header: true,
              child: Text(t.beforeYouGo, style: context.text.labelLarge?.copyWith(color: context.palette.muted)),
            ),
          ),
          Wrap(
            spacing: AppSpace.xs,
            runSpacing: AppSpace.xs,
            children: [
              if (c.needsTwoPeople)
                StatusChip(
                  key: const ValueKey('chip-two-people'),
                  label: t.needsTwoPeople,
                  icon: Icons.groups_rounded,
                  tone: StatusTone.warning,
                ),
              if (c.requiresAssembly)
                StatusChip(
                  key: const ValueKey('chip-assembly'),
                  label: t.assemblyRequested,
                  icon: Icons.handyman_rounded,
                  tone: StatusTone.info,
                ),
              if (c.isOversized)
                StatusChip(
                  key: const ValueKey('chip-oversized'),
                  label: t.oversizedChip,
                  icon: Icons.open_in_full_rounded,
                  tone: StatusTone.warning,
                ),
              if (job.isCod)
                StatusChip(
                  key: const ValueKey('chip-cash'),
                  label: t.cashChip(job.codAmount.toStringAsFixed(2)),
                  icon: Icons.payments_outlined,
                  tone: StatusTone.accent,
                ),
            ],
          ),
        ],
      ),
    );
  }
}

/// The customer's note and the instructions for the door (the gate code, "call from downstairs"), in a highlighted box so they are not missed.
class JobNotesBox extends StatelessWidget {
  const JobNotesBox({super.key, required this.job});
  final DriverJob job;

  @override
  Widget build(BuildContext context) {
    final c = job.context;
    if (!c.hasNotes) return const SizedBox.shrink();
    final t = OpsStrings.of(context);
    final p = context.palette;
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpace.md),
      child: Semantics(
        container: true,
        child: Container(
          key: const ValueKey('job-notes'),
          width: double.infinity,
          padding: const EdgeInsets.all(AppSpace.md),
          decoration: BoxDecoration(
            color: p.brassTint,
            borderRadius: BorderRadius.circular(AppRadius.input),
            border: BorderDirectional(start: BorderSide(color: p.brass, width: 4)),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (c.instructions != null) _note(context, Icons.vpn_key_outlined, t.doorInstructions, c.instructions!),
              if (c.instructions != null && c.customerNotes != null) const Gap(AppSpace.sm),
              if (c.customerNotes != null)
                _note(context, Icons.sticky_note_2_outlined, t.customerNote, c.customerNotes!),
            ],
          ),
        ),
      ),
    );
  }

  Widget _note(BuildContext context, IconData icon, String title, String text) {
    final p = context.palette;
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(icon, size: 22, color: p.brassDeep),
        const Gap(AppSpace.xs),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                title,
                style: context.text.labelMedium?.copyWith(color: p.brassDeep, fontWeight: FontWeight.w700),
              ),
              const Gap(2),
              Text(text, style: context.text.bodyLarge?.copyWith(fontWeight: FontWeight.w500)),
            ],
          ),
        ),
      ],
    );
  }
}

/// Who to call, when there is more than one number: a big button for the person at the door and one for the account holder (each says whom it
/// calls), and the second number the customer gave when the dashboard shows it. With a single number the big Call tile is all there is, so
/// nothing is drawn here.
class JobContacts extends ConsumerWidget {
  const JobContacts({super.key, required this.job});
  final DriverJob job;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = OpsStrings.of(context);
    final r = job.context.recipient;
    final alt = ref.watch(opsAlternatePhoneEnabledProvider) ? job.alternatePhone : null;
    final holderPhone = job.customerPhone;
    final other = r != null && !r.isAccountHolder && r.phone != null;
    if (!other && alt == null) return const SizedBox.shrink();
    return PanelSection(
      title: t.call,
      children: [
        if (other)
          _CallRow(key: const ValueKey('call-recipient'), role: t.callRecipient, name: r.name ?? '', phone: r.phone!),
        if (other && holderPhone != null) const Gap(AppSpace.xs),
        if (holderPhone != null)
          _CallRow(
            key: const ValueKey('call-account-holder'),
            role: other ? t.callAccountHolder : t.call,
            name: job.customerName,
            phone: holderPhone,
          ),
        // The customer's second number, for when the main one is not answered.
        if (alt != null) AlternatePhoneCall(key: const ValueKey('call-alternative'), phone: alt),
      ],
    );
  }
}

class _CallRow extends StatelessWidget {
  const _CallRow({super.key, required this.role, required this.name, required this.phone});
  final String role;
  final String name;
  final String phone;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Semantics(
      button: true,
      label: [role, name].where((s) => s.isNotEmpty).join(' '),
      excludeSemantics: true,
      child: Material(
        color: scheme.surface,
        borderRadius: BorderRadius.circular(AppRadius.input),
        child: InkWell(
          borderRadius: BorderRadius.circular(AppRadius.input),
          onTap: () => dial(context, phone),
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 64),
            child: Padding(
              padding: const EdgeInsetsDirectional.fromSTEB(AppSpace.md, AppSpace.xs, AppSpace.xs, AppSpace.xs),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Text(role, style: context.text.titleSmall),
                        if (name.isNotEmpty) Text(name, style: context.text.bodyMedium),
                        Directionality(
                          textDirection: TextDirection.ltr,
                          child: Text(phone, style: context.text.bodySmall?.copyWith(color: context.palette.muted)),
                        ),
                      ],
                    ),
                  ),
                  const Gap(AppSpace.xs),
                  Container(
                    width: 48,
                    height: 48,
                    decoration: BoxDecoration(color: scheme.primaryContainer, shape: BoxShape.circle),
                    child: Icon(Icons.call_rounded, color: scheme.onPrimaryContainer),
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

/// What is in the parcel: one block per line (name, variant, "× quantity"), with weight and size under it when the catalogue knows them and
/// small chips for the lines that are oversized or need assembling. The total weight closes the list.
class DriverItems extends StatelessWidget {
  const DriverItems({super.key, required this.job});
  final DriverJob job;

  @override
  Widget build(BuildContext context) {
    final items = job.items;
    if (items.isEmpty) return const SizedBox.shrink();
    final t = OpsStrings.of(context);
    final muted = context.palette.muted;
    final facts = job.context.itemFacts;
    final units = items.fold<int>(0, (sum, i) => sum + i.quantity);
    final total = job.context.totalWeightKg;
    return PanelSection(
      title: t.itemsTitle(units),
      children: [
        for (var i = 0; i < items.length; i++) ...[
          if (i > 0) const Divider(height: AppSpace.md * 2),
          _line(context, items[i], facts[items[i].id], muted),
        ],
        if (total != null) ...[
          const Divider(height: AppSpace.md * 2),
          Row(
            children: [
              Icon(Icons.scale_outlined, size: 18, color: muted),
              const Gap(AppSpace.xs),
              Expanded(child: Text(t.totalWeight(total), style: context.text.bodyMedium)),
            ],
          ),
        ],
      ],
    );
  }

  Widget _line(BuildContext context, OpsItem item, ItemFacts? f, Color muted) {
    final t = OpsStrings.of(context);
    final detail = <String>[
      if (f?.weightKg != null) t.kg(f!.weightKg!),
      if (f != null && f.hasSize) t.size(f.length!, f.width!, f.height!),
    ];
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(item.name, style: context.text.bodyLarge),
              if (item.variantName != null)
                Text(item.variantName!, style: context.text.bodySmall?.copyWith(color: muted)),
              if (detail.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 2),
                  child: Directionality(
                    textDirection: TextDirection.ltr,
                    child: Align(
                      alignment: AlignmentDirectional.centerStart,
                      child: Text(detail.join('  ·  '), style: context.text.bodySmall?.copyWith(color: muted)),
                    ),
                  ),
                ),
              if (f != null && (f.isOversized || f.requiresAssembly))
                Padding(
                  padding: const EdgeInsets.only(top: AppSpace.xxs),
                  child: Wrap(
                    spacing: AppSpace.xxs,
                    runSpacing: AppSpace.xxs,
                    children: [
                      if (f.isOversized) StatusChip(label: t.oversizedChip, tone: StatusTone.warning, dense: true),
                      if (f.requiresAssembly) StatusChip(label: t.lineAssembly, tone: StatusTone.info, dense: true),
                    ],
                  ),
                ),
            ],
          ),
        ),
        const Gap(AppSpace.sm),
        // The multiplication sign and the digits read left to right in both languages.
        Directionality(
          textDirection: TextDirection.ltr,
          child: Text('× ${item.quantity}', style: context.text.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
        ),
      ],
    );
  }
}
