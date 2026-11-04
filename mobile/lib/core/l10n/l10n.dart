import 'package:flutter/widgets.dart';

import '../../l10n/generated/app_localizations.dart';
import '../network/api_exception.dart';

export '../../l10n/generated/app_localizations.dart';

extension L10nContextX on BuildContext {
  AppLocalizations get l10n => AppLocalizations.of(this);
  String get localeCode => Localizations.localeOf(this).languageCode;
}

/// Human-friendly message for any error. Server messages are already
/// localised via `Accept-Language`, so they take precedence for 4xx.
String describeError(AppLocalizations l10n, Object? error) {
  if (error is ApiException) {
    switch (error.kind) {
      case ApiErrorKind.offline:
        return l10n.errorOffline;
      case ApiErrorKind.timeout:
        return l10n.errorTimeout;
      case ApiErrorKind.server:
        return l10n.errorServer;
      case ApiErrorKind.rateLimited:
        final seconds = error.retryAfter?.inSeconds;
        return seconds != null ? l10n.errorRateLimited(seconds) : (error.message ?? l10n.errorGeneric);
      case ApiErrorKind.unauthorized:
        return error.message ?? l10n.errorSessionExpired;
      case ApiErrorKind.forbidden:
        return error.message ?? l10n.errorForbidden;
      case ApiErrorKind.notFound:
        return error.message ?? l10n.errorNotFound;
      case ApiErrorKind.cancelled:
      case ApiErrorKind.conflict:
      case ApiErrorKind.validation:
      case ApiErrorKind.unknown:
        return error.message ?? l10n.errorGeneric;
    }
  }
  return l10n.errorGeneric;
}
