<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Branches\Models\Branch;
use App\Domain\Pricing\Services\PriceResolver;

/**
 * The answer to "I scanned this": the usual product card plus what the app needs to add the item without asking again.
 *
 *  - variant_id:   the exact variant to put in the cart (null when needs_choice)
 *  - needs_choice: the code names a product that has several variants (colour, size ...): the shopper must pick one
 *  - in_stock:     at least one unit is available (at the branch, or in any open branch when no branch is chosen)
 *  - purchasable:  the shop sells it in this branch context (it has a price here); independent of stock
 *  - max_quantity: the most the app may add in one go = min(units available, the per-line cart limit); 0 when it cannot be added,
 *                  null while a variant still has to be chosen
 * The price, picture and SKU of a matched variant replace the card's "from" price of the whole product.
 */
class ScanResultPresenter
{
    public function __construct(private readonly CatalogPresenter $cards, private readonly PriceResolver $prices) {}

    public function present(ProductMatch $match, ?Branch $branch): array
    {
        $product = $match->product;
        $variants = $product->variants->each(fn ($v) => $v->setRelation('product', $product));
        $this->prices->prime($variants, $branch?->id);
        $stock = $this->cards->stockMap($variants->pluck('id')->all(), $branch?->id);
        $card = $this->cards->card($product, $branch, $stock);

        $extra = ['variant_id' => null, 'variant_name' => null, 'variant_sku' => null, 'matched_by' => $match->via,
            'needs_choice' => $match->needsChoice(), 'max_quantity' => null];

        if ($variant = $match->variant) {
            $variant->loadMissing(['attributeValues.attribute', 'images']);
            $price = $this->prices->resolve($variant, $branch);
            $available = max(0, (int) ($stock[$variant->id] ?? 0));
            $own = $variant->images->first();
            $extra = [...$extra, 'variant_id' => $variant->id, 'variant_name' => $variant->displayName(), 'variant_sku' => $variant->sku,
                'in_stock' => $available > 0, 'purchasable' => $price !== null,
                'max_quantity' => $price !== null ? min((int) config('platform.checkout.max_quantity_per_line'), $available) : 0];
            $card['price'] = $price?->toArray();
            if ($own) {
                $card['image'] = ['url' => $own->url(), 'thumb_url' => $own->url(640), 'alt' => $own->alt() ?? $card['name']];
            }
        }

        return array_merge($card, $extra);
    }
}
