# Phase 03 — Central Product Catalog, Categories, Variants and Branch Pricing

## Objective
Build the centralized product catalog with Arabic/English content, product variants, media, and optional branch-specific price/availability overrides.

## Business rules
- Each product exists once in the central catalog.
- Products may be enabled in all branches or selected branches.
- Global default price exists where applicable.
- Branch override price takes precedence over global default.
- Variant-specific branch pricing takes precedence over product-level branch pricing.
- Product availability and pricing must be checked server-side.
- Historical order snapshots are implemented in the order phase.

## Product management (Laravel admin)
Implement:
- Product list with search, filters, sorting and pagination.
- Create/edit/view/archive products.
- SKU and barcode management.
- Product status: draft, active, inactive, archived.
- Product name and descriptions in Arabic and English.
- Short and long descriptions.
- Category and brand assignment.
- Product tags.
- Product specifications and attributes.
- Dimensions and weight with units.
- Material, color, size and other configurable attributes.
- Warranty information.
- Assembly/service flags.
- Multiple images with ordering and alt text.
- Product videos.
- Featured/bestseller/new-arrival flags.
- SEO title/description and search keywords.
- Bulk CSV/XLSX import/export with validation and error report.
- Bulk status/category/brand updates.
- Product duplication.
- Product change history.

## Categories and brands
- Unlimited categories and nested subcategories.
- Parent-child category relationship.
- Arabic/English category translations.
- Category image, icon, banner, sort order and status.
- Prevent invalid circular category nesting.
- Brands with Arabic/English names, logo and status.
- Category-specific filters where configured.

## Product variants
Support products with or without variants.
Examples: color, size, material, finish, package size.
- Variant SKU unique.
- Optional barcode unique.
- Variant attributes.
- Variant images.
- Variant status.
- Variant-level weight/dimensions if needed.
- Branch inventory is tracked by variant, not only product.

## Pricing and branch availability
Create admin screens to:
- Set global default price and sale price.
- Set branch-specific override price and sale price.
- Inherit global price if branch override is absent.
- Enable/disable product by branch.
- Set branch-specific visibility.
- Set start/end dates for sale prices.
- View effective price for each branch.
- Maintain price change history and actor.
- Validate sale price is not higher than regular price unless explicitly allowed by business policy.

Effective pricing precedence:
1. Variant + branch override.
2. Product + branch override.
3. Variant global price, if defined.
4. Product global price.

Document and test the exact precedence. Do not silently fall back to a price if the product/variant is disabled in that branch.

## Database tables
- products
- product_translations
- categories
- category_translations
- brands
- product_variants
- product_attributes
- product_attribute_values
- product_images
- product_videos
- product_branch_settings
- product_variant_branch_settings
- product_warranties
- product_related
- product_price_history

## API endpoints
- `GET /api/v1/categories`
- `GET /api/v1/categories/{id}`
- `GET /api/v1/brands`
- `GET /api/v1/products`
- `GET /api/v1/products/{id}`
- `GET /api/v1/products/{id}/availability`
- `GET /api/v1/branches/{id}/products`
- `GET /api/v1/products/search`
- Admin catalog CRUD endpoints.

## Search
Initially use PostgreSQL full-text/trigram capabilities as appropriate. Normalize common Arabic variants for search, support Arabic and English product fields, partial matches, SKU and barcode. Keep search implementation behind a service interface so OpenSearch can be introduced later.

## Media
- Store media in S3-compatible object storage.
- Validate MIME type and file size.
- Generate optimized image variants/thumbnails.
- Do not store file bytes in database.
- Support image ordering and deletion with reference checks.

## Tests
- Translation uniqueness.
- SKU/barcode uniqueness.
- Variant management.
- Branch availability.
- Price precedence.
- Sale price validity.
- Arabic and English search.
- Import validation.
- Unauthorized catalog edits.

## Acceptance criteria
- Admin can manage the complete catalog.
- Product content supports Arabic and English.
- Products and variants have unique identifiers.
- Branch-specific pricing works with documented precedence.
- Branch availability is enforced.
- Product APIs are paginated, filterable and documented.
