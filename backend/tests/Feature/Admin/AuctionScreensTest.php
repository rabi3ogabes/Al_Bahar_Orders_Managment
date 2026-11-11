<?php

use App\Domain\Auctions\Models\Auction;
use App\Domain\Auctions\Models\AuctionBidder;
use App\Domain\Auctions\Models\AuctionWinner;
use App\Domain\Auctions\Services\AuctionService;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Livewire\Admin\Auctions\AuctionConsole;
use App\Livewire\Admin\Auctions\AuctionEditor;
use App\Livewire\Admin\Auctions\AuctionIndex;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('renders the auction screens for auction staff and forbids others', function () {
    $w = auctionWorld();
    foreach ([User::factory()->superAdmin()->create(), $w->admin] as $u) {
        $this->actingAs($u)->get(route('admin.auctions.index'))->assertOk()->assertSee('Heritage Majlis');
        $this->actingAs($u)->get(route('admin.auctions.create'))->assertOk();
        $this->actingAs($u)->get(route('admin.auctions.edit', $w->auction))->assertOk();
        $this->actingAs($u)->get(route('admin.auctions.console', $w->lot))->assertOk()->assertSee('Carved Cedar Chest');
    }
    $other = staffWithRole('marketing_manager');
    foreach ([route('admin.auctions.index'), route('admin.auctions.create'), route('admin.auctions.edit', $w->auction), route('admin.auctions.console', $w->lot)] as $url) {
        $this->actingAs($other)->get($url)->assertForbidden();
    }
    // The auditor can view but not create.
    $this->actingAs(staffWithRole('auditor'))->get(route('admin.auctions.create'))->assertForbidden();
});

it('creates an auction with EN/AR terms, adds a lot and publishes it', function () {
    $mgr = staffWithRole('auction_manager');
    $this->actingAs($mgr);
    Livewire::test(AuctionEditor::class)->call('save')->assertHasErrors(['tr.en.title', 'tr.ar.title']);
    Livewire::test(AuctionEditor::class)
        ->set('tr.en.title', 'Pearl Diving Heritage')->set('tr.ar.title', 'تراث الغوص')->set('tr.en.terms', 'Winner pays within 48h.')->set('tr.ar.terms', 'يدفع الفائز خلال 48 ساعة.')
        ->set('form.deposit_amount', '500')->set('form.extension_window_seconds', 60)->set('form.extension_seconds', 90)
        ->call('save')->assertHasNoErrors()->assertRedirect();
    $a = Auction::with('translations')->firstOrFail();
    expect($a->status)->toBe('draft')->and($a->deposit_amount)->toBe('500.00')->and($a->extension_seconds)->toBe(90)
        ->and($a->translations->firstWhere('locale', 'ar')->terms)->toBe('يدفع الفائز خلال 48 ساعة.');

    $e = Livewire::test(AuctionEditor::class, ['auction' => $a])->call('publish')->assertDispatched('toast', type: 'error'); // no lots yet
    $e->call('newLot')->set('lot.title_en', 'Antique Dhow Model')->set('lot.title_ar', 'نموذج سفينة')->set('lot.starting_price', '1000')->set('lot.min_increment', '100')
        ->set('lot.reserve_price', '2500')->set('lot.starts_at', now('Asia/Qatar')->addDay()->format('Y-m-d\TH:i'))->set('lot.ends_at', now('Asia/Qatar')->addDays(2)->format('Y-m-d\TH:i'))
        ->call('saveLot')->assertHasNoErrors();
    expect($a->lots()->count())->toBe(1)->and($a->lots()->first()->makeVisible('reserve_price')->reserve_price)->toBe('2500.00');
    $e->call('publish');
    expect($a->fresh()->status)->toBe('scheduled');
    expect(Livewire::test(AuctionIndex::class)->viewData('auctions')->total())->toBe(1);
});

it('runs the auctioneer console: pause, resume, retract, block and hammer', function () {
    $w = auctionWorld();
    bid($w, $w->u1, '150.00');
    bid($w, $w->u2, '320.00');
    bid($w, $w->u1, '400.00');
    bid($w, $w->u2, '450.00');
    $this->actingAs($w->admin);
    $c = Livewire::test(AuctionConsole::class, ['lot' => $w->lot])
        ->assertViewHas('bids', fn ($b) => $b->count() === 4)->assertViewHas('reserveMet', true)->assertSee($w->u2->name);

    $c->call('ask', 'pause')->call('confirmDialog')->assertHasErrors('reason');
    $c->set('reason', 'Network check in the hall')->call('confirmDialog')->assertHasNoErrors();
    expect($w->lot->fresh()->status)->toBe('paused');
    $c->call('resume');
    expect($w->lot->fresh()->status)->toBe('open');

    $top = $w->lot->bids()->where('amount', '450.00')->first();
    $c->call('ask', 'retract', $top->id)->set('reason', 'Bidder confirmed mistake')->call('confirmDialog');
    expect($top->fresh()->status)->toBe('retracted')->and($w->lot->fresh()->current_price)->toBe('400.00');
    $c->call('ask', 'block', $w->u2->id)->set('reason', 'Repeated mistaken bids')->call('confirmDialog');
    expect(AuctionBidder::where('user_id', $w->u2->id)->value('status'))->toBe('blocked');

    $c->call('hammer');
    $lot = $w->lot->fresh();
    expect($lot->status)->toBe('sold')->and(AuctionWinner::where('auction_lot_id', $lot->id)->value('user_id'))->toBe($w->u1->id)
        ->and(AuditLog::whereIn('action', ['auction.lot_paused', 'auction.lot_resumed', 'auction.lot_override', 'auction.lot_closed', 'auction.bidder_blocked'])->count())->toBe(5);
    $c->assertViewHas('winners', fn ($ws) => $ws->first()?->status === 'pending_payment');
});

