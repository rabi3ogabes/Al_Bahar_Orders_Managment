<?php

use App\Domain\Identity\Models\User;
use App\Domain\Operations\Services\AttentionFeed;
use App\Domain\Operations\Support\AttentionItem;
use App\Domain\Operations\Support\AttentionList;
use App\Livewire\Admin\Operations\AttentionBadge;
use App\Livewire\Admin\Operations\AttentionCard;
use App\Livewire\Admin\Operations\NeedsAttention;
use App\Support\AdminNavigation;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/OpsSupport.php';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->now = qatar('2026-11-19 12:00');          // Thursday noon: both branches are open
    $this->travelTo($this->now);
    $this->a = opsBranch(attributes: ['code' => 'AAA']);
    $this->b = opsBranch(attributes: ['code' => 'BBB']);
    $this->mgrA = opsStaff('branch_manager', $this->a);
});

/** One of every kind of line at branch A, so a test can see who gets which. */
function opsWorldA(): array
{
    $a = test()->a;
    $w = [];
    $w['new_red'] = opsOrder($a, [], ['status' => 'pending'], opsAgo(20));
    $w['new_ok'] = opsOrder($a, [], ['status' => 'pending'], opsAgo(2));
    $w['stuck_red'] = opsOrder($a, [], ['status' => 'preparing', 'accepted_at' => opsUtc(opsAgo(100))], opsAgo(130));
    $w['stuck_ok'] = opsOrder($a, [], ['status' => 'preparing', 'accepted_at' => opsUtc(opsAgo(10))], opsAgo(30));
    $w['ready_amber'] = opsOrder($a, [], ['status' => 'ready', 'ready_at' => opsUtc(opsAgo(40))], opsAgo(90));
    $w['ready_ok'] = opsOrder($a, [], ['status' => 'ready', 'ready_at' => opsUtc(opsAgo(5))], opsAgo(90));
    $w['silent_red'] = opsOrder($a, [], ['status' => 'ready', 'ready_at' => opsUtc(opsAgo(60))], opsAgo(90));
    opsDelivery($w['silent_red']['part'], $a, 'assigned', ['assigned_at' => opsUtc(opsAgo(12))]);
    $w['silent_ok'] = opsOrder($a, [], ['status' => 'ready', 'ready_at' => opsUtc(opsAgo(60))], opsAgo(90));
    opsDelivery($w['silent_ok']['part'], $a, 'assigned', ['assigned_at' => opsUtc(opsAgo(2))]);
    $w['failed'] = opsOrder($a, [], ['status' => 'failed'], opsAgo(300));
    opsDelivery($w['failed']['part'], $a, 'failed', ['failed_at' => opsUtc(opsAgo(3)), 'failure_reason' => 'customer_absent']);
    $w['pickup_late'] = opsOrder($a, ['fulfillment_type' => 'pickup'], ['status' => 'pickup_ready', 'type' => 'pickup', 'ready_at' => opsUtc(opsAgo(3 * 1440))], opsAgo(4 * 1440));
    opsPickup($w['pickup_late']['part'], $a, 'ready', opsAgo(60));
    $w['pickup_fresh'] = opsOrder($a, ['fulfillment_type' => 'pickup'], ['status' => 'pickup_ready', 'type' => 'pickup', 'ready_at' => opsUtc(opsAgo(1440))], opsAgo(2 * 1440));
    $w['cancel'] = opsOrder($a, [], ['status' => 'pending'], opsAgo(40));
    $w['cancel']['cancellation'] = opsCancellation($w['cancel']['order'], opsAgo(20));
    $w['refund'] = opsOrder($a, [], ['status' => 'delivered'], opsAgo(3000));
    $w['refund']['refund'] = opsRefund($w['refund']['order'], opsAgo(25 * 60));
    $driver = opsStaff('delivery_employee', $a);
    $w['cash'] = opsOrder($a, [], ['status' => 'delivered'], opsAgo(3000));
    opsCash($a, $driver, $w['cash']['order'], opsAgo(30 * 60));
    $w['driver'] = $driver;

    return $w;
}

function opsIds(AttentionList $list): array
{
    return $list->items->map(fn (AttentionItem $i) => $i->category.':'.$i->subject)->all();
}

