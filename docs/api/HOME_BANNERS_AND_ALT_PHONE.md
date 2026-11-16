# Home slide banners and the alternative-phone switch (contract)

Shared contract between the API/dashboard/web store (backend helper) and the Flutter apps (app helper). Additive to the existing `GET /api/v1/homepage` and `GET /api/v1/app-config`.

## Slide banners on the home page
Admin: Dashboard → Content → **Home banners** (page `admin.banners.index`, redesigned). A **slider** is a home-page section of type `banners` (the existing `homepage_sections` row): the admin can have several sliders, **chooses where each one sits among the home sections** (placement = its position in the home layout, moved up/down), and gives each slider its slides: an image per language (AR/EN), optional title/subtitle, an optional link, schedule, publish switch, and a position among that slider's slides (up/down). Banners of the other placements (`category_top`, `auctions`) keep working as today.

`GET /api/v1/homepage` — a section of type `banners`:
```json
{ "id": "uuid", "type": "banners", "layout": "carousel", "title": "…" | null, "subtitle": "…" | null,
  "slider": { "autoplay": true, "interval": 5, "aspect": "16:9", "dots": true, "show_titles": true, "rounded": true },
  "items": [ { "id": "uuid", "title": "…"|null, "subtitle": "…"|null, "image_url": "https://…",
               "link": null | { "type": "category|page|product|brand|auction|url|search", "value": "…" } } ] }
```
- `aspect` ∈ `16:9`, `21:9`, `3:1`, `4:3`, `1:1`; `interval` 3–15 seconds; all four keys always present with defaults.
- `link` is `null` for a banner without a link (the slide is not tappable). `value` per type: `category` → category **id** (uuid), `page` → CMS page **slug**, `product` → product id, `brand` → brand id, `auction` → auction id, `url` → an `https://` address (the server refuses anything else), `search` → the search text.
- `image_url` is the language's artwork (Arabic falls back to English). Sliders with no published, in-window slide are left out.
- A slider has its own platform filter per slide (`all|mobile|web`); the API already filters by the caller.
- Banners are served in the admin order; the home sections in their admin order. A slider placed after the 2nd section appears after the 2nd section in the app and the web store alike.

## Alternative phone switch
Settings → General & brand (or Checkout): **Alternative phone number** on/off (`customer_app.alternate_phone_enabled`, default **on**). `GET /api/v1/app-config` gains `alternate_phone_enabled: bool`.
- Off: the customer apps and the web store do not show or ask for the alternative number (profile, address, checkout); the API ignores an alternative number sent by a client and stops returning it; the staff and driver apps and the dashboard stop showing it on orders. Stored values are kept (turning it back on restores them).
- On: exactly as today.
