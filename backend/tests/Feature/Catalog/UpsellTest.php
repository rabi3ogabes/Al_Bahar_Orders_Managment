<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\UpsellService;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Livewire\Admin\Catalog\ProductEditor;
use App\Livewire\Admin\Catalog\ProductUpsell;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function upsellProduct(array $attrs = [], ?Branch $stockAt = null, int $stock = 5, ?string $name = null): Product
{
    $p = Product::factory()->create($attrs + ['price' => '300.00']);
    if ($name) {
        $p->translations()->where('locale', 'en')->first()->update(['name' => $name]);
    }
    if ($stockAt && $stock > 0) {
        app(InventoryLedger::class)->apply($stockAt->id, $p->variants()->first()->id, MovementType::OpeningBalance, $stock);
    }

    return $p->fresh();
}

function upsellIds(Product $p): array
{
    return DB::table('product_related')->where('product_id', $p->id)->where('type', 'upsell')->orderBy('sort_order')->pluck('related_product_id')->all();
}

describe('admin editor', function () {
    beforeEach(function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->branch = Branch::factory()->create();
        $this->admin = User::factory()->superAdmin()->create();
        $this->product = upsellProduct(['sku' => 'MAIN-1'], $this->branch);
    });

    it('renders the upsell tab and counts chosen products', function () {
        $other = upsellProduct([], $this->branch);
        app(UpsellService::class)->add($this->product, $other->id);
        $this->actingAs($this->admin)->get(route('admin.products.edit', ['product' => $this->product, 'tab' => 'upsell']))->assertOk()->assertSee($other->sku);
    });

    it('adds, removes with undo, and reorders suggestions instantly, with an audit trail', function () {
        $a = upsellProduct([], $this->branch);
        $b = upsellProduct([], $this->branch);
        $c = upsellProduct([], $this->branch);
        $this->actingAs($this->admin);
        $t = Livewire::test(ProductUpsell::class, ['product' => $this->product]);
        foreach ([$a, $b, $c] as $p) {
            $t->call('add', $p->id)->assertHasNoErrors();
        }
        expect(upsellIds($this->product))->toBe([$a->id, $b->id, $c->id]);

        $t->call('move', $c->id, -1);
        expect(upsellIds($this->product))->toBe([$a->id, $c->id, $b->id]);
        $t->call('move', $a->id, -1); // already first: no change
        expect(upsellIds($this->product))->toBe([$a->id, $c->id, $b->id]);

        $t->call('remove', $a->id)->assertDispatched('toast', fn ($name, $params) => ($params['undoEvent'] ?? null) === 'upsell-undo' && $params['undoParams']['position'] === 0);
        expect(upsellIds($this->product))->toBe([$c->id, $b->id]);
        $t->call('undo', $this->product->id, $a->id, 0);
        expect(upsellIds($this->product))->toBe([$a->id, $c->id, $b->id]);
        expect(DB::table('product_related')->where('product_id', $this->product->id)->pluck('sort_order')->sort()->values()->all())->toBe([0, 1, 2]);

        expect(AuditLog::where('auditable_id', $this->product->id)->where('action', 'like', 'catalog.upsell_%')->pluck('action')->unique()->sort()->values()->all())
            ->toBe(['catalog.upsell_added', 'catalog.upsell_removed', 'catalog.upsell_reordered']);
    });

    it('never lets a product suggest itself, an unpublished product or the same product twice', function () {
        $draft = upsellProduct(['status' => 'draft']);
        $a = upsellProduct([], $this->branch);
        $this->actingAs($this->admin);
        $t = Livewire::test(ProductUpsell::class, ['product' => $this->product]);
        $t->call('add', $this->product->id)->call('add', $draft->id)->call('add', $a->id)->call('add', $a->id);
        expect(upsellIds($this->product))->toBe([$a->id]);
        expect(fn () => DB::table('product_related')->insert(['product_id' => $a->id, 'related_product_id' => $a->id, 'type' => 'upsell', 'sort_order' => 0]))->toThrow(Exception::class);
    });

    it('searches by name or SKU in either language, hiding itself, drafts and chosen products', function () {
        $svc = app(UpsellService::class);
        $match = upsellProduct(['sku' => 'LAMP-9'], $this->branch, name: 'Brass Table Lamp');
        $chosen = upsellProduct(['sku' => 'LAMP-8'], $this->branch, name: 'Brass Desk Lamp');
        $draft = upsellProduct(['status' => 'draft', 'sku' => 'LAMP-7'], name: 'Brass Floor Lamp');
        $svc->add($this->product, $chosen->id);
        expect($svc->candidates($this->product, 'brass lamp')->pluck('id')->all())->toBe([$match->id])
            ->and($svc->candidates($this->product, 'LAMP-9')->pluck('id')->all())->toBe([$match->id])
            ->and($svc->candidates($this->product, 'MAIN-1')->pluck('id')->all())->toBe([])
            ->and($svc->candidates($this->product, '  ')->count())->toBe(0);
        $ar = $match->translations()->where('locale', 'ar')->first();
        $ar->update(['name' => 'مصباح نحاسي']);
        expect($svc->candidates($this->product, 'مصباح')->pluck('id')->all())->toBe([$match->id]);

        $this->actingAs($this->admin);
        Livewire::test(ProductUpsell::class, ['product' => $this->product])->set('search', 'LAMP-9')->assertSee('LAMP-9')->assertDontSee('LAMP-7');
    });

    it('stops at 8 suggestions', function () {
        $this->actingAs($this->admin);
        $t = Livewire::test(ProductUpsell::class, ['product' => $this->product]);
        foreach (range(1, 9) as $i) {
            $t->call('add', upsellProduct([])->id);
        }
        expect(upsellIds($this->product))->toHaveCount(8);
        $t->assertDispatched('toast', fn ($n, $p) => ($p['type'] ?? null) === 'error');
    });

    it('can also suggest this product on the other one, only when asked', function () {
        $a = upsellProduct([], $this->branch);
        $b = upsellProduct([], $this->branch);
        $this->actingAs($this->admin);
        $t = Livewire::test(ProductUpsell::class, ['product' => $this->product]);
        $t->call('add', $a->id);
        expect(upsellIds($a))->toBe([]);
        $t->set('mutual', true)->call('add', $b->id);
        expect(upsellIds($b))->toBe([$this->product->id])->and(app(UpsellService::class)->suggestedByCount($this->product))->toBe(1);
        Livewire::test(ProductUpsell::class, ['product' => $this->product])->assertSee(trans_choice('catalog_admin.upsell_suggested_by', 1, ['count' => 1]));
    });

    it('is read-only without catalog.edit and hidden without catalog.view', function () {
        $a = upsellProduct([], $this->branch);
        $manager = staffWithRole('branch_manager', $this->branch);
        $this->actingAs($manager);
        Livewire::test(ProductUpsell::class, ['product' => $this->product])->call('add', $a->id)->assertForbidden();
        Livewire::test(ProductUpsell::class, ['product' => $this->product])->call('remove', $a->id)->assertForbidden();
        expect(upsellIds($this->product))->toBe([]);
        $this->actingAs(staffWithRole('delivery_employee', $this->branch));
        Livewire::test(ProductUpsell::class, ['product' => $this->product])->assertForbidden();
    });

    it('is a tab of the product editor', function () {
        $this->actingAs($this->admin);
        Livewire::withQueryParams(['tab' => 'upsell'])->test(ProductEditor::class, ['product' => $this->product])->assertSet('tab', 'upsell')->assertSeeLivewire(ProductUpsell::class);
    });
});

