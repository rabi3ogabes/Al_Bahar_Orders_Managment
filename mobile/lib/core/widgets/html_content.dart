import 'package:flutter/material.dart';
import 'package:flutter_widget_from_html_core/flutter_widget_from_html_core.dart';
import 'package:url_launcher/url_launcher.dart';

import '../theme/tokens.dart';

/// Renders server HTML (product descriptions, CMS pages) natively —
/// no WebView, respects theme typography and text direction.
class HtmlContent extends StatelessWidget {
  const HtmlContent(this.html, {super.key, this.style});

  final String html;
  final TextStyle? style;

  @override
  Widget build(BuildContext context) {
    return HtmlWidget(
      html,
      textStyle: style ?? context.text.bodyMedium?.copyWith(height: 1.65),
      onTapUrl: (url) async {
        final uri = Uri.tryParse(url);
        if (uri == null) return false;
        return launchUrl(uri, mode: LaunchMode.externalApplication);
      },
      customStylesBuilder: (element) {
        if (element.localName == 'a') {
          final hex = context.palette.brass.toARGB32().toRadixString(16).padLeft(8, '0').substring(2);
          return {'color': '#$hex'};
        }
        return null;
      },
    );
  }
}
