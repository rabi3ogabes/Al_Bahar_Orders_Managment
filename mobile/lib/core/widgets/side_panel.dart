import 'dart:async';
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
/// [builder] draws the body; it scrolls by default (set [scrollable] to false when the body is its own list or lays out
/// its own pinned footer with [SidePanelLayout]). [footer] stays pinned under the body for the primary action
/// ("Pay now", "Save").
///
/// Set [isForm] for anything the person types into. Closing then always goes through the route's pop check (the close
/// button, the scrim, Back and Esc), so a `PopScope` / `DirtyFormGuard` inside [builder] can ask "Discard changes?".
/// Swipe-to-dismiss on the phone sheet is switched off for forms, because a sheet drag bypasses `PopScope`.
/// [barrierDismissible] = false additionally ignores scrim taps.
///
/// Rules this helper follows so every window behaves the same: the keyboard lifts the footer, Esc closes, focus stays
/// inside, the route is announced to screen readers, reduced motion fades instead of sliding.
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
  bool isForm = false,
}) {
  final wide = MediaQuery.sizeOf(context).width >= kSidePanelBreakpoint;
  Widget frame({required bool sheet}) => _PanelFrame(
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
      useRootNavigator: true,
      showDragHandle: true,
      isDismissible: barrierDismissible,
      enableDrag: barrierDismissible && !isForm,
      constraints: BoxConstraints(maxHeight: MediaQuery.sizeOf(context).height * 0.92),
      builder: (ctx) => frame(sheet: true),
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
            child: Semantics(
              scopesRoute: true,
              namesRoute: true,
              explicitChildNodes: true,
              label: title,
              child: Material(
                color: ctx.colors.surface,
                elevation: 12,
                clipBehavior: Clip.antiAlias,
                borderRadius: const BorderRadiusDirectional.horizontal(start: Radius.circular(24)),
                child: frame(sheet: false),
              ),
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
      return reduce ? FadeTransition(opacity: animation, child: child) : SlideTransition(position: slide, child: child);
    },
  );
}

/// Body that scrolls above a pinned footer, for panels whose footer depends on their own state (a live "Show 24
/// results", a Save button that follows the form). Use it as the whole body of a `showSidePanel(scrollable: false)`
/// call; it also works as the body of a full-screen route, so the page and the panel share one layout.
class SidePanelLayout extends StatelessWidget {
  const SidePanelLayout({super.key, required this.child, this.footer, this.padding = _bodyPad});

  final Widget child;

  /// Pinned under the body (primary action first). Null for a read-only panel.
  final Widget? footer;

  final EdgeInsetsGeometry padding;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        // A wide panel / a page body is a tight height: the footer sits at the bottom. A phone sheet is loose: the
        // sheet is as tall as its content, up to 92 % of the screen.
        final fill = constraints.hasTightHeight;
        final scroll = SingleChildScrollView(padding: padding, child: child);
        return Column(
          mainAxisSize: fill ? MainAxisSize.max : MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (fill) Expanded(child: scroll) else Flexible(child: scroll),
            if (footer != null) PanelFooter(child: footer!),
          ],
        );
      },
    );
  }
}

/// One step inside a window ("Cancel order", "Request a return"): a back arrow and a title above [child].
/// A window never opens another window on top of itself; it swaps its content for a step like this one instead.
class PanelStep extends StatelessWidget {
  const PanelStep({super.key, required this.title, required this.onBack, required this.child});
  final String title;
  final VoidCallback onBack;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final header = Padding(
      padding: const EdgeInsetsDirectional.fromSTEB(8, 4, 16, 4),
      child: Row(
        children: [
          IconButton(
            tooltip: MaterialLocalizations.of(context).backButtonTooltip,
            constraints: const BoxConstraints(minWidth: kMinTouchTarget, minHeight: kMinTouchTarget),
            icon: const BackButtonIcon(),
            onPressed: onBack,
          ),
          Expanded(
            child: Semantics(header: true, child: Text(title, style: context.text.titleMedium)),
          ),
        ],
      ),
    );
    return LayoutBuilder(
      builder: (context, constraints) {
        final fill = constraints.hasTightHeight;
        return Column(
          mainAxisSize: fill ? MainAxisSize.max : MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            header,
            const Divider(height: 1),
            if (fill) Expanded(child: child) else Flexible(child: child),
          ],
        );
      },
    );
  }
}

/// The pinned action area: a hairline above it, the safe area below it, room for a thumb.
class PanelFooter extends StatelessWidget {
  const PanelFooter({super.key, required this.child});
  final Widget child;

  @override
  Widget build(BuildContext context) => DecoratedBox(
    decoration: BoxDecoration(
      color: context.colors.surface,
      border: Border(top: BorderSide(color: context.palette.line)),
    ),
    child: SafeArea(
      top: false,
      minimum: const EdgeInsets.only(bottom: 4),
      child: Padding(padding: const EdgeInsets.fromLTRB(20, 12, 20, 12), child: child),
    ),
  );
}

