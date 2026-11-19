import 'dart:async';

import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/ops_widgets.dart';
import 'package:albahar_customer/ops/staff/staff_providers.dart';
import 'package:albahar_customer/ops/staff/staff_strings.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// The staff app's account menu: language, "Sound for new orders" (a short chime while the app is open; off until switched on, remembered on this
/// phone) and sign out. The driver app keeps its own, shorter menu.
class StaffAccountMenu extends ConsumerWidget {
  const StaffAccountMenu({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = OpsStrings.of(context);
    final sound = ref.watch(staffSoundProvider);
    return PopupMenuButton<String>(
      tooltip: t.menu,
      icon: const Icon(Icons.more_vert_rounded),
      onSelected: (v) {
        switch (v) {
          case 'language':
            unawaited(ref.read(localeControllerProvider.notifier).setLocale(Locale(t.ar ? 'en' : 'ar')));
          case 'sound':
            ref.read(staffSoundProvider.notifier).set(!sound);
          default:
            unawaited(confirmSignOut(context, ref));
        }
      },
      itemBuilder: (_) => [
        PopupMenuItem(
          value: 'language',
          child: ListTile(leading: const Icon(Icons.translate_rounded), title: Text(t.language)),
        ),
        CheckedPopupMenuItem(
          value: 'sound',
          checked: sound,
          child: Semantics(
            toggled: sound,
            label: '${t.soundForNewOrders}. ${t.soundForNewOrdersHint}',
            excludeSemantics: true,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(t.soundForNewOrders),
                Text(
                  t.soundForNewOrdersHint,
                  style: Theme.of(context).textTheme.bodySmall,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                ),
              ],
            ),
          ),
        ),
        PopupMenuItem(
          value: 'signout',
          child: ListTile(leading: const Icon(Icons.logout_rounded), title: Text(t.signOut)),
        ),
      ],
    );
  }
}