it('opens a pending live lot with a server-timed duration and blocks console actions without permission', function () {
    $w = auctionWorld([], ['type' => 'live']);
    $mgr = $w->admin;
    $lot2 = app(AuctionService::class)->addLot($mgr, $w->auction, ['title_en' => 'Brass Lantern', 'title_ar' => 'فانوس نحاسي', 'starting_price' => '50', 'min_increment' => '5']);
    $this->actingAs($mgr);
    Livewire::test(AuctionConsole::class, ['lot' => $lot2])->call('ask', 'open')->set('duration', '600')->call('confirmDialog');
    $lot2->refresh();
    expect($lot2->status)->toBe('open')->and((int) round(now()->diffInSeconds($lot2->ends_at)))->toBeBetween(590, 600);

    $this->actingAs(staffWithRole('auditor'));
    Livewire::test(AuctionConsole::class, ['lot' => $lot2])->assertOk()->call('hammer')->assertForbidden();
    Livewire::test(AuctionConsole::class, ['lot' => $lot2])->call('ask', 'pause')->assertForbidden();
});

it('edits and deletes lots from the auction editor, with live lots restricted to text and reserve', function () {
    $w = auctionWorld();
    $svc = app(AuctionService::class);
    $pending = $svc->addLot($w->admin, $w->auction->fresh(), ['title_en' => 'Brass Lantern', 'title_ar' => 'فانوس نحاسي', 'starting_price' => '200', 'min_increment' => '20',
        'reserve_price' => '500', 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2)]);
    $this->actingAs($w->admin);
    $e = Livewire::test(AuctionEditor::class, ['auction' => $w->auction]);

    // Pending lot: every field.
    $e->call('editLot', $pending->id)->assertSet('lot.title_en', 'Brass Lantern')->assertSet('lot.reserve_price', '500.00')
        ->set('lot.title_en', 'Antique Brass Lantern')->set('lot.starting_price', '250')->set('lot.reserve_price', '')->call('saveLot')->assertHasNoErrors();
    $fresh = $pending->fresh();
    expect($fresh->title_en)->toBe('Antique Brass Lantern')->and($fresh->starting_price)->toBe('250.00')->and($fresh->reserve_price)->toBeNull();
    $e->call('editLot', $pending->id)->set('lot.buy_now_price', '100')->call('saveLot')->assertHasErrors('lot.buy_now_price'); // must exceed the starting price

    // Live lot with a bid: text only; tampered price fields are never sent to the service.
    bid($w, $w->u1, '100.00');
    $e->call('editLot', $w->lot->id)->set('lot.title_ar', 'صندوق أرز مرمم')->set('lot.starting_price', '1')->set('lot.reserve_price', '1')->call('saveLot')->assertHasNoErrors();
    $live = $w->lot->fresh()->makeVisible('reserve_price');
    expect($live->title_ar)->toBe('صندوق أرز مرمم')->and($live->starting_price)->toBe('100.00')->and($live->reserve_price)->toBe('300.00');

    // Delete: only the untouched pending lot.
    $e->call('deleteLot', $w->lot->id)->assertDispatched('toast', type: 'error');
    expect($w->lot->fresh())->not->toBeNull();
    $e->call('deleteLot', $pending->id)->assertDispatched('toast', type: 'success');
    expect($pending->fresh())->toBeNull();

    // Lots of another auction can't be reached through this editor, and read-only roles can't edit.
    $other = auctionWorld();
    $e->call('editLot', $other->lot->id)->assertStatus(404);
    $this->actingAs(staffWithRole('auditor'));
    Livewire::test(AuctionEditor::class, ['auction' => $w->auction])->call('editLot', $w->lot->id)->assertForbidden();
});

it('extends a live lot from the auctioneer console', function () {
    $w = auctionWorld();
    $end = $w->lot->fresh()->ends_at;
    $this->actingAs($w->admin);
    $c = Livewire::test(AuctionConsole::class, ['lot' => $w->lot]);

    $c->call('ask', 'extend')->assertSet('duration', '300')->call('confirmDialog')->assertHasErrors('reason');
    $c->set('reason', 'Projector failure in the hall')->set('duration', '600')->call('confirmDialog')->assertHasNoErrors()->assertDispatched('toast', type: 'success');
    expect($w->lot->fresh()->ends_at->equalTo($end->addSeconds(600)))->toBeTrue();

    $this->actingAs(staffWithRole('auditor'));
    Livewire::test(AuctionConsole::class, ['lot' => $w->lot])->call('ask', 'extend')->assertForbidden();
});
