import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/core/utils/phone.dart';
import 'package:flutter/foundation.dart';

/// Where the customer stands with "an alternative number the driver can call".
enum AlternatePhoneStatus {
  /// A valid Qatar mobile number, different from the main one.
  valid,

  /// They ticked "I don't have another number".
  declined,

  /// Nothing entered and not ticked yet: placing the order waits for one or the other.
  missing,

  /// Typed, but not a Qatar mobile number.
  invalid,

  /// Typed, but it is the account's main number.
  sameAsMain,
}

/// The customer's answer on the delivery step: a number, or "I don't have another number". Never both: typing unticks, ticking empties.
@immutable
class AlternatePhoneInput {
  const AlternatePhoneInput({this.text = '', this.declined = false});

  /// A saved number (E.164) shown as the customer will see it: the 8 national digits.
  factory AlternatePhoneInput.fromSaved(String? e164) {
    final normalized = e164 == null ? null : QatarPhone.toE164(e164);
    return AlternatePhoneInput(text: normalized == null ? '' : normalized.substring(4));
  }

  final String text;
  final bool declined;

  bool get hasText => text.trim().isNotEmpty;

  /// The typed number as `+974XXXXXXXX`, or null when it is not a valid Qatar mobile number (Arabic digits are fine).
  String? get e164 => hasText ? QatarPhone.toE164(text) : null;

  AlternatePhoneInput typed(String value) => AlternatePhoneInput(text: value);

  AlternatePhoneInput decline(bool value) => value ? const AlternatePhoneInput(declined: true) : const AlternatePhoneInput();

  AlternatePhoneStatus status({String? mainPhone}) {
    if (hasText) {
      final number = e164;
      if (number == null) return AlternatePhoneStatus.invalid;
      final main = mainPhone == null ? null : QatarPhone.toE164(mainPhone);
      return number == main ? AlternatePhoneStatus.sameAsMain : AlternatePhoneStatus.valid;
    }
    return declined ? AlternatePhoneStatus.declined : AlternatePhoneStatus.missing;
  }

  /// Whether placing may go ahead: a good number, or the tick.
  bool isDone({String? mainPhone}) {
    final s = status(mainPhone: mainPhone);
    return s == AlternatePhoneStatus.valid || s == AlternatePhoneStatus.declined;
  }

  /// The fields of the quote / order request, once the customer has answered: both keys are sent, so the server knows this app asks
  /// the question (an older app sends neither and is not held to it). Before an answer nothing is sent: the screen holds the
  /// place-order button itself, and the quote has no reason to complain about a question still open.
  JsonMap requestFields({String? mainPhone}) {
    final s = status(mainPhone: mainPhone);
    if (s != AlternatePhoneStatus.valid && s != AlternatePhoneStatus.declined) return const {};
    return {'alternate_phone': s == AlternatePhoneStatus.valid ? e164 : null, 'no_alternate_phone': s == AlternatePhoneStatus.declined};
  }

  @override
  bool operator ==(Object other) => other is AlternatePhoneInput && other.text == text && other.declined == declined;

  @override
  int get hashCode => Object.hash(text, declined);
}
