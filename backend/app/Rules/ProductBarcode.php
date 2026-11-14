<?php

namespace App\Rules;

use App\Domain\Catalog\Services\ProductService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The barcode of a product or variant: letters, digits, dot, dash or underscore (no spaces), and not already used by another product or
 * variant. This is the code a customer's phone reads in "Scan to add" and the code the label printer draws, so it must be unambiguous.
 * Empty is allowed (a product without a barcode is scanned by its SKU).
 */
class ProductBarcode implements ValidationRule
{
    /** Letters and digits first, then also . _ - */
    public const PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    public function __construct(
        private readonly ?string $ignoreProductId = null,
        private readonly ?string $ignoreVariantId = null,
        private readonly ?string $ownerProductId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            $fail(__('catalog_admin.barcode_format'));

            return;
        }
        if (app(ProductService::class)->barcodeTaken($value, $this->ignoreProductId, $this->ignoreVariantId, $this->ownerProductId)) {
            $fail(__('errors.barcode_taken'));
        }
    }
}
