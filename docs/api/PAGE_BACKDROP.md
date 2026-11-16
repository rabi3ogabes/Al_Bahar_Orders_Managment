# Page background (Qatar skyline), colour and opacity, set from the dashboard

One look for the whole customer experience: the web store and the customer app paint a calm background behind every page.
By default it is the **Doha skyline** (Aspire Tower, Burj Doha, Tornado Tower, Katara and Lusail towers) drawn as a vector in
one colour at low opacity. The admin can change the picture, remove it, set the opacity, and set the page colour.

Single source of the drawing: `scripts/qatar-skyline/build.py` writes `backend/public/brand/qatar-skyline.svg`,
`storefront/src/assets/qatar-skyline.svg` (identical bytes) and `mobile/lib/core/theme/qatar_skyline_paths.dart`
(the same shapes as native paths; the file header carries the sha256 of the SVG). Never edit those three by hand.

## API: `GET /api/v1/theme` (and `?design=` preview) gets one more key, `backdrop`

```json
"backdrop": {
  "style": "skyline",      // "skyline" (built-in vector artwork, see `scene`) | "image" (admin upload) | "none" (colour only)
  "scene": "doha",         // which built-in artwork: "doha" | "dhow" | "souq" | "dunes" | "museum" | "arabesque" (see Models below)
  "image_url": null,       // absolute https URL, only when style = "image"
  "fit": "bottom",         // images only: "bottom" (full width, natural height, standing on the bottom edge) | "cover"
  "opacity": 0.08,         // 0.02 .. 0.50, two decimals
  "color": null,           // "#RRGGBB" page colour for LIGHT mode, or null = the theme's own background colour
  "tint": null             // "#RRGGBB" colour of the built-in skyline, or null = the theme's primary colour
}
```

* Defaults when the admin never saved anything: `style=skyline, scene=doha, fit=bottom, opacity=0.08, color=null, tint=null`.
* `scene` is always present in the answer (default `doha`); `style` stays `skyline` for every built-in artwork so older clients that only know the Doha skyline still draw something sensible. A client that does not know a `scene` value draws `doha`.
* `image_url` is null unless `style = "image"` and a file exists. If the file is gone the server answers `style = "skyline"`.
* The block is part of the same cached payload (60 s); saving in the dashboard clears the cache.
* **Clients must be tolerant.** A payload without `backdrop`, or with a value outside the allowed set, means "feature off"
  (older server) and the page looks exactly as it did before. Unknown keys are ignored.
* **Active** = `style != "none"` or `color != null`. Not active: nothing changes anywhere.

## Models (built-in artworks)

All drawn once by `scripts/qatar-skyline/build.py` (doha) and `scripts/qatar-skyline/build_scenes.py` (the rest), one colour, closed polygons.
Files: `backend/public/brand/qatar-<scene>.svg` (`qatar-skyline.svg` is `doha`), the byte-identical copy in `storefront/src/assets/`,
and for the app `mobile/lib/core/theme/qatar_skyline_paths.dart` (doha) and `mobile/lib/core/theme/qatar_art_paths.dart` (`kQatarArt` map, the other five).
Never edit them by hand; each file header carries the sha256 of its SVG.

| `scene` | Name (EN / AR) | Layout | Box |
|---|---|---|---|
| `doha` | Doha skyline / أفق الدوحة | stands on the bottom edge | 1600 x 340 |
| `dhow` | Dhows on the Gulf / الداو على الخليج | stands on the bottom edge | 1600 x 340 |
| `souq` | Souq Waqif / سوق واقف | stands on the bottom edge | 1600 x 340 |
| `dunes` | Desert dunes / كثبان الصحراء | stands on the bottom edge | 1600 x 340 |
| `museum` | Corniche and the Museum of Islamic Art / الكورنيش ومتحف الفن الإسلامي | stands on the bottom edge | 1600 x 340 |
| `arabesque` | Islamic geometry / الزخرفة الإسلامية | **tile**: one cell that repeats over the whole page | 200 x 200 |

Rules for a **tile** artwork (`arabesque`): the cell is drawn 168 logical px wide and high, repeated in both directions from the top-left of
the page area, each cell clipped to its box (the pattern is cut at the cell edges so neighbours join). Web: `mask-size: 168px 168px; mask-repeat: repeat`.
App: loop over the cells and clip each. On web the pattern is fixed to the viewport like the other artworks. Bottom-standing artworks follow rule 3 below.

## Rendering rules (identical on web and app)

1. Order, bottom to top: page colour, backdrop picture, page content. Cards, sheets, bars and the footer menu keep their own
   opaque surfaces, so text never sits on the picture.
2. **Colour** (`color`) replaces the theme's page background in light mode only. Dark mode keeps its own dark page colour.
   Safety net on the client: if the contrast between the theme's text colour and `color` is below 3:1, ignore `color`.
3. **Built-in artwork** (`style = skyline`, any `scene`): tinted with `tint` or else the theme primary (in dark mode the theme's dark-mode primary), painted at `opacity`,
   standing on the bottom edge of the visible page, as wide as the viewport but never narrower than 760 logical px
   (on a phone the centre of the skyline shows, cropped at both sides), height follows the 1600:340 drawing, horizontally centred.
4. **Image**: shown with its own colours at `opacity`. `fit = bottom`: full width, natural height, bottom edge. `fit = cover`: fills the page.
5. Never takes pointer input, is hidden from screen readers, does not animate, and is not printed.
6. The picture is fixed to the viewport (does not scroll with long pages) on web; in the app it belongs to the page.
7. Only the customer app and the web store use it. The driver and staff apps and the dashboard itself do not.

## Dashboard: Appearance > Page background (`admin.appearance.background`)

* Permissions: `cms.view` to open, `cms.edit` to change. Every save is audited (`cms.backdrop_updated`, `cms.backdrop_image_removed`).
* Settings key `app.backdrop`; uploaded picture on the media disk under `backdrop/`.
* Controls: picture (a gallery of radio cards: the six models, My picture, None; each model card shows its own artwork in the current tint), upload / replace / remove (remove asks first), fit (images),
  opacity slider 2 to 50 %, page colour (picker + "Use theme colour"), skyline colour (picker + "Use theme colour"),
  live preview (phone and web card, light / dark switch), Save (disabled until something changed) and "Back to default".
* Uploads: JPG, PNG or WebP up to 4 MB, minimum 600 px wide. SVG is not accepted for upload (script risk); the built-in skyline is the vector.
* Page colour is refused when its contrast with the active theme's text colour is below 4.5:1 (WCAG AA), with a plain message.

## Why the footer menu had a black bar

The floating footer had nothing painted behind it, so the empty window (black on Android night mode, iOS and web) showed through
under and around the bar. The app now paints the page colour behind the whole footer region (including the system gesture area)
and sets the system navigation bar to the same colour.
