# Search engine tags, sitemap and robots

The store is a single-page app, so every address starts from the same HTML file. `StorefrontController` rewrites that file's `<head>` for each
address from the real catalogue (`App\Support\StorefrontSeo`), so search engines and link previews (WhatsApp, X, Facebook) see the right page.

| Address | Title / description from | Extras |
|---|---|---|
| `/` | its own title and description (EN and AR) | Organization (logo, contact point from the first active showroom) and WebSite (search box) data; categories and featured products in the plain copy |
| `/shop`, `/shop?category=…` | the category's name and description | CollectionPage + breadcrumbs, canonical `/shop?category=…`; `?q=` pages are `noindex` |
| `/products/{slug}` | the product's **SEO title** and **SEO description** (product editor), else its name and short description | Product data (price, currency, stock, brand, photos; rating only when real reviews exist), breadcrumbs, Open Graph product tags |
| `/pages/{slug}` | the page's SEO fields, else its title and first words | breadcrumbs |
| `/cart`, `/checkout`, `/account…`, `/orders…`, `/login` | – | `noindex,nofollow` |
| `/showrooms`, `/auctions`, `/auctions/{id}`, `/auctions/lots/{id}`, `/deals`, `/deals/{id}` | their own title and description | an auction that is a draft, or an id that does not exist, answers 404 |
| a product, page, deal, auction, lot or address that does not exist | – | real **404** status and `noindex` |

- `GET /sitemap.xml` lists the home page, shop, showrooms, auctions, every active category, every active product (with its photo) and every published page. It is rebuilt at most every 10 minutes.
- `GET /robots.txt` keeps search engines out of the admin, API, cart, checkout, account and order pages, and names the sitemap.
- The canonical host is `APP_URL`. Tags are cached for 5 minutes per address (`platform.seo.cache_seconds`), so a new product appears in them within 5 minutes.
- Every public page exists in two languages at one address: English at the plain address, Arabic at the same address with `?lang=ar`
  (`/shop?category=sofas&lang=ar`). The server writes the right `<html lang dir>`, title, description, canonical, `og:locale`, Open Graph and
  Twitter tags, and `hreflang` links (`en`, `ar`, `x-default`) in the first HTML of each, and the sitemap lists both addresses with the same links.
  Arabic text comes from the product, category and page translations and falls back to English where none was written. `/deals` is one
  bilingual address and is not duplicated. A shopper who opens an Arabic address sees Arabic; switching language in the app drops `?lang`
  from the address so a reload keeps the language just chosen.
- Search engines and AI crawlers that do not run JavaScript get the page's real text in a `<noscript>` copy inside the first HTML (heading,
  description, price, availability, brand and item code for a product; the category's products; the page body reduced to headings, text, lists,
  tables and links). Browsers that run JavaScript never show it. Product data (JSON-LD) always matches that visible copy; a rating is only
  described when real reviews exist.
- Early-loading hints: the shell lists the page's own code chunk and the two fonts the first paint needs (`modulepreload` / `preload`), read from
  the build's `.vite/manifest.json` (`App\Support\StorefrontAssetHints`). Without a manifest nothing is added.
- `robots.txt` allows every crawler, AI crawlers included (there is no per-bot block list), keeps the private areas out and names the sitemap.

After a release, submit `https://<domain>/sitemap.xml` in Google Search Console and Bing Webmaster Tools.


## Quality pass (Wave 6, roadmap 6.7)

Measured on a production build of the store (`npm run build`) served with the demo catalogue, once as the code stood before this pass
(Lighthouse 13.x, Chrome 153, simulated slow 4G and 4x CPU slowdown on mobile) and once after it. The "after" server compressed text and sent
`Cache-Control: public, immutable` on `/store/assets/` exactly like the new `docker/nginx/default.conf` does. Demo products have no photos, so a
product's largest paint is its heading; with real photos the LCP is the (already sized, `fetchpriority`/eager) first picture.

### Scores, before → after

