<?php

use App\Domain\Identity\Models\User;
use App\Domain\Operations\Services\LiveBoard;
use App\Domain\Operations\Support\BoardCard;
use App\Domain\Operations\Support\OpsAge;
use App\Livewire\Admin\Operations\LiveBoardPage;
use App\Support\AdminNavigation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/OpsSupport.php';
require_once __DIR__.'/../Search/SearchSupport.php';

/**
 * The live operations board: which lane every open branch part stands in, who may see which card, what a card says, how it is bounded, and the page
 * around it (side window, Accept, wall display). The browser side is tested in tests/js/ops-board-core.test.mjs.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->now = qatar('2026-11-19 12:00');          // Thursday noon: every branch is open since 10:00
    $this->travelTo($this->now);
    $this->a = opsBranch(attributes: ['code' => 'AAA']);
    $this->b = opsBranch(attributes: ['code' => 'BBB']);
    $this->mgrA = opsStaff('branch_manager', $this->a);
    $this->admin = User::factory()->superAdmin()->create();
});

/** The board as a person sees it: lane key => the cards shown, as number => card. @return array<string, array<string, BoardCard>> */
function boardOf(User $who, ?string $branch = null, array $open = []): array
{
    $b = app(LiveBoard::class)->for($who, $branch, $open);

    return collect($b->lanes)->map(fn (array $lane) => $lane['cards']->keyBy('number')->all())->all();
}

function boardLane(array $board, string $number): ?string
{
    foreach ($board as $lane => $cards) {
        if (isset($cards[$number])) {
            return $lane;
        }
    }

    return null;
}

/** One part in every state the board knows, at branch A, so a test can see who lands where. */
function boardWorld(): array
{
    $a = test()->a;
    $w = [];
    $w['new'] = opsOrder($a, [], ['status' => 'pending'], opsAgo(3));
    $w['new_red'] = opsOrder($a, [], ['status' => 'pending'], opsAgo(25));
    $w['accepted'] = opsOrder($a, [], ['status' => 'accepted', 'accepted_at' => opsUtc(opsAgo(5))], opsAgo(10));
    $w['preparing'] = opsOrder($a, [], ['status' => 'preparing', 'accepted_at' => opsUtc(opsAgo(20))], opsAgo(40));
    $w['preparing_amber'] = opsOrder($a, [], ['status' => 'preparing', 'accepted_at' => opsUtc(opsAgo(50))], opsAgo(60));
    $w['preparing_red'] = opsOrder($a, [], ['status' => 'preparing', 'accepted_at' => opsUtc(opsAgo(100))], opsAgo(110));
    $w['ready_nodriver'] = opsOrder($a, [], ['status' => 'ready', 'ready_at' => opsUtc(opsAgo(5))], opsAgo(90));
    $w['ready_named'] = opsOrder($a, [], ['status' => 'ready', 'ready_at' => opsUtc(opsAgo(8))], opsAgo(90));
    $driver = opsStaff('delivery_employee', $a, ['name' => 'Hamad Driver']);
    opsDelivery($w['ready_named']['part'], $a, 'assigned', ['driver_id' => $driver->id, 'assigned_at' => opsUtc(opsAgo(2))]);
    $w['ready_accepted'] = opsOrder($a, [], ['status' => 'ready', 'ready_at' => opsUtc(opsAgo(8))], opsAgo(90));
    opsDelivery($w['ready_accepted']['part'], $a, 'accepted', ['driver_id' => $driver->id, 'assigned_at' => opsUtc(opsAgo(7))]);
    $w['ready_red'] = opsOrder($a, [], ['status' => 'ready', 'ready_at' => opsUtc(opsAgo(70))], opsAgo(90));
    $w['ready_pickup'] = opsOrder($a, ['fulfillment_type' => 'pickup'], ['status' => 'ready', 'type' => 'pickup', 'ready_at' => opsUtc(opsAgo(5))], opsAgo(30));
    $w['out'] = opsOrder($a, [], ['status' => 'out_for_delivery', 'dispatched_at' => opsUtc(opsAgo(25))], opsAgo(200));
    $w['dispatched'] = opsOrder($a, [], ['status' => 'dispatched', 'dispatched_at' => opsUtc(opsAgo(5))], opsAgo(200));
    $w['pickup'] = opsOrder($a, ['fulfillment_type' => 'pickup'], ['status' => 'pickup_ready', 'type' => 'pickup', 'ready_at' => opsUtc(opsAgo(60))], opsAgo(300));
    $w['pickup_red'] = opsOrder($a, ['fulfillment_type' => 'pickup'], ['status' => 'pickup_ready', 'type' => 'pickup', 'ready_at' => opsUtc(opsAgo(5 * 1440))], opsAgo(6 * 1440));
    $w['failed'] = opsOrder($a, [], ['status' => 'failed'], opsAgo(300));
    opsDelivery($w['failed']['part'], $a, 'failed', ['failed_at' => opsUtc(opsAgo(3)), 'failure_reason' => 'customer_absent']);
    $w['cancel'] = opsOrder($a, [], ['status' => 'preparing', 'accepted_at' => opsUtc(opsAgo(10))], opsAgo(40));
    $w['cancel']['cancellation'] = opsCancellation($w['cancel']['order'], opsAgo(20));
    // not the branch's work, or finished
    $w['unpaid'] = opsOrder($a, ['status' => 'pending_payment', 'payment_method' => 'sadad', 'payment_status' => 'pending'], ['status' => 'pending'], opsAgo(40));
    $w['done'] = opsOrder($a, [], ['status' => 'delivered'], opsAgo(500));
    $w['cancelled'] = opsOrder($a, [], ['status' => 'cancelled'], opsAgo(500));

    return $w;
}

