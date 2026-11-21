<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Jobs\ProcessStockAlertsJob;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\StockAlert;
use App\Domain\Catalog\Services\StockAlertNotifier;
use App\Domain\Catalog\Services\StockAlertPricing;
use App\Domain\Catalog\Services\StockAlertService;
use App\Domain\Catalog\Services\StockAlertSettings;
use App\Domain\Deals\Services\DealService;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Notifications\Services\NotificationRules;
use App\Domain\Pricing\Services\PricingService;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Catalog\ProductEditor;
use App\Livewire\Admin\Catalog\ProductQuickView;
use App\Livewire\Admin\Organization\SettingsIndex;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
| Back-in-stock and price-drop alerts: asking, listing, cancelling, and the messages. Time stands still at a Thursday noon in Qatar so the quiet hours
| (22:00 to 08:00) never get in the way unless a test moves the clock there.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'Asia/Qatar'));
    Cache::flush();
    $this->branchA = Branch::factory()->create(['code' => 'ALA']);
    $this->branchB = Branch::factory()->create(['code' => 'ALB']);
    $this->customer = User::factory()->create(['marketing_consent_at' => now(), 'locale' => 'en']);
    actingAsCustomer($this->customer);
});

/** An active product whose only variant has no stock anywhere yet. */
function alertProduct(string $price = '200.00'): Product
{
    return Product::factory()->create(['price' => $price]);
}

function alertStock(Product $p, Branch $branch, int $units = 5): void
{
    app(InventoryLedger::class)->apply($branch->id, $p->variants()->first()->id, MovementType::OpeningBalance, $units);
}

function alertSettings(array $values): void
{
    foreach ($values as $key => $value) {
        app(Settings::class)->set('alerts.'.$key, $value);
    }
}

function askAlert(Product $p, string $type = 'back_in_stock', ?Branch $branch = null)
{
    return test()->postJson('/api/v1/me/stock-alerts', ['variant_id' => $p->variants()->first()->id, 'type' => $type, 'branch_id' => $branch?->id]);
}

function alertInbox(User $u, string $event)
{
    return Notification::where('user_id', $u->id)->where('event', $event)->get();
}

// ── Asking, listing, cancelling ──────────────────────────────────────────────────────────────────────────────────

it('lets a customer ask to be told when a product is back, shows it in the list and lets them cancel it', function () {
    $p = alertProduct();
    $id = askAlert($p, 'back_in_stock', $this->branchA)->assertCreated()->assertJsonPath('data.subscribed', true)->assertJsonPath('data.alert.type', 'back_in_stock')->json('data.alert.id');

    $list = $this->getJson('/api/v1/me/stock-alerts')->assertOk()->assertJsonPath('meta.count', 1)->assertJsonPath('meta.back_in_stock', true)->assertJsonPath('meta.price_drop', false);
    $list->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.product.id', $p->id)->assertJsonPath('data.0.variant.id', $p->variants()->first()->id)
        ->assertJsonPath('data.0.in_stock', false)->assertJsonPath('data.0.branch_id', $this->branchA->id);
    expect($list->json('data.0.since'))->toContain('2026-11-19T12:00:00+03:00')->and($list->json('data.0.product.name'))->toBe($p->trans('name'));

    $this->deleteJson("/api/v1/me/stock-alerts/{$id}")->assertOk()->assertJsonPath('data.subscribed', false);
    $this->deleteJson("/api/v1/me/stock-alerts/{$id}")->assertOk(); // a retry is fine
    $this->getJson('/api/v1/me/stock-alerts')->assertJsonPath('meta.count', 0)->assertJsonPath('data', []);
});