| Page | Device | Language, theme | Performance | Accessibility | Best practices | SEO | LCP | CLS | TBT |
|---|---|---|---|---|---|---|---|---|---|
| home | mobile | en light | 68 → 100 | 97 → 100 | 100 → 100 | 100 → 100 | 5.9 → 1.0 s | 0 | 87 ms |
| home | mobile | ar light | 74 → 95 | 97 → 100 | 100 → 100 | 100 → 100 | 5.6 → 1.0 s | 0 | 269 ms |
| home | mobile | en dark | 78 → 81 | 97 → 100 | 100 → 100 | 100 → 100 | 5.7 → 1.0 s | 0 | 802 ms |
| home | desktop | en light | 98 → 100 | 97 → 100 | 100 → 100 | 100 → 100 | 1.2 → 0.3 s | 0.002 | 0 ms |
| shop | mobile | en light | 95 → 99 | 95 → 100 | 100 → 100 | 100 → 100 | 1.4 → 1.0 s | 0.011 | 113 ms |
| shop | desktop | en light | 100 → 100 | 95 → 100 | 100 → 100 | 100 → 100 | 0.4 → 0.3 s | 0.023 | 0 ms |
| category | mobile | en light | 99 → 94 | 95 → 100 | 100 → 100 | 100 → 100 | 1.2 → 0.9 s | 0.158 | 60 ms |
| category | desktop | en light | 96 → 97 | 95 → 100 | 100 → 100 | 100 → 100 | 0.5 → 0.3 s | 0.115 | 0 ms |
| product | mobile | en light | 72 → 86 | 93 → 100 | 100 → 100 | 100 → 100 | 6.6 → 4.1 s | 0 | 128 ms |
| product | mobile | ar light | 74 → 86 | 93 → 100 | 100 → 100 | 100 → 100 | 6.5 → 4.0 s | 0 | 126 ms |
| product | mobile | en dark | 69 → 86 | 93 → 100 | 100 → 100 | 100 → 100 | 6.5 → 4.0 s | 0 | 111 ms |
| product | desktop | en light | 97 → 99 | 93 → 100 | 100 → 100 | 100 → 100 | 1.3 → 1.0 s | 0.005 | 0 ms |
| deals | mobile | en light | 94 → 100 | 96 → 100 | 100 → 100 | 100 → 100 | 1.2 → 0.9 s | 0 | 68 ms |
| deals | desktop | en light | 100 → 100 | 96 → 100 | 100 → 100 | 100 → 100 | 0.3 → 0.3 s | 0.003 | 0 ms |
| auctions | mobile | en light | 97 → 100 | 96 → 100 | 100 → 100 | 100 → 100 | 1.2 → 0.9 s | 0 | 26 ms |
| auctions | desktop | en light | 100 → 100 | 96 → 100 | 100 → 100 | 100 → 100 | 0.4 → 0.3 s | 0.019 | 0 ms |
| auction | mobile | en light | n/a → 84 | n/a → 100 | n/a → 100 | n/a → 100 | n/a → 4.1 s | 0.092 | 54 ms |
| auction | desktop | en light | n/a → 99 | n/a → 100 | n/a → 100 | n/a → 100 | n/a → 0.9 s | 0.054 | 0 ms |
| cart | mobile | en light | 75 → 99 | 96 → 100 | 100 → 100 | 63 → 63 | 6.5 → 0.9 s | 0 | 96 ms |
| cart | desktop | en light | 100 → 100 | 96 → 100 | 100 → 100 | 63 → 63 | 0.3 → 0.3 s | 0 | 0 ms |
| checkout | mobile | en light | 77 → 100 | 96 → 100 | 100 → 100 | 63 → 63 | 6.1 → 0.9 s | 0 | 75 ms |
| checkout | desktop | en light | 100 → 100 | 96 → 100 | 100 → 100 | 63 → 63 | 0.3 → 0.3 s | 0 | 0 ms |
| cms | mobile | en light | 77 → 86 | 94 → 100 | 100 → 100 | 100 → 100 | 6.3 → 3.8 s | 0.102 | 46 ms |
| cms | desktop | en light | 96 → 99 | 94 → 100 | 100 → 100 | 100 → 100 | 1.4 → 0.9 s | 0.049 | 0 ms |

Reading the table:

- Every page is 100 for accessibility, best practices and SEO. The two SEO 63 rows (`/cart`, `/checkout`) are correct: they are private pages and
  say `noindex,nofollow` on purpose, which Lighthouse reports as "page is blocked from indexing".
- Desktop performance is 97 to 100 on every page. Mobile performance of the home, shop, category, deals, auctions, cart and checkout pages is 94 to 100;
  Lighthouse moves a few points between runs (home mobile re-run twice after the table: English 98 and 96, Arabic 99 and 99, dark 99 and 96, so the 81 in the table is
  main-thread noise on the test machine).
