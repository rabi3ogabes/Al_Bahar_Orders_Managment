# Promotions: leave things out

Roadmap item 5.7. Marketing > Promotions. Dashboard and server only: the apps and the storefront show whatever the quote returns, so nothing changed there.

## What marketing sees

In the promotion window, under the scope (the whole basket, categories or specific products), a block **Leave out** (collapsed unless something is already left out, with a count):

- **Products to leave out**: the same search box and the same **paste SKUs** box as "specific products" (one SKU per line or comma separated, product or variant SKUs, the pasted order is kept, up to 200).
- **Categories to leave out**: tick categories. A category also leaves out all its sub-categories.
- **Brands to leave out**: tick brands.

The Promotions list shows a plain line under the rules: "Whole basket except 3 products, 1 brand", "2 categories except 1 product", "1 product except 2 brands" (Arabic too).

## The rules (server is the source of truth)

1. **An excluded line is never discounted.** A line is excluded when its product is listed, its category (or a parent category) is listed, or its brand is listed.
2. **Exclusion wins over inclusion.** A product that is in the SKU list but excluded by brand, or in an included category but excluded by SKU, is excluded.
3. **A mixed basket is discounted on its eligible lines only**: percentage, fixed (capped at what the eligible lines are worth), bundle (excluded units do not count towards the quantity) and buy-X-get-Y (excluded units are not in the groups).
4. **The minimum spend counts the eligible lines only.** The basket minimum is a promise about what the customer buys *of the offer*; an excluded sofa does not help reach it. (Documented choice; the alternative would let an excluded item unlock a discount on the rest.)
5. **Coupons inherit it.** A coupon works through its promotion, so every code of a promotion has the same exclusions. A coupon whose promotion has no eligible line left shows "The items in your cart are not part of this offer." (`coupon_excluded`), in English and Arabic, instead of "not eligible". A basket the offer never covered still says "not eligible".
6. **Nothing eligible, nothing discounted**: no discount line, totals unchanged. An automatic promotion stays silent.
7. Branch restriction, customer group, stacking and priority combine as before; exclusions only remove lines.

## Boundaries covered by tests

Everything excluded; exclusion plus inclusion of the same product (SKU list vs brand, category vs product); 200 products; a product that was deleted (the row goes with it) or is a draft in the list; a deleted brand or category; the same product twice (refused by the table); double submit of Save (one promotion, one list); forged ids; paste with unknown SKUs, archived products and the 200 cap; Arabic.

## Design note

- **Three small link tables** (`promotion_excluded_products`, `_categories`, `_brands`; composite key, both columns indexed, cascade on delete) in the migration `2026_11_23_110100_create_promotion_exclusions.php`. They are read with the promotion in the same eager load as its other lists, so a quote costs no extra query per line; the engine expands an excluded category once per evaluation like it does for included ones.
- **The list reads counts, not rows**: `withCount` for the summary, one query per list, never one per promotion (tested).
- **Save is idempotent now**: after the first save the window knows the promotion, so a second tap on Save updates it instead of creating a second promotion (this also fixes the same double submit for ordinary promotions).
- **Audit**: `marketing.promotion_created/updated` carries `excluded_products`, `excluded_categories`, `excluded_brands`.
- **Not built**: exclusions for coupons on their own (they always follow their promotion), exclusions by tag or price.
