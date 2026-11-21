import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/catalog/domain/share_text.dart';
import 'package:albahar_customer/features/catalog/presentation/state/catalog_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:share_plus/share_plus.dart';
import 'package:url_launcher/url_launcher.dart';

/// Opens a link outside the app (WhatsApp). A provider so tests can stand in for the platform.
final externalLinkProvider = Provider<Future<bool> Function(Uri uri)>(
  (ref) =>
      (uri) => launchUrl(uri, mode: LaunchMode.externalApplication),
);

/// "Share on WhatsApp": WhatsApp's own share link with [text] filled in (the customer picks who). If WhatsApp cannot be opened the customer is
/// told in a sentence and the system share sheet opens instead, so sharing never dead-ends.
Future<void> shareOnWhatsApp(BuildContext context, WidgetRef ref, String text, {String? subject}) async {
  var opened = false;
  try {
    opened = await ref.read(externalLinkProvider)(whatsAppShareUri(text));
  } catch (_) {
    opened = false;
  }
  if (opened || !context.mounted) return;
  showInfoSnack(context, context.l10n.shareWhatsAppMissing);
  try {
    await ref.read(shareActionProvider)(ShareParams(text: text, subject: subject));
  } catch (e) {
    if (context.mounted) showErrorSnack(context, e);
  }
}

/// The WhatsApp-first share button: one outlined button with the app's own wording; the system share sheet stays one tap away on the page's share icon.
class WhatsAppShareButton extends ConsumerWidget {
  const WhatsAppShareButton({super.key, required this.text, this.label, this.subject});

  final String text;
  final String? label;
  final String? subject;

  @override
  Widget build(BuildContext context, WidgetRef ref) => Align(
    alignment: AlignmentDirectional.centerStart,
    child: TextButton.icon(
      key: const ValueKey('share-whatsapp'),
      style: TextButton.styleFrom(minimumSize: const Size(48, 48)),
      onPressed: () => unawaited(shareOnWhatsApp(context, ref, text, subject: subject)),
      icon: const Icon(Icons.chat_bubble_outline_rounded, size: 20),
      label: Text(label ?? context.l10n.shareOnWhatsApp),
    ),
  );
}
