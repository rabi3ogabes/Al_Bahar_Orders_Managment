<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserRole;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\ReturnRequest;
use App\Livewire\Admin\Sales\OrderIndex;
use App\Livewire\Admin\Sales\OrderShow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/../Operations/OpsSupport.php';

/**
 * A branch-limited member reads only THEIR branches' parcels of a split order (roadmap 4.6): the order screen, the list rows and the staff API,
 * for the dashboard page, the side window and the deep link. A member with every branch reads it whole.
 */
beforeEach(function () {
    $this->travelTo(qatar('2026-11-19 12:00'));
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->a = Branch::factory()->create(['code' => 'DOH', 'name_en' => 'Doha Branch']);
    $this->b = Branch::factory()->create(['code' => 'WKR', 'name_en' => 'Wakra Branch']);
    $this->c = Branch::factory()->create(['code' => 'KHR', 'name_en' => 'Khor Branch']);
    $this->mgrA = staffWithRole('order_manager', $this->a);
    $this->mgrB = staffWithRole('order_manager', $this->b);
    $this->mgrC = staffWithRole('order_manager', $this->c);
    $this->admin = staffWithRole('admin');

    /** One order with a parcel at every branch given: a line, a history row, a note and an item change each. */
    $this->split = function (array $branches, array $attrs = []): Order {
        static $n = 0;
        $n++;
        $order = Order::create($attrs + ['number' => 'SPLIT'.$n, 'user_id' => null, 'source' => 'app', 'status' => 'confirmed', 'payment_status' => 'pending', 'payment_method' => 'cod',
            'fulfillment_type' => 'delivery', 'currency' => 'QAR', 'subtotal' => '300.00', 'grand_total' => '300.00', 'customer_name' => 'Noor Ali', 'placed_at' => now(), 'adjustment_count' => 0]);
        foreach ($branches as $i => $branch) {
            $f = OrderFulfillment::create(['order_id' => $order->id, 'branch_id' => $branch->id, 'number' => $order->number.'-P'.($i + 1), 'type' => 'delivery', 'status' => 'pending', 'subtotal' => '100.00', 'delivery_fee' => '0.00']);
            OrderItem::create(['order_id' => $order->id, 'order_fulfillment_id' => $f->id, 'branch_id' => $branch->id, 'sku' => 'SKU-'.$branch->code, 'name_en' => 'Item of '.$branch->code,
                'name_ar' => 'صنف '.$branch->code, 'unit_price' => '100.00', 'unit_final_price' => '100.00', 'quantity' => 1, 'line_subtotal' => '100.00', 'line_total' => '100.00']);
            DB::table('order_status_history')->insert(['order_id' => $order->id, 'order_fulfillment_id' => $f->id, 'kind' => 'fulfillment', 'to_status' => 'accepted', 'actor_type' => 'system', 'reason' => 'note-'.$branch->code, 'created_at' => now()]);
            DB::table('order_notes')->insert(['id' => (string) Str::uuid(), 'order_id' => $order->id, 'order_fulfillment_id' => $f->id, 'body' => 'Internal note for '.$branch->code, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('order_revisions')->insert(['id' => (string) Str::uuid(), 'order_id' => $order->id, 'order_fulfillment_id' => $f->id, 'branch_id' => $branch->id, 'number' => $i + 1, 'reason_code' => 'out_of_stock',
                'before' => json_encode(['grand_total' => '300.00']), 'after' => json_encode(['grand_total' => '290.00']), 'difference' => '-10.00', 'created_at' => now()]);
        }
        $order->update(['adjustment_count' => count($branches)]);
        DB::table('order_notes')->insert(['id' => (string) Str::uuid(), 'order_id' => $order->id, 'body' => 'Note for the whole order', 'created_at' => now(), 'updated_at' => now()]);

        return $order->fresh();
    };
    $this->both = ($this->split)([$this->a, $this->b]);
    $this->onlyB = ($this->split)([$this->b]);
});

describe('the order page and its side window', function () {
    it('shows a branch only its own parcel of a split order: the parcel, its line, its timeline, its note and its item change', function () {
        $c = Livewire::actingAs($this->mgrA)->test(OrderShow::class, ['order' => $this->both]);
        $c->assertSee('SPLIT1-P1')->assertSee('Doha Branch')->assertSee('Item of DOH')->assertSee('Internal note for DOH')->assertSee('Note for the whole order')
            ->assertDontSee('SPLIT1-P2')->assertDontSee('Wakra Branch')->assertDontSee('Item of WKR')->assertDontSee('Internal note for WKR')->assertDontSee('SKU-WKR');
        $html = $c->html();
        expect($html)->not->toContain('WKR')->and($html)->not->toContain('Wakra');
        expect($c->viewData('o')->fulfillments->pluck('branch_id')->all())->toBe([$this->a->id])->and($c->viewData('o')->revisions->pluck('branch_id')->all())->toBe([$this->a->id])
            ->and(count($c->viewData('timeline')))->toBe(1);
    });

    it('shows the other branch to the same people when they hold both, and everything to a member with every branch', function () {
        $both = staffWithRole('order_manager', $this->a);
        UserRole::create(['user_id' => $both->id, 'role_id' => Role::where('key', 'order_manager')->value('id'), 'branch_id' => $this->b->id]);
        BranchEmployee::create(['branch_id' => $this->b->id, 'user_id' => $both->id, 'is_active' => true]);
        Livewire::actingAs($both)->test(OrderShow::class, ['order' => $this->both])->assertSee('SPLIT1-P1')->assertSee('SPLIT1-P2')->assertDontSee('KHR');
        Livewire::actingAs($this->admin)->test(OrderShow::class, ['order' => $this->both])->assertSee('SPLIT1-P1')->assertSee('SPLIT1-P2')->assertSee('Internal note for WKR')->assertSee('Item of WKR');
    });

    it('does not open an order of other branches at all, by page, window or deep link', function () {
        $this->actingAs($this->mgrA)->get(route('admin.orders.show', $this->onlyB))->assertForbidden();
        $this->actingAs($this->mgrC)->get(route('admin.orders.show', $this->both))->assertForbidden();
        Livewire::actingAs($this->mgrA)->withQueryParams(['view' => $this->onlyB->id])->test(OrderIndex::class)->assertSet('showOrder', false)->assertSet('viewId', '')->assertDontSee($this->onlyB->number);
        Livewire::actingAs($this->mgrA)->test(OrderIndex::class)->call('peekOrder', $this->onlyB->id)->assertSet('showOrder', false);
        Livewire::actingAs($this->mgrA)->withQueryParams(['view' => $this->both->id])->test(OrderIndex::class)->assertSet('showOrder', true)->assertSee('SPLIT1-P1')->assertDontSee('SPLIT1-P2');
    });

    it('still lets the member act on their own parcel, and cannot touch another one by id', function () {
        $parcelB = $this->both->fulfillments->firstWhere('branch_id', $this->b->id);
        Livewire::actingAs($this->mgrA)->test(OrderShow::class, ['order' => $this->both])->call('advance', $parcelB->id, 'accepted')->assertForbidden();
        expect($parcelB->fresh()->status->value)->toBe('pending');
        Livewire::actingAs($this->mgrA)->test(OrderShow::class, ['order' => $this->both])->call('advance', $this->both->fulfillments->firstWhere('branch_id', $this->a->id)->id, 'accepted');
        expect($this->both->fulfillments->firstWhere('branch_id', $this->a->id)->fresh()->status->value)->toBe('accepted');
    });
});

describe('the order list', function () {
    it('lists an order for each branch that holds a part, with only that branch\'s own parcel on the row', function () {
        $c = Livewire::actingAs($this->mgrA)->test(OrderIndex::class);
        $rows = $c->viewData('orders');
        expect($rows->pluck('number')->all())->toBe(['SPLIT1'])->and($rows->first()->fulfillments->pluck('branch_id')->all())->toBe([$this->a->id]);
        $c->assertSee('DOH')->assertDontSee('WKR');
        $admin = Livewire::actingAs($this->admin)->test(OrderIndex::class);
        expect($admin->viewData('orders')->pluck('number')->sort()->values()->all())->toBe(['SPLIT1', 'SPLIT2'])
            ->and($admin->viewData('orders')->firstWhere('number', 'SPLIT1')->fulfillments->count())->toBe(2);
        $admin->assertSee('WKR');
    });

    it('does not find an order through another branch by searching for its number, customer or parcel', function () {
        $c = Livewire::actingAs($this->mgrA)->test(OrderIndex::class);
        $c->set('search', 'SPLIT2')->assertDontSee('SPLIT2');
        expect($c->viewData('orders')->total())->toBe(0);
        $c->set('search', 'SPLIT1-P2');
        expect($c->viewData('orders')->total())->toBe(0);   // the number of another branch's parcel finds nothing
        $c->set('search', 'SPLIT1');
        expect($c->viewData('orders')->total())->toBe(1);
        $c->set('search', '')->set('branch', $this->b->id);
        expect($c->viewData('orders')->total())->toBe(0);   // a branch filter can only narrow
    });
});

describe('the staff API', function () {
    it('lists and reads an order with the caller\'s own parcels only', function () {
        actingAsStaff($this->mgrA);
        $this->getJson('/api/v1/admin/orders')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', 'SPLIT1')->assertJsonCount(1, 'data.0.fulfillments')
            ->assertJsonPath('data.0.fulfillments.0.branch_id', $this->a->id);
        $show = $this->getJson("/api/v1/admin/orders/{$this->both->id}")->assertOk();
        $show->assertJsonCount(1, 'data.fulfillments')->assertJsonPath('data.fulfillments.0.branch.id', $this->a->id)->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.branch_id', $this->a->id)
            ->assertJsonCount(1, 'data.revisions')->assertJsonCount(1, 'data.history')->assertJsonCount(2, 'data.internal_notes')->assertJsonPath('data.progress', null);
        $json = $show->getContent();
        expect($json)->not->toContain('Wakra Branch')->and($json)->not->toContain('SPLIT1-P2')->and($json)->not->toContain('Item of WKR')->and($json)->not->toContain('Internal note for WKR');
        $this->getJson("/api/v1/admin/orders/{$this->onlyB->id}")->assertForbidden();
    });

    it('gives a member with every branch the whole order', function () {
        actingAsStaff($this->admin);
        $this->getJson("/api/v1/admin/orders/{$this->both->id}")->assertOk()->assertJsonCount(2, 'data.fulfillments')->assertJsonCount(2, 'data.items')->assertJsonCount(2, 'data.revisions')->assertJsonCount(2, 'data.history');
        $this->getJson('/api/v1/admin/orders')->assertOk()->assertJsonCount(2, 'data');
    });

    it('keeps another branch\'s claim out of the detail', function () {
        $claim = fn (Branch $branch) => ReturnRequest::create(['number' => 'RT-'.Str::random(6), 'order_id' => $this->both->id, 'user_id' => User::factory()->create()->id, 'branch_id' => $branch->id,
            'status' => 'requested', 'reason_code' => 'damaged', 'items' => []]);
        $claim($this->a);
        $theirs = $claim($this->b);
        actingAsStaff($this->mgrA);
        $returns = collect($this->getJson("/api/v1/admin/orders/{$this->both->id}")->assertOk()->json('data.returns'));
        expect($returns->pluck('number')->all())->not->toContain($theirs->number)->and($returns->count())->toBe(1);
        Livewire::actingAs($this->mgrA)->test(OrderShow::class, ['order' => $this->both])->assertDontSee($theirs->number);
        Livewire::actingAs($this->admin)->test(OrderShow::class, ['order' => $this->both])->assertSee($theirs->number);
    });
});
