import 'package:albahar_customer/core/utils/json.dart';
import 'package:flutter/foundation.dart';

/// How many intro slides there are (the art is fixed: Doha skyline, map of Qatar, majlis).
const int introSlideCount = 3;

/// What fits one slide under its picture: a title up to 40 characters, a text up to 120 (the dashboard enforces the same limits).
const int introTitleMax = 40;
const int introBodyMax = 120;

final RegExp _blank = RegExp(r'[\s\u0085]+');
final RegExp _tag = RegExp(r'</?[A-Za-z!?][^>]*>');
final RegExp _control = RegExp('[\\u0000-\\u001F\\u007F-\\u009F\\u202A-\\u202E\\u2066-\\u2069]');

/// A text from the dashboard turned into something safe to show: plain, one line, at most [max] characters. Anything that is
/// not usable (not a string, empty, markup only, a control character) is null, which means "use the app's own words".
///
/// The server already serves clean text; this is the second wall for a hand-edited cache, an old server or a proxy that rewrote
/// the answer. White space is folded into single spaces, tags are removed, and the length is cut by characters (not UTF-16 units,
/// so an emoji counts once like it does on the dashboard).
String? cleanIntroText(Object? value, {required int max}) {
  if (value is! String) return null;
  var text = value.replaceAll(_blank, ' ').trim();
  if (text.isEmpty || _control.hasMatch(text)) return null;
  text = text.replaceAll(_tag, '').replaceAll(_blank, ' ').trim();
  if (text.isEmpty) return null;
  final runes = text.runes;
  if (runes.length > max) text = String.fromCharCodes(runes.take(max)).trimRight();
  return text.isEmpty ? null : text;
}

/// The dashboard's words for one intro slide, in both languages. Each text is null when the dashboard has none, and the screen
/// then shows the app's own text (from the language files) for that language. A language never borrows the other one's words.
@immutable
class IntroSlideText {
  const IntroSlideText({this.titleEn, this.titleAr, this.bodyEn, this.bodyAr});

  /// Reads one entry of `intro.slides` (`{title: {en, ar}, body: {en, ar}}`); anything else reads as "nothing set".
  factory IntroSlideText.fromJson(Object? json) {
    final map = asMap(json);
    final title = asMap(map['title']);
    final body = asMap(map['body']);
    return IntroSlideText(
      titleEn: cleanIntroText(title['en'], max: introTitleMax),
      titleAr: cleanIntroText(title['ar'], max: introTitleMax),
      bodyEn: cleanIntroText(body['en'], max: introBodyMax),
      bodyAr: cleanIntroText(body['ar'], max: introBodyMax),
    );
  }

  /// No dashboard text at all: the app's own words.
  static const none = IntroSlideText();

  final String? titleEn;
  final String? titleAr;
  final String? bodyEn;
  final String? bodyAr;

  /// The title for [localeCode] (`ar` or anything else = English), or null to use the app's own.
  String? title(String localeCode) => localeCode == 'ar' ? titleAr : titleEn;

  /// The text for [localeCode], or null to use the app's own.
  String? body(String localeCode) => localeCode == 'ar' ? bodyAr : bodyEn;

  JsonMap toJson() => {
    'title': {'en': titleEn, 'ar': titleAr},
    'body': {'en': bodyEn, 'ar': bodyAr},
  };

  @override
  bool operator ==(Object other) =>
      other is IntroSlideText &&
      other.titleEn == titleEn &&
      other.titleAr == titleAr &&
      other.bodyEn == bodyEn &&
      other.bodyAr == bodyAr;

  @override
  int get hashCode => Object.hash(titleEn, titleAr, bodyEn, bodyAr);
}
