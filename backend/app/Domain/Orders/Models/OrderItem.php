<?php

namespace App\Domain\Orders\Models;

use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['variant_attributes' => 'array', 'quantity' => 'integer', 'quantity_cancelled' => 'integer', 'quantity_returned' => 'integer', 'quantity_handed_over' => 'integer'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(OrderFulfillment::class, 'order_fulfillment_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function activeQuantity(): int
    {
        return $this->quantity - $this->quantity_cancelled;
    }

    public function name(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $this->getAttribute('name_'.$locale) ?: $this->name_en;
    }

    public function variantName(?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();

        return $this->getAttribute('variant_name_'.$locale) ?: $this->variant_name_en;
    }
}