describe('customer API', function () {
    beforeEach(function () {
        $this->w = commerceWorld();
        $this->svc = app(UpsellService::class);
    });

    it('returns up to 8 published, purchasable, in-stock upsells in the admin order inside the product detail', function () {
        $a = upsellProduct([], $this->w->a);
        $b = upsellProduct([], $this->w->a);
        $out = upsellProduct([], $this->w->a, stock: 0);
        $draft = upsellProduct(['status' => 'draft'], $this->w->a);
        $other = upsellProduct([], $this->w->b); // stocked only at the other branch
        foreach ([$b, $out, $draft, $a, $other] as $p) {
            DB::table('product_related')->insert(['product_id' => $this->w->p1->id, 'related_product_id' => $p->id, 'type' => 'upsell', 'sort_order' => DB::table('product_related')->where('product_id', $this->w->p1->id)->count()]);
        }
        $r = $this->getJson("/api/v1/products/{$this->w->p1->id}?branch_id={$this->w->a->id}")->assertOk();
        expect(collect($r->json('data.upsell'))->pluck('id')->all())->toBe([$b->id, $a->id]);
        $r->assertJsonStructure(['data' => ['upsell' => [['id', 'slug', 'name', 'variant_id', 'image', 'price' => ['final'], 'in_stock', 'purchasable', 'rating' => ['average', 'count']]]]]);

        // No branch context: stock is summed over active branches, so the other-branch product is orderable too.
        $ids = collect($this->getJson("/api/v1/products/{$this->w->p1->id}")->json('data.upsell'))->pluck('id')->all();
        expect($ids)->toBe([$b->id, $a->id, $other->id]);
        // "related" is unaffected by upsell rows.
        expect($this->getJson("/api/v1/products/{$this->w->p1->id}")->json('data.related'))->toBe([]);
    });

    it('has an empty upsell list when none are chosen, and caps at 8', function () {
        expect($this->getJson("/api/v1/products/{$this->w->p1->id}")->assertOk()->json('data.upsell'))->toBe([]);
        foreach (range(1, 10) as $i) {
            $p = upsellProduct([], $this->w->a);
            DB::table('product_related')->insert(['product_id' => $this->w->p1->id, 'related_product_id' => $p->id, 'type' => 'upsell', 'sort_order' => $i]);
        }
        expect($this->getJson("/api/v1/products/{$this->w->p1->id}")->json('data.upsell'))->toHaveCount(8);
    });

    it('uses a constant number of queries however many upsells there are', function () {
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson("/api/v1/products/{$this->w->p1->id}?branch_id={$this->w->a->id}")->assertOk();

            return count(DB::getQueryLog());
        };
        $this->svc->add($this->w->p1, upsellProduct([], $this->w->a)->id);
        $few = $count();
        foreach (range(1, 5) as $i) {
            $this->svc->add($this->w->p1, upsellProduct([], $this->w->a)->id);
        }
        expect($count())->toBe($few);
    });

    it('suggests deduplicated upsells for the cart, never what is already in it', function () {
        $x = upsellProduct([], $this->w->a);
        $y = upsellProduct([], $this->w->a);
        $z = upsellProduct([], $this->w->a, stock: 0);
        $this->svc->add($this->w->p1, $x->id);
        $this->svc->add($this->w->p1, $y->id);
        $this->svc->add($this->w->p1, $z->id);
        $this->svc->add($this->w->p1, $this->w->p2->id); // p2 is also in the cart below
        $this->svc->add($this->w->p2, $y->id);           // y is suggested by both cart items
        $this->putJson('/api/v1/cart', ['branch_id' => $this->w->a->id])->assertOk();
        $this->getJson('/api/v1/cart/suggestions')->assertOk()->assertJsonPath('data', []);

        $this->postJson('/api/v1/cart/items', ['variant_id' => $this->w->v1->id, 'quantity' => 1])->assertCreated();
        $this->postJson('/api/v1/cart/items', ['variant_id' => $this->w->v2->id, 'quantity' => 1])->assertCreated();
        $ids = collect($this->getJson('/api/v1/cart/suggestions')->assertOk()->json('data'))->pluck('id')->all();
        expect($ids)->toBe([$y->id, $x->id]); // y first (two votes), no duplicates, no p2 (in cart), no z (out of stock)

        // Adding a suggestion to the cart removes it from the strip.
        $this->postJson('/api/v1/cart/items', ['variant_id' => $y->variants()->first()->id, 'quantity' => 1])->assertCreated();
        expect(collect($this->getJson('/api/v1/cart/suggestions')->json('data'))->pluck('id')->all())->toBe([$x->id]);
    });

    it('requires a signed-in customer for cart suggestions', function () {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/cart/suggestions')->assertUnauthorized();
    });
});
