import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/quick_view_panel.dart';
import 'package:albahar_customer/features/scan/data/scan_source.dart';
import 'package:albahar_customer/features/scan/presentation/state/scan_controller.dart';
import 'package:albahar_customer/features/scan/presentation/widgets/manual_code_window.dart';
import 'package:albahar_customer/features/scan/presentation/widgets/scan_overlay.dart';
import 'package:albahar_customer/features/scan/presentation/widgets/scan_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// "Scan to add": the camera fills the screen, a barcode (or QR code) in view is looked up and put in the cart at once, and the
/// camera keeps running so the next product can be scanned straight away. Nothing here navigates away on its own: the result
/// slides up over the camera, and the cart / search / sign-in only open when the person taps for them.
class ScanScreen extends ConsumerStatefulWidget {
  const ScanScreen({super.key});

  @override
  ConsumerState<ScanScreen> createState() => _ScanScreenState();
}

class _ScanScreenState extends ConsumerState<ScanScreen> with WidgetsBindingObserver {
  StreamSubscription<String>? _codes;
  late final ScanSource _source = ref.read(scanSourceProvider);

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _codes = _source.codes.listen(_onCode);
    // Opens the camera (and asks for permission the first time) once the first frame has the preview attached.
    WidgetsBinding.instance.addPostFrameCallback((_) => unawaited(_source.start()));
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    unawaited(_codes?.cancel());
    unawaited(_source.stop());
    super.dispose();
  }

  /// The camera is released in the background and taken again on return (which is also how a permission switched on in
  /// Settings is picked up).
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    switch (state) {
      case AppLifecycleState.resumed:
        unawaited(_source.start());
      case AppLifecycleState.inactive ||
          AppLifecycleState.hidden ||
          AppLifecycleState.paused ||
          AppLifecycleState.detached:
        unawaited(_source.stop());
    }
  }

  void _onCode(String code) {
    // A window (option picker, typing window, cart) is open above the camera: what the camera sees now is not meant for the cart.
    if (!mounted || !(ModalRoute.of(context)?.isCurrent ?? true)) return;
    unawaited(ref.read(scanControllerProvider.notifier).submit(code));
  }

  void _close() {
    if (context.canPop()) {
      context.pop();
    } else {
      context.go('/home');
    }
  }

  Future<void> _enterCode() async {
    final code = await showManualCodeWindow(context);
    if (code == null || !mounted) return;
    await ref.read(scanControllerProvider.notifier).submit(code, manual: true);
  }

  Future<void> _chooseOption() async {
    final product = ref.read(scanControllerProvider).problem?.product;
    if (product == null) return;
    await showQuickView(context, product);
    if (mounted) ref.read(scanControllerProvider.notifier).clearChoice();
  }

  Future<void> _undo() async {
    final removed = await ref.read(scanControllerProvider.notifier).undo();
    if (removed && mounted) showInfoSnack(context, context.l10n.scanRemoved);
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final controller = ref.read(scanControllerProvider.notifier);

    // A product with several options opens the option window at once instead of guessing.
    ref.listen(
      scanControllerProvider.select((s) => s.problem?.kind == ScanProblemKind.needsChoice ? s.problem : null),
      (prev, next) {
        if (next != null && prev != next) unawaited(_chooseOption());
      },
    );

    final looking = ref.watch(scanControllerProvider.select((s) => s.looking));
    final flash = ref.watch(scanControllerProvider.select((s) => s.entry?.id ?? 0));

    return Scaffold(
      backgroundColor: Colors.black,
      body: Stack(
        fit: StackFit.expand,
        children: [
          // The live picture sits under everything; it is plain black until the camera answers.
          _source.buildPreview(context),
          ValueListenableBuilder<ScanCameraState>(
            valueListenable: _source.camera,
            builder: (context, camera, _) => switch (camera) {
              ScanCameraState.ready => ScanOverlay(flash: flash, looking: looking),
              ScanCameraState.starting => Stack(
                fit: StackFit.expand,
                children: [
                  ScanOverlay(flash: flash, looking: looking),
                  Center(child: _Starting(label: l10n.scanCameraStarting)),
                ],
              ),
              ScanCameraState.permissionDenied || ScanCameraState.unavailable => _CameraProblem(
                state: camera,
                onSettings: _source.openSettings,
                onSearch: () => unawaited(context.push('/search')),
              ),
            },
          ),
          Positioned(
            top: 0,
            left: 0,
            right: 0,
            child: SafeArea(
              bottom: false,
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: AppSpace.xs, vertical: AppSpace.xxs),
                child: Row(
                  children: [
                    // Always visible: the way out never depends on the camera.
                    RoundIconButton(
                      icon: Icons.close_rounded,
                      tooltip: l10n.scanClose,
                      background: Colors.black.withValues(alpha: 0.5),
                      color: Colors.white,
                      onPressed: _close,
                    ),
                    Expanded(
                      child: Text(
                        l10n.scanTitle,
                        textAlign: TextAlign.center,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: context.text.titleMedium?.copyWith(color: Colors.white, fontWeight: FontWeight.w600),
                      ),
                    ),
                    _TorchButton(source: _source),
                  ],
                ),
              ),
            ),
          ),
          Positioned(
            left: 0,
            right: 0,
            bottom: 0,
            child: Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 560),
                child: ScanPanel(
                  actions: ScanPanelActions(
                    keepScanning: controller.dismiss,
                    viewCart: () => context.go('/cart'),
                    enterCode: () => unawaited(_enterCode()),
                    searchByName: () => unawaited(context.push('/search')),
                    chooseOption: () => unawaited(_chooseOption()),
                    signIn: () => unawaited(context.push('/login?from=${Uri.encodeComponent('/scan')}')),
                    retry: () => unawaited(controller.retry()),
                    undo: () => unawaited(_undo()),
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _Starting extends StatelessWidget {
  const _Starting({required this.label});
  final String label;

  @override
  Widget build(BuildContext context) => Semantics(
    liveRegion: true,
    label: label,
    child: const SizedBox(
      width: 28,
      height: 28,
      child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white),
    ),
  );
}

class _TorchButton extends StatelessWidget {
  const _TorchButton({required this.source});
  final ScanSource source;

  @override
  Widget build(BuildContext context) => ValueListenableBuilder<ScanTorch>(
    valueListenable: source.torch,
    builder: (context, torch, _) {
      final l10n = context.l10n;
      final on = torch == ScanTorch.on;
      if (torch == ScanTorch.unavailable) {
        // Keeps the title centred and the layout still; nothing to press on a camera without a light.
        return const SizedBox(width: kMinTouchTarget, height: kMinTouchTarget);
      }
      return RoundIconButton(
        icon: on ? Icons.flash_on_rounded : Icons.flash_off_rounded,
        tooltip: on ? l10n.scanTorchOff : l10n.scanTorchOn,
        background: on ? Colors.white : Colors.black.withValues(alpha: 0.5),
        color: on ? Colors.black : Colors.white,
        onPressed: () => unawaited(source.toggleTorch()),
      );
    },
  );
}

/// Shown over the black preview when there is no camera to look through: why, and what to do instead.
class _CameraProblem extends StatelessWidget {
  const _CameraProblem({required this.state, required this.onSettings, required this.onSearch});

  final ScanCameraState state;
  final Future<void> Function() onSettings;
  final VoidCallback onSearch;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final denied = state == ScanCameraState.permissionDenied;
    return SafeArea(
      child: Padding(
        // Leaves the panel at the bottom and the top bar free.
        padding: EdgeInsets.fromLTRB(
          AppSpace.lg,
          kMinTouchTarget + AppSpace.md,
          AppSpace.lg,
          MediaQuery.sizeOf(context).height * 0.26,
        ),
        child: Center(
          child: SingleChildScrollView(
            child: Semantics(
              liveRegion: true,
              container: true,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(Icons.no_photography_outlined, size: 44, color: Colors.white70),
                  const Gap(AppSpace.sm),
                  Text(
                    denied ? l10n.scanPermissionTitle : l10n.scanCameraUnavailableTitle,
                    textAlign: TextAlign.center,
                    style: context.text.titleLarge?.copyWith(color: Colors.white),
                  ),
                  const Gap(AppSpace.xs),
                  Text(
                    denied ? l10n.scanPermissionMessage : l10n.scanCameraUnavailableMessage,
                    textAlign: TextAlign.center,
                    style: context.text.bodyMedium?.copyWith(color: Colors.white70),
                  ),
                  const Gap(AppSpace.md),
                  if (denied)
                    AppButton(
                      label: l10n.openSettings,
                      icon: Icons.settings_outlined,
                      expand: false,
                      onPressed: () => unawaited(onSettings()),
                    )
                  else
                    AppButton(
                      label: l10n.scanSearchByName,
                      icon: Icons.search_rounded,
                      expand: false,
                      onPressed: onSearch,
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