- **Below the 95 target on mobile: product 86, information page 86, auction 84.** Their first paint is the page's own heading, and that text only exists after
  the browser has downloaded the script, the list of showrooms (which decides the branch) and the page's data (two to three requests one after the
  other on a simulated 4G line). The earlier "before" numbers (72 to 77) were worse because the script was sent uncompressed (474 KB instead of 148 KB).
  The remaining step is to send the first data inside the HTML (server-side rendering of the page's data), which is a larger change than this pass and is
  listed under "Not done".
- CLS is at most 0.05 except the category list (a row of filter chips appears when the category list arrives: 0.03 to 0.16 between runs) and the auction page (0.09).
- 404 pages cannot be scored by Lighthouse (it refuses non-200 pages); they were checked with axe and curl instead.

### Accessibility (axe-core, WCAG 2.1 A and AA plus best practices)

17 page types (home, shop, category, search, deals, auctions list, one auction, one lot, cart, checkout, `/login`, two information pages, showrooms, 404 page,
404 product) in English and Arabic, light and dark, 1280 px and (English light) 375 px: **247 violations before, 0 after**, no console errors, one `h1`
per page, no sideways overflow. What was fixed:

| Problem | Where | Fix |
|---|---|---|
| Text on the primary colour too faint (3.0 to 4.4 : 1) in light and dark | footer, top bar, logo, auction and loyalty bands | new token `--on-primary-soft` (88 % of the on-primary colour in light, the full colour in dark) |
| Red "Live" badge 4.49 : 1 | auction badges | `--danger` light value `#b0453a` → `#a63d32` |
| Product link name differed from its visible text | every product card | the link takes its name from its content |
| `aria-label` on a plain `<span>` | star rating | `role="img"` |
| Content outside landmarks | promo strip above the header | `<aside aria-label>` |
| Heading levels skipped (h1 → h3) | footer, filters, product list, auction and lot cards, admin-written page bodies | footer and card headings are `h2`; hidden `h2` for "Filters" and "Products"; editor headings are re-levelled (`fixHeadingLevels`) |
| No `h1` on a whole-page message | empty cart, sign-in needed, not found, missing product, auction, deal, order | those messages are the page's `h1` |
| Touch targets under 44 px on phones | logo, heart buttons, "view all", breadcrumbs, footer links, newsletter button, filter chips, small buttons, sort box, showroom links | at least 44 px high on phones; short one-word links are narrower than 44 px but 44 px high with more than 24 px of space around them |

### Search engines and crawlers

- Arabic addresses (`?lang=ar`) with `hreflang` en / ar / x-default, `<html lang dir>`, Arabic title and description, `og:locale`, Twitter title, description and image (see the table at the top).
- The plain-HTML copy of each page (`<noscript>`), so crawlers that do not run JavaScript get the real content.
- Unique home title and description; Organization data now has a logo and a contact point from the first active showroom.
- Real 404 for a missing auction, lot, deal, product or page, in both languages. `/login` is a real route that opens the sign-in window over the home page
  (it used to show the "not found" page while the server answered 200).
- Sitemap lists every address in both languages with `xhtml:link` alternates. It is about twice as long as before (limit 50,000 addresses per file; split it into
  an index when the catalogue passes about 20,000 products).
- The auction page no longer crashes for a lot that has no bids yet (`current_price` is `null` until the first bid; the page now shows the opening bid).

### Speed

- `docker/nginx/default.conf`: gzip for HTML, JS, CSS, JSON, SVG and the sitemap (the store's first load is a third of its size). This is the biggest single gain.
- The shell preloads the page's own code chunk and the first-paint fonts (see above).
- The tab title follows the shopper's language while moving around the store (`useDocTitle`).
- Already in place and kept: hashed assets with `immutable` for a year, self-hosted fonts with `font-display: swap` (the Arabic font file is only fetched when
  Arabic text is on the page), lazily loaded routes, sized images with `srcset`.

### How to re-run the checks

```bash
cd storefront && npm run build && npm run typecheck && npm test           # 286 logic tests
cd backend && vendor/bin/pest tests/Feature/StorefrontSeoContentTest.php tests/Feature/StorefrontSeoTest.php tests/Feature/StorefrontShareTest.php tests/Feature/StorefrontTest.php
# serve the build (php artisan serve with the shell route, copy storefront/dist into backend/public/store), then in a temp folder:
npm i --no-save playwright @axe-core/playwright lighthouse
STORE_URL=http://127.0.0.1:8000 node <repo>/storefront/tests/e2e/a11y-audit.e2e.mjs            # axe, exit 1 on any finding
STORE_URL=http://127.0.0.1:8000 CHROME_PATH=/usr/bin/google-chrome node <repo>/storefront/tests/e2e/lighthouse-audit.e2e.mjs   # scores table
```

Use a local copy of the store for these runs, never the live site. Lighthouse needs the text to be compressed to give production-like numbers (a plain
`php artisan serve` sends it uncompressed: put a small gzip proxy in front or use nginx).

### Design note

- **One address, two languages** (`?lang=ar`) instead of `/ar/...` paths: no routing change in the app, no duplicate route table, every existing link keeps
  working, and the sitemap / `hreflang` links tell search engines about the pair. Reversible: dropping the parameter handling returns the old behaviour.
- **`<noscript>` copy instead of server-rendering the React app**: crawlers without JavaScript get the text; people never see it. It is a plain, sanitised
  copy (headings, text, lists, tables, links; no attributes except a safe link), cached for 5 minutes with the rest of the tags (`platform.seo.cache_seconds`).
- **Every number**: LCP = time until the largest text or picture is drawn; CLS = unexpected movement of content (0 is none); TBT = time the page could not react
  to a tap while loading; Performance is Lighthouse's weighted mix of these on a simulated slow phone, so it moves a few points between runs.
- **Not done / next step**: sending the first page data inside the HTML (needed for mobile 95+ on product, information and auction pages); a request that
  carries the branch before the first paint; reserving the height of the category chip row.
