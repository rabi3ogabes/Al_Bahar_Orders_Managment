import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/network/api_exception.dart';

/// What to say when sending a return request failed. The server's own wording for a refusal is short ("These items can't be returned."),
/// so the app explains the likely reasons and the way out in its own plain sentences.
String returnFailureMessage(AppLocalizations l10n, Object error) {
  if (error is ApiException) {
    switch (error.kind) {
      case ApiErrorKind.forbidden:
        return l10n.returnsUnavailable;
      case ApiErrorKind.validation:
        if (error.errors.keys.any((k) => k == 'items' || k.startsWith('items.'))) return l10n.returnErrItems;
        if (error.errors.containsKey('reason_code')) return l10n.returnErrReason;
        if (error.errors.isNotEmpty) return describeError(l10n, error);
        // A refusal with no field named: the items are not (or no longer) returnable.
        return l10n.returnErrNotAllowed;
      case ApiErrorKind.unauthorized:
      case ApiErrorKind.notFound:
      case ApiErrorKind.conflict:
      case ApiErrorKind.rateLimited:
      case ApiErrorKind.server:
      case ApiErrorKind.timeout:
      case ApiErrorKind.offline:
      case ApiErrorKind.cancelled:
      case ApiErrorKind.unknown:
        return describeError(l10n, error);
    }
  }
  return describeError(l10n, error);
}
