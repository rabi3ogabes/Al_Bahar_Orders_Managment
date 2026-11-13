import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../l10n/l10n.dart';
import '../theme/tokens.dart';

/// Width from which a secondary flow opens as a side panel instead of a bottom sheet.
const double kSidePanelBreakpoint = 720;

/// Opens a secondary flow (a quick view, details, a cart, an edit form) in a window instead of replacing the page,
/// so the person keeps their place and one Back closes it.
///
/// - Phones (< 720 px wide): a tall bottom sheet with a drag handle. Swipe down, scrim tap or Back closes it.
/// - Tablets, web and landscape: a panel that slides in from the END edge (right in English, left in Arabic)
///   with the page still visible behind a light scrim. Scrim tap, the close button, Esc or Back closes it.
///
/// Both are routes, so the system Back button / swipe closes the top one only, and focus returns to the caller.
/// [builder] draws the body; it scrolls by default (set [scrollable] to false when the body is its own list).
/// [footer] stays pinned under the body for the primary action ("Pay now", "Save"). Use [barrierDismissible] = false
/// for a form with unsaved edits and guard it with a PopScope inside [builder].
Future<T?> showSidePanel<T>({
  required BuildContext context,
  required String title,
  required WidgetBuilder builder,
  String? subtitle,
  Widget? footer,
  List<Widget> actions = const [],
  double width = 440,
  bool scrollable = true,
  bool barrierDismissible = true,
}) {
  final wide = MediaQuery.sizeOf(context).width >= kSidePanelBreakpoint;
  Widget frame(BuildContext ctx, {required bool sheet}) => _PanelFrame(
    title: title,
    subtitle: subtitle,
    footer: footer,
    actions: actions,
    scrollable: scrollable,
    sheet: sheet,
    body: builder,
  );

  if (!wide) {
    return showModalBottomSheet<T>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      showDragHandle: true,
      isDismissible: barrierDismissible,
      enableDrag: barrierDismissible,
      constraints: BoxConstraints(maxHeight: MediaQuery.sizeOf(context).height * 0.92),
      builder: (ctx) => frame(ctx, sheet: true),
    );
  }

  final label = MaterialLocalizations.of(context).modalBarrierDismissLabel;
  return showGeneralDialog<T>(
    context: context,
    barrierDismissible: barrierDismissible,
    barrierLabel: label,
    barrierColor: Colors.black.withValues(alpha: 0.32),
    transitionDuration: const Duration(milliseconds: 300),
    pageBuilder: (ctx, _, _) {
      final screen = MediaQuery.sizeOf(ctx).width;
      return SafeArea(
        child: Align(
          alignment: AlignmentDirectional.centerEnd,
          child: SizedBox(
            width: math.min(width, screen - 48),
            height: double.infinity,
            child: Material(
              color: ctx.colors.surface,
              elevation: 12,
              clipBehavior: Clip.antiAlias,
              borderRadius: const BorderRadiusDirectional.horizontal(start: Radius.circular(24)),
              child: frame(ctx, sheet: false),
            ),
          ),
        ),
      );
    },
    transitionBuilder: (ctx, animation, _, child) {
      final rtl = Directionality.of(ctx) == TextDirection.rtl;
      final slide = Tween<Offset>(
        begin: Offset(rtl ? -1 : 1, 0),
        end: Offset.zero,
      ).animate(CurvedAnimation(parent: animation, curve: Curves.easeOutCubic, reverseCurve: Curves.easeInCubic));
      final reduce = MediaQuery.disableAnimationsOf(ctx);
      return reduce
          ? FadeTransition(opacity: animation, child: child)
          : SlideTransition(position: slide, child: child);
    },
  );
}

class _PanelFrame extends StatelessWidget {
  const _PanelFrame({
    required this.title,
    required this.subtitle,
    required this.footer,
    required this.actions,
    required this.scrollable,
    required this.sheet,
    required this.body,
  });

  final String title;
  final String? subtitle;
  final Widget? footer;
  final List<Widget> actions;
  final bool scrollable;
  final bool sheet;
  final WidgetBuilder body;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    final content = Builder(builder: body);
    return Column(
      mainAxisSize: sheet ? MainAxisSize.min : MainAxisSize.max,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: EdgeInsetsDirectional.fromSTEB(20, sheet ? 0 : 12, 8, 8),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Semantics(header: true, child: Text(title, style: text.titleLarge)),
                      if (subtitle != null)
                        Padding(
                          padding: const EdgeInsets.only(top: 2),
                          child: Text(subtitle!, style: text.bodySmall),
                        ),
                    ],
                  ),
                ),
              ),
              ...actions,
              IconButton(
                tooltip: context.l10n.close,
                constraints: const BoxConstraints(minWidth: 48, minHeight: 48),
                icon: const Icon(Icons.close_rounded),
                onPressed: () => Navigator.of(context).maybePop(),
              ),
            ],
          ),
        ),
        const Divider(height: 1),
        if (sheet)
          Flexible(child: scrollable ? SingleChildScrollView(padding: _bodyPad, child: content) : content)
        else
          Expanded(child: scrollable ? SingleChildScrollView(padding: _bodyPad, child: content) : content),
        if (footer != null) ...[
          const Divider(height: 1),
          SafeArea(
            top: false,
            minimum: const EdgeInsets.only(bottom: 4),
            child: Padding(padding: const EdgeInsets.fromLTRB(20, 12, 20, 12), child: footer),
          ),
        ],
      ],
    );
  }
}

const EdgeInsets _bodyPad = EdgeInsets.fromLTRB(20, 16, 20, 20);