it('lists everything that waits for a manager, worst first, and hides what is not late yet', function () {
    $w = opsWorldA();
    $list = app(AttentionFeed::class)->for($this->mgrA);
    $byCat = $list->items->groupBy('category')->map->count()->all();

    expect($byCat)->toEqual(['unaccepted' => 2, 'stuck' => 1, 'undispatched' => 2, 'driver_silent' => 1, 'delivery_failed' => 1, 'pickup_waiting' => 1, 'cancellation' => 1, 'refund' => 1, 'cash' => 1])
        ->and($list->items->pluck('subject')->all())->not->toContain($w['stuck_ok']['number'], $w['silent_ok']['number'], $w['pickup_fresh']['number'])
        // an order the customer asked to cancel is one line (the request), not also "waiting to be accepted"
        ->and(opsIds($list))->toContain('cancellation:'.$w['cancel']['number'])->not->toContain('unaccepted:'.$w['cancel']['number']);

    // Sorted by urgency: every red line before every amber line before every ok line; longer waits first inside a state.
    $ranks = $list->items->map->rank()->all();
    expect($ranks)->toBe(collect($ranks)->sortDesc()->values()->all());
    $reds = $list->items->where('state', 'red');
    expect($reds->pluck('age.ageMinutes')->all())->toBe($reds->pluck('age.ageMinutes')->sortDesc()->values()->all());
    expect($list->counts())->toMatchArray(['total' => $list->items->count()]);
});

it('gives every line the one thing to do about it', function () {
    $w = opsWorldA();
    $items = app(AttentionFeed::class)->for($this->mgrA)->items->keyBy(fn (AttentionItem $i) => $i->category.':'.$i->subject);
    $actionOf = fn (string $cat, array $ids) => $items[$cat.':'.$ids['number']]->action;

    expect($actionOf('unaccepted', $w['new_red']))->toMatchArray(['type' => 'peek', 'order' => $w['new_red']['order'], 'label' => 'Open order'])
        ->and($actionOf('stuck', $w['stuck_red'])['type'])->toBe('peek')
        ->and($actionOf('undispatched', $w['ready_amber']))->toMatchArray(['type' => 'link', 'label' => 'Assign driver'])
        ->and($actionOf('undispatched', $w['ready_amber'])['url'])->toContain('lane=to_dispatch')->toContain('search='.$w['ready_amber']['number'])
        ->and($actionOf('driver_silent', $w['silent_red']))->toMatchArray(['type' => 'link', 'label' => 'Reassign driver'])
        ->and($actionOf('delivery_failed', $w['failed'])['url'])->toContain('lane=exceptions')
        ->and($actionOf('pickup_waiting', $w['pickup_late']))->toMatchArray(['type' => 'reissue', 'fulfillment' => $w['pickup_late']['part'], 'label' => 'Re-issue pass'])
        ->and($actionOf('cancellation', $w['cancel']))->toMatchArray(['type' => 'peek', 'label' => 'Review request'])
        ->and($actionOf('refund', $w['refund'])['type'])->toBe('link')
        ->and($items['cash:'.$w['driver']->name]->action['url'])->toContain('tab=outstanding');
    // the second tap: ring the customer
    expect($items['unaccepted:'.$w['new_red']['number']]->call)->toMatchArray(['phone' => '+97455123456'])
        ->and($items['delivery_failed:'.$w['failed']['number']]->note)->toBe(__('sales_admin.fail_customer_absent'))
        ->and($items['pickup_waiting:'.$w['pickup_late']['number']]->note)->toBe(__('ops_live.note.pass_expired'));
});