class _PanelFrame extends StatefulWidget {
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
  State<_PanelFrame> createState() => _PanelFrameState();
}

class _PanelFrameState extends State<_PanelFrame> {
  final _toast = PanelToastController();

  @override
  void dispose() {
    _toast.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    final sheet = widget.sheet;
    final content = Builder(builder: widget.body);
    final reduce = MediaQuery.disableAnimationsOf(context);
    return Actions(
      // Esc closes the window (through the route's pop check, so a dirty form still asks first).
      actions: {
        DismissIntent: CallbackAction<DismissIntent>(
          onInvoke: (_) {
            Navigator.of(context).maybePop();
            return null;
          },
        ),
      },
      child: FocusScope(
        autofocus: true,
        child: PanelToastScope(
          controller: _toast,
          child: AnimatedPadding(
            // The keyboard lifts the footer and the focused field instead of covering them.
            duration: reduce ? Duration.zero : const Duration(milliseconds: 120),
            padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
            child: Stack(
              children: [
                Column(
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
                                  if (widget.title.isNotEmpty)
                                    Semantics(header: true, child: Text(widget.title, style: text.titleLarge)),
                                  if (widget.subtitle != null)
                                    Padding(
                                      padding: const EdgeInsets.only(top: 2),
                                      child: Text(widget.subtitle!, style: text.bodySmall),
                                    ),
                                ],
                              ),
                            ),
                          ),
                          ...widget.actions,
                          IconButton(
                            tooltip: context.l10n.close,
                            constraints: const BoxConstraints(minWidth: kMinTouchTarget, minHeight: kMinTouchTarget),
                            icon: const Icon(Icons.close_rounded),
                            onPressed: () => Navigator.of(context).maybePop(),
                          ),
                        ],
                      ),
                    ),
                    const Divider(height: 1),
                    if (sheet)
                      Flexible(
                        child: widget.scrollable ? SingleChildScrollView(padding: _bodyPad, child: content) : content,
                      )
                    else
                      Expanded(
                        child: widget.scrollable ? SingleChildScrollView(padding: _bodyPad, child: content) : content,
                      ),
                    if (widget.footer != null) PanelFooter(child: widget.footer!),
                  ],
                ),
                PositionedDirectional(top: 64, start: 12, end: 12, child: _PanelToastView(controller: _toast)),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// A snackbar cannot be seen above a modal route (it lives on the page underneath). Inside a panel, the shared
/// `showInfoSnack` / `showErrorSnack` helpers therefore show their message as a small toast just under the panel's
/// header instead, where the keyboard and the pinned action never hide it.
class PanelToastController extends ChangeNotifier {
  String? message;
  SnackBarAction? action;
  Timer? _timer;

  void show(String text, {SnackBarAction? action}) {
    _timer?.cancel();
    message = text;
    this.action = action;
    notifyListeners();
    _timer = Timer(Duration(seconds: action == null ? 3 : 5), hide);
  }

  void hide() {
    _timer?.cancel();
    if (message == null) return;
    message = null;
    action = null;
    notifyListeners();
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }
}

/// Makes the nearest panel's toast reachable from anywhere inside it.
class PanelToastScope extends InheritedWidget {
  const PanelToastScope({super.key, required this.controller, required super.child});
  final PanelToastController controller;

  /// The toast of the panel [context] is inside, or null on a normal page.
  static PanelToastController? maybeOf(BuildContext context) =>
      context.getInheritedWidgetOfExactType<PanelToastScope>()?.controller;

  @override
  bool updateShouldNotify(PanelToastScope oldWidget) => controller != oldWidget.controller;
}

class _PanelToastView extends StatelessWidget {
  const _PanelToastView({required this.controller});
  final PanelToastController controller;

  @override
  Widget build(BuildContext context) => ListenableBuilder(
    listenable: controller,
    builder: (context, _) {
      final message = controller.message;
      if (message == null) return const SizedBox.shrink();
      final scheme = context.colors;
      final action = controller.action;
      return Semantics(
        liveRegion: true,
        child: Material(
          color: scheme.inverseSurface,
          elevation: 6,
          borderRadius: BorderRadius.circular(AppRadius.input),
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: kMinTouchTarget),
            child: Padding(
              padding: const EdgeInsetsDirectional.fromSTEB(16, 4, 8, 4),
              child: Row(
                children: [
                  Expanded(
                    child: Text(message, style: context.text.bodyMedium?.copyWith(color: scheme.onInverseSurface)),
                  ),
                  if (action != null)
                    TextButton(
                      onPressed: () {
                        controller.hide();
                        action.onPressed();
                      },
                      child: Text(action.label, style: TextStyle(color: scheme.inversePrimary)),
                    ),
                ],
              ),
            ),
          ),
        ),
      );
    },
  );
}

const EdgeInsets _bodyPad = EdgeInsets.fromLTRB(20, 16, 20, 20);
