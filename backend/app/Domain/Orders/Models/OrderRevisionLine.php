<?php

namespace App\Domain\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One changed item of a revision, with the names in both languages frozen at the time (the order line may change again later).
 *
 * @property int $id
 * @property string $order_revision_id
 * @property int $position
 * @property string $action reduce|remove|replace
 * @property string $order_item_id
 * @property ?string $replacement_order_item_id
 * @property ?string $product_variant_id
 * @property string $sku
 * @property string $name_en
 * @property string $name_ar
 * @property ?string $variant_name_en
 * @property ?string $variant_name_ar
 * @property int $from_quantity
 * @property int $to_quantity
 * @property string $unit_price
 * @property string $line_total_before
 * @property string $line_total_after
 * @property int $stock_corrected
 * @property ?array<string, mixed> $replacement
 */
class OrderRevisionLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['replacement' => 'array', 'from_quantity' => 'integer', 'to_quantity' => 'integer', 'stock_corrected' => 'integer', 'position' => 'integer'];

    /** @return BelongsTo<OrderRevision, $this> */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(OrderRevision::class, 'order_revision_id');
    }

    public function name(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return (string) ($this->getAttribute('name_'.$locale) ?: $this->name_en);
    }

    public function variantName(?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();

        return $this->getAttribute('variant_name_'.$locale) ?: $this->variant_name_en;
    }

    /** The substitute's name in $locale (the snapshot stores both languages). */
    public function replacementName(?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();
        $r = $this->replacement;

        return $r === null ? null : (string) (($r['name_'.$locale] ?? null) ?: ($r['name_en'] ?? ''));
    }

    public function replacementVariantName(?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();
        $r = $this->replacement;

        return $r === null ? null : (($r['variant_name_'.$locale] ?? null) ?: ($r['variant_name_en'] ?? null));
    }
}