it('shows each person only what their permissions and branches allow', function () {
    opsWorldA();
    $feed = app(AttentionFeed::class);
    $cats = fn ($user) => $feed->for($user)->items->pluck('category')->unique()->sort()->values()->all();

    // another branch's manager: nothing of branch A
    expect($cats(opsStaff('branch_manager', $this->b)))->toBe([]);
    // a delivery employee: the dispatch lines, not new orders, cancellations, refunds or cash
    expect($cats(opsStaffWith($this->a, ['deliveries.view'])))->toBe(['delivery_failed', 'driver_silent', 'undispatched']);
    // orders.view alone: new orders, preparing and cancellations (not the dispatch, pickup or money lines)
    expect($cats(opsStaffWith($this->a, ['orders.view'])))->toBe(['cancellation', 'stuck', 'unaccepted']);
    // pickup.view alone: waiting pickups only
    expect($cats(opsStaffWith($this->a, ['pickup.view'])))->toBe(['pickup_waiting']);
    // payments.view alone: cash held
    expect($cats(opsStaffWith($this->a, ['payments.view'])))->toBe(['cash']);
    // refunds.view with one of the four: the refund line too
    expect($cats(opsStaffWith($this->a, ['payments.view', 'refunds.view'])))->toBe(['cash', 'refund']);
    // a pickup clerk without pickup.edit is sent to the order, not given the re-issue button
    $clerk = opsStaffWith($this->a, ['pickup.view']);
    expect($feed->for($clerk)->items->first()->action['type'])->toBe('peek');
});

it('shows system problems only to people who can change settings', function () {
    DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subHours(3)]);
    DB::table('marketing_campaigns')->insert(['id' => $cid = (string) Str::uuid(), 'name' => 'Black Friday', 'channels' => '["push"]', 'title_en' => 'T', 'title_ar' => 'ت', 'body_en' => 'B', 'body_ar' => 'ب',
        'segment' => '{}', 'status' => 'sending', 'created_at' => now()->subHours(3), 'updated_at' => now()->subMinutes(45)]);
    DB::table('marketing_campaigns')->insert(['id' => (string) Str::uuid(), 'name' => 'Fresh', 'channels' => '["push"]', 'title_en' => 'T', 'title_ar' => 'ت', 'body_en' => 'B', 'body_ar' => 'ب',
        'segment' => '{}', 'status' => 'sending', 'created_at' => now()->subHours(3), 'updated_at' => now()->subMinutes(5)]);
    $admin = User::factory()->superAdmin()->create();
    $feed = app(AttentionFeed::class);

    $items = $feed->for($admin)->items;
    expect($items->pluck('category')->sort()->values()->all())->toBe(['campaign', 'queue'])
        ->and($items->firstWhere('category', 'campaign')->subject)->toBe('Black Friday')
        ->and($items->firstWhere('category', 'campaign')->state)->toBe('amber')
        ->and($items->firstWhere('category', 'queue')->detail)->toBe('1 background job failed')
        ->and($feed->for($this->mgrA)->items->pluck('category')->all())->not->toContain('queue', 'campaign');
});

it('skips a system card when its table or column does not exist yet', function () {
    Schema::drop('failed_jobs');
    $admin = User::factory()->superAdmin()->create();
    Schema::table('marketing_campaigns', fn ($t) => $t->dropColumn('status'));
    expect(app(AttentionFeed::class)->for($admin)->items->all())->toBe([]);
    Livewire::actingAs($admin)->test(NeedsAttention::class)->assertOk()->assertSee(__('ops_live.page.empty_title'));
});

it('filters by branch and by "mine" without ever widening what the person may see', function () {
    $w = opsWorldA();
    $both = opsStaffWith($this->a, ['orders.view', 'orders.edit']);
    DB::table('order_fulfillments')->where('id', $w['new_red']['part'])->update(['assigned_to' => $both->id]);
    opsOrder($this->b, [], ['status' => 'pending'], opsAgo(30));
    $admin = User::factory()->superAdmin()->create();
    $feed = app(AttentionFeed::class);

    expect($feed->for($both, mine: true)->items->pluck('subject')->all())->toBe([$w['new_red']['number']])
        ->and($feed->for($this->mgrA, mine: true)->items->all())->toBe([])
        ->and($feed->for($admin)->items->pluck('branchCode')->unique()->sort()->values()->all())->toBe(['AAA', 'BBB'])
        ->and($feed->for($admin, $this->b->id)->items->pluck('branchCode')->unique()->all())->toBe(['BBB'])
        ->and($feed->for($this->mgrA, $this->b->id)->items->pluck('branchCode')->unique()->all())->toBe(['AAA']) // not their branch: the filter is ignored, never widened
        ->and(collect($feed->for($admin)->branches)->pluck('code')->sort()->values()->all())->toBe(['AAA', 'BBB'])
        ->and(collect($feed->for($this->mgrA)->branches)->pluck('code')->all())->toBe(['AAA']);
});

