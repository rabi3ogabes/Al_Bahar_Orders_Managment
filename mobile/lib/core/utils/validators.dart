import 'package:flutter/services.dart';

/// Client-side checks that mirror the API rules so the customer gets instant,
/// field-level feedback. The server stays authoritative.
abstract final class Validators {
  static final _email = RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$');

  static bool isEmail(String value) => _email.hasMatch(value.trim());

  /// Mirrors `Password::min(8)->letters()->numbers()`.
  static bool isStrongPassword(String value) =>
      value.length >= 8 && RegExp(r'\p{L}', unicode: true).hasMatch(value) && RegExp(r'\d').hasMatch(value);
}

/// Turns Arabic-Indic and Persian digits (and the Arabic decimal separator)
/// into ASCII so numeric fields work with any keyboard.
class AsciiDigitsFormatter extends TextInputFormatter {
  const AsciiDigitsFormatter();

  static const _arabic = '٠١٢٣٤٥٦٧٨٩';
  static const _persian = '۰۱۲۳۴۵۶۷۸۹';

  static String convert(String input) {
    final out = StringBuffer();
    for (final rune in input.runes) {
      final ch = String.fromCharCode(rune);
      final a = _arabic.indexOf(ch);
      final p = _persian.indexOf(ch);
      if (a >= 0) {
        out.write(a);
      } else if (p >= 0) {
        out.write(p);
      } else if (ch == '٫' || ch == '،') {
        out.write('.');
      } else {
        out.write(ch);
      }
    }
    return out.toString();
  }

  @override
  TextEditingValue formatEditUpdate(TextEditingValue oldValue, TextEditingValue newValue) {
    final text = convert(newValue.text);
    return text == newValue.text ? newValue : newValue.copyWith(text: text);
  }
}
