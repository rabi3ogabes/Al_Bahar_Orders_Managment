<?php

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Orders\Models\Order;

function namedProduct(string $en, string $ar): Product
{
    $p = Product::factory()->create();
    $p->translations()->where('locale', 'en')->first()->update(['name' => $en]);
    $p->translations()->where('locale', 'ar')->first()->update(['name' => $ar]);

    return $p;
}

it('suggests products, categories and brands while typing, in English and Arabic', function () {
    $p = namedProduct('Lavender Dishwashing Liquid', 'سائل غسيل الأطباق بالخزامى');
    $brand = Brand::create(['slug' => 'lav', 'name_en' => 'Lavita', 'name_ar' => 'لافيتا', 'status' => 'active']);
    $cat = Category::create(['slug' => 'dish-care', 'sort_order' => 1]);
    $cat->translations()->createMany([['locale' => 'en', 'name' => 'Dishwashing'], ['locale' => 'ar', 'name' => 'غسيل الأطباق']]);

    $en = $this->getJson('/api/v1/search/suggest?q=dishwash')->assertOk()->json('data');
    expect(collect($en['products'])->pluck('id'))->toContain($p->id)
        ->and(collect($en['categories'])->pluck('slug'))->toContain('dish-care')
        ->and($en['did_you_mean'])->toBeNull();

    $ar = $this->getJson('/api/v1/search/suggest?q='.urlencode('الاطباق'))->assertOk()->json('data');
    expect(collect($ar['products'])->pluck('id'))->toContain($p->id);

    expect(collect($this->getJson('/api/v1/search/suggest?q=lavi')->json('data.brands'))->pluck('slug'))->toContain('lav');
});

it('offers a "did you mean" correction word by word when nothing matches', function () {
    namedProduct('Lavender Dishwashing Liquid', 'سائل غسيل الأطباق بالخزامى');
    $res = $this->getJson('/api/v1/search/suggest?q=lavendar+dishwashng+bottle')->assertOk()->json('data');

    expect($res['products'])->toBe([])->and($res['did_you_mean'])->toBe('lavender dishwashing bottle');
});

it('rejects too-short queries', function () {
    $this->getJson('/api/v1/search/suggest?q=a')->assertStatus(422);
});

it('lists products the customer already bought, most frequent first, without cancelled orders', function () {
    $w = commerceWorld();
    $this->getJson('/api/v1/me/buy-again')->assertOk()->assertJsonPath('data', []);

    foreach ([[$w->v1, 2], [$w->v1, 1], [$w->v2, 3]] as [$variant, $qty]) {
        $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => $qty]);
        quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod']);
    }
    $rows = $this->getJson('/api/v1/me/buy-again')->assertOk()->json('data');
    expect(collect($rows)->pluck('product.id')->all())->toBe([$w->p1->id, $w->p2->id])
        ->and($rows[0]['times_ordered'])->toBe(2)->and($rows[0]['variant_id'])->toBe($w->v1->id)->and($rows[1]['last_quantity'])->toBe(3);

    Order::query()->update(['status' => 'cancelled']);
    $this->getJson('/api/v1/me/buy-again')->assertOk()->assertJsonPath('data', []);
});

it('needs a signed-in customer for buy again', function () {
    $this->getJson('/api/v1/me/buy-again')->assertStatus(401);
});