it('is idempotent: asking twice keeps one alert with its date, and the old delete-by-variant still works', function () {
    $p = alertProduct();
    $first = askAlert($p)->assertCreated()->json('data.alert');
    $this->travelTo(now()->addHour());
    $second = askAlert($p)->assertOk()->json('data.alert');
    expect($second['id'])->toBe($first['id'])->and($second['since'])->toBe($first['since'])->and(StockAlert::count())->toBe(1);

    $this->deleteJson('/api/v1/me/stock-alerts', ['variant_id' => $p->variants()->first()->id, 'type' => 'back_in_stock'])->assertOk()->assertJsonPath('data.subscribed', false);
    expect(StockAlert::count())->toBe(0);
});

it('refuses a back-in-stock alert for something that can be bought now, and a price alert while the owner has it switched off', function () {
    $p = alertProduct();
    alertStock($p, $this->branchA);
    askAlert($p, 'back_in_stock', $this->branchA)->assertStatus(422)->assertJsonPath('code', 'already_available');
    askAlert($p, 'back_in_stock', $this->branchB)->assertCreated(); // sold out at THEIR branch: they may wait for it
    askAlert($p, 'price_drop')->assertStatus(422)->assertJsonPath('code', 'alerts_disabled'); // off by default: marketing-style
    expect(StockAlert::where('type', 'price_drop')->count())->toBe(0);

    alertSettings(['back_in_stock_enabled' => false]);
    askAlert(alertProduct())->assertStatus(422)->assertJsonPath('code', 'alerts_disabled');
});

it('validates the request and keeps one customer from seeing or removing another\'s alerts', function () {
    $p = alertProduct();
    $this->postJson('/api/v1/me/stock-alerts', ['variant_id' => 'nope', 'type' => 'back_in_stock'])->assertStatus(422);
    $this->postJson('/api/v1/me/stock-alerts', ['variant_id' => $p->variants()->first()->id, 'type' => 'wish'])->assertStatus(422);
    $this->postJson('/api/v1/me/stock-alerts', ['variant_id' => (string) Str::uuid(), 'type' => 'back_in_stock'])->assertStatus(422);
    $p->update(['status' => 'inactive']);
    askAlert($p)->assertNotFound();
    $p->update(['status' => 'active']);

    $mine = askAlert($p)->assertCreated()->json('data.alert.id');
    $other = User::factory()->create();
    actingAsCustomer($other);
    $this->getJson('/api/v1/me/stock-alerts')->assertJsonPath('meta.count', 0);
    $this->deleteJson("/api/v1/me/stock-alerts/{$mine}")->assertOk();           // answers like one that is gone ...
    expect(StockAlert::whereKey($mine)->exists())->toBeTrue();                  // ... and touches nothing
    $this->deleteJson('/api/v1/me/stock-alerts', ['variant_id' => $p->variants()->first()->id, 'type' => 'back_in_stock'])->assertOk();
    expect(StockAlert::whereKey($mine)->exists())->toBeTrue();

    $this->getJson('/api/v1/stock-alerts/config')->assertOk()->assertJsonPath('data.back_in_stock', true)->assertJsonPath('data.price_drop', false)
        ->assertJsonPath('data.price_drop_percent', 5)->assertJsonPath('data.price_drop_min_amount', '5.00');
});

it('stops at 100 alerts per customer and starts an expired alert over', function () {
    $p = alertProduct();
    $variants = collect(range(1, StockAlertSettingsLimit()))->map(fn () => alertProduct()->variants()->first());
    foreach ($variants as $v) {
        StockAlert::create(['user_id' => $this->customer->id, 'product_variant_id' => $v->id, 'product_id' => $v->product_id, 'type' => 'back_in_stock', 'expires_at' => now()->addDays(30)]);
    }
    askAlert($p)->assertStatus(422)->assertJsonPath('code', 'too_many_alerts');

    $old = StockAlert::where('product_variant_id', $variants[1]->id)->firstOrFail();
    $old->update(['expires_at' => now()->subDay()]); // one expired: no longer counts
    askAlert($p)->assertCreated();

    $old->update(['notified_at' => now()->subDays(2), 'created_at' => now()->subDays(40)]);
    $this->deleteJson('/api/v1/me/stock-alerts/'.StockAlert::where('product_id', $p->id)->value('id'))->assertOk();
    $this->postJson('/api/v1/me/stock-alerts', ['variant_id' => $variants[1]->id, 'type' => 'back_in_stock'])->assertCreated(); // fired and expired earlier, asked again: it starts over
    expect($old->fresh()->notified_at)->toBeNull()->and($old->fresh()->created_at->toDateString())->toBe(now()->toDateString())->and($old->fresh()->expires_at->isFuture())->toBeTrue();
});

