# Design themes

A **design** is a base theme (`brand_themes.kind = 'base'`) that bundles colours with a layout style and look tokens. The admin switches the whole web store and mobile app with one press at Admin > Content > Store design. Event looks (Ramadan, Eid...) only recolour the current design; they never change the layout.

## API contract: `GET /api/v1/theme`

Public, cached 60 s (`brand_theme:active:v3`). Besides the existing fields (`key`, `kind`, `name`, `colors`, `images`, `banner`, `valid_until`, `version`, `footer`):

| Field | Source | Meaning |
|---|---|---|
| `layout` | default base theme | `classic` or `fresh` |
| `tokens` | default base theme | full object, defaults applied |
| `design` | default base theme | `{key, name_en, name_ar, name}` |
| `footer` | `AppFooterService` | the customer app's bottom menu (Appearance > App footer) |
| `backdrop` | `BackdropService` | the picture behind every page: `{style, scene, image_url, fit, opacity, color, tint}` (see below) |

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

## Page background (`backdrop`)

One calm picture behind every page of the web store and the customer app: by default the Doha skyline in one colour at low opacity. The admin can pick any of the six built-in Qatar artworks (the models, below), their own picture, or nothing. The full contract (rendering rules, tolerance, why the footer was black) is `docs/api/PAGE_BACKDROP.md`; this is where it lives in the code.

| Key | Values | Default |
|---|---|---|
| `style` | `skyline` (a built-in artwork, see `scene`) `image` `none` | `skyline` |
| `scene` | which built-in artwork: `doha` `dhow` `souq` `dunes` `museum` `arabesque`; always present, unknown or missing means `doha`; `style` stays `skyline` for all six | `doha` |
| `image_url` | absolute URL, only when `style = image` and the file exists, else `null` | `null` |
| `fit` | `bottom` `cover` (pictures only) | `bottom` |
| `opacity` | float `0.02` to `0.50` | `0.08` |
| `color` | `#RRGGBB` page colour for light mode, or `null` = the theme's own background | `null` |
| `tint` | `#RRGGBB` colour of the built-in artwork (all six), or `null` = the theme's primary | `null` |

- **Where to edit**: Admin > Content > Page background (`/admin/appearance/background`, `App\Livewire\Admin\Content\BackdropIndex`). `cms.view` opens it, `cms.edit` changes it. Deferred save: a live preview (phone and web, light and dark) follows every control, nothing is published until Save. Audit: `cms.backdrop_updated` (old and new values include the `scene`), `cms.backdrop_image_removed`, `cms.backdrop_reset`.
- **The gallery** is one pick-one group of eight cards: the six models, then *My picture* and *None*. Each model card shows its own drawing in the current artwork colour on the page colour, with its name in the dashboard language and, in small type, in the other one. A model sets `style = skyline` and `scene` together (one round trip); *My picture* and *None* only set the style, so the artwork chosen last is remembered. The *Artwork colour* control belongs to all six models and is hidden for *My picture* and *None*; *How the picture sits* (fit) is for *My picture* only.
- **Code**: `App\Domain\Cms\Services\BackdropService` (setting `app.backdrop`, strict allow-lists, unknown fields dropped, an unknown `scene` becomes `doha` instead of an error, a setting saved before scenes existed reads as `doha`, `payload()` is what `ThemeService::compose()` publishes, so `/api/v1/theme` and the `?design=` preview both carry it). Saving, removing a picture and "Back to default" clear the theme cache. The WCAG maths lives in `App\Support\Contrast`.
- **Stored vs published**: strength is stored as a whole percent (2 to 50) and published as `0.02` to `0.50`. Colours are upper-case `#RRGGBB` or `null`. The uploaded picture lives on the media disk under `backdrop/` (JPG, PNG or WebP, up to 4 MB, at least 600 px wide; SVG is refused). If `style = image` but the file is gone, the server answers `style = skyline`.
- **Readability**: a page colour is refused (plain message in the dashboard) when its contrast with the active look's `ink` colour is below 4.5 : 1. Clients keep their own 3 : 1 safety net.
- **Back to default** keeps an uploaded picture (it stays under "My picture"); only **Remove picture** deletes the file.
- **The drawings** are generated, never edited by hand. `scripts/qatar-skyline/build.py` writes the Doha skyline into three files (`backend/public/brand/qatar-skyline.svg`, `storefront/src/assets/qatar-skyline.svg`, `mobile/lib/core/theme/qatar_skyline_paths.dart`); `scripts/qatar-skyline/build_scenes.py` writes the other five as `backend/public/brand/qatar-<scene>.svg`, the byte-identical `storefront/src/assets/qatar-<scene>.svg` and one `mobile/lib/core/theme/qatar_art_paths.dart` whose header carries `// source-sha256 <scene>: <sha256 of that SVG>` per artwork. `tests/Feature/Admin/BackdropTest.php` fails when a copy drifts apart or a Dart file was not generated from its SVG.
- A payload without `backdrop`, or with a value outside the allowed set, means "feature off" for a client: the page looks as it did before. A client that does not know a `scene` draws `doha`.

### The six models (`scene`)

One registry, `App\Domain\Cms\Support\BackdropScenes` (key, drawing file, tile flag, box), is the single source for the service, the dashboard page and the tests. The names live in `lang/{en,ar}/backdrop_admin.php` (`scene_<key>`).

| `scene` | Name (EN / AR) | Layout | Box |
|---|---|---|---|
| `doha` | Doha skyline / أفق الدوحة | stands on the bottom edge (file `qatar-skyline.svg`) | 1600 x 340 |
| `dhow` | Dhows on the Gulf / الداو على الخليج | stands on the bottom edge | 1600 x 340 |
| `souq` | Souq Waqif / سوق واقف | stands on the bottom edge | 1600 x 340 |
| `dunes` | Desert dunes / كثبان الصحراء | stands on the bottom edge | 1600 x 340 |
| `museum` | Corniche and the Museum of Islamic Art / الكورنيش ومتحف الفن الإسلامي | stands on the bottom edge | 1600 x 340 |
| `arabesque` | Islamic geometry / الزخرفة الإسلامية | **tile**: one cell repeated over the whole page | 200 x 200 |

- **Preview in the dashboard**: bottom-standing scenes are a CSS mask of the drawing (`asset('brand/qatar-<scene>.svg')`, `width: max(100%, 760px)` on the real page, `aspect-ratio 1600 / 340`, centred on the bottom edge), painted in the artwork colour at the chosen strength. The tile is a repeating mask from the top-left corner: `mask-size: 168px 168px; mask-repeat: repeat` on a real page, scaled down in proportion in the small phone card (59 px) and web card (35 px) and in the gallery thumbnails (40 px).
- **Adding a seventh model**: draw it in `scripts/qatar-skyline/scenes.py` and add it to its `SCENES` map (`build_scenes.py` then writes all three copies), run the build, add the key to `BackdropScenes::ALL`, a `scene_<key>` line in both language files, and the enum in `docs/api/openapi.json` and `docs/api/PAGE_BACKDROP.md`; the sync test then covers its copies.

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
