# Replacing the catalogue (`catalog:replace`)

Al Bahar and Al Marwah are two different clients. The first catalogue of this store was imported from the other client's shop
(SKUs `AM-…`, their picture files). `catalog:replace` removes all of that and creates Al Bahar's own catalogue instead.

## What it does

1. **Removes the old catalogue** (skip with `--keep-old`), in one database transaction:
   products, variants, pictures (the files too), cutout results, stock rows and stock movements, categories, and every brand except `al-bahar`.
   - The stock ledger (`stock_movements`) refuses deletes by a trigger. The command switches that trigger off for this one transaction
     and switches it on again before the transaction ends; a failure rolls everything back, trigger included. The swap is written to the audit log (`catalog.replaced`).
   - Orders already placed stay. Their lines keep the name and SKU they were bought under, lose the picture (the file is gone) and
     no longer point at a product. The audit log is append-only and still holds the old product texts.
2. **Creates the new catalogue** from `backend/database/data/albahar_catalog.json` (7 categories, 35 products, English and Arabic names,
   short and long descriptions, SEO texts, SKUs `ABH-…`, brand Al Bahar, status active) with opening stock in every branch (`--stock=40`, `0` for none).
3. **Downloads the pictures** listed in `--images` (a JSON file `{"ABH-1001": ["https://…"], "category:kitchen-dining": "https://…"}`), re-encodes them to WebP with
   thumbnails (products) or a 1400 px cover (categories). Only products and categories that have no picture yet get one.

Safe to run again: existing SKUs are skipped, pictures are only added where missing.

```bash
php artisan catalog:replace --dry-run                      # what would happen
php artisan catalog:replace --force --images=/path/pictures.json
```

## Values you must check

The prices, sale prices, weights and the opening stock in the spec are **AI-suggested sample values**, not the client's. Edit them in the
dashboard (Catalogue → Products, Inventory → Stock) before real customers order. The names, descriptions and pictures are AI-written and
AI-generated; replace any picture that does not match the real product you will sell.

## Rolling back

A database dump was taken first: `/root/backups/albahar-pre-catalog-reset-20261113-2223.dump` on the server
(`pg_dump -Fc`). Restore into an empty database with `pg_restore -d albahar --clean --if-exists <file>`; picture files are not part of the dump.