function StockAlertSettingsLimit(): int
{
    return StockAlertSettings::MAX_PER_CUSTOMER;
}

it('counts the customers waiting for a product once each, for the dashboard', function () {
    alertSettings(['price_drop_enabled' => true]);
    $p = alertProduct();
    askAlert($p, 'back_in_stock')->assertCreated();
    askAlert($p, 'price_drop')->assertCreated();
    $other = User::factory()->create();
    actingAsCustomer($other);
    askAlert($p, 'back_in_stock')->assertCreated();
    expect(app(StockAlertService::class)->waiting($p->id))->toBe(['back_in_stock' => 2, 'price_drop' => 1])
        ->and(app(StockAlertService::class)->waiting(alertProduct()->id))->toBe(['back_in_stock' => 0, 'price_drop' => 0]);

    $admin = staffWithRole('admin');
    $this->actingAs($admin);
    Livewire::test(ProductEditor::class, ['product' => $p])->assertSee('2 customers are waiting for it to be back')->assertSee('1 customer watches the price');
    Livewire::test(ProductQuickView::class, ['productId' => $p->id])->assertSee('2 customers are waiting for it to be back');
});

// ── Back in stock ────────────────────────────────────────────────────────────────────────────────────────────────

it('tells each customer once when the product is back at their branch, with a link to it', function () {
    $p = alertProduct();
    askAlert($p, 'back_in_stock', $this->branchA)->assertCreated();
    askAlert($p, 'back_in_stock')->assertOk(); // the same alert again

    alertStock($p, $this->branchB);                                                   // stock arrives at ANOTHER branch: not theirs
    expect(alertInbox($this->customer, 'stock.back_in_stock'))->toHaveCount(0);

    alertStock($p, $this->branchA);                                                   // at theirs: the stock movement queues the job (sync here)
    $got = alertInbox($this->customer, 'stock.back_in_stock');
    expect($got)->toHaveCount(1)->and($got[0]->title)->toBe('Back in stock')->and($got[0]->body)->toBe($p->trans('name').' is available again.')
        ->and($got[0]->data['deep_link'])->toBe('albahar://products/'.$p->id);
    expect(StockAlert::firstOrFail()->notified_at)->not->toBeNull();

    app(StockAlertNotifier::class)->run();                                            // the scheduler's pass, and more stock: nothing again
    alertStock($p, $this->branchA, 3);
    expect(alertInbox($this->customer, 'stock.back_in_stock'))->toHaveCount(1);
    $this->getJson('/api/v1/me/stock-alerts')->assertJsonPath('meta.count', 0); // done: no longer listed
});

it('applies to any branch when the customer named none, and sends Arabic to an Arabic customer', function () {
    $this->customer->update(['locale' => 'ar']);
    $p = alertProduct();
    askAlert($p)->assertCreated();
    alertStock($p, $this->branchB);
    $n = alertInbox($this->customer, 'stock.back_in_stock')->first();
    expect($n->title)->toBe('عاد إلى المخزون')->and($n->body)->toBe($p->trans('name', 'ar').' متوفر مجدداً.');
});

