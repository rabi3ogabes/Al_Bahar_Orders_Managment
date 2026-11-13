# Search engine tags, sitemap and robots

The store is a single-page app, so every address starts from the same HTML file. `StorefrontController` rewrites that file's `<head>` for each
address from the real catalogue (`App\Support\StorefrontSeo`), so search engines and link previews (WhatsApp, X, Facebook) see the right page.

| Address | Title / description from | Extras |
|---|---|---|
| `/` | the app shell | Organization and WebSite (search box) data |
| `/shop`, `/shop?category=…` | the category's name and description | CollectionPage + breadcrumbs, canonical `/shop?category=…`; `?q=` pages are `noindex` |
| `/products/{slug}` | the product's **SEO title** and **SEO description** (product editor), else its name and short description | Product data (price, currency, stock, brand, photos; rating only when real reviews exist), breadcrumbs, Open Graph product tags |
| `/pages/{slug}` | the page's SEO fields, else its title and first words | breadcrumbs |
| `/cart`, `/checkout`, `/account…`, `/orders…`, `/login` | – | `noindex,nofollow` |
| a product, page or address that does not exist | – | real **404** status and `noindex` |

- `GET /sitemap.xml` lists the home page, shop, showrooms, auctions, every active category, every active product (with its photo) and every published page. It is rebuilt at most every 10 minutes.
- `GET /robots.txt` keeps search engines out of the admin, API, cart, checkout, account and order pages, and names the sitemap.
- The canonical host is `APP_URL`. Tags are cached for 5 minutes per address (`platform.seo.cache_seconds`), so a new product appears in them within 5 minutes.
- Tags are English (the shell's language); the app switches to Arabic in the browser.

After a release, submit `https://<domain>/sitemap.xml` in Google Search Console and Bing Webmaster Tools.
