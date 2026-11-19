import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_staff_models.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/staff/staff_providers.dart';
import 'package:albahar_customer/ops/staff/staff_strings.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// The branch name in the header. For a person who works in more than one branch it is a button with a small arrow that opens the list of
/// their branches (the choice is remembered on this phone); for everybody else it is plain text, exactly as before.
class BranchTitle extends ConsumerWidget {
  const BranchTitle({super.key, required this.overview});
  final StaffOverview? overview;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = OpsStrings.of(context);
    final o = overview;
    final name = o?.branchName ?? t.staffApp;
    final text = Text(name, overflow: TextOverflow.ellipsis);
    if (o == null || o.branches.length < 2) return text;
    return Semantics(
      button: true,
      label: t.branchPicker(name),
      excludeSemantics: true,
      child: InkWell(
        key: const ValueKey('branch-picker'),
        borderRadius: BorderRadius.circular(AppRadius.sm),
        onTap: () => showBranchPicker(context, branches: o.branches, selectedId: o.branchId),
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: kMinTouchTarget),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Flexible(child: text),
              const Icon(Icons.arrow_drop_down_rounded),
            ],
          ),
        ),
      ),
    );
  }
}

/// The person's branches in a window; a tap on one switches the whole screen to it and remembers it on this phone.
Future<void> showBranchPicker(
  BuildContext context, {
  required List<StaffBranchRef> branches,
  required String selectedId,
}) {
  final t = OpsStrings.of(context);
  return showSidePanel<void>(
    context: context,
    title: t.chooseBranch,
    scrollable: false,
    builder: (_) => _BranchList(branches: branches, selectedId: selectedId),
  );
}

class _BranchList extends ConsumerWidget {
  const _BranchList({required this.branches, required this.selectedId});
  final List<StaffBranchRef> branches;
  final String selectedId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final p = context.palette;
    return ListView(
      shrinkWrap: true,
      children: [
        for (final b in branches)
          ListTile(
            key: ValueKey('branch-${b.id}'),
            minTileHeight: 60,
            selected: b.id == selectedId,
            leading: Icon(Icons.storefront_rounded, color: b.id == selectedId ? context.colors.primary : p.muted),
            title: Text(b.name),
            trailing: b.id == selectedId ? Icon(Icons.check_rounded, color: context.colors.primary) : null,
            onTap: () {
              if (b.id != selectedId) {
                // The filters of the other branch's lists mean nothing here.
                ref.read(staffTodoFilterProvider.notifier).reset();
                ref.read(staffBranchChoiceProvider.notifier).choose(b.id);
              }
              Navigator.of(context).pop();
            },
          ),
      ],
    );
  }
}