it('never shows an unpaid online order, a cancelled part or finished work', function () {
    opsOrder($this->a, ['status' => 'pending_payment', 'payment_method' => 'sadad'], ['status' => 'pending'], opsAgo(300));
    foreach (['cancelled', 'delivered', 'dispatched', 'out_for_delivery', 'returned'] as $status) {
        opsOrder($this->a, [], ['status' => $status], opsAgo(300));
    }
    expect(app(AttentionFeed::class)->for($this->mgrA)->items->all())->toBe([]);
});

/** $n parts of every kind of line (new, preparing too long, ready, failed), some with deliveries and some with a request to cancel. */
function opsMix(int $from, int $to): void
{
    foreach (range($from, $to) as $i) {
        $kind = $i % 4;
        $part = match ($kind) {
            0 => ['status' => 'pending'],
            1 => ['status' => 'preparing', 'accepted_at' => opsUtc(opsAgo(100))],
            2 => ['status' => 'ready', 'ready_at' => opsUtc(opsAgo(70))],
            default => ['status' => 'failed'],
        };
        $ids = opsOrder(test()->a, [], $part, opsAgo(120 + $i));
        if ($kind === 3) {
            opsDelivery($ids['part'], test()->a, 'failed', ['failed_at' => opsUtc(opsAgo(70))]);
        }
        if ($kind === 2 && $i % 8 === 2) {
            opsDelivery($ids['part'], test()->a, 'assigned', ['assigned_at' => opsUtc(opsAgo(30))]);
        }
        if ($i % 5 === 0) {
            opsCancellation($ids['order'], opsAgo(30));
        }
    }
}

it('does not grow its queries with the number of rows', function () {
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $list = app(AttentionFeed::class)->for($this->mgrA);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$n, $list->items->count()];
    };
    opsMix(1, 12);
    app(AttentionFeed::class)->for($this->mgrA);       // warm-up: permissions, settings and branch hours are read once per request and remembered
    [$small, $smallRows] = $count();
    opsMix(13, 200);
    [$big, $bigRows] = $count();

    expect($smallRows)->toBeGreaterThan(10)->and($bigRows)->toBeGreaterThan(190)->and($big)->toBe($small)->and($big)->toBeLessThanOrEqual(30);
});

it('renders 200 rows of the page itself in a fixed number of queries', function () {
    opsMix(1, 12);
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = Livewire::actingAs($this->mgrA)->test(NeedsAttention::class)->html();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$n, $html];
    };
    $count();                                           // warm-up (the container remembers permissions and settings between the two renders)
    [$small] = $count();
    opsMix(13, 200);
    [$big, $html] = $count();
    expect($big)->toBe($small)->and(substr_count($html, 'data-category='))->toBeGreaterThan(190);
});

it('caps a very long list per kind and says so', function () {
    foreach (range(1, AttentionFeed::CAP + 5) as $i) {
        opsOrder($this->a, [], ['status' => 'pending'], opsAgo(10 + $i));
    }
    $list = app(AttentionFeed::class)->for($this->mgrA);
    expect($list->items)->toHaveCount(AttentionFeed::CAP)->and($list->truncated)->toBeTrue();
    Livewire::actingAs($this->mgrA)->test(NeedsAttention::class)->assertSee(__('ops_live.page.truncated'));
});

it('says so when even the part query had to stop', function () {
    foreach (range(1, AttentionFeed::CAP * 5 + 1) as $i) {
        opsOrder($this->a, [], ['status' => $i % 2 === 0 ? 'pending' : 'failed'], opsAgo(10 + $i));
    }
    $list = app(AttentionFeed::class)->for($this->mgrA);
    expect($list->truncated)->toBeTrue()->and($list->items->count())->toBeLessThanOrEqual(AttentionFeed::CAP * 5);
    // a short list is not flagged
    DB::table('order_fulfillments')->delete();
    opsOrder($this->a, [], ['status' => 'pending'], opsAgo(10));
    expect(app(AttentionFeed::class)->for($this->mgrA)->truncated)->toBeFalse();
});

