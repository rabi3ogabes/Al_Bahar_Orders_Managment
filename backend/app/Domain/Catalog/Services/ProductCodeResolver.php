<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Turns whatever a phone camera (or a person typing) produced into one sellable product.
 *
 * Accepted input, in the order it is tried:
 *   1. a deep link `albahar://products/{id}` or a store link `https://host/[…/]products/{slug or id}` (query and fragment ignored);
 *   2. an exact code, case-insensitive: variant barcode, product barcode, variant SKU, product SKU;
 *   3. a number that is the same barcode written another way (UPC-A 12 digits <-> EAN-13 with a leading zero, GTIN-14, EAN-8);
 *   4. a product id (UUID) or a slug (English or Arabic).
 *
 * Only active products (and active variants) that the branch context may show are ever matched; a draft, inactive or archived
 * product behaves exactly like a code that does not exist. Every lookup is an index lookup (see the 2026_11_14_300000 migration).
 */
class ProductCodeResolver
{
    /** Longest string looked at: a store link with a long Arabic slug fits; anything longer cannot be a code the shop printed. */
    public const MAX_LENGTH = 512;

    /** Lengths a retail barcode can have (EAN-8, UPC-A, EAN-13, GTIN-14); a number is only "the same barcode" at one of these. */
    private const BARCODE_LENGTHS = [8, 12, 13, 14];

    public function resolve(string $input, ?string $branchId = null): ?ProductMatch
    {
        $code = $this->clean($input);
        if ($code === null) {
            return null;
        }

        $link = $this->fromLink($code);
        if ($link !== null) {
            // A link names a product, never a code: look the reference up as an id or slug and stop (no code lookups on a URL).
            return $this->byReference($link['reference'], $link['via'], $branchId);
        }

        foreach ([['variant', 'barcode', 'variant_barcode'], ['product', 'barcode', 'product_barcode'],
            ['variant', 'sku', 'variant_sku'], ['product', 'sku', 'product_sku']] as [$level, $column, $via]) {
            $match = $this->byCode($level, $column, [$code], $via, $branchId);
            if ($match !== null) {
                return $match;
            }
        }

        $numbers = $this->sameBarcode($code);
        if ($numbers) {
            foreach ([['variant', 'barcode', 'variant_barcode'], ['product', 'barcode', 'product_barcode']] as [$level, $column, $via]) {
                $match = $this->byCode($level, $column, $numbers, $via, $branchId);
                if ($match !== null) {
                    return $match;
                }
            }
        }

        return $this->byReference($code, 'slug', $branchId);
    }

    /** Trimmed, control characters removed, Arabic digits made Latin; null when nothing usable is left. */
    private function clean(string $input): ?string
    {
        if ($input === '' || strlen($input) > self::MAX_LENGTH * 4 || ! mb_check_encoding($input, 'UTF-8')) {
            return null;
        }
        $code = preg_replace('/[\p{C}]+/u', '', $input) ?? '';
        $code = preg_replace('/^[\s\x{00A0}\x{200B}-\x{200F}\x{FEFF}]+|[\s\x{00A0}\x{200B}-\x{200F}\x{FEFF}]+$/u', '', $code) ?? '';
        $code = strtr($code, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);

        return $code === '' || mb_strlen($code) > self::MAX_LENGTH ? null : $code;
    }

    /** @return array{reference: string, via: string}|null the product id or slug a deep link or store link points at */
    private function fromLink(string $code): ?array
    {
        $reference = null;
        $via = null;
        if (preg_match('#^albahar://products/([^/?\#\s]+)#iu', $code, $m)) {
            [$reference, $via] = [$m[1], 'deep_link'];
        } elseif (preg_match('#^https?://[^/?\#\s]+(?:/[^?\#\s]*)?/products/([^/?\#\s]+)#iu', $code, $m)) {
            [$reference, $via] = [$m[1], 'url'];
        } elseif (preg_match('#^(?:https?|albahar)://#i', $code)) {
            // A link to something else (a category, the home page, another site): it is not a product.
            return ['reference' => '', 'via' => 'url'];
        }
        if ($reference === null) {
            return null;
        }
        $reference = rawurldecode($reference);

        return mb_check_encoding($reference, 'UTF-8') ? ['reference' => $reference, 'via' => $via] : ['reference' => '', 'via' => $via];
    }

