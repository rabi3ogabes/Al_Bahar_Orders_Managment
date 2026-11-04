/// Qatar phone helpers. The server is authoritative (it normalises to E.164);
/// the client only does light validation for instant feedback.
abstract final class QatarPhone {
  static const _arabicDigits = '٠١٢٣٤٥٦٧٨٩';
  static const _persianDigits = '۰۱۲۳۴۵۶۷۸۹';

  /// Converts Arabic-Indic / Persian digits to ASCII and strips separators.
  static String normalizeDigits(String input) {
    final buffer = StringBuffer();
    for (final rune in input.runes) {
      final ch = String.fromCharCode(rune);
      final a = _arabicDigits.indexOf(ch);
      final p = _persianDigits.indexOf(ch);
      if (a >= 0) {
        buffer.write(a);
      } else if (p >= 0) {
        buffer.write(p);
      } else if (RegExp(r'[0-9+]').hasMatch(ch)) {
        buffer.write(ch);
      }
    }
    return buffer.toString();
  }

  /// Returns `+974XXXXXXXX` or null when the number is clearly invalid.
  static String? toE164(String input) {
    var digits = normalizeDigits(input);
    if (digits.startsWith('+')) digits = digits.substring(1);
    if (digits.startsWith('00')) digits = digits.substring(2);
    if (digits.startsWith('974') && digits.length == 11) digits = digits.substring(3);
    if (digits.length != 8) return null;
    // Qatar mobile numbers start with 3, 5, 6 or 7.
    if (!RegExp(r'^[3567]\d{7}$').hasMatch(digits)) return null;
    return '+974$digits';
  }

  static bool isValid(String input) => toE164(input) != null;

  /// `+97455123456` → `+974 5512 3456`.
  static String display(String e164) {
    final m = RegExp(r'^\+974(\d{4})(\d{4})$').firstMatch(e164);
    return m == null ? e164 : '+974 ${m.group(1)} ${m.group(2)}';
  }
}
