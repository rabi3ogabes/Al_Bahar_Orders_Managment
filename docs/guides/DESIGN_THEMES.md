# Design themes

A **design** is a base theme (`brand_themes.kind = 'base'`) that bundles colours with a layout style and look tokens. The admin switches the whole web store and mobile app with one press at Admin > Content > Store design. Event looks (Ramadan, Eid...) only recolour the current design; they never change the layout.

## API contract: `GET /api/v1/theme`

Public, cached 60 s (`brand_theme:active:v3`). Besides the existing fields (`key`, `kind`, `name`, `colors`, `images`, `banner`, `valid_until`, `version`, `footer`):

| Field | Source | Meaning |
|---|---|---|
| `layout` | default base theme | `classic` or `fresh` |
| `tokens` | default base theme | full object, defaults applied |
| `design` | default base theme | `{key, name_en, name_ar, name}` |

`colors`, `images`, `banner` come from the active look (forced > live event > default), so an event recolours the design.

Tokens (all optional in storage; the API always returns all six):

| Token | Values | Default |
|---|---|---|
| `radius` | `md` `lg` `xl` | `lg` |
| `card` | `flat` `soft` `outlined` | `soft` |
| `header` | `standard` `brand` | `standard` |
| `nav` | `classic` `center_action` | `classic` |
| `background` | `plain` `gradient` | `plain` |
| `density` | `comfortable` `compact` | `comfortable` |

Unknown or invalid values fall back to the default (`BrandTheme::normalizeTokens`).

### Preview

`GET /api/v1/theme?design=<key>` returns the payload as if that base design were live (colours, layout, tokens, design all from it). Read-only and public; unknown keys or non-base keys return the normal payload. The admin "Preview store" button opens `<storefront>/?preview_design=<key>`; the storefront passes that key through as `?design=`. The storefront address is `STOREFRONT_URL` (`config('platform.storefront_url')`), falling back to `APP_URL`.

## Seeded designs

`qatar-maroon` (Al Bahar Classic, default), `fresh-market`, `ocean-breeze`, `royal-navy`. `ThemeService::seedDesigns()` is idempotent and runs from a migration, the `theme:seed-presets` command and the Appearance / Store design page mounts. An existing design is given layout and tokens only if it has none, so admin edits are never overwritten.

## Switching

`ThemeService::applyDesign($design)` makes it the default, clears the cache and writes audit `cms.design_applied` (old and new key). `force()` for event looks is unchanged. The admin page needs `cms.view` to see and `cms.edit` to switch; it confirms first and offers Undo in the toast.

## Add a new design

1. Add an entry to `ThemeService::designs()`: unique `key`, `name_en`, `name_ar`, `layout` (`classic`|`fresh`), six `colors` (hex) and `tokens`.
2. Run `php artisan theme:seed-presets` (or open the Store design page). Existing installs get the new row; nothing else is touched.
3. The mini mockup (`resources/views/components/admin/design-mock.blade.php`) draws it from the colours, layout and tokens automatically.
4. A new layout name needs: the value in `BrandTheme::LAYOUTS`, the `brand_themes_layout_check` constraint, a `design_admin.layout_*` label in EN and AR, and support in the storefront and the Flutter app.
5. Add a test next to `tests/Feature/Admin/DesignThemesTest.php`.
