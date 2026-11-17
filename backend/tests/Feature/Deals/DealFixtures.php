<?php

use App\Domain\Catalog\Models\Product;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Services\DealService;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Orders\Models\Order;
use Illuminate\Support\Str;

/** The marketing manager every deal in these tests is made by (permissions: marketing.*). */
function dealAdmin(): User
{
    static $cache = [];
    $key = (string) app()->make('db')->connection()->getDatabaseName().spl_object_id(app());
    if (! isset($cache[$key]) || ! User::whereKey($cache[$key]->id)->exists()) {
        $cache[$key] = staffWithRole('marketing_manager');
    }

    return $cache[$key];
}

/**
 * Save a deal through the service, as a marketing manager. Defaults: switched on, running for a day, 30 % off, at most 2 per order,
 * the product `$products` (id => units, null = unlimited) or the world's first product with 10 units.
 *
 * @param  array<string, mixed>  $over
 * @param  array<string, int|null>|null  $products
 */
function makeDeal(array $over = [], ?array $products = null, ?Deal $deal = null): Deal
{
    $rows = [];
    foreach ($products ?? [test()->w->p1->id => 10] as $id => $qty) {
        $rows[] = ['product_id' => $id, 'quantity_total' => $qty];
    }
    $data = array_merge([
        'name_en' => 'Weekend Furniture Drop', 'name_ar' => 'عرض الأثاث', 'tagline_en' => 'Big pieces, small prices', 'tagline_ar' => 'قطع كبيرة وأسعار صغيرة',
        'is_active' => true, 'starts_at' => null, 'ends_at' => now()->addDay(), 'discount_type' => 'percent', 'discount_value' => '30',
        'limit_per_order' => 2, 'limit_per_customer' => null, 'show_on_home' => true, 'products' => $rows,
    ], $over);

    return app(DealService::class)->save(dealAdmin(), $deal, $data);
}

/** A product at `$price` with `$stock` units in branch A of the commerce world. */
function dealProduct(string $price, int $stock = 20): Product
{
    $p = Product::factory()->create(['price' => $price]);
    app(InventoryLedger::class)->apply(test()->w->a->id, $p->variants()->first()->id, MovementType::OpeningBalance, $stock);

    return $p;
}

function dealAddToCart(string $variantId, int $quantity): void
{
    test()->postJson('/api/v1/cart/items', ['variant_id' => $variantId, 'quantity' => $quantity])->assertCreated();
}

/** The checkout options of the commerce world (delivery to the customer's address, paid on delivery). */
function dealOptions(array $extra = []): array
{
    return $extra + ['address_id' => test()->w->address->id, 'payment_method' => 'cod'];
}

function dealQuote(array $extra = []): array
{
    return test()->postJson('/api/v1/checkout/quote', dealOptions($extra))->assertOk()->json('data');
}

/** Place with the hash of a quote taken now. */
function placeDealOrder(array $extra = [], ?string $hash = null)
{
    $hash ??= dealQuote($extra)['quote_hash'];

    return test()->postJson('/api/v1/checkout/orders', dealOptions($extra) + ['quote_hash' => $hash], ['Idempotency-Key' => (string) Str::uuid()]);
}

function dealOrderOf($response): Order
{
    return Order::with('items')->findOrFail($response->json('data.order.id'));
}
