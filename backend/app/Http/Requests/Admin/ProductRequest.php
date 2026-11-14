<?php

namespace App\Http\Requests\Admin;

use App\Rules\ProductBarcode;
use Illuminate\Foundation\Http\FormRequest;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public static function rulesFor(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'sku' => [$req, 'string', 'max:64', 'regex:/^[A-Za-z0-9\-_.]+$/'],
            'barcode' => ['nullable', 'string', 'max:64', 'regex:'.ProductBarcode::PATTERN],
            'category_id' => ['nullable', 'uuid', 'exists:categories,id'],
            'brand_id' => ['nullable', 'uuid', 'exists:brands,id'],
            'status' => ['sometimes', 'in:draft,active,inactive,archived'],
            'price' => [$req, 'numeric', 'min:0', 'max:9999999999'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'sale_starts_at' => ['nullable', 'date'],
            'sale_ends_at' => ['nullable', 'date', 'after:sale_starts_at'],
            'allow_sale_above_price' => ['sometimes', 'boolean'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'weight' => ['nullable', 'numeric', 'min:0'], 'weight_unit' => ['sometimes', 'in:kg,g'],
            'length' => ['nullable', 'numeric', 'min:0'], 'width' => ['nullable', 'numeric', 'min:0'], 'height' => ['nullable', 'numeric', 'min:0'],
            'dimension_unit' => ['sometimes', 'in:cm,m,mm'],
            'is_featured' => ['sometimes', 'boolean'], 'is_bestseller' => ['sometimes', 'boolean'], 'is_new_arrival' => ['sometimes', 'boolean'],
            'requires_assembly' => ['sometimes', 'boolean'], 'assembly_service_available' => ['sometimes', 'boolean'], 'is_oversized' => ['sometimes', 'boolean'],
            'available_in_all_branches' => ['sometimes', 'boolean'],
            'tags' => ['nullable', 'array'], 'tags.*' => ['string', 'max:40'],
            'specifications' => ['nullable', 'array'],
            'specifications.*.label_en' => ['required_with:specifications', 'string', 'max:80'],
            'specifications.*.label_ar' => ['required_with:specifications', 'string', 'max:80'],
            'specifications.*.value_en' => ['required_with:specifications', 'string', 'max:255'],
            'specifications.*.value_ar' => ['required_with:specifications', 'string', 'max:255'],
            'translations' => [$req, 'array'],
            'translations.en.name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'translations.ar.name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'translations.*.slug' => ['nullable', 'string', 'max:200'],
            'translations.*.short_description' => ['nullable', 'string', 'max:1000'],
            'translations.*.description' => ['nullable', 'string', 'max:20000'],
            'translations.*.material' => ['nullable', 'string', 'max:255'],
            'translations.*.seo_title' => ['nullable', 'string', 'max:255'],
            'translations.*.seo_description' => ['nullable', 'string', 'max:500'],
            'translations.*.search_keywords' => ['nullable', 'string', 'max:1000'],
            'variants' => ['sometimes', 'array'],
            'variants.*.sku' => ['required_with:variants', 'string', 'max:64', 'distinct'],
            'variants.*.barcode' => ['nullable', 'string', 'max:64', 'regex:'.ProductBarcode::PATTERN, 'distinct'],
            'variants.*.price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.sale_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.name_en' => ['nullable', 'string', 'max:255'],
            'variants.*.name_ar' => ['nullable', 'string', 'max:255'],
            'variants.*.attribute_value_ids' => ['nullable', 'array'],
            'variants.*.attribute_value_ids.*' => ['integer', 'exists:product_attribute_values,id'],
            'warranty' => ['nullable', 'array'],
            'warranty.duration_months' => ['nullable', 'integer', 'min:1', 'max:240'],
            'warranty.provider' => ['nullable', 'string', 'max:120'],
            'warranty.terms_en' => ['nullable', 'string', 'max:5000'],
            'warranty.terms_ar' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function rules(): array
    {
        return self::rulesFor($this->isMethod('patch'));
    }
}
