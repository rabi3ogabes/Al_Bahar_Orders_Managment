import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../l10n/l10n.dart';
import '../network/api_exception.dart';
import '../providers.dart';
import '../theme/tokens.dart';
import 'app_button.dart';
import 'side_panel.dart';

/// Calm, centred empty state with an optional action.
class EmptyState extends StatelessWidget {
  const EmptyState({
    super.key,
    required this.icon,
    required this.title,
    this.message,
    this.actionLabel,
    this.onAction,
    this.compact = false,
  });

  final IconData icon;
  final String title;
  final String? message;
  final String? actionLabel;
  final VoidCallback? onAction;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final palette = context.palette;
    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(AppSpace.xl),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 420),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: compact ? 64 : 88,
                height: compact ? 64 : 88,
                decoration: BoxDecoration(color: palette.brassTint, shape: BoxShape.circle),
                child: Icon(icon, size: compact ? 28 : 36, color: palette.brass),
              ),
              const Gap(AppSpace.lg),
              Text(title, textAlign: TextAlign.center, style: context.text.headlineSmall),
              if (message != null) ...[
                const Gap(AppSpace.xs),
                Text(
                  message!,
                  textAlign: TextAlign.center,
                  style: context.text.bodyMedium?.copyWith(color: palette.muted),
                ),
              ],
              if (actionLabel != null && onAction != null) ...[
                const Gap(AppSpace.lg),
                AppButton(label: actionLabel!, onPressed: onAction, expand: false),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

/// Error state that understands [ApiException] (offline, 404, 5xx…).
class ErrorState extends StatelessWidget {
  const ErrorState({super.key, required this.error, this.onRetry, this.compact = false});

  final Object? error;
  final VoidCallback? onRetry;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final api = error is ApiException ? error as ApiException : null;
    final (icon, title) = switch (api?.kind) {
      ApiErrorKind.offline => (Icons.wifi_off_rounded, l10n.errorOffline),
      ApiErrorKind.timeout => (Icons.hourglass_empty_rounded, l10n.errorTimeout),
      ApiErrorKind.notFound => (Icons.search_off_rounded, l10n.notFoundTitle),
      ApiErrorKind.forbidden => (Icons.lock_outline_rounded, l10n.errorForbidden),
      _ => (Icons.error_outline_rounded, l10n.errorTitle),
    };
    final message = api?.kind == ApiErrorKind.offline || api?.kind == ApiErrorKind.timeout
        ? null
        : describeError(l10n, error);
    return EmptyState(
      icon: icon,
      title: title,
      message: [
        if (message != null && message != title) message,
        if (api?.requestId != null && api!.kind == ApiErrorKind.server) l10n.requestReference(api.requestId!),
      ].join('\n').ifEmpty,
      actionLabel: onRetry == null ? null : l10n.retry,
      onAction: onRetry,
      compact: compact,
    );
  }
}

extension on String {
  String? get ifEmpty => isEmpty ? null : this;
}

/// Thin banner shown at the top of the shell while offline.
class OfflineBanner extends ConsumerWidget {
  const OfflineBanner({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final online = ref.watch(onlineProvider);
    return AnimatedSize(
      duration: AppDurations.medium,
      curve: Curves.easeOut,
      child: online
          ? const SizedBox(width: double.infinity)
          : Semantics(
              liveRegion: true,
              child: Container(
                width: double.infinity,
                color: context.colors.inverseSurface,
                padding: const EdgeInsetsDirectional.symmetric(horizontal: AppSpace.md, vertical: AppSpace.xs),
                child: SafeArea(
                  bottom: false,
                  child: Row(
                    children: [
                      Icon(Icons.cloud_off_rounded, size: 16, color: context.colors.onInverseSurface),
                      const Gap(AppSpace.xs),
                      Expanded(
                        child: Text(
                          context.l10n.offlineBanner,
                          style: context.text.labelMedium?.copyWith(color: context.colors.onInverseSurface),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
    );
  }
}

/// Renders an [AsyncValue] with consistent loading / error handling.
class AsyncValueView<T> extends StatelessWidget {
  const AsyncValueView({
    super.key,
    required this.value,
    required this.data,
    this.loading,
    this.onRetry,
    this.skipLoadingOnRefresh = true,
  });

  final AsyncValue<T> value;
  final Widget Function(T data) data;
  final Widget? loading;
  final VoidCallback? onRetry;
  final bool skipLoadingOnRefresh;

  @override
  Widget build(BuildContext context) {
    return value.when(
      skipLoadingOnRefresh: skipLoadingOnRefresh,
      skipLoadingOnReload: true,
      data: data,
      loading: () => loading ?? const Center(child: CircularProgressIndicator()),
      error: (e, _) => ErrorState(error: e, onRetry: onRetry),
    );
  }
}

/// Shows a snackbar for an error using the localised description.
void showErrorSnack(BuildContext context, Object error) {
  final panel = PanelToastScope.maybeOf(context);
  if (panel != null) {
    panel.show(describeError(context.l10n, error));
    return;
  }
  final messenger = ScaffoldMessenger.maybeOf(context);
  messenger?.hideCurrentSnackBar();
  messenger?.showSnackBar(SnackBar(content: Text(describeError(context.l10n, error))));
}

void showInfoSnack(BuildContext context, String message, {SnackBarAction? action}) {
  // Inside a side panel a snackbar would sit behind the panel: show the message in the panel instead.
  final panel = PanelToastScope.maybeOf(context);
  if (panel != null) {
    panel.show(message, action: action);
    return;
  }
  final messenger = ScaffoldMessenger.maybeOf(context);
  messenger?.hideCurrentSnackBar();
  messenger?.showSnackBar(SnackBar(content: Text(message), action: action));
}