it('counts for the menu badge and remembers the answer for a few seconds', function () {
    opsOrder($this->a, [], ['status' => 'pending'], opsAgo(30));
    opsOrder($this->a, [], ['status' => 'pending'], opsAgo(7));
    opsOrder($this->a, [], ['status' => 'pending'], opsAgo(1));
    $feed = app(AttentionFeed::class);
    expect($feed->summary($this->mgrA))->toBe(['total' => 3, 'red' => 1, 'amber' => 1]);
    opsOrder($this->a, [], ['status' => 'pending'], opsAgo(2));
    expect($feed->summary($this->mgrA)['total'])->toBe(3);             // remembered
    $this->travel(20)->seconds();
    Cache::flush();
    expect($feed->summary($this->mgrA)['total'])->toBe(4);
});

it('reads timestamps the same in a closed branch: the wait does not count while it is shut', function () {
    $this->travelTo(qatar('2026-11-19 08:00'));                       // before opening
    opsOrder($this->a, [], ['status' => 'pending'], qatar('2026-11-18 23:00'));
    $item = app(AttentionFeed::class)->for($this->mgrA)->items->first();
    expect($item->state)->toBe('ok')->and($item->age->paused)->toBeTrue()->and($item->age->ageMinutes)->toBe(0)->and($item->age->wallMinutes)->toBe(540);
});

// ── The page ─────────────────────────────────────────────────────────────────────────────────────────────────────

it('opens for anyone with orders, deliveries, payments or pickup view, and for nobody else', function () {
    $this->actingAs(opsStaff('branch_manager', $this->a))->get(route('admin.operations.attention'))->assertOk();
    foreach (['orders.view', 'deliveries.view', 'payments.view', 'pickup.view'] as $permission) {
        $this->actingAs(opsStaffWith($this->a, [$permission]))->get(route('admin.operations.attention'))->assertOk();
    }
    $this->actingAs(opsStaff('inventory_manager', $this->a))->get(route('admin.operations.attention'))->assertForbidden();
    $this->actingAs(opsStaffWith($this->a, ['refunds.view']))->get(route('admin.operations.attention'))->assertForbidden();
    auth()->logout();
    $this->get(route('admin.operations.attention'))->assertRedirect(route('admin.login'));
});

it('renders in English and Arabic without a single raw translation key', function (string $locale) {
    opsWorldA();
    $this->actingAs(opsStaff('branch_manager', $this->a))->withSession(['locale' => $locale]);
    $html = $this->get(route('admin.operations.attention'))->assertOk()->assertSee(__('ops_live.page.title', [], $locale))->getContent();
    foreach (['ops_live.', 'sales_admin.', 'finance_admin.', 'drawer_sales.', 'org_admin.', 'status.'] as $namespace) {
        expect($html)->not->toContain($namespace);
    }
    expect($html)->toContain(__('ops_live.group.red.title', [], $locale))->toContain(__('ops_live.cat.unaccepted.label', [], $locale))
        ->toContain('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"')->toContain('wire:poll.20s');
})->with(['en', 'ar']);

it('shows a calm empty state with a check when nothing needs anyone', function () {
    Livewire::actingAs($this->mgrA)->test(NeedsAttention::class)->assertSee('All clear. Nothing needs you right now.')->assertSeeHtml('data-empty="clear"')
        ->assertSee(__('ops_live.page.empty_hint'));
    $this->actingAs($this->mgrA)->withSession(['locale' => 'ar']);
    expect($this->get(route('admin.operations.attention'))->getContent())->toContain('كل شيء على ما يرام. لا يحتاج أي شيء إليك الآن.');
});

