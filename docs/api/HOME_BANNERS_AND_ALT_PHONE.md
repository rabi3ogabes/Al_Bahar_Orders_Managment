# Home slide banners and the alternative-phone switch (contract)

Shared contract between the API/dashboard/web store (backend) and the Flutter apps (app helper). Additive to the existing `GET /api/v1/home` and `GET /api/v1/app-config`.
Status: implemented (backend, dashboard, web store). Anything below marked **changed** differs from the first draft of this contract.

## Slide banners on the home page

Admin: Dashboard → Content → **Home banners** (page `admin.banners.index`, redesigned). A **slider** is a home-page section of type `banners` (the existing `homepage_sections` row): the admin can have several sliders, **chooses where each one sits among the home sections** (placement = its position in the home layout, moved up/down one section at a time), and gives each slider its slides: an image per language (AR/EN), optional title/subtitle, an optional link, schedule, publish switch, a platform (`all|mobile|web`) and a position among that slider's slides (up/down, drag). Banners of the other placements (`category_top`, `auctions`) stay manageable on the same page ("Other places") and are **not** served by `GET /home`.

### Endpoint
`GET /api/v1/home` (**changed**: the first draft said `/homepage`; the real route is `/home`, and `/api/v1/homepage` now answers the same, so both work).
Query: `branch_id` (uuid, optional), `platform` = `mobile` | `web` (optional, **new**; the apps send nothing and get `mobile`; the web store sends `web`). Any other value is a 422.
The answer is cached for 60 s per language, platform and branch, and the cache is dropped at once on every dashboard change (a saved slide, a move, a hide/show, a delete or restore is live immediately).

A section of type `banners`:
```json
{ "id": "uuid", "type": "banners", "layout": "carousel", "title": "…" | null, "subtitle": "…" | null,
  "slider": { "autoplay": true, "interval": 5, "aspect": "16:9", "dots": true, "show_titles": true, "rounded": true },
  "items": [ { "id": "uuid", "title": "…"|null, "subtitle": "…"|null, "image_url": "https://…",
               "link": null | { "type": "category|page|product|brand|auction|url|search", "value": "…" } } ] }
```
- Key order and shape exactly as above. `layout` is always `carousel` for a slider. `title`/`subtitle` are an optional public heading for the slider (null = none; the apps may ignore it). The admin-only slider name is never sent.
- `slider`: only on `banners` sections, always complete. `aspect` ∈ `16:9`, `21:9`, `3:1`, `4:3`, `1:1` (width:height of every slide of this slider); `interval` is whole seconds, 3–15; `autoplay` false means no automatic sliding; `dots` shows page dots; `show_titles` means write the slide's title/subtitle over the picture; `rounded` rounds the corners. A stored value that is missing or out of range is served as its default (`autoplay` true, `interval` 5, `aspect` `16:9`, the rest true), so a client never has to guess.
- `link` is `null` for a slide without a link (the slide is **not tappable**). `value` per type: `category` → category **id** (uuid), `page` → CMS page **slug**, `product` → product id, `brand` → brand id, `auction` → auction id, `url` → an `https://` address (the server refuses anything else: no `http:`, `javascript:`, `data:`, relative or user-info addresses), `search` → the search text (≤ 120 characters).
- **A link whose target no longer exists or is not visible to customers** (deleted/hidden category, unpublished page, inactive product or brand, draft auction) is served as `link: null`: the slide is still shown, just not tappable. Clients never get an error for it.
- `image_url` is the language's artwork (Arabic falls back to English), always present (a slide without an image is left out). `title`/`subtitle` follow the same fallback.
- Slides are served in the admin order, filtered by the caller's platform (`all` + the caller's), publish state and schedule window; at most 50 per slider (the dashboard allows 20). **Sliders with no published, in-window slide are left out**, as is any unpublished or out-of-window section.
- Sections are served in the admin order. A slider placed after the 2nd section is the 3rd item of `sections` and must appear after the 2nd section in the app and the web store alike. Unknown section `type`s must still be ignored by clients.

### Web store
The store renders `sections` in order. A slider the admin put first is the very first thing on the page (above the hero); the hero panel no longer borrows the first slide. Links map to `/shop?category=<id>`, `/pages/<slug>`, `/products/<id>`, `/shop?brand=<id>`, `/auctions/<id>`, `/shop?q=<text>`, and `https://…` opens in a new tab with `rel="noopener noreferrer"`.

## Alternative phone switch
Settings → General & brand: **Ask for an alternative phone number** on/off (`customer_app.alternate_phone_enabled`, default **on**, audited like every setting).
`GET /api/v1/app-config` gains `alternate_phone_enabled: bool` (existing keys unchanged; still cached 60 s).
- **Off:** the customer apps and the web store do not show or ask for the alternative number (profile, address, checkout); the API **ignores** an alternative number sent by a client (never an error, even for an invalid value) and **stops returning it**: wherever it was returned the key stays but is `null` (`GET/PATCH /me` `alternate_phone`; the staff order detail, the dispatch queue, the driver's jobs and `me/deliveries`), so older strict clients keep parsing. A quote/order made while it is off has no alternative number, `alternate_phone_required` is never raised, and the number is not saved to the profile. The dashboard (order page, driver console) shows it nowhere. **Stored values are kept untouched** and come back when the switch is turned on; deleting an account still erases them.
- **On:** exactly as before (see `API_CONTRACT.md` §checkout).
- Clients: read the flag from `/app-config`, never block rendering on it, use the last known value (else on) until it arrives.