it('never announces a product that sold out again before the job looked, and keeps waiting', function () {
    $p = alertProduct();
    askAlert($p, 'back_in_stock', $this->branchA)->assertCreated();
    Queue::fake();
    alertStock($p, $this->branchA, 1);                                                  // the stock movement only queues the look ...
    Queue::assertPushed(ProcessStockAlertsJob::class);
    app(InventoryLedger::class)->apply($this->branchA->id, $p->variants()->first()->id, MovementType::AdjustmentOut, -1);   // ... and it sold out meanwhile
    expect(app(StockAlertNotifier::class)->backInStock())->toBe(0)->and(alertInbox($this->customer, 'stock.back_in_stock'))->toHaveCount(0)
        ->and(StockAlert::firstOrFail()->notified_at)->toBeNull();

    alertStock($p, $this->branchA, 2);                                                  // later it really is back
    expect(app(StockAlertNotifier::class)->backInStock())->toBe(1)->and(alertInbox($this->customer, 'stock.back_in_stock'))->toHaveCount(1);
});

it('does not tell anyone twice when two passes run at the same moment', function () {
    $p = alertProduct();
    askAlert($p)->assertCreated();
    Queue::fake();
    alertStock($p, $this->branchA);
    $alert = StockAlert::with(['user', 'variant.product.translations'])->firstOrFail();
    StockAlert::whereKey($alert->id)->update(['notified_at' => now()]);                // the other pass claimed it a moment ago
    expect(app(StockAlertNotifier::class)->backInStock())->toBe(0)->and(alertInbox($this->customer, 'stock.back_in_stock'))->toHaveCount(0);
});

it('waits for the end of the quiet hours, and for the switches', function () {
    $p = alertProduct();
    askAlert($p, 'back_in_stock', $this->branchA)->assertCreated();
    $this->travelTo(CarbonImmutable::parse('2026-11-19 23:00:00', 'Asia/Qatar'));
    alertStock($p, $this->branchA);
    expect(alertInbox($this->customer, 'stock.back_in_stock'))->toHaveCount(0)->and(StockAlert::firstOrFail()->notified_at)->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-11-20 08:05:00', 'Asia/Qatar'));
    alertSettings(['back_in_stock_enabled' => false]);
    expect(app(StockAlertNotifier::class)->backInStock())->toBe(0);
    alertSettings(['back_in_stock_enabled' => true]);
    app(NotificationRules::class)->set('stock.back_in_stock', false);
    expect(app(StockAlertNotifier::class)->backInStock())->toBe(0)->and(StockAlert::firstOrFail()->notified_at)->toBeNull();
    app(NotificationRules::class)->reset('stock.back_in_stock');
    expect(app(StockAlertNotifier::class)->backInStock())->toBe(1)->and(alertInbox($this->customer, 'stock.back_in_stock'))->toHaveCount(1);
});

it('reaches a customer who has not agreed to marketing (they asked for this one) but not one who turned offers off by hand', function () {
    $this->customer->update(['marketing_consent_at' => null]);
    $p = alertProduct();
    askAlert($p)->assertCreated();
    alertStock($p, $this->branchA);
    expect(alertInbox($this->customer, 'stock.back_in_stock'))->toHaveCount(1);

    $q = alertProduct();
    askAlert($q)->assertCreated();
    NotificationPreference::create(['user_id' => $this->customer->id, 'channel' => 'in_app', 'category' => 'marketing', 'is_enabled' => false]);
    alertStock($q, $this->branchA);
    expect(alertInbox($this->customer, 'stock.back_in_stock'))->toHaveCount(1); // their own switch wins
});

it('skips an alert whose product is switched off at the branch, and expired alerts', function () {
    $p = alertProduct();
    $q = alertProduct();
    askAlert($p, 'back_in_stock', $this->branchA)->assertCreated();
    askAlert($q, 'back_in_stock', $this->branchA)->assertCreated();
    StockAlert::where('product_id', $q->id)->update(['expires_at' => now()->subMinute()]);
    app(PricingService::class)->setBranchAvailability($p, $this->branchA->id, false);
    alertStock($p, $this->branchA);
    alertStock($q, $this->branchA);
    expect(alertInbox($this->customer, 'stock.back_in_stock'))->toHaveCount(0);
    app(PricingService::class)->setBranchAvailability($p, $this->branchA->id, true);
    expect(app(StockAlertNotifier::class)->run()['back_in_stock'])->toBe(1);
});

