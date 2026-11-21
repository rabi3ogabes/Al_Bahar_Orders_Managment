<?php

use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderInvoice;
use App\Domain\Orders\Models\ReturnRequest;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Organization\SettingsIndex;
use App\Livewire\Admin\Sales\ReturnIndex;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/** A delivered cash-on-delivery order of the signed-in customer with two items handed over, inside the return window. */
function deliveredOrder(): array
{
    $w = commerceWorld();
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 2]);
    $order = Order::find(quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->json('data.order.id'));
    $order->fulfillments()->update(['status' => 'delivered', 'delivered_at' => now()->subDay()]);
    $item = $order->items()->first();
    $item->forceFill(['quantity_handed_over' => 2])->save();

    return [$w, $order->fresh(), $item];
}

it('serves the small public app config: designed-by lines and the returns switch', function () {
    $r = $this->getJson('/api/v1/app-config')->assertOk()
        ->assertJsonPath('data.designed_by.en', 'Designed by Athar Qatar')->assertJsonPath('data.designed_by.ar', 'تصميم أثر قطر')->assertJsonPath('data.returns_enabled', true)
        ->assertJsonPath('data.ops.status_style', 'buttons');
    expect($r->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=60');

    $s = app(Settings::class);
    $s->set('customer_app.designed_by_en', '  Designed by Studio X  ');
    $s->set('customer_app.designed_by_ar', null);
    $s->set('returns.enabled', false);
    $this->getJson('/api/v1/app-config')->assertOk()->assertJsonPath('data.designed_by.en', 'Designed by Studio X')->assertJsonPath('data.designed_by.ar', '')
        ->assertJsonPath('data.returns_enabled', false)->assertJsonPath('data.ops.status_style', 'buttons');
});

it('lets the admin edit the designed-by lines and the returns switch in Settings, and clear a line to hide it', function () {
    $this->actingAs(staffWithRole('super_admin'));
    Livewire::test(SettingsIndex::class)->set('tab', 'general')
        ->set('values.general.customer_app__designed_by_en', 'Designed by Studio X')->set('values.general.customer_app__designed_by_ar', '')
        ->call('save')->assertHasNoErrors();
    Livewire::test(SettingsIndex::class)->set('tab', 'checkout')->set('values.checkout.returns__enabled', false)->call('save')->assertHasNoErrors();

    $this->getJson('/api/v1/app-config')->assertJsonPath('data.designed_by.en', 'Designed by Studio X')->assertJsonPath('data.designed_by.ar', '')->assertJsonPath('data.returns_enabled', false);
    Livewire::test(SettingsIndex::class)->set('tab', 'general')->set('values.general.customer_app__designed_by_en', str_repeat('x', 81))->call('save')
        ->assertHasErrors(['values.general.customer_app__designed_by_en']);
});

it('offers "Return order" while the switch is on and hides and refuses it when the admin turns it off', function () {
    [, $order, $item] = deliveredOrder();
    $body = ['reason_code' => 'changed_mind', 'items' => [['order_item_id' => $item->id, 'quantity' => 1]]];

    $this->getJson("/api/v1/orders/{$order->id}")->assertOk()->assertJsonPath('data.can_return', true);
    app(Settings::class)->set('returns.enabled', false);
    $this->getJson("/api/v1/orders/{$order->id}")->assertOk()->assertJsonPath('data.can_return', false);
    $this->getJson('/api/v1/orders')->assertOk()->assertJsonMissing(['can_return' => true]);
    $this->postJson("/api/v1/orders/{$order->id}/returns", $body)->assertForbidden();
    expect(ReturnRequest::count())->toBe(0);

    app(Settings::class)->set('returns.enabled', true);
    $this->getJson("/api/v1/orders/{$order->id}")->assertJsonPath('data.can_return', true);
    $this->postJson("/api/v1/orders/{$order->id}/returns", $body)->assertCreated();
    expect(ReturnRequest::count())->toBe(1);
});

it('keeps handling return requests made before the switch was turned off', function () {
    [, $order, $item] = deliveredOrder();
    $this->postJson("/api/v1/orders/{$order->id}/returns", ['reason_code' => 'changed_mind', 'items' => [['order_item_id' => $item->id, 'quantity' => 1]]])->assertCreated();
    app(Settings::class)->set('returns.enabled', false);

    $this->actingAs(staffWithRole('super_admin'));
    Livewire::test(ReturnIndex::class)->assertSee(ReturnRequest::first()->number);
});

it('downloads the invoice as a PDF in the order language, once issued and always the same number', function () {
    [$w, $order] = deliveredOrder();
    foreach (['en', 'ar'] as $locale) {
        $order->forceFill(['locale' => $locale])->save();
        $r = $this->get("/api/v1/orders/{$order->id}/invoice")->assertOk();
        expect($r->headers->get('content-type'))->toContain('pdf')->and($r->headers->get('content-disposition'))->toContain('INV-'.$order->number.'.pdf')
            ->and(substr($r->getContent(), 0, 5))->toBe('%PDF-');
    }
    expect(OrderInvoice::where('order_id', $order->id)->count())->toBe(1);

    $order->forceFill(['status' => 'cancelled'])->save();
    $this->get("/api/v1/orders/{$order->id}/invoice")->assertOk(); // already invoiced orders stay downloadable
});

it('refuses the invoice while payment is pending and never shows another customer\'s invoice', function () {
    [$w, $order] = deliveredOrder();
    $order->forceFill(['status' => 'pending_payment'])->save();
    $this->get("/api/v1/orders/{$order->id}/invoice")->assertStatus(409);

    $order->forceFill(['status' => 'confirmed'])->save();
    actingAsCustomer();
    $this->get("/api/v1/orders/{$order->id}/invoice")->assertNotFound();
    Cache::flush();
});
