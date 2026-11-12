import 'dart:async';
import 'dart:io' show Platform;

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:url_launcher/url_launcher.dart';

/// Which store app this build is: `customer`, `driver` or `staff` (set once at start-up), and its build number.
/// Sent on every API call so the server can refuse a build that the admin has forced to update.
abstract final class AppIdentity {
  static String app = 'customer';
  static int build = 0;
  static String version = '';

  static String get platform => defaultTargetPlatform == TargetPlatform.iOS ? 'ios' : 'android';

  static Future<void> init(String appId) async {
    app = appId;
    try {
      final info = await PackageInfo.fromPlatform();
      build = int.tryParse(info.buildNumber) ?? 0;
      version = info.version;
    } catch (_) {
      // Unknown build: the server skips enforcement when X-App-Build is 0.
    }
  }
}

/// Adds `X-App`, `X-App-Platform`, `X-App-Build` to every request, and turns HTTP 426 into the update screen.
class AppIdentityInterceptor extends Interceptor {
  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    options.headers['X-App'] = AppIdentity.app;
    options.headers['X-App-Platform'] = AppIdentity.platform;
    if (AppIdentity.build > 0) options.headers['X-App-Build'] = AppIdentity.build;
    handler.next(options);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    if (err.response?.statusCode == 426) {
      final body = err.response?.data;
      final update = body is Map ? body['update'] : null;
      updateRequired.value = UpdateInfo.fromJson(
        update is Map ? Map<String, dynamic>.from(update) : const {},
        forced: true,
      );
    }
    handler.next(err);
  }
}

class UpdateInfo {
  const UpdateInfo({required this.status, this.latestVersion, this.storeUrl, this.messageEn = '', this.messageAr = ''});

  factory UpdateInfo.fromJson(Map<String, dynamic> j, {bool forced = false}) {
    final m = j['message'] is Map ? Map<String, dynamic>.from(j['message'] as Map) : const <String, dynamic>{};
    return UpdateInfo(
      status: forced ? 'required' : (j['status']?.toString() ?? 'ok'),
      latestVersion: j['latest_version']?.toString(),
      storeUrl: j['store_url']?.toString(),
      messageEn: m['en']?.toString() ?? '',
      messageAr: m['ar']?.toString() ?? '',
    );
  }

  final String status; // ok | available | required
  final String? latestVersion;
  final String? storeUrl;
  final String messageEn;
  final String messageAr;

  bool get required => status == 'required';
  bool get available => status == 'available';
}

/// The forced update, set by the update check or by a 426 answer from any API call. Once set it stays until the app is replaced.
final ValueNotifier<UpdateInfo?> updateRequired = ValueNotifier<UpdateInfo?>(null);

/// Wraps the whole app: checks for updates at start-up, when the app returns to the foreground and every 30 minutes.
/// A required update covers the app with a screen it cannot leave; an optional one shows a card that can be dismissed.
class UpdateGate extends StatefulWidget {
  const UpdateGate({super.key, required this.child, required this.baseUrl});
  final Widget child;
  final String baseUrl;

  @override
  State<UpdateGate> createState() => _UpdateGateState();
}

class _UpdateGateState extends State<UpdateGate> with WidgetsBindingObserver {
  late final Dio _dio = Dio(
    BaseOptions(
      baseUrl: widget.baseUrl,
      connectTimeout: const Duration(seconds: 8),
      receiveTimeout: const Duration(seconds: 8),
    ),
  );
  Timer? _timer;
  UpdateInfo? _optional;
  bool _dismissed = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    updateRequired.addListener(_rebuild);
    unawaited(_check());
    _timer = Timer.periodic(const Duration(minutes: 30), (_) => unawaited(_check()));
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    updateRequired.removeListener(_rebuild);
    _timer?.cancel();
    _dio.close();
    super.dispose();
  }

  void _rebuild() {
    if (mounted) setState(() {});
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) unawaited(_check());
  }

  Future<void> _check() async {
    if (AppIdentity.build <= 0) return;
    try {
      final r = await _dio.get<dynamic>(
        '/app-update',
        queryParameters: {'app': AppIdentity.app, 'platform': AppIdentity.platform, 'build': AppIdentity.build},
      );
      final data = r.data is Map ? (r.data as Map)['data'] : null;
      if (data is! Map) return;
      final info = UpdateInfo.fromJson(Map<String, dynamic>.from(data));
      if (info.required) {
        updateRequired.value = info;
      } else if (mounted) {
        setState(() => _optional = info.available ? info : null);
      }
    } catch (_) {
      // Offline or server hiccup: never block the app because the check failed.
    }
  }

  @override
  Widget build(BuildContext context) {
    final forced = updateRequired.value;
    return Stack(
      children: [
        widget.child,
        if (_optional != null && !_dismissed && forced == null)
          PositionedDirectional(
            start: 16,
            end: 16,
            bottom: 16 + MediaQuery.paddingOf(context).bottom,
            child: _OptionalUpdateCard(info: _optional!, onLater: () => setState(() => _dismissed = true)),
          ),
        if (forced != null) Positioned.fill(child: ForceUpdateScreen(info: forced)),
      ],
    );
  }
}