it('removes old alerts at night: expired ones and back-in-stock alerts that fired a month ago', function () {
    $p = alertProduct();
    $mk = fn (array $o) => StockAlert::create($o + ['user_id' => User::factory()->create()->id, 'product_variant_id' => $p->variants()->first()->id, 'product_id' => $p->id, 'type' => 'back_in_stock', 'expires_at' => now()->addDays(10)]);
    $keep = $mk([]);
    $expired = $mk(['expires_at' => now()->subDay()]);
    $firedOld = $mk(['notified_at' => now()->subDays(31)]);
    $firedRecent = $mk(['notified_at' => now()->subDays(2)]);
    expect(app(StockAlertService::class)->prune())->toBe(2)->and(StockAlert::pluck('id')->all())->toEqualCanonicalizing([$keep->id, $firedRecent->id]);
    expect($expired->exists)->toBeTrue(); // (the models are only handles)
});

// ── Price drops ──────────────────────────────────────────────────────────────────────────────────────────────────

function priceWorld(object $t, string $price = '200.00'): Product
{
    alertSettings(['price_drop_enabled' => true]);
    $p = alertProduct($price);
    alertStock($p, $t->branchA, 10);
    test()->postJson('/api/v1/me/stock-alerts', ['variant_id' => $p->variants()->first()->id, 'type' => 'price_drop', 'branch_id' => $t->branchA->id])->assertCreated();

    return $p;
}

it('remembers the price the customer saw, and announces a real drop once, with what it was', function () {
    $p = priceWorld($this);
    expect(StockAlert::firstOrFail()->reference_price)->toBe('200.00');
    $this->getJson('/api/v1/me/stock-alerts')->assertJsonPath('data.0.reference_price.amount', '200.00')->assertJsonPath('data.0.current_price.amount', '200.00')->assertJsonPath('data.0.in_stock', true);

    app(PricingService::class)->setProductPrice($p, '200.00', '150.00');              // a sale: 25 % off
    $n = alertInbox($this->customer, 'stock.price_drop');
    expect($n)->toHaveCount(1)->and($n[0]->body)->toBe($p->trans('name').' is now QAR 150.00 (was QAR 200.00).')->and($n[0]->data['deep_link'])->toBe('albahar://products/'.$p->id);
    expect(StockAlert::firstOrFail()->reference_price)->toBe('150.00')->and(StockAlert::firstOrFail()->notified_at)->not->toBeNull();

    app(StockAlertNotifier::class)->run();                                            // the scheduler again, same price: nothing
    app(StockAlertNotifier::class)->priceDrops($p->id);
    expect(alertInbox($this->customer, 'stock.price_drop'))->toHaveCount(1);
    $this->getJson('/api/v1/me/stock-alerts')->assertJsonPath('meta.count', 1)->assertJsonPath('data.0.reference_price.amount', '150.00'); // it keeps watching
});

it('ignores drops that are too small, and moves with a price that rises so the next fall is a new drop', function () {
    $p = priceWorld($this);
    $pricing = app(PricingService::class);
    $pricing->setProductPrice($p, '195.00', null);                                      // 2.5 % and 5 QAR: below the percentage
    $pricing->setProductPrice($p, '192.00', null);                                      // 4 %: still below
    expect(alertInbox($this->customer, 'stock.price_drop'))->toHaveCount(0);

    alertSettings(['price_drop_percent' => 2, 'price_drop_min_amount' => '10.00']);
    app(StockAlertNotifier::class)->priceDrops();                                       // 4 % but 8 QAR: below the amount
    expect(alertInbox($this->customer, 'stock.price_drop'))->toHaveCount(0);

    $pricing->setProductPrice($p, '300.00', null);                                      // up: the customer's picture follows
    expect(StockAlert::firstOrFail()->reference_price)->toBe('300.00');
    $pricing->setProductPrice($p, '270.00', null);                                      // 10 % down from what they last saw
    expect(alertInbox($this->customer, 'stock.price_drop'))->toHaveCount(1);
});