it('filters the page by "mine" and by branch, with chips only for people who work in several branches', function () {
    $w = opsWorldA();
    $b2 = opsOrder($this->b, [], ['status' => 'pending'], opsAgo(30));
    $admin = User::factory()->superAdmin()->create();
    $page = Livewire::actingAs($admin)->test(NeedsAttention::class)->assertSee($w['new_red']['number'])->assertSee($b2['number'])
        ->assertSeeHtml('chip-'.$this->a->id)->assertSeeHtml('chip-'.$this->b->id);
    $page->call('setBranch', $this->b->id)->assertSee($b2['number'])->assertDontSee($w['new_red']['number']);
    $page->call('setBranch', $this->b->id)->assertSee($w['new_red']['number']);            // tapping the chip again clears it
    $page->call('setScope', 'mine')->assertSee(__('ops_live.page.empty_mine'))->assertDontSee($w['new_red']['number']);
    $page->call('setScope', 'weird')->assertSet('scope', 'all');
    // a branch employee of one branch sees no branch chips
    Livewire::actingAs($this->mgrA)->test(NeedsAttention::class)->assertDontSeeHtml('chip-'.$this->a->id);
    // a tampered branch id in the URL is ignored
    Livewire::actingAs($this->mgrA)->withQueryParams(['branch' => $this->b->id])->test(NeedsAttention::class)->assertSee($w['new_red']['number']);
});

it('opens an order in the side window of the page, and not one of another branch', function () {
    $w = opsWorldA();
    $other = opsOrder($this->b, [], ['status' => 'pending'], opsAgo(30));
    $page = Livewire::actingAs($this->mgrA)->test(NeedsAttention::class)->call('peekOrder', $w['new_red']['order'])->assertSet('showOrder', true)->assertSet('viewId', $w['new_red']['order'])
        ->assertSee($w['new_red']['number']);
    $page->call('closeOrderPeek')->assertSet('showOrder', false)->assertSet('viewId', '');
    $page->call('peekOrder', $other['order'])->assertSet('showOrder', false)->assertSet('viewId', '');
    Livewire::actingAs($this->mgrA)->withQueryParams(['view' => $w['new_red']['order']])->test(NeedsAttention::class)->assertSet('showOrder', true);
    Livewire::actingAs($this->mgrA)->withQueryParams(['view' => $other['order']])->test(NeedsAttention::class)->assertSet('showOrder', false);
});

it('re-issues a pickup pass in place, only for someone who may, keeping a part handed over in pieces as it is', function () {
    $w = opsWorldA();
    $before = DB::table('pickup_reservations')->where('order_fulfillment_id', $w['pickup_late']['part'])->value('token_hash');
    Livewire::actingAs(opsStaffWith($this->a, ['pickup.view']))->test(NeedsAttention::class)->call('reissuePass', $w['pickup_late']['part'])->assertForbidden();
    expect(DB::table('pickup_reservations')->where('order_fulfillment_id', $w['pickup_late']['part'])->value('token_hash'))->toBe($before);
    Livewire::actingAs($this->mgrA)->test(NeedsAttention::class)->call('reissuePass', $w['pickup_late']['part'])->assertDispatched('toast', type: 'success', message: __('ops_live.reissued'));
    $after = DB::table('pickup_reservations')->where('order_fulfillment_id', $w['pickup_late']['part'])->first();
    expect($after->token_hash)->not->toBe($before)->and($after->status)->toBe('ready')->and($after->token_expires_at)->not->toBeNull();
    // the part's own wait is not reset by a new pass, and the action is audited with who did it
    expect(DB::table('order_fulfillments')->where('id', $w['pickup_late']['part'])->value('ready_at'))->toContain('2026-11-16')
        ->and(DB::table('audit_logs')->where('action', 'pickup.pass_reissued')->count())->toBe(1);

    // handed over in pieces: the pass is renewed, the part stays "partly collected"
    DB::table('pickup_reservations')->where('order_fulfillment_id', $w['pickup_late']['part'])->update(['status' => 'partially_collected']);
    Livewire::actingAs($this->mgrA)->test(NeedsAttention::class)->call('reissuePass', $w['pickup_late']['part'])->assertDispatched('toast', type: 'success');
    expect(DB::table('pickup_reservations')->where('order_fulfillment_id', $w['pickup_late']['part'])->value('status'))->toBe('partially_collected');
    // a part that is not waiting any more gives a plain error, not a crash
    DB::table('order_fulfillments')->where('id', $w['pickup_late']['part'])->update(['status' => 'picked_up']);
    Livewire::actingAs($this->mgrA)->test(NeedsAttention::class)->call('reissuePass', $w['pickup_late']['part'])->assertNotFound();
    // a part of another branch cannot be reached by its id
    $other = opsOrder($this->b, ['fulfillment_type' => 'pickup'], ['status' => 'pickup_ready', 'type' => 'pickup', 'ready_at' => opsUtc(opsAgo(5000))], opsAgo(6000));
    opsPickup($other['part'], $this->b);
    Livewire::actingAs($this->mgrA)->test(NeedsAttention::class)->call('reissuePass', $other['part'])->assertForbidden();
});

