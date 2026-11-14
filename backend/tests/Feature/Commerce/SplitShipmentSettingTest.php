<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Organization\SettingsIndex;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

// Settings → Checkout → "Show the allow split shipment switch" (checkout.show_split_shipment).
// A cart of 5 of one product against two branches holding 3 each can only be filled by splitting.

function splitWorld(bool $oneBranch = false): object
{
    $w = commerceWorld([3, 5], [3, 5]);
    if ($oneBranch) {
        $w->b->update(['status' => 'inactive']);
    }
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 5])->assertCreated();

    return $w;
}

function splitQuote(object $w, array $extra = []): array
{
    return test()->postJson('/api/v1/checkout/quote', ['address_id' => $w->address->id, 'payment_method' => 'cod'] + $extra)->assertOk()->json('data');
}

function setSplitSetting(?bool $value): void
{
    if ($value !== null) {
        app(Settings::class)->set('checkout.show_split_shipment', $value);
    }
}

it('defaults to on, so nothing changes for a business that has several branches', function () {
    expect(config('platform.checkout.show_split_shipment'))->toBeTrue()->and(app(Settings::class)->get('checkout.show_split_shipment'))->toBeTrue();
});

// setting × branches × what the client sends  →  [switch shown, order split]
it('decides the switch and the split from the setting, the branches and the client flag', function (?bool $setting, bool $oneBranch, array $sent, bool $shown, bool $split, string $why) {
    setSplitSetting($setting);
    $w = splitWorld($oneBranch);
    $q = splitQuote($w, $sent);

    expect($q['split_choice'])->toBe($shown, "switch shown: $why")->and($q['is_split'])->toBe($split, "split: $why")
        ->and($q['can_place_order'])->toBe($split, "placeable: $why"); // 5 units cannot come from one branch, so without a split the cart cannot be placed
    if (! $split) {
        expect(collect($q['errors'])->pluck('code'))->toContain('insufficient_stock');
    }
})->with([
    'on, many branches, client silent' => [true, false, [], true, true, 'default behaviour'],
    'on, many branches, client says yes' => [true, false, ['allow_split' => true], true, true, 'customer allows it'],
    'on, many branches, client says no' => [true, false, ['allow_split' => false], true, false, 'customer refuses it'],
    'unset (default), many branches, silent' => [null, false, [], true, true, 'default is on'],
    'off, many branches, client silent' => [false, false, [], false, false, 'the admin hid it'],
    'off, many branches, client says yes' => [false, false, ['allow_split' => true], false, false, 'a client cannot override the admin'],
    'off, many branches, client says no' => [false, false, ['allow_split' => false], false, false, 'same'],
    'on, one branch, client silent' => [true, true, [], false, false, 'only one branch: nothing to choose'],
    'on, one branch, client says yes' => [true, true, ['allow_split' => true], false, false, 'only one branch'],
    'on, one branch, client says no' => [true, true, ['allow_split' => false], false, false, 'only one branch'],
    'off, one branch, client says yes' => [false, true, ['allow_split' => true], false, false, 'off and one branch'],
    'off, one branch, client silent' => [false, true, [], false, false, 'off and one branch'],
]);

it('does not offer the switch when one branch can fill the whole cart on its own', function () {
    $w = commerceWorld([5, 5], [0, 0]); // B is active but holds nothing
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 2])->assertCreated();
    $q = splitQuote($w);
    expect($q['split_choice'])->toBeFalse()->and($q['is_split'])->toBeFalse()->and($q['can_place_order'])->toBeTrue();
});

it('offers the switch when several branches hold the cart, even though one is used', function () {
    $w = commerceWorld([5, 5], [5, 5]);
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1])->assertCreated();
    $q = splitQuote($w);
    expect($q['split_choice'])->toBeTrue()->and($q['is_split'])->toBeFalse()->and($q['can_place_order'])->toBeTrue();
});

it('places a split order only while the setting allows it', function () {
    $w = splitWorld();
    quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod', 'allow_split' => true])->assertCreated();
    expect(Order::first()->fulfillments)->toHaveCount(2);
});

it('refuses to place the order as a split when the admin turned the switch off, even if an old app still sends allow_split', function () {
    setSplitSetting(false);
    $w = splitWorld();
    $opts = ['address_id' => $w->address->id, 'payment_method' => 'cod', 'allow_split' => true];
    $quote = splitQuote($w, ['allow_split' => true]);
    $this->postJson('/api/v1/checkout/orders', $opts + ['quote_hash' => $quote['quote_hash']], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(422)->assertJsonPath('code', 'checkout_invalid');
    expect(Order::count())->toBe(0);
});

it('still places a normal single-branch order with the switch off, ignoring a client that sends allow_split', function () {
    setSplitSetting(false);
    $w = commerceWorld();
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v2->id, 'quantity' => 1])->assertCreated();
    foreach ([['allow_split' => true], ['allow_split' => false], []] as $sent) {
        $res = quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'] + $sent)->assertCreated();
        expect(Order::findOrFail($res->json('data.order.id'))->fulfillments)->toHaveCount(1);
        $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v2->id, 'quantity' => 1])->assertCreated();
    }
});

it('applies the same rule to store pickup', function () {
    $w = commerceWorld([3, 5], [3, 5]);
    $w->a->update(['pickup_enabled' => true]);
    $w->b->update(['pickup_enabled' => true]);
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 5])->assertCreated();
    $pickup = ['fulfillment_type' => 'pickup', 'branch_id' => $w->a->id, 'payment_method' => 'pay_at_pickup'];

    $on = $this->postJson('/api/v1/checkout/quote', $pickup)->assertOk()->json('data');
    expect($on['split_choice'])->toBeTrue()->and($on['is_split'])->toBeTrue();

    setSplitSetting(false);
    $off = $this->postJson('/api/v1/checkout/quote', $pickup + ['allow_split' => true])->assertOk()->json('data');
    expect($off['split_choice'])->toBeFalse()->and($off['is_split'])->toBeFalse()->and(collect($off['errors'])->pluck('code'))->toContain('insufficient_stock');
});

it('lets an admin switch it in Settings → Checkout and keeps the bilingual label and help text', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->superAdmin()->create();
    $this->actingAs($admin);

    $page = Livewire::test(SettingsIndex::class)->set('tab', 'checkout');
    expect($page->get('values.checkout.checkout__show_split_shipment'))->toBeTrue(); // the default shows as ticked
    $page->assertSee('Show "allow split shipment" at checkout')->assertSee('more than one active branch');

    $page->set('values.checkout.checkout__show_split_shipment', false)->call('save')->assertHasNoErrors();
    expect(app(Settings::class)->get('checkout.show_split_shipment'))->toBeFalse();
    $page->set('values.checkout.checkout__show_split_shipment', true)->call('save')->assertHasNoErrors();
    expect(app(Settings::class)->get('checkout.show_split_shipment'))->toBeTrue();

    foreach (['en', 'ar'] as $locale) {
        $strings = require base_path("lang/{$locale}/org_admin.php");
        expect($strings['setting']['checkout__show_split_shipment'] ?? null)->toBeString()->not->toBeEmpty()
            ->and($strings['setting_hint']['checkout__show_split_shipment'] ?? null)->toBeString()->not->toBeEmpty();
    }
});

it('only lets staff who may edit settings change it', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $branch = Branch::factory()->create();
    $this->actingAs(staffWithRole('pickup_employee', $branch))->get(route('admin.settings.index'))->assertForbidden();
});