it('counts a countdown offer only while it runs, with units left', function () {
    $this->w = null;
    $p = priceWorld($this);
    $admin = staffWithRole('marketing_manager');
    $svc = app(DealService::class);
    $mk = fn (array $o) => $svc->save($admin, null, array_merge(['name_en' => 'Drop', 'name_ar' => 'عرض', 'is_active' => true, 'starts_at' => null, 'ends_at' => now()->addDay(), 'discount_type' => 'percent',
        'discount_value' => '30', 'limit_per_order' => 2, 'show_on_home' => false, 'products' => [['product_id' => $p->id, 'quantity_total' => 5]]], $o));

    $mk(['starts_at' => now()->addHours(3), 'ends_at' => now()->addDay()]);           // not started: no drop on paper
    app(StockAlertNotifier::class)->run();
    expect(alertInbox($this->customer, 'stock.price_drop'))->toHaveCount(0);

    $this->travelTo(now()->addHours(4));                                              // the offer is running now: 140.00, a 30 % drop
    app(StockAlertNotifier::class)->run();
    $n = alertInbox($this->customer, 'stock.price_drop');
    expect($n)->toHaveCount(1)->and($n[0]->body)->toContain('QAR 140.00');

    $this->travelTo(now()->addDay());                                                 // it ended: the price is back up and the alert follows it
    app(StockAlertNotifier::class)->run();
    expect(StockAlert::firstOrFail()->reference_price)->toBe('200.00');
});

it('never announces a drop on a product that is out of stock, or to a customer who has not agreed to offers', function () {
    $p = priceWorld($this);
    app(InventoryLedger::class)->apply($this->branchA->id, $p->variants()->first()->id, MovementType::AdjustmentOut, -10);   // sold out
    app(PricingService::class)->setProductPrice($p, '200.00', '100.00');
    expect(alertInbox($this->customer, 'stock.price_drop'))->toHaveCount(0);
    alertStock($p, $this->branchA, 4);                                                // back, still cheap: now it is news
    app(StockAlertNotifier::class)->run();
    expect(alertInbox($this->customer, 'stock.price_drop'))->toHaveCount(1);

    $q = priceWorld($this, '400.00');
    $this->customer->update(['marketing_consent_at' => null]);
    app(PricingService::class)->setProductPrice($q, '400.00', '300.00');
    expect(alertInbox($this->customer, 'stock.price_drop'))->toHaveCount(1);              // still just the one
    expect(StockAlert::where('product_id', $q->id)->firstOrFail()->reference_price)->toBe('400.00'); // and it was not used up
});

it('sends at most the weekly number of price drops per customer, the biggest first', function () {
    $products = collect([['200.00', '190.00'], ['200.00', '160.00'], ['200.00', '100.00'], ['200.00', '170.00']])->map(fn ($pair) => [priceWorld($this, $pair[0]), $pair[1]]);
    alertSettings(['max_price_pushes_per_week' => 3, 'quiet_start' => '', 'quiet_end' => '']);
    foreach ($products as [$p, $new]) {
        DB::table('products')->where('id', $p->id)->update(['sale_price' => $new]);   // edited behind the event queue: only the scheduler pass sees them all at once
    }
    expect(app(StockAlertNotifier::class)->priceDrops())->toBe(3);
    $told = alertInbox($this->customer, 'stock.price_drop')->pluck('body')->map(fn ($b) => Str::between($b, 'is now QAR ', ' ('))->sort()->values()->all();
    expect($told)->toBe(['100.00', '160.00', '170.00']);                                // the 5 % drop (190.00) waited
    expect(app(StockAlertNotifier::class)->priceDrops())->toBe(0);                      // the cap holds on the next pass

    $this->travelTo(now()->addDays(8));
    expect(app(StockAlertNotifier::class)->priceDrops())->toBe(1);                      // a week later the one that waited goes
});