it('refreshes by itself: polls and listens for the live order event', function () {
    $html = Livewire::actingAs($this->mgrA)->test(NeedsAttention::class)->html();
    expect($html)->toContain('wire:poll.20s.visible')->toContain('ops-order-live');
});

it('puts the new entry and a live count in the menu for the right people', function () {
    $mgr = AdminNavigation::for($this->mgrA);
    $operations = collect($mgr)->firstWhere('label', 'ops_live.page.eyebrow');
    expect(collect($operations['items'])->pluck('route')->all())->toBe(['admin.operations.attention', 'admin.operations.board'])   // the inbox, then the live board (anyone who sees orders)
        ->and(array_slice(collect($mgr)->pluck('label')->all(), 0, 2))->toBe(['nav.overview', 'ops_live.page.eyebrow']);   // right after the overview
    foreach (['orders.view', 'deliveries.view', 'payments.view', 'pickup.view'] as $permission) {
        $items = collect(AdminNavigation::for(opsStaffWith($this->a, [$permission])))->flatMap(fn ($g) => $g['items'])->pluck('route');
        expect($items)->toContain('admin.operations.attention');
    }
    $none = collect(AdminNavigation::for(opsStaff('inventory_manager', $this->a)))->flatMap(fn ($g) => $g['items'])->pluck('route');
    expect($none)->not->toContain('admin.operations.attention');

    opsOrder($this->a, [], ['status' => 'pending'], opsAgo(30));
    $badge = Livewire::actingAs($this->mgrA)->test(AttentionBadge::class);
    $badge->assertSeeHtml('data-attention-badge="1"')->assertSeeHtml('bg-ruby-soft');
    Livewire::actingAs(opsStaff('inventory_manager', $this->a))->test(AttentionBadge::class)->assertSeeHtml('data-attention-badge="0"');
});

it('gives the dashboard a small card: the worst five, the total and a link to the list', function () {
    foreach (range(1, 8) as $i) {
        opsOrder($this->a, [], ['status' => 'pending'], opsAgo(10 + $i));
    }
    Livewire::actingAs($this->mgrA)->test(AttentionCard::class)
        ->assertViewHas('top', fn ($top) => $top->count() === 5)->assertViewHas('counts', fn ($c) => $c['total'] === 8)
        ->assertSee(__('ops_live.card.see_all'))->assertSee('+ 3 more')->assertSeeHtml(route('admin.operations.attention'));
    // all clear
    Livewire::actingAs(opsStaff('branch_manager', $this->b))->test(AttentionCard::class)->assertSee(__('ops_live.card.clear'));
    // nobody without the permission sees anything of it
    expect(Livewire::actingAs(opsStaff('inventory_manager', $this->a))->test(AttentionCard::class)->html())->not->toContain('data-attention-card');
});

it('shows the card in Arabic too', function () {
    opsOrder($this->a, [], ['status' => 'pending'], opsAgo(30));
    app()->setLocale('ar');
    Livewire::actingAs($this->mgrA)->test(AttentionCard::class)->assertSee(__('ops_live.card.title', [], 'ar'))->assertSee(__('ops_live.cat.unaccepted.label', [], 'ar'));
});

it('checks the time again on every refresh', function () {
    $ids = opsOrder($this->a, [], ['status' => 'pending'], opsAgo(2));
    $page = Livewire::actingAs($this->mgrA)->test(NeedsAttention::class)->assertSeeHtml('data-group="ok"')->assertDontSeeHtml('data-group="red"');
    $this->travel(20)->minutes();
    $page->call('$refresh')->assertSeeHtml('data-group="red"');
    expect(CarbonImmutable::now()->gt($this->now))->toBeTrue();
});
