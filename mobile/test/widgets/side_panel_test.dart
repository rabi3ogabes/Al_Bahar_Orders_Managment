import 'dart:async';

import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/dirty_form_guard.dart';
import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fakes.dart';

/// A page with one button that opens a window built by [open].
Widget _page(Future<Object?> Function(BuildContext) open, {Locale locale = const Locale('en')}) => wrapApp(
  Builder(
    builder: (context) => Center(
      child: ElevatedButton(onPressed: () => unawaited(open(context)), child: const Text('open')),
    ),
  ),
  locale: locale,
);

void _size(WidgetTester t, double width, [double height = 800]) {
  t.view.physicalSize = Size(width, height);
  t.view.devicePixelRatio = 1;
  addTearDown(t.view.reset);
}

Future<Object?> _simple(BuildContext context) => showSidePanel<void>(
  context: context,
  title: 'Cart',
  builder: (_) => const Text('window body'),
  footer: AppButton(label: 'Checkout', onPressed: () {}),
);

void main() {
  group('showSidePanel', () {
    testWidgets('a phone gets a bottom sheet with a drag handle and a close button', (t) async {
      _size(t, 390);
      await t.pumpWidget(_page(_simple));
      await t.tap(find.text('open'));
      await t.pumpAndSettle();
      expect(find.byType(BottomSheet), findsOneWidget);
      expect(find.text('window body'), findsOneWidget);
      expect(find.text('Cart'), findsOneWidget);
      expect(find.widgetWithText(AppButton, 'Checkout'), findsOneWidget);
      expect(find.byTooltip('Close'), findsOneWidget);
    });

    testWidgets('a tablet gets a panel on the right edge, the page stays visible behind it', (t) async {
      _size(t, 1024);
      await t.pumpWidget(_page(_simple));
      await t.tap(find.text('open'));
      await t.pumpAndSettle();
      expect(find.byType(BottomSheet), findsNothing);
      final panel = t.getRect(find.ancestor(of: find.text('window body'), matching: find.byType(Material)).first);
      expect(panel.right, 1024, reason: 'the English panel sits against the right edge');
      expect(panel.width, lessThan(480));
      expect(find.text('open'), findsOneWidget, reason: 'the page is still there');
    });

    testWidgets('in Arabic the panel is on the left edge', (t) async {
      _size(t, 1024);
      await t.pumpWidget(_page(_simple, locale: const Locale('ar')));
      await t.tap(find.text('open'));
      await t.pumpAndSettle();
      final panel = t.getRect(find.ancestor(of: find.text('window body'), matching: find.byType(Material)).first);
      expect(panel.left, 0, reason: 'the Arabic panel sits against the left edge');
    });

    testWidgets('the pinned footer sits at the bottom of a panel, however short the content is', (t) async {
      _size(t, 1024, 700);
      await t.pumpWidget(
        _page(
          (c) => showSidePanel<void>(
            context: c,
            title: 'Short',
            scrollable: false,
            builder: (_) => const SidePanelLayout(footer: Text('pinned'), child: Text('tiny')),
          ),
        ),
      );
      await t.tap(find.text('open'));
      await t.pumpAndSettle();
      expect(t.getBottomLeft(find.text('pinned')).dy, greaterThan(640));
      expect(t.getTopLeft(find.text('tiny')).dy, lessThan(120));
    });

    testWidgets('on a phone the sheet is only as tall as its content', (t) async {
      _size(t, 390, 800);
      await t.pumpWidget(
        _page(
          (c) => showSidePanel<void>(
            context: c,
            title: 'Short',
            scrollable: false,
            builder: (_) => const SidePanelLayout(footer: Text('pinned'), child: Text('tiny')),
          ),
        ),
      );
      await t.tap(find.text('open'));
      await t.pumpAndSettle();
      expect(t.getTopLeft(find.byType(BottomSheet)).dy, greaterThan(400));
    });

    testWidgets('Back closes only the top window, then the one under it', (t) async {
      _size(t, 390);
      await t.pumpWidget(_page(_simple));
      await t.tap(find.text('open'));
      await t.pumpAndSettle();
      expect(find.text('window body'), findsOneWidget);
      // A confirmation on top of the window: Back closes the confirmation first.
      final context = t.element(find.text('window body'));
      unawaited(confirmAction(context, title: 'Remove it?', confirmLabel: 'Remove'));
      await t.pumpAndSettle();
      expect(find.text('Remove it?'), findsOneWidget);
      await t.binding.handlePopRoute();
      await t.pumpAndSettle();
      expect(find.text('Remove it?'), findsNothing);
      expect(find.text('window body'), findsOneWidget, reason: 'the window is still open');
      await t.binding.handlePopRoute();
      await t.pumpAndSettle();
      expect(find.text('window body'), findsNothing);
      expect(find.text('open'), findsOneWidget);
    });

    testWidgets('Back, the scrim and Esc close a panel on a tablet', (t) async {
      _size(t, 1024);
      await t.pumpWidget(_page(_simple));
      for (final close in <Future<void> Function()>[
        () => t.binding.handlePopRoute(),
        () async {
          await t.tapAt(const Offset(40, 300));
        },
        () async {
          await t.sendKeyEvent(LogicalKeyboardKey.escape);
        },
        () async {
          await t.tap(find.byTooltip('Close'));
        },
      ]) {
        await t.tap(find.text('open'));
        await t.pumpAndSettle();
        expect(find.text('window body'), findsOneWidget);
        await close();
        await t.pumpAndSettle();
        expect(find.text('window body'), findsNothing);
      }
    });

    testWidgets('a message shown inside a window appears inside it, not behind it', (t) async {
      _size(t, 390);
      late BuildContext inside;
      await t.pumpWidget(
        _page(
          (c) => showSidePanel<void>(
            context: c,
            title: 'Form',
            builder: (ctx) {
              inside = ctx;
              return const Text('window body');
            },
          ),
        ),
      );
      await t.tap(find.text('open'));
      await t.pumpAndSettle();
      showInfoSnack(inside, 'Saved');
      await t.pump();
      expect(find.text('Saved'), findsOneWidget);
      expect(find.byType(SnackBar), findsNothing, reason: 'a snack bar would hide behind the window');
      await t.pump(const Duration(seconds: 4));
      expect(find.text('Saved'), findsNothing);
    });

    testWidgets('the keyboard lifts the pinned footer instead of covering it', (t) async {
      _size(t, 390, 800);
      await t.pumpWidget(
        _page(
          (c) => showSidePanel<void>(
            context: c,
            title: 'Form',
            scrollable: false,
            isForm: true,
            builder: (_) => const SidePanelLayout(
              footer: Text('pinned'),
              child: TextField(decoration: InputDecoration(labelText: 'name')),
            ),
          ),
        ),
      );
      await t.tap(find.text('open'));
      await t.pumpAndSettle();
      final before = t.getBottomLeft(find.text('pinned')).dy;
      t.view.viewInsets = const FakeViewPadding(bottom: 300);
      addTearDown(t.view.resetViewInsets);
      await t.pumpAndSettle();
      expect(t.getBottomLeft(find.text('pinned')).dy, lessThanOrEqualTo(before - 250));
    });
  });

  group('forms in a window', () {
    Future<Object?> form(BuildContext c) => showSidePanel<String>(
      context: c,
      title: 'Edit',
      scrollable: false,
      isForm: true,
      builder: (_) => const _GuardedForm(),
    );

    testWidgets('closing a dirty form asks "Discard changes?" and focuses the safe choice', (t) async {
      _size(t, 390);
      await t.pumpWidget(_page(form));
      await t.tap(find.text('open'));
      await t.pumpAndSettle();
      // Nothing typed yet: closes at once.
      await t.tap(find.byTooltip('Close'));
      await t.pumpAndSettle();
      expect(find.text('Edit'), findsNothing);

      await t.tap(find.text('open'));
      await t.pumpAndSettle();
      await t.enterText(find.byType(TextField), 'typed');
      await t.pump();
      await t.tap(find.byTooltip('Close'));
      await t.pumpAndSettle();
      expect(find.text('Discard changes?'), findsOneWidget);
      final keep = t.widget<TextButton>(find.widgetWithText(TextButton, 'Keep editing'));
      expect(keep.autofocus, isTrue, reason: 'Enter must never throw typed text away');
      await t.tap(find.text('Keep editing'));
      await t.pumpAndSettle();
      expect(find.byType(TextField), findsOneWidget, reason: 'still editing');
      expect(find.text('typed'), findsOneWidget);

      // Back asks too, and Discard really closes.
      await t.binding.handlePopRoute();
      await t.pumpAndSettle();
      expect(find.text('Discard changes?'), findsOneWidget);
      await t.tap(find.text('Discard'));
      await t.pumpAndSettle();
      expect(find.byType(TextField), findsNothing);
    });

    testWidgets('a phone form cannot be swiped away with typed text in it', (t) async {
      _size(t, 390);
      await t.pumpWidget(_page(form));
      await t.tap(find.text('open'));
      await t.pumpAndSettle();
      await t.enterText(find.byType(TextField), 'typed');
      await t.fling(find.text('Edit'), const Offset(0, 500), 1500);
      await t.pumpAndSettle();
      expect(find.byType(TextField), findsOneWidget, reason: 'a drag bypasses PopScope, so forms switch it off');
    });

    testWidgets('tapping the scrim of a dirty tablet form asks first', (t) async {
      _size(t, 1024);
      await t.pumpWidget(_page(form));
      await t.tap(find.text('open'));
      await t.pumpAndSettle();
      await t.enterText(find.byType(TextField), 'typed');
      await t.pump();
      await t.tapAt(const Offset(40, 300));
      await t.pumpAndSettle();
      expect(find.text('Discard changes?'), findsOneWidget);
    });
  });

  group('PanelStep', () {
    testWidgets('swaps a window\'s content for a step with a back arrow', (t) async {
      _size(t, 390);
      await t.pumpWidget(_page((c) => showSidePanel<void>(context: c, title: 'Order', builder: (_) => const _Steps())));
      await t.tap(find.text('open'));
      await t.pumpAndSettle();
      expect(find.text('details'), findsOneWidget);
      await t.tap(find.text('go'));
      await t.pumpAndSettle();
      expect(find.text('Cancel order'), findsOneWidget);
      expect(find.text('details'), findsNothing);
      expect(find.byType(BottomSheet), findsOneWidget, reason: 'one window, never a window on a window');
      await t.tap(find.byTooltip('Back'));
      await t.pumpAndSettle();
      expect(find.text('details'), findsOneWidget);
    });
  });
}

class _GuardedForm extends StatefulWidget {
  const _GuardedForm();

  @override
  State<_GuardedForm> createState() => _GuardedFormState();
}

class _GuardedFormState extends State<_GuardedForm> {
  final _c = TextEditingController();

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => DirtyFormGuard(
    controllers: [_c],
    isDirty: () => _c.text.isNotEmpty,
    child: SidePanelLayout(
      footer: AppButton(label: 'Save', onPressed: () => Navigator.of(context).pop(_c.text)),
      child: TextField(controller: _c),
    ),
  );
}

class _Steps extends StatefulWidget {
  const _Steps();

  @override
  State<_Steps> createState() => _StepsState();
}

class _StepsState extends State<_Steps> {
  bool _step = false;

  @override
  Widget build(BuildContext context) => _step
      ? PanelStep(
          title: 'Cancel order',
          onBack: () => setState(() => _step = false),
          child: const Center(child: Text('reasons')),
        )
      : Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text('details'),
            TextButton(onPressed: () => setState(() => _step = true), child: const Text('go')),
          ],
        );
}