/** A question to the customer about a replacement, waiting on a part. */
function boardWaitingAnswer(array $o, $branch, int $askedMinutesAgo, int $responseMinutes = 60): string
{
    $item = fn () => (string) DB::table('order_items')->where('order_fulfillment_id', $o['part'])->value('id');
    $replacement = (string) Str::uuid();
    DB::table('order_items')->insert(['id' => $replacement, 'order_id' => $o['order'], 'order_fulfillment_id' => $o['part'], 'branch_id' => $branch->id, 'sku' => 'SKU-R'.Str::random(4),
        'name_en' => 'Replacement sofa', 'name_ar' => 'أريكة بديلة', 'unit_price' => '90.00', 'unit_final_price' => '90.00', 'quantity' => 1, 'line_subtotal' => '90.00', 'line_total' => '90.00',
        'adjustment_kind' => 'replacement', 'created_at' => now(), 'updated_at' => now()]);
    $id = (string) Str::uuid();
    DB::table('order_substitution_requests')->insert(['id' => $id, 'order_id' => $o['order'], 'order_fulfillment_id' => $o['part'], 'branch_id' => $branch->id, 'original_order_item_id' => $item(),
        'replacement_order_item_id' => $replacement, 'status' => 'waiting', 'fallback' => 'remove', 'response_minutes' => $responseMinutes, 'asked_at' => opsUtc(opsAgo($askedMinutesAgo)),
        'deadline_at' => opsUtc(opsAgo($askedMinutesAgo)->addMinutes($responseMinutes)), 'original_total' => '100.00', 'replacement_total' => '90.00', 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

// ── Lanes ──────────────────────────────────────────────────────────────────────────────────────────────────────────

it('puts every open part in exactly one lane, by where it stands and how late it is', function () {
    $w = boardWorld();
    $board = boardOf(test()->mgrA);
    $lane = fn (string $key) => boardLane($board, $w[$key]['number']);

    expect($lane('new'))->toBe('new')->and($lane('accepted'))->toBe('preparing')->and($lane('preparing'))->toBe('preparing')->and($lane('preparing_amber'))->toBe('preparing')
        ->and($lane('ready_nodriver'))->toBe('ready')->and($lane('ready_named'))->toBe('ready')->and($lane('ready_accepted'))->toBe('ready')->and($lane('ready_pickup'))->toBe('ready')
        ->and($lane('out'))->toBe('out')->and($lane('dispatched'))->toBe('out')->and($lane('pickup'))->toBe('pickup');
    // broken or late, whichever step they were in
    expect($lane('new_red'))->toBe('attention')->and($lane('preparing_red'))->toBe('attention')->and($lane('ready_red'))->toBe('attention')->and($lane('pickup_red'))->toBe('attention')
        ->and($lane('failed'))->toBe('attention')->and($lane('cancel'))->toBe('attention');
    // not the branch's work, or finished: nowhere
    expect($lane('unpaid'))->toBeNull()->and($lane('done'))->toBeNull()->and($lane('cancelled'))->toBeNull();
    // one card per part, none twice
    $numbers = collect($board)->flatMap(fn ($cards) => array_keys($cards));
    expect($numbers->count())->toBe($numbers->unique()->count())->and($numbers->count())->toBe(17);
});

it('says what each card is waiting for, and why an attention card is there', function () {
    $w = boardWorld();
    $board = boardOf(test()->mgrA);
    $card = fn (string $key) => collect($board)->flatten(1)->first(fn (BoardCard $c) => $c->number === $w[$key]['number']);

    expect($card('new')->stage)->toBe('new')->and($card('accepted')->stage)->toBe('accepted')->and($card('preparing')->stage)->toBe('preparing')
        ->and($card('ready_nodriver')->stage)->toBe('undispatched')->and($card('ready_named')->stage)->toBe('driver_named')->and($card('ready_accepted')->stage)->toBe('driver_accepted')
        ->and($card('ready_pickup')->stage)->toBe('ready_pickup')->and($card('out')->stage)->toBe('out')->and($card('pickup')->stage)->toBe('pickup');
    expect([$card('new_red')->reason, $card('new_red')->stage, $card('new_red')->from])->toBe(['late', 'new', 'new'])
        ->and([$card('preparing_red')->reason, $card('preparing_red')->from])->toBe(['late', 'preparing'])
        ->and([$card('ready_red')->reason, $card('ready_red')->stage])->toBe(['late', 'undispatched'])
        ->and([$card('pickup_red')->reason, $card('pickup_red')->from])->toBe(['late', 'pickup'])
        ->and([$card('failed')->reason, $card('failed')->stage])->toBe(['failed', 'failed'])
        ->and([$card('cancel')->reason, $card('cancel')->stage])->toBe(['cancellation', 'cancellation'])
        ->and($card('new')->reason)->toBeNull();
});

it('uses the order clock: the same limits, red from the red limit, amber before it, opening hours only', function () {
    $w = boardWorld();
    $board = boardOf(test()->mgrA);
    $age = fn (string $key) => collect($board)->flatten(1)->first(fn (BoardCard $c) => $c->number === $w[$key]['number'])->age;

    expect($age('new')->state)->toBe(OpsAge::OK)->and($age('new')->ageMinutes)->toBe(3)
        ->and($age('preparing_amber')->state)->toBe(OpsAge::AMBER)->and($age('preparing_red')->state)->toBe(OpsAge::RED)
        ->and($age('ready_nodriver')->state)->toBe(OpsAge::OK)->and($age('ready_red')->state)->toBe(OpsAge::RED)
        ->and($age('out')->state)->toBe(OpsAge::OK)->and($age('out')->label)->toBe('25 min')                       // on the road: plain time, no limit
        ->and($age('ready_accepted'))->toBeNull()                                                                      // an accepted driver is moving: no clock
        ->and($age('failed')->state)->toBe(OpsAge::OK);                                                              // failed 3 minutes ago: the "ready" limits apply to the reschedule
    // the shop was shut all night: an order placed at 21:50 is not red at 10:30 next morning
    test()->travelTo(qatar('2026-11-20 10:30'));                       // Friday morning: the shop opens at 14:00
    $late = opsOrder(test()->a, [], ['status' => 'pending'], qatar('2026-11-19 22:30'));
    $night = collect(boardOf(test()->mgrA)['new'])->get($late['number'])->age;
    expect($night->state)->toBe(OpsAge::OK)->and($night->ageMinutes)->toBe(0)->and($night->wallMinutes)->toBe(720)->and($night->paused)->toBeTrue();
});

it('counts every lane, with how many are late and getting late', function () {
    boardWorld();
    $b = app(LiveBoard::class)->for(test()->mgrA);
    expect($b->lanes['new']['total'])->toBe(1)->and($b->lanes['preparing']['total'])->toBe(3)->and($b->lanes['preparing']['amber'])->toBe(1)
        ->and($b->lanes['ready']['total'])->toBe(4)->and($b->lanes['out']['total'])->toBe(2)->and($b->lanes['pickup']['total'])->toBe(1)
        ->and($b->lanes['attention']['total'])->toBe(6)->and($b->lanes['attention']['red'])->toBe(5)    // new, preparing, ready, pickup and the 20 minute old cancellation request are red; the failed delivery (3 minutes) is not
        ->and($b->open)->toBe(17)->and($b->counts()['red'])->toBe(5)->and($b->counts()['amber'])->toBe(1)->and($b->counts()['ok'])->toBe(11)->and($b->truncated)->toBeFalse();
    expect(array_keys($b->lanes))->toBe(LiveBoard::LANES);
});

it('draws all six lanes for a branch with nothing open', function () {
    $b = app(LiveBoard::class)->for(opsStaff('branch_manager', opsBranch()));
    expect(array_keys($b->lanes))->toBe(['new', 'preparing', 'ready', 'out', 'pickup', 'attention'])->and($b->open)->toBe(0)->and($b->counts())->toBe(['red' => 0, 'amber' => 0, 'ok' => 0]);
    foreach ($b->lanes as $lane) {
        expect($lane['cards'])->toHaveCount(0)->and($lane['total'])->toBe(0)->and($lane['more'])->toBe(0);
    }
});

it('sorts a lane worst first, then the longest wait, then by id, so cards never swap places between refreshes', function () {
    $ok = opsOrder(test()->a, [], ['status' => 'pending'], opsAgo(2));
    $older = opsOrder(test()->a, [], ['status' => 'pending'], opsAgo(9));
    $amber = opsOrder(test()->a, [], ['status' => 'pending'], opsAgo(6));
    $tie1 = opsOrder(test()->a, [], ['status' => 'pending'], opsAgo(4));
    $tie2 = opsOrder(test()->a, [], ['status' => 'pending'], opsAgo(4));
    $order = array_keys(boardOf(test()->mgrA)['new']);
    $ties = collect([$tie1, $tie2])->sortBy('part')->pluck('number')->all();            // equal waits: by the part's id
    expect($order)->toBe([$older['number'], $amber['number'], ...$ties, $ok['number']]);
    expect(array_keys(boardOf(test()->mgrA)['new']))->toBe($order);                      // the same list on every read
    expect(boardOf(test()->mgrA)['new'][$older['number']]->state())->toBe('amber')->and(boardOf(test()->mgrA)['new'][$ok['number']]->state())->toBe('ok');
});

// ── Branches and permissions ─────────────────────────────────────────────────────────────────────────────────────────

it('shows a branch person only their branches, and a company-wide person every branch, narrowed by the filter and never widened by it', function () {
    $mine = opsOrder(test()->a, [], ['status' => 'pending'], opsAgo(3));
    $theirs = opsOrder(test()->b, [], ['status' => 'pending'], opsAgo(3));
    expect(array_keys(boardOf(test()->mgrA)['new']))->toBe([$mine['number']]);
    expect(array_keys(boardOf(test()->mgrA, test()->b->id)['new']))->toBe([$mine['number']]);      // asking for another branch's board: still only their own
    expect(array_keys(boardOf(test()->mgrA, 'not-a-branch')['new']))->toBe([$mine['number']]);
    expect(array_keys(boardOf(test()->admin)['new']))->toContain($mine['number'], $theirs['number'])
        ->and(array_keys(boardOf(test()->admin, test()->b->id)['new']))->toBe([$theirs['number']])
        ->and(array_keys(boardOf(test()->admin, test()->a->id)['new']))->toBe([$mine['number']]);
    expect(app(LiveBoard::class)->for(test()->mgrA)->branches)->toBe([['id' => test()->a->id, 'code' => 'AAA', 'name' => (string) test()->a->localized('name')]]);
});

it('sees nothing without the right to see orders', function () {
    opsOrder(test()->a, [], ['status' => 'pending'], opsAgo(3));
    $driver = opsStaffWith(test()->a, ['deliveries.view', 'deliveries.edit']);
    $b = app(LiveBoard::class)->for($driver);
    expect($b->open)->toBe(0)->and($b->branches)->toBe([]);
    $this->actingAs($driver)->get(route('admin.operations.board'))->assertForbidden();
    $this->actingAs(opsStaffWith(test()->a, ['payments.view']))->get(route('admin.operations.board'))->assertForbidden();
});

it('cards are part-aware: an order from two branches is a card in each, labelled "part n of m"', function () {
    $o = opsOrder(test()->a, [], ['status' => 'pending'], opsAgo(3));
    DB::table('order_fulfillments')->insert(['id' => (string) Str::uuid(), 'order_id' => $o['order'], 'branch_id' => test()->b->id, 'number' => $o['number'].'-2', 'type' => 'delivery', 'status' => 'accepted',
        'accepted_at' => opsUtc(opsAgo(2)), 'created_at' => opsUtc(opsAgo(3)), 'updated_at' => opsUtc(opsAgo(2))]);
    $mine = boardOf(test()->mgrA);
    expect(boardLane($mine, $o['number']))->toBe('new')->and($mine['new'][$o['number']]->part)->toBe('Part 1 of 2');       // branch B's part is not theirs to see
    $both = boardOf(test()->admin);
    expect($both['new'][$o['number']]->part)->toBe('Part 1 of 2')->and($both['preparing'][$o['number']]->part)->toBe('Part 2 of 2')
        ->and($both['new'][$o['number']]->branchCode)->toBe('AAA')->and($both['preparing'][$o['number']]->branchCode)->toBe('BBB');
    $single = opsOrder(test()->a, [], ['status' => 'pending'], opsAgo(2));
    expect(boardOf(test()->mgrA)['new'][$single['number']]->part)->toBeNull();
});

// ── What a card says ─────────────────────────────────────────────────────────────────────────────────────────────────

it('shows the items, the driver, express and no customer name, phone or address', function () {
    $o = opsOrder(test()->a, ['customer_name' => 'Secret Customer', 'customer_phone' => '+97455123456'], ['status' => 'ready', 'is_express' => true, 'ready_at' => opsUtc(opsAgo(5))], opsAgo(60), 3);
    $driver = opsStaff('delivery_employee', test()->a, ['name' => 'Hamad Driver']);
    opsDelivery($o['part'], test()->a, 'assigned', ['driver_id' => $driver->id, 'assigned_at' => opsUtc(opsAgo(1))]);
    $card = boardOf(test()->mgrA)['ready'][$o['number']];
    expect($card->units)->toBe(3)->and($card->driver)->toBe('Hamad Driver')->and($card->express)->toBeTrue()->and($card->type)->toBe('delivery');
    $html = Livewire::actingAs(test()->mgrA)->test(LiveBoardPage::class)->html();
    expect($html)->toContain($o['number'])->toContain('Hamad Driver')->not->toContain('Secret Customer')->not->toContain('55123456');
});

it('shows the cash to collect: the driver\'s amount, the balance of a single part, an order\'s share, nothing when paid or paid online', function () {
    $cod = opsOrder(test()->a, ['payment_method' => 'cod', 'grand_total' => '1250.50', 'paid_total' => '0'], ['status' => 'preparing', 'accepted_at' => opsUtc(opsAgo(5))], opsAgo(10));
    $withDelivery = opsOrder(test()->a, ['payment_method' => 'cod', 'grand_total' => '800.00'], ['status' => 'ready', 'ready_at' => opsUtc(opsAgo(5))], opsAgo(20));
    opsDelivery($withDelivery['part'], test()->a, 'pending', ['cod_amount' => '640.00']);
    $partPaid = opsOrder(test()->a, ['payment_method' => 'cod', 'grand_total' => '300.00', 'paid_total' => '100.00'], ['status' => 'preparing', 'accepted_at' => opsUtc(opsAgo(5))], opsAgo(10));
    $paid = opsOrder(test()->a, ['payment_method' => 'cod', 'grand_total' => '300.00', 'paid_total' => '300.00'], ['status' => 'preparing', 'accepted_at' => opsUtc(opsAgo(5))], opsAgo(10));
    $online = opsOrder(test()->a, ['payment_method' => 'sadad', 'payment_status' => 'paid', 'grand_total' => '999.00', 'paid_total' => '999.00'], ['status' => 'preparing', 'accepted_at' => opsUtc(opsAgo(5))], opsAgo(10));
    $pickup = opsOrder(test()->a, ['payment_method' => 'pay_at_pickup', 'fulfillment_type' => 'pickup', 'grand_total' => '450.00'], ['status' => 'pickup_ready', 'type' => 'pickup', 'ready_at' => opsUtc(opsAgo(5))], opsAgo(20));
    $board = boardOf(test()->mgrA);
    $due = fn (array $o) => collect($board)->flatten(1)->first(fn (BoardCard $c) => $c->number === $o['number'])->due;
    expect($due($cod))->toContain('1,250.50')->and($due($withDelivery))->toContain('640.00')->and($due($partPaid))->toContain('200.00')
        ->and($due($paid))->toBeNull()->and($due($online))->toBeNull()->and($due($pickup))->toContain('450.00');
    expect(collect($board)->flatten(1)->first(fn (BoardCard $c) => $c->number === $online['number'])->payment)->toBe('sadad');
});

it('splits the cash of an order that comes from two branches between its parts, to the dirham', function () {
    $o = opsOrder(test()->a, ['payment_method' => 'cod', 'grand_total' => '1000.00', 'paid_total' => '0'], ['status' => 'preparing', 'accepted_at' => opsUtc(opsAgo(5)), 'delivery_fee' => '0'], opsAgo(10));
    $second = (string) Str::uuid();
    DB::table('order_fulfillments')->insert(['id' => $second, 'order_id' => $o['order'], 'branch_id' => test()->b->id, 'number' => $o['number'].'-2', 'type' => 'delivery', 'status' => 'preparing', 'delivery_fee' => '0',
        'accepted_at' => opsUtc(opsAgo(5)), 'created_at' => opsUtc(opsAgo(10)), 'updated_at' => opsUtc(opsAgo(5))]);
    DB::table('order_items')->insert(['id' => (string) Str::uuid(), 'order_id' => $o['order'], 'order_fulfillment_id' => $second, 'branch_id' => test()->b->id, 'sku' => 'SKU-B', 'name_en' => 'Table', 'name_ar' => 'طاولة',
        'unit_price' => '300.00', 'unit_final_price' => '300.00', 'quantity' => 1, 'line_subtotal' => '300.00', 'line_total' => '300.00', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('order_items')->where('order_fulfillment_id', $o['part'])->update(['line_total' => '700.00', 'line_subtotal' => '700.00']);
    $both = app(LiveBoard::class)->for(test()->admin)->lanes['preparing']['cards']->where('number', $o['number'])->values();
    expect($both)->toHaveCount(2);
    $amounts = $both->map(fn (BoardCard $c) => (float) str_replace(['QAR', ',', ' '], '', (string) $c->due))->sort()->values()->all();
    expect($amounts)->toBe([300.0, 700.0]);
});

it('says when it is due: today, tomorrow or a date, with the slot', function () {
    $slot = (string) Str::uuid();
    DB::table('delivery_slots')->insert(['id' => $slot, 'branch_id' => test()->a->id, 'fulfillment_type' => 'delivery', 'day_of_week' => 4, 'starts_at' => '14:00:00', 'ends_at' => '16:00:00',
        'capacity' => 5, 'created_at' => now(), 'updated_at' => now()]);
    $today = opsOrder(test()->a, [], ['status' => 'preparing', 'accepted_at' => opsUtc(opsAgo(5)), 'delivery_slot_id' => $slot, 'slot_date' => '2026-11-19'], opsAgo(10));
    $tomorrow = opsOrder(test()->a, [], ['status' => 'preparing', 'accepted_at' => opsUtc(opsAgo(5)), 'delivery_slot_id' => $slot, 'slot_date' => '2026-11-20'], opsAgo(10));
    $later = opsOrder(test()->a, [], ['status' => 'preparing', 'accepted_at' => opsUtc(opsAgo(5)), 'delivery_slot_id' => $slot, 'slot_date' => '2026-11-28'], opsAgo(10));
    $none = opsOrder(test()->a, [], ['status' => 'preparing', 'accepted_at' => opsUtc(opsAgo(5))], opsAgo(10));
    $b = boardOf(test()->mgrA)['preparing'];
    expect($b[$today['number']]->when)->toBe('Today 14:00–16:00')->and($b[$tomorrow['number']]->when)->toBe('Tomorrow 14:00–16:00')
        ->and($b[$later['number']]->when)->toBe('Sat 28 Nov 14:00–16:00')->and($b[$none['number']]->when)->toBeNull();
    app()->setLocale('ar');
    expect(boardOf(test()->mgrA)['preparing'][$today['number']]->when)->toBe('اليوم 14:00–16:00');
});

it('flags a part that waits for the customer\'s answer in any lane, and moves it to attention once half its time has gone', function () {
    $fresh = opsOrder(test()->a, [], ['status' => 'preparing', 'accepted_at' => opsUtc(opsAgo(20))], opsAgo(30));
    boardWaitingAnswer($fresh, test()->a, 10);                      // 10 of 60 minutes: still calm
    $half = opsOrder(test()->a, [], ['status' => 'preparing', 'accepted_at' => opsUtc(opsAgo(40))], opsAgo(50));
    boardWaitingAnswer($half, test()->a, 35);                       // more than half
    $over = opsOrder(test()->a, [], ['status' => 'preparing', 'accepted_at' => opsUtc(opsAgo(40))], opsAgo(50));
    boardWaitingAnswer($over, test()->a, 70);                       // the time is up
    $none = opsOrder(test()->a, [], ['status' => 'preparing', 'accepted_at' => opsUtc(opsAgo(20))], opsAgo(30));
    $b = boardOf(test()->mgrA);
    expect(boardLane($b, $fresh['number']))->toBe('preparing')->and($b['preparing'][$fresh['number']]->waitingCustomer)->toBeTrue()
        ->and(boardLane($b, $half['number']))->toBe('attention')->and($b['attention'][$half['number']]->reason)->toBe('customer')->and($b['attention'][$half['number']]->state())->toBe('amber')
        ->and($b['attention'][$over['number']]->state())->toBe('red')->and($b['attention'][$over['number']]->waitingCustomer)->toBeTrue()
        ->and($b['preparing'][$none['number']]->waitingCustomer)->toBeFalse();
});

it('knows what to do for each card: accept what is new, assign a driver to what is ready, reschedule what failed', function () {
    $w = boardWorld();
    $board = boardOf(test()->mgrA);
    $card = fn (string $key) => collect($board)->flatten(1)->first(fn (BoardCard $c) => $c->number === $w[$key]['number']);
    expect($card('new')->canAccept)->toBeTrue()->and($card('new_red')->canAccept)->toBeTrue()->and($card('preparing')->canAccept)->toBeFalse()->and($card('cancel')->canAccept)->toBeFalse()
        ->and($card('ready_nodriver')->assign)->toBe(route('admin.deliveries.index', ['lane' => 'to_dispatch', 'search' => $w['ready_nodriver']['number']]))
        ->and($card('ready_named')->assign)->toBe(route('admin.deliveries.index', ['lane' => 'scheduled', 'search' => $w['ready_named']['number']]))
        ->and($card('failed')->assign)->toBe(route('admin.deliveries.index', ['lane' => 'exceptions', 'search' => $w['failed']['number']]))
        ->and($card('ready_accepted')->assign)->toBeNull()->and($card('out')->assign)->toBeNull();
    // a person who may only look has no buttons
    $viewer = opsStaffWith(test()->a, ['orders.view']);
    $v = collect(boardOf($viewer))->flatten(1)->first(fn (BoardCard $c) => $c->number === $w['new']['number']);
    expect($v->canAccept)->toBeFalse();
    $v2 = collect(boardOf($viewer))->flatten(1)->first(fn (BoardCard $c) => $c->number === $w['ready_nodriver']['number']);
    expect($v2->assign)->toBeNull();
});

// ── Bounds ──────────────────────────────────────────────────────────────────────────────────────────────────────────

it('shows ten cards a lane, says how many more there are, opens to sixty, and counts them all', function () {
    foreach (range(1, 75) as $i) {
        opsOrder(test()->a, [], ['status' => 'pending'], opsAgo(2));
    }
    $b = app(LiveBoard::class)->for(test()->mgrA);
    expect($b->lanes['new']['cards'])->toHaveCount(LiveBoard::SHOWN)->and($b->lanes['new']['total'])->toBe(75)->and($b->lanes['new']['more'])->toBe(65);
    $open = app(LiveBoard::class)->for(test()->mgrA, null, ['new']);
    expect($open->lanes['new']['cards'])->toHaveCount(LiveBoard::EXPANDED)->and($open->lanes['new']['more'])->toBe(15)->and($open->lanes['preparing']['cards'])->toHaveCount(0);
});

it('reads at most 150 parts of each state, oldest first, so a flood of one state never hides the others, and says so', function () {
    $oldest = opsOrder(test()->a, [], ['status' => 'pending'], opsAgo(2000));
    $ready = opsOrder(test()->a, [], ['status' => 'ready', 'ready_at' => opsUtc(opsAgo(5))], opsAgo(60));
    $newest = opsOrder(test()->a, [], ['status' => 'pending'], opsAgo(1));
    DB::statement("insert into order_fulfillments (id, order_id, branch_id, number, type, status, created_at, updated_at)
        select gen_random_uuid(), ?, ?, 'BULK-' || g, 'delivery', 'pending', ?::timestamptz - (g || ' seconds')::interval, now() from generate_series(1, 170) g", [$oldest['order'], test()->a->id, opsUtc(opsAgo(100))]);
    $b = app(LiveBoard::class)->for(test()->mgrA, null, ['new', 'attention']);
    $shown = collect($b->lanes)->flatMap(fn ($l) => $l['cards'])->pluck('number');
    expect($b->truncated)->toBeTrue()->and($b->lanes['new']['total'] + $b->lanes['attention']['total'])->toBe(LiveBoard::PER_STATE)   // 172 pending parts, 150 read
        ->and($shown->contains($oldest['number']))->toBeTrue()                                                                         // the longest-stuck were kept
        ->and($b->lanes['ready']['total'])->toBe(1)->and($shown->contains($ready['number']))->toBeTrue();                              // another state is untouched by the flood
    // with the flood gone, nothing is cut and nothing is said
    DB::table('order_fulfillments')->where('number', 'like', 'BULK-%')->delete();
    expect(app(LiveBoard::class)->for(test()->mgrA)->truncated)->toBeFalse();
});

it('asks the database the same number of questions for forty parts and for two hundred', function () {
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(LiveBoard::class)->for(test()->mgrA);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    foreach (range(1, 40) as $i) {
        opsOrder(test()->a, [], ['status' => (['pending', 'accepted', 'ready', 'out_for_delivery', 'pickup_ready'])[$i % 5], 'ready_at' => opsUtc(opsAgo(5)), 'accepted_at' => opsUtc(opsAgo(5))], opsAgo(10));
    }
    $count();                                                                  // the first read also loads the person's rights and the settings once
    $few = $count();
    foreach (range(1, 160) as $i) {
        opsOrder(test()->a, [], ['status' => (['pending', 'accepted', 'ready', 'out_for_delivery', 'pickup_ready'])[$i % 5], 'ready_at' => opsUtc(opsAgo(5)), 'accepted_at' => opsUtc(opsAgo(5))], opsAgo(10));
    }
    $many = $count();
    expect($many)->toBe($few)->and($many)->toBeLessThanOrEqual(10);
});

// ── The page ────────────────────────────────────────────────────────────────────────────────────────────────────────

it('renders the six lanes with their words, counts and cards, in English and Arabic', function (string $locale) {
    $w = boardWorld();
    $html = test()->withSession(['locale' => $locale])->actingAs(test()->mgrA)->get(route('admin.operations.board'))->assertOk()->getContent();
    foreach (LiveBoard::LANES as $key) {
        expect($html)->toContain('data-lane-box="'.$key.'"')->toContain(__('ops_board.lane.'.$key.'.title', [], $locale));
    }
    expect($html)->toContain($w['new']['number'])->toContain(__('ops_board.stage.undispatched', [], $locale))->toContain(__('ops_board.reason.failed', [], $locale))
        ->toContain(__('ops_board.page.title', [], $locale))->toContain('data-board')->toContain('x-data="opsBoard(')
        ->toContain($locale === 'ar' ? 'dir="rtl"' : 'dir="ltr"')->toContain(__('ops_board.wall.enter', [], $locale))->toContain('wire:navigate');
})->with(['en', 'ar']);

it('shows a calm message and still draws the lanes when nothing is open', function () {
    Livewire::actingAs(opsStaff('branch_manager', opsBranch()))->test(LiveBoardPage::class)->assertSee(__('ops_board.clear_title'))->assertSeeHtml('data-empty="clear"')
        ->assertSee(__('ops_board.empty.new'))->assertSee(__('ops_board.empty.attention'));
});

it('lets a person with several branches filter, and says which branch each card belongs to', function () {
    $mine = opsOrder(test()->a, [], ['status' => 'pending'], opsAgo(3));
    $other = opsOrder(test()->b, [], ['status' => 'pending'], opsAgo(3));
    $c = Livewire::actingAs(test()->admin)->test(LiveBoardPage::class)->assertSee($mine['number'])->assertSee($other['number'])->assertSee('AAA')->assertSee('BBB');
    $c->call('setBranch', test()->b->id)->assertSet('branch', test()->b->id)->assertDontSee($mine['number'])->assertSee($other['number']);
    $c->call('setBranch', test()->b->id)->assertSet('branch', '')->assertSee($mine['number']);          // tapping the same branch again shows all
    // a branch person has one branch and no chips
    $single = Livewire::actingAs(test()->mgrA)->test(LiveBoardPage::class)->assertSee($mine['number'])->assertDontSee($other['number']);
    expect($single->html())->not->toContain('aria-pressed');
    $single->set('branch', test()->b->id)->assertDontSee($other['number']);                              // typing another branch into the address shows nothing of it
});

it('opens a card\'s order in the side window, only when the person may see it', function () {
    $mine = opsOrder(test()->a, [], ['status' => 'pending'], opsAgo(3));
    $other = opsOrder(test()->b, [], ['status' => 'pending'], opsAgo(3));
    Livewire::actingAs(test()->mgrA)->test(LiveBoardPage::class)
        ->call('peekOrder', $mine['order'])->assertSet('showOrder', true)->assertSet('viewId', $mine['order'])
        ->call('closeOrderPeek')->assertSet('showOrder', false)->assertSet('viewId', '')
        ->call('peekOrder', $other['order'])->assertSet('showOrder', false)->assertSet('viewId', '')->assertDispatched('toast');
    Livewire::withQueryParams(['view' => $mine['order']])->actingAs(test()->mgrA)->test(LiveBoardPage::class)->assertSet('showOrder', true);
    Livewire::withQueryParams(['view' => $other['order']])->actingAs(test()->mgrA)->test(LiveBoardPage::class)->assertSet('showOrder', false);
});

it('accepts a new order from its card with the same permission and effect as the order window, and refuses a second tap', function () {
    $o = opsOrder(test()->a, [], ['status' => 'pending'], opsAgo(3));
    $c = Livewire::actingAs(test()->mgrA)->test(LiveBoardPage::class)->assertSeeHtml('wire:click="acceptPart(\''.$o['part'].'\')"');
    $c->call('acceptPart', $o['part'])->assertDispatched('toast', type: 'success', message: __('ops_board.accepted_done', ['number' => $o['number']]));
    expect(DB::table('order_fulfillments')->where('id', $o['part'])->value('status'))->toBe('accepted');
    expect(boardLane(boardOf(test()->mgrA), $o['number']))->toBe('preparing');
    $c->call('acceptPart', $o['part'])->assertDispatched('toast', type: 'error');                       // already accepted: a plain message, no second change
    expect(DB::table('order_fulfillments')->where('id', $o['part'])->value('status'))->toBe('accepted');
});

it('refuses Accept to a person who may only look, and to a person of another branch', function () {
    $o = opsOrder(test()->a, [], ['status' => 'pending'], opsAgo(3));
    $viewer = opsStaffWith(test()->a, ['orders.view']);
    Livewire::actingAs($viewer)->test(LiveBoardPage::class)->assertDontSeeHtml('wire:click="acceptPart(')->call('acceptPart', $o['part'])->assertForbidden();
    Livewire::actingAs(opsStaff('branch_manager', test()->b))->test(LiveBoardPage::class)->call('acceptPart', $o['part'])->assertForbidden();
    expect(DB::table('order_fulfillments')->where('id', $o['part'])->value('status'))->toBe('pending');
    Livewire::actingAs(test()->mgrA)->test(LiveBoardPage::class)->call('acceptPart', (string) Str::uuid())->assertDispatched('toast', type: 'error');
    Livewire::actingAs(test()->mgrA)->test(LiveBoardPage::class)->call('acceptPart', 'not-a-uuid')->assertDispatched('toast', type: 'error');
});

it('opens a lane to show more of its cards and folds it again, only for real lanes', function () {
    foreach (range(1, 14) as $i) {
        opsOrder(test()->a, [], ['status' => 'pending'], opsAgo(2));
    }
    $c = Livewire::actingAs(test()->mgrA)->test(LiveBoardPage::class)->assertSee(__('ops_board.more', ['n' => 4]));
    $c->call('toggleLane', 'new')->assertSet('open', ['new'])->assertDontSee(__('ops_board.more', ['n' => 4]))->assertSee(__('ops_board.less'))
        ->call('toggleLane', 'new')->assertSet('open', [])
        ->call('toggleLane', 'nonsense')->assertSet('open', []);
});

it('shows the wall display without the menu, the top bar, the footer or any button, and the normal page with them', function () {
    opsOrder(test()->a, [], ['status' => 'pending'], opsAgo(3));
    $normal = test()->actingAs(test()->mgrA)->get(route('admin.operations.board'))->assertOk()->getContent();
    $wall = test()->actingAs(test()->mgrA)->get(route('admin.operations.board', ['wall' => 1]))->assertOk()->getContent();
    expect($normal)->toContain('<aside')->toContain('data-build-footer')->toContain('data-command-palette-trigger')->toContain('wire:click="acceptPart(')->toContain('wire:click="peekOrder(');
    expect($wall)->not->toContain('<aside')->not->toContain('data-build-footer')->not->toContain('data-command-palette-trigger')->not->toContain('<header class="sticky')
        ->not->toContain('wire:click="acceptPart(')->not->toContain('wire:click="peekOrder(')->toContain('data-board')->toContain(__('ops_board.wall.exit'))
        ->toContain('startOpsLive');                                                                // the new-order toast and sound of every page stay, once
    expect(substr_count($wall, 'startOpsLive'))->toBe(1);
    // the wall display cannot be used to act
    Livewire::withQueryParams(['wall' => '1'])->actingAs(test()->mgrA)->test(LiveBoardPage::class)->assertSet('wall', true)->call('peekOrder', 'x')->assertSet('showOrder', false);
    $o = opsOrder(test()->a, [], ['status' => 'pending'], opsAgo(3));
    Livewire::withQueryParams(['wall' => '1'])->actingAs(test()->mgrA)->test(LiveBoardPage::class)->call('acceptPart', $o['part'])->assertForbidden();
});

it('hands the browser the branch channels this person may join, the timer speeds and its words', function () {
    $boot = bootOf(Livewire::actingAs(test()->mgrA)->test(LiveBoardPage::class)->html(), 'opsBoard');
    expect($boot['channels'])->toBe(['branches.'.test()->a->id])->and($boot['poll'])->toBe(['live' => 30, 'down' => 10])->and($boot['wall'])->toBeFalse()
        ->and($boot['text'])->toHaveKeys(['live', 'reconnecting', 'polling', 'stale', 'sound_on', 'sound_off'])->and($boot['text']['polling'])->toContain(':s');
    $all = bootOf(Livewire::actingAs(test()->admin)->test(LiveBoardPage::class)->html(), 'opsBoard');
    expect($all['channels'])->toContain('branches.'.test()->a->id, 'branches.'.test()->b->id);
    // a company-wide role that is not an employee of any branch sees every order but is refused the branch channels by the socket: none is offered, the order feed of the layout still reaches them
    $accountant = opsStaff('accountant');
    $order = opsOrder(test()->a, [], ['status' => 'pending'], opsAgo(3));
    $page = Livewire::actingAs($accountant)->test(LiveBoardPage::class)->assertSee($order['number']);
    expect(bootOf($page->html(), 'opsBoard')['channels'])->toBe([]);
});

it('has the menu entry for people who see orders and for nobody else', function () {
    $labels = fn (User $u) => collect(AdminNavigation::for($u))->flatMap(fn ($g) => array_column($g['items'], 'route'))->all();
    expect($labels(test()->mgrA))->toContain('admin.operations.board')->and($labels(test()->admin))->toContain('admin.operations.board')
        ->and($labels(opsStaffWith(test()->a, ['deliveries.view'])))->not->toContain('admin.operations.board');
    test()->actingAs(test()->mgrA)->get(route('admin.dashboard'))->assertSee(route('admin.operations.board'), false)->assertSee(__('ops_board.nav'));
});

it('keeps the English and Arabic words of the board in step', function () {
    $en = Arr::dot(require base_path('lang/en/ops_board.php'));
    $ar = Arr::dot(require base_path('lang/ar/ops_board.php'));
    expect(array_diff_key($en, $ar))->toBe([])->and(array_diff_key($ar, $en))->toBe([]);
    $placeholders = fn (string $t) => collect(preg_match_all('/:([a-z]+)/', $t, $m) ? $m[1] : [])->unique()->sort()->values()->all();
    foreach ($en as $key => $text) {
        expect(trim($text))->not->toBe('')->and(trim($ar[$key]))->not->toBe('', "ar {$key} is empty")->and($placeholders($ar[$key]))->toBe($placeholders($text), "placeholders of {$key} differ")
            ->and(preg_match('/\p{Arabic}/u', $ar[$key]))->toBe(1, "ar {$key} has no Arabic");
    }
});

it('keeps every stage and reason the service can produce in the language files', function () {
    $en = require base_path('lang/en/ops_board.php');
    foreach (['new', 'accepted', 'preparing', 'undispatched', 'driver_named', 'driver_accepted', 'ready_pickup', 'out', 'pickup', 'failed', 'cancellation', 'customer'] as $stage) {
        expect($en['stage'])->toHaveKey($stage);
    }
    foreach (['cancellation', 'failed', 'late', 'customer'] as $reason) {
        expect($en['reason'])->toHaveKey($reason);
    }
    foreach (LiveBoard::LANES as $lane) {
        expect($en['lane'])->toHaveKey($lane)->and($en['empty'])->toHaveKey($lane);
    }
});
