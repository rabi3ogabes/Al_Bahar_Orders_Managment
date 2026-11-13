import 'package:flutter/services.dart';

/// Arabic-Indic and Persian digits and the Arabic decimal marks become plain ASCII, so "٣٢٠٫٥٠" reads as "320.50".
String normalizeDigits(String input) {
  final out = StringBuffer();
  for (final r in input.runes) {
    if (r >= 0x0660 && r <= 0x0669) {
      out.writeCharCode(0x30 + r - 0x0660);
    } else if (r >= 0x06F0 && r <= 0x06F9) {
      out.writeCharCode(0x30 + r - 0x06F0);
    } else if (r == 0x066B || r == 0x066C || r == 0x060C || r == 0x2C) {
      out.write('.');
    } else {
      out.writeCharCode(r);
    }
  }
  return out.toString();
}

/// Parses a money amount typed in either digit set; null when it is not a plain number.
double? parseAmount(String input) {
  final t = normalizeDigits(input.trim());
  if (!RegExp(r'^\d+(\.\d{1,2})?$').hasMatch(t)) return null;
  return double.tryParse(t);
}

/// Keeps a money field to digits and one decimal point with at most two decimals (either digit set accepted).
class AmountInputFormatter extends TextInputFormatter {
  const AmountInputFormatter();

  @override
  TextEditingValue formatEditUpdate(TextEditingValue oldValue, TextEditingValue newValue) {
    final text = normalizeDigits(newValue.text).replaceAll(RegExp(r'[^0-9.]'), '');
    if (!RegExp(r'^\d*\.?\d{0,2}$').hasMatch(text)) return oldValue;
    if (text == newValue.text) return newValue;
    return TextEditingValue(
      text: text,
      selection: TextSelection.collapsed(offset: text.length),
    );
  }
}