Future<void> openStore(String? url) async {
  if (url == null || url.isEmpty) return;
  final uri = Uri.tryParse(url);
  if (uri == null) return;
  try {
    await launchUrl(uri, mode: LaunchMode.externalApplication);
  } catch (_) {}
}

String _text(BuildContext context, {required String en, required String ar}) =>
    Localizations.localeOf(context).languageCode == 'ar' ? ar : en;

/// Full-screen, no way back: Back does nothing, the only action is "Update now".
class ForceUpdateScreen extends StatelessWidget {
  const ForceUpdateScreen({super.key, required this.info});
  final UpdateInfo info;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final isAr = Localizations.localeOf(context).languageCode == 'ar';
    final message = (isAr ? info.messageAr : info.messageEn).trim();
    return PopScope(
      canPop: false,
      child: Material(
        color: scheme.surface,
        child: Container(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
              colors: [scheme.primary.withValues(alpha: 0.10), scheme.surface],
            ),
          ),
          child: SafeArea(
            child: Center(
              child: SingleChildScrollView(
                padding: const EdgeInsets.all(32),
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 420),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Container(
                        width: 96,
                        height: 96,
                        decoration: BoxDecoration(
                          color: scheme.primary,
                          shape: BoxShape.circle,
                          boxShadow: [
                            BoxShadow(
                              color: scheme.primary.withValues(alpha: 0.25),
                              blurRadius: 30,
                              offset: const Offset(0, 12),
                            ),
                          ],
                        ),
                        child: Icon(Icons.system_update_rounded, size: 46, color: scheme.onPrimary),
                      ),
                      const SizedBox(height: 28),
                      Text(
                        _text(context, en: 'Update required', ar: 'التحديث مطلوب'),
                        textAlign: TextAlign.center,
                        style: Theme.of(context).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w700),
                      ),
                      const SizedBox(height: 12),
                      Text(
                        message.isNotEmpty
                            ? message
                            : _text(
                                context,
                                en: 'A new version is available. Please update to continue.',
                                ar: 'يتوفر إصدار جديد. يرجى التحديث للمتابعة.',
                              ),
                        textAlign: TextAlign.center,
                        style: Theme.of(context).textTheme.bodyLarge
                            ?.copyWith(color: scheme.onSurfaceVariant, height: 1.5),
                      ),
                      const SizedBox(height: 32),
                      SizedBox(
                        width: double.infinity,
                        height: 54,
                        child: FilledButton.icon(
                          onPressed: info.storeUrl == null || info.storeUrl!.isEmpty
                              ? null
                              : () => openStore(info.storeUrl),
                          icon: const Icon(Icons.storefront_rounded),
                          label: Text(
                            Platform.isIOS
                                ? _text(context, en: 'Open the App Store', ar: 'افتح App Store')
                                : _text(context, en: 'Open Google Play', ar: 'افتح Google Play'),
                          ),
                        ),
                      ),
                      if (info.latestVersion != null && info.latestVersion!.isNotEmpty) ...[
                        const SizedBox(height: 16),
                        Text(
                          '${_text(context, en: 'Newest version', ar: 'أحدث إصدار')} ${info.latestVersion}',
                          style: Theme.of(context).textTheme.bodySmall?.copyWith(color: scheme.onSurfaceVariant),
                        ),
                      ],
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _OptionalUpdateCard extends StatelessWidget {
  const _OptionalUpdateCard({required this.info, required this.onLater});
  final UpdateInfo info;
  final VoidCallback onLater;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Material(
      elevation: 8,
      borderRadius: BorderRadius.circular(20),
      color: scheme.surfaceContainerHigh,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Row(
          children: [
            Icon(Icons.system_update_rounded, color: scheme.primary),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                _text(context, en: 'A new version is available', ar: 'يتوفر إصدار جديد'),
                style: Theme.of(context).textTheme.titleSmall,
              ),
            ),
            TextButton(
              onPressed: onLater,
              child: Text(_text(context, en: 'Later', ar: 'لاحقاً')),
            ),
            FilledButton(
              onPressed: () => openStore(info.storeUrl),
              child: Text(_text(context, en: 'Update', ar: 'تحديث')),
            ),
          ],
        ),
      ),
    );
  }
}