    /** The same barcode written as UPC-A / EAN-13 / EAN-8 / GTIN-14: leading zeros do not change which product it is. */
    private function sameBarcode(string $code): array
    {
        if (! preg_match('/^\d{6,14}$/', $code)) {
            return [];
        }
        $significant = ltrim($code, '0');
        if ($significant === '') {
            return [];
        }
        $forms = [];
        foreach (self::BARCODE_LENGTHS as $length) {
            if (strlen($significant) <= $length) {
                $forms[] = str_pad($significant, $length, '0', STR_PAD_LEFT);
            }
        }

        return array_values(array_diff(array_unique($forms), [$code]));
    }

    /** @param list<string> $codes */
    private function byCode(string $level, string $column, array $codes, string $via, ?string $branchId): ?ProductMatch
    {
        if (! in_array($column, ['barcode', 'sku'], true)) {
            return null; // the column name is spliced into SQL below: it must be one of ours
        }
        $marks = implode(', ', array_fill(0, count($codes), 'lower(?)'));

        if ($level === 'variant') {
            $variant = ProductVariant::query()->where('product_variants.status', 'active')
                ->whereRaw("lower(product_variants.{$column}) in ({$marks})", $codes)
                ->whereHas('product', fn (Builder $p) => $p->active()->visibleInBranch($branchId))
                ->orderByRaw("(product_variants.{$column} = ?) desc", [$codes[0]])->orderBy('product_variants.id')
                ->first(['product_variants.id', 'product_variants.product_id']);

            return $variant ? $this->match($variant->product_id, $variant->id, $via) : null;
        }

        $product = Product::query()->active()->visibleInBranch($branchId)
            ->whereRaw("lower(products.{$column}) in ({$marks})", $codes)
            ->orderByRaw("(products.{$column} = ?) desc", [$codes[0]])->orderBy('products.id')
            ->first(['products.id']);

        return $product ? $this->match($product->id, null, $via) : null;
    }

    private function byReference(string $reference, string $via, ?string $branchId): ?ProductMatch
    {
        if ($reference === '') {
            return null;
        }
        $query = Product::query()->active()->visibleInBranch($branchId);
        if (Str::isUuid($reference)) {
            $id = $query->whereKey(strtolower($reference))->value('products.id');
            if ($id) {
                return $this->match($id, null, $via === 'slug' ? 'id' : $via);
            }

            return null;
        }
        // A slug has no spaces and is not a number: anything else was a code that simply does not exist.
        if (! preg_match('/^[\p{L}\p{N}][\p{L}\p{N}\p{M}_-]*$/u', $reference) || ctype_digit($reference)) {
            return null;
        }
        $slug = mb_strtolower($reference);
        $id = $query->whereHas('translations', fn (Builder $t) => $t->whereIn('locale', config('platform.supported_locales'))->where('slug', $slug))
            ->orderBy('products.id')->value('products.id');

        return $id ? $this->match($id, null, $via) : null;
    }

    /** Loads the product the way a catalogue card needs it and settles which variant was meant. */
    private function match(string $productId, ?string $variantId, string $via): ?ProductMatch
    {
        $product = Product::query()->with(['translations', 'brand', 'images', 'variants' => fn ($v) => $v->where('status', 'active')])->find($productId);
        if ($product === null || $product->variants->isEmpty()) {
            return null; // a product nobody can buy (no active variant) is not a product to the shopper
        }
        $variant = $variantId !== null
            ? $product->variants->firstWhere('id', $variantId)
            : ($product->variants->count() === 1 ? $product->variants->first() : null); // several variants: the shopper must choose

        return $variantId !== null && $variant === null ? null : new ProductMatch($product, $variant, $via);
    }
}
