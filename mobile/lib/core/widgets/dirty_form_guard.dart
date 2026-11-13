import 'package:flutter/material.dart';

import '../l10n/l10n.dart';

/// Asks before throwing away typed input when the user leaves a form with the
/// back button or the system back gesture.
///
/// Set [enabled] to false while submitting and after a successful save so the
/// screen can close normally.
class DirtyFormGuard extends StatelessWidget {
  const DirtyFormGuard({
    super.key,
    required this.controllers,
    required this.isDirty,
    required this.child,
    this.enabled = true,
  });

  /// Text fields to watch.
  final List<Listenable> controllers;

  /// Whether the form holds changes that would be lost.
  final bool Function() isDirty;

  final bool enabled;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: Listenable.merge(controllers),
      builder: (context, _) {
        final dirty = enabled && isDirty();
        return PopScope(
          canPop: !dirty,
          onPopInvokedWithResult: (didPop, _) async {
            if (didPop) return;
            final leave = await confirmDiscard(context);
            if (leave && context.mounted) Navigator.of(context).pop();
          },
          child: child,
        );
      },
    );
  }
}

/// "Discard changes?" dialog. Resolves to true when the user chooses to leave.
Future<bool> confirmDiscard(BuildContext context) async {
  final l10n = context.l10n;
  final result = await showDialog<bool>(
    context: context,
    builder: (ctx) => AlertDialog(
      title: Text(l10n.discardChangesTitle),
      content: Text(l10n.discardChangesMessage),
      actions: [
        TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: Text(l10n.keepEditing)),
        FilledButton(onPressed: () => Navigator.of(ctx).pop(true), child: Text(l10n.discard)),
      ],
    ),
  );
  return result ?? false;
}
