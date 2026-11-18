# Customer app intro screens: editable words (contract)

Shared contract between the dashboard, `GET /api/v1/app-config` and the customer Flutter app. Additive: an app that does not know the new key ignores it.

## What the owner edits

Dashboard → Organization → **Settings → General & brand → Intro screens** (same page and Save button as the "Designed by" line; permission `settings.view` to see, `settings.edit` to change).
For each of the **3 slides** (Doha skyline, map of Qatar, majlis) a **title** and a **text**, in **English and Arabic**: 12 fields.

- An **empty field means "use the app's default text"**, which is shown as the placeholder (grey). An admin can never produce a blank slide.
- Limits: title up to **40** characters, text up to **120** (characters, not bytes). The field stops typing at the limit, a counter shows `n / 40`, and the server checks again.
- Plain text, one line: HTML tags are removed, new lines / tabs / no-break spaces become one space, the ends are trimmed. Control characters and direction-override characters are refused with a message.
- A live preview of the slide's text card (English and Arabic, light and dark) sits under each language's fields and follows every keystroke.
- **Reset to default** (per slide) empties that slide's four texts and saves at once (the other fields of the page are untouched); the toast has **Undo**, which puts back exactly what the fields held.
- Every changed text is an ordinary settings change: audited (`settings.updated`, key `customer_app.intro_<slide>_<title|body>_<en|ar>`).

The shipped words (placeholders, and the app's fallback) are in `backend/config/platform.php` → `customer_app.intro_defaults` and in the app's `lib/l10n/app_en.arb` / `app_ar.arb` (`onboardingTitle1..3`, `onboardingBody1..3`); a backend test keeps the two copies identical and inside the limits.

## API

`GET /api/v1/app-config` (public, no sign-in) gains `intro`, right after `designed_by`; every other key is unchanged:

```json
{ "data": {
  "designed_by": { "en": "Designed by Athar Qatar", "ar": "تصميم أثر قطر" },
  "intro": { "slides": [
    { "title": { "en": "Hala! Homes", "ar": null }, "body": { "en": null, "ar": null } },
    { "title": { "en": null, "ar": null }, "body": { "en": null, "ar": null } },
    { "title": { "en": null, "ar": null }, "body": { "en": null, "ar": null } }
  ] },
  "returns_enabled": true, "alternate_phone_enabled": true, "ops": { "status_style": "buttons" }
} }
```

- Always exactly 3 slides, in order (Doha skyline, map, majlis); each text is the admin's text **or `null`**, and `null` means "use the app's own text for that language". The server never sends the default text itself.
- Texts are already plain and within the limits (the server re-cleans whatever is stored, so seeded or imported values are safe too): tags removed, one line, at most 40 / 120 characters; a stored value with a control character, a wrong type or invalid UTF-8 is served as `null`.
- A language never falls back to the other language's custom text: Arabic shows Arabic text or the Arabic default, English shows English text or the English default.

### Caching
The answer is `Cache-Control: public, max-age=60`. The dashboard drops the server-side settings cache on every save, so the very next request already carries the change; the only delay a phone can see is that one minute of HTTP cache. The app asks at launch (while the splash shows) and again whenever it comes back to the foreground, so a change reaches a phone within about a minute of it opening or resuming the app.

## Client rules (Flutter customer app)

- **First paint is instant and never waits for the network**: the intro uses the last `app-config` the phone kept on its storage (works offline) and otherwise its own ARB text; the request runs in the background with a short timeout and any failure is silent.
- When the answer arrives while a slide is on screen, the words **cross-fade in place** (about 0.3 s, instant with "reduce motion"); nothing else on the page moves, because the picture and the words are anchored from the top of the page and the words are the last thing on it.
- Switching language (the corner switch of the intro) changes the words at once, with the screen's existing language fade.
- Parsing is defensive: `null`, wrong types, HTML-like strings, control characters and over-long text never crash or overflow. The app clamps to 40 / 120 characters and shows plain text. Art, order, paging and the art's accessibility labels are unchanged; the slide title is a header for screen readers and the text is read as written.