it('holds price drops in the quiet hours and while the switches are off', function () {
    $p = priceWorld($this);
    $this->travelTo(CarbonImmutable::parse('2026-11-19 22:30:00', 'Asia/Qatar'));
    app(PricingService::class)->setProductPrice($p, '200.00', '100.00');
    expect(alertInbox($this->customer, 'stock.price_drop'))->toHaveCount(0);
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00', 'Asia/Qatar'));
    app(NotificationRules::class)->set('stock.price_drop', false);
    expect(app(StockAlertNotifier::class)->priceDrops())->toBe(0);
    app(NotificationRules::class)->reset('stock.price_drop');
    alertSettings(['price_drop_enabled' => false]);
    expect(app(StockAlertNotifier::class)->priceDrops())->toBe(0);
    alertSettings(['price_drop_enabled' => true]);
    expect(app(StockAlertNotifier::class)->priceDrops())->toBe(1);
});

it('is cheap: pricing 30 alerts of one product reads the catalogue as often as pricing one', function () {
    alertSettings(['price_drop_enabled' => true]);
    $p = alertProduct();
    alertStock($p, $this->branchA, 10);
    $mk = fn () => StockAlert::create(['user_id' => User::factory()->create()->id, 'product_variant_id' => $p->variants()->first()->id, 'product_id' => $p->id, 'branch_id' => $this->branchA->id,
        'type' => 'price_drop', 'reference_price' => '200.00', 'expires_at' => now()->addDays(30)]);
    $count = function (int $alerts) use ($mk) {
        $rows = collect(range(1, $alerts))->map(fn () => $mk());
        DB::flushQueryLog();
        DB::enableQueryLog();
        $priced = app(StockAlertPricing::class)->forAlerts($rows);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();
        expect($priced)->toHaveCount($alerts);

        return $n;
    };
    $one = $count(1);
    expect($count(30))->toBe($one);
});

// ── Settings page ────────────────────────────────────────────────────────────────────────────────────────────────

it('lets the owner set the thresholds in Settings, with limits, and refuses nonsense', function () {
    $admin = staffWithRole('admin');
    $this->actingAs($admin);
    Livewire::test(SettingsIndex::class, ['tab' => 'notifications'])
        ->set('values.notifications.alerts__price_drop_enabled', true)->set('values.notifications.alerts__price_drop_percent', '12')
        ->set('values.notifications.alerts__price_drop_min_amount', '20.00')->set('values.notifications.alerts__max_price_pushes_per_week', '2')
        ->set('values.notifications.alerts__retention_days', '30')->set('values.notifications.alerts__quiet_start', '23:00')->set('values.notifications.alerts__quiet_end', '07:30')
        ->call('save')->assertHasNoErrors();
    $s = app(Settings::class);
    expect($s->get('alerts.price_drop_enabled'))->toBeTrue()->and($s->get('alerts.price_drop_percent'))->toBe(12)->and($s->get('alerts.max_price_pushes_per_week'))->toBe(2)
        ->and(app(StockAlertSettings::class)->minAmount()->toDecimal())->toBe('20.00')
        ->and(app(StockAlertSettings::class)->quietHours()->from)->toBe('23:00');

    Livewire::test(SettingsIndex::class, ['tab' => 'notifications'])
        ->set('values.notifications.alerts__price_drop_percent', '95')->set('values.notifications.alerts__max_price_pushes_per_week', '0')
        ->set('values.notifications.alerts__quiet_start', '25:00')->call('save')
        ->assertHasErrors(['values.notifications.alerts__price_drop_percent', 'values.notifications.alerts__max_price_pushes_per_week', 'values.notifications.alerts__quiet_start']);

    // A hand-edited value outside the limits can never produce an absurd rule.
    $s->set('alerts.price_drop_percent', 500);
    $s->set('alerts.price_drop_min_amount', 'abc');
    expect(app(StockAlertSettings::class)->dropPercent())->toBe(90)->and(app(StockAlertSettings::class)->minAmount()->toDecimal())->toBe('5.00');
});
