<?php

use App\Livewire\Admin\Sales\OrderIndex;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/OpsSupport.php';

/** The orders list: how long each order has waited (a chip, never colour alone) and an "oldest first" order. */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(qatar('2026-11-19 12:00'));
    $this->a = opsBranch(attributes: ['code' => 'AAA']);
    $this->b = opsBranch(attributes: ['code' => 'BBB']);
    $this->mgr = opsStaff('branch_manager', $this->a);
});

it('shows a chip with the age of what each order waits for, and none for finished or unpaid orders', function () {
    $new = opsOrder($this->a, [], ['status' => 'pending'], opsAgo(7));
    $slow = opsOrder($this->a, [], ['status' => 'preparing', 'accepted_at' => opsUtc(opsAgo(100))], opsAgo(130));
    $ready = opsOrder($this->a, [], ['status' => 'ready', 'ready_at' => opsUtc(opsAgo(3))], opsAgo(50));
    $done = opsOrder($this->a, ['status' => 'completed'], ['status' => 'delivered'], opsAgo(500));
    $unpaid = opsOrder($this->a, ['status' => 'pending_payment', 'payment_method' => 'sadad'], ['status' => 'pending'], opsAgo(90));

    $page = Livewire::actingAs($this->mgr)->test(OrderIndex::class);
    $waits = $page->viewData('waits');
    expect($waits[$new['order']]->state)->toBe('amber')->and($waits[$new['order']]->label)->toBe('7 min')
        ->and($waits[$slow['order']]->state)->toBe('red')->and($waits[$ready['order']]->state)->toBe('ok')
        ->and($waits)->not->toHaveKey($done['order'])->and($waits)->not->toHaveKey($unpaid['order']);
    $html = $page->html();
    expect(substr_count($html, 'data-age-state="amber"'))->toBe(1)->and(substr_count($html, 'data-age-state="red"'))->toBe(1)->and(substr_count($html, 'data-age-state="ok"'))->toBe(1)
        ->and($html)->toContain('Late')->toContain('Getting late');          // the word for screen readers, next to the shape
});

it('shows the worst wait when an order has several parts', function () {
    $one = opsOrder($this->a, [], ['status' => 'pending'], opsAgo(2));
    $partB = (string) Str::uuid();
    DB::table('order_fulfillments')->insert(['id' => $partB, 'order_id' => $one['order'], 'branch_id' => $this->a->id, 'number' => $one['number'].'-2', 'type' => 'delivery', 'status' => 'preparing',
        'accepted_at' => opsUtc(opsAgo(95)), 'created_at' => opsUtc(opsAgo(100)), 'updated_at' => opsUtc(opsAgo(95))]);
    expect(Livewire::actingAs($this->mgr)->test(OrderIndex::class)->viewData('waits')[$one['order']]->state)->toBe('red');
});

it('sorts oldest first when asked, newest first otherwise, and ignores anything else', function () {
    $x = opsOrder($this->a, [], ['status' => 'pending'], opsAgo(30));
    $y = opsOrder($this->a, [], ['status' => 'pending'], opsAgo(10));
    $z = opsOrder($this->a, [], ['status' => 'pending'], opsAgo(20));
    $numbers = fn ($page) => $page->viewData('orders')->pluck('number')->all();

    $page = Livewire::actingAs($this->mgr)->test(OrderIndex::class);
    expect($numbers($page))->toBe([$y['number'], $z['number'], $x['number']]);
    $page->set('sort', 'oldest');
    expect($numbers($page))->toBe([$x['number'], $z['number'], $y['number']]);
    $page->set('sort', 'sideways');
    expect($numbers($page))->toBe([$y['number'], $z['number'], $x['number']]);
    expect($numbers(Livewire::actingAs($this->mgr)->withQueryParams(['sort' => 'oldest'])->test(OrderIndex::class)))->toBe([$x['number'], $z['number'], $y['number']]);
    $html = $page->set('sort', '')->html();
    expect($html)->toContain('Oldest first')->toContain('Newest first')->toContain('aria-label="Sort the orders"');
});

it('keeps the filters, tabs and branch scope exactly as they were', function () {
    $mine = opsOrder($this->a, [], ['status' => 'pending'], opsAgo(10));
    $theirs = opsOrder($this->b, [], ['status' => 'pending'], opsAgo(10));
    $page = Livewire::actingAs($this->mgr)->test(OrderIndex::class)->set('sort', 'oldest');
    expect($page->viewData('orders')->pluck('id')->all())->toBe([$mine['order']]);
    $page->call('setQuick', 'cod')->assertViewHas('orders', fn ($o) => $o->count() === 1)->call('setQuick', 'pickup')->assertViewHas('orders', fn ($o) => $o->count() === 0)
        ->set('tab', 'needs_action')->assertSet('sort', 'oldest');
    expect(Livewire::actingAs($this->mgr)->test(OrderIndex::class)->viewData('waits'))->not->toHaveKey($theirs['order']);
});

it('measures a whole page of orders without a query per row', function () {
    foreach (range(1, 4) as $i) {
        opsOrder($this->a, [], ['status' => 'pending'], opsAgo(10 + $i));
    }
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($this->mgr)->test(OrderIndex::class);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $count();                                                   // warm-up
    $small = $count();
    foreach (range(5, 20) as $i) {
        $kind = $i % 3;
        $o = opsOrder($this->a, [], $kind === 0 ? ['status' => 'pending'] : ($kind === 1 ? ['status' => 'ready', 'ready_at' => opsUtc(opsAgo(40))] : ['status' => 'preparing', 'accepted_at' => opsUtc(opsAgo(50))]), opsAgo(100 + $i));
        if ($kind === 1) {
            opsDelivery($o['part'], $this->a, 'assigned', ['assigned_at' => opsUtc(opsAgo(8))]);
        }
    }
    $big = $count();
    expect($big)->toBe($small);                                   // the same queries for 4 rows and 20 rows
});

it('shows the chip in Arabic with the Arabic word and units', function () {
    opsOrder($this->a, [], ['status' => 'pending'], opsAgo(7));
    app()->setLocale('ar');
    $html = Livewire::actingAs($this->mgr)->test(OrderIndex::class)->html();
    expect($html)->toContain('7 د')->toContain('يقترب من التأخر')->toContain(__('ops_live.sort.oldest', [], 'ar'))->not->toContain('ops_live.');
});
