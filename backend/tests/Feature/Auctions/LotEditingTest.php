<?php

use App\Domain\Auctions\Models\AuctionLot;
use App\Domain\Auctions\Models\AuctionProduct;
use App\Domain\Auctions\Services\AuctionService;
use App\Domain\Auctions\Services\LotService;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Product;
use App\Support\DomainException;

function pendingLot(object $w, array $over = []): AuctionLot
{
    return app(AuctionService::class)->addLot($w->admin, $w->auction->fresh(), array_merge([
        'title_en' => 'Brass Lantern', 'title_ar' => 'فانوس نحاسي', 'starting_price' => '200.00', 'min_increment' => '20.00', 'reserve_price' => '500.00',
        'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2),
    ], $over));
}

function codeOf(callable $fn): ?string
{
    try {
        $fn();
    } catch (DomainException $e) {
        return $e->errorCode;
    }

    return null;
}

it('lets every field of a pending lot change, including its catalogue link', function () {
    $w = auctionWorld();
    $lot = pendingLot($w);
    $svc = app(AuctionService::class);
    $variant = Product::factory()->create()->variants()->first();
    $branch = Branch::factory()->create();

    $svc->updateLot($w->admin, $lot, ['title_en' => 'Antique Brass Lantern', 'starting_price' => '250.00', 'min_increment' => '25.00', 'buy_now_price' => '900.00',
        'reserve_price' => '', 'quantity' => 1, 'ends_at' => now()->addHours(3)->toIso8601String(), 'product_variant_id' => $variant->id, 'branch_id' => $branch->id]);

    $fresh = $lot->fresh();
    expect($fresh->title_en)->toBe('Antique Brass Lantern')->and($fresh->starting_price)->toBe('250.00')->and($fresh->buy_now_price)->toBe('900.00')
        ->and($fresh->reserve_price)->toBeNull()
        ->and(AuctionProduct::where('auction_lot_id', $lot->id)->pluck('product_variant_id')->all())->toBe([$variant->id]);

    // Removing the link, and re-pointing it, replace rather than duplicate.
    $svc->updateLot($w->admin, $lot, ['product_variant_id' => null]);
    expect(AuctionProduct::where('auction_lot_id', $lot->id)->count())->toBe(0);
    expect(AuditLog::where('action', 'auction.lot_updated')->where('auditable_id', $lot->id)->count())->toBe(2);
});

it('enforces lot invariants and unique lot numbers', function () {
    $w = auctionWorld();
    $lot = pendingLot($w);
    $svc = app(AuctionService::class);

    expect(codeOf(fn () => $svc->updateLot($w->admin, $lot, ['buy_now_price' => '150.00'])))->toBe('lot_invalid')       // not above starting price
        ->and(codeOf(fn () => $svc->updateLot($w->admin, $lot, ['min_increment' => '0'])))->toBe('lot_invalid')
        ->and(codeOf(fn () => $svc->updateLot($w->admin, $lot, ['ends_at' => now()->subHour()->toIso8601String()])))->toBe('lot_invalid')
        ->and(codeOf(fn () => $svc->updateLot($w->admin, $lot, ['lot_number' => $w->lot->lot_number])))->toBe('lot_number_taken');
    expect($lot->fresh()->starting_price)->toBe('200.00');
});

it('only lets text change on a live lot, and the reserve only until the first bid', function () {
    $w = auctionWorld();
    $svc = app(AuctionService::class);

    $svc->updateLot($w->admin, $w->lot, ['title_en' => 'Carved Cedar Chest (restored)', 'description_en' => 'Hand-carved', 'reserve_price' => '350.00']);
    expect($w->lot->fresh()->title_en)->toBe('Carved Cedar Chest (restored)')->and($w->lot->fresh()->reserve_price)->toBe('350.00');

    // Sending a locked field with its current value is harmless; a different value is refused.
    $svc->updateLot($w->admin, $w->lot, ['starting_price' => '100.00', 'title_ar' => 'صندوق أرز منحوت (مرمم)']);
    foreach ([['starting_price' => '90.00'], ['min_increment' => '5.00'], ['buy_now_price' => '999.00'], ['quantity' => 2], ['lot_number' => 9]] as $locked) {
        expect(codeOf(fn () => $svc->updateLot($w->admin, $w->lot, $locked)))->toBe('lot_field_locked');
    }

    bid($w, $w->u1, '100.00');
    expect(codeOf(fn () => $svc->updateLot($w->admin, $w->lot, ['reserve_price' => '120.00'])))->toBe('lot_field_locked')
        ->and($w->lot->fresh()->reserve_price)->toBe('350.00');
    $svc->updateLot($w->admin, $w->lot, ['title_en' => 'Cedar Chest']); // text is still fine
    expect($w->lot->fresh()->title_en)->toBe('Cedar Chest');
});

it('publishes a sequenced "edited" event when a live lot changes, so clients refresh', function () {
    $w = auctionWorld();
    $before = $w->lot->fresh()->last_event_sequence;

    app(AuctionService::class)->updateLot($w->admin, $w->lot, ['title_en' => 'Cedar Chest, restored']);
    $lot = $w->lot->fresh();
    expect($lot->last_event_sequence)->toBe($before + 1)
        ->and($lot->events()->reorder('sequence', 'desc')->first()->type)->toBe('edited');

    // A no-op edit does not spam events.
    app(AuctionService::class)->updateLot($w->admin, $w->lot, ['title_en' => 'Cedar Chest, restored']);
    expect($w->lot->fresh()->last_event_sequence)->toBe($before + 1);
});

it('refuses to edit finished lots, cancelled auctions and unauthorised staff', function () {
    $w = auctionWorld();
    $svc = app(AuctionService::class);
    $lot = pendingLot($w);

    expect(codeOf(fn () => $svc->updateLot(staffWithRole('sales_employee'), $lot, ['title_en' => 'x'])))->toBe('branch_forbidden');

    app(LotService::class)->close($w->lot, 'hammer', actor: $w->admin);
    expect(codeOf(fn () => $svc->updateLot($w->admin, $w->lot->fresh(), ['title_en' => 'x'])))->toBe('invalid_state_transition');

    $svc->cancel($w->admin, $w->auction->fresh(), 'Postponed');
    expect(codeOf(fn () => $svc->updateLot($w->admin, $lot->fresh(), ['title_en' => 'x'])))->toBe('invalid_state_transition');
});

it('deletes only untouched pending lots and keeps published auctions populated', function () {
    $w = auctionWorld();
    $svc = app(AuctionService::class);
    $extra = pendingLot($w);

    expect(codeOf(fn () => $svc->deleteLot($w->admin, $w->lot)))->toBe('lot_not_deletable'); // open
    bid($w, $w->u1, '100.00');
    expect(codeOf(fn () => $svc->deleteLot(staffWithRole('sales_employee'), $extra)))->toBe('branch_forbidden');

    $svc->deleteLot($w->admin, $extra);
    expect(AuctionLot::find($extra->id))->toBeNull()
        ->and(AuditLog::where('action', 'auction.lot_deleted')->exists())->toBeTrue();

    // The auction now has one lot (open, with bids): it can't be removed, and a lone pending lot of a published auction can't go.
    expect(codeOf(fn () => $svc->deleteLot($w->admin, $w->lot->fresh())))->toBe('lot_not_deletable');
    $solo = auctionWorld(auction: ['type' => 'scheduled']);
    $solo->lot->update(['status' => 'pending']);
    expect(codeOf(fn () => app(AuctionService::class)->deleteLot($solo->admin, $solo->lot->fresh())))->toBe('auction_needs_a_lot');
});

it('lets a draft auction drop its last lot and closes a live auction whose remaining lots are all done', function () {
    $w = auctionWorld();
    $svc = app(AuctionService::class);
    $pending = pendingLot($w);
    app(LotService::class)->close($w->lot, 'hammer', actor: $w->admin);   // the open lot ends; only the pending one remains
    expect($w->auction->fresh()->status)->toBe('live');

    $svc->deleteLot($w->admin, $pending);
    expect($w->auction->fresh()->status)->toBe('closed');

    $draft = $svc->save($w->admin, ['type' => 'scheduled', 'translations' => ['en' => ['title' => 'Draft'], 'ar' => ['title' => 'مسودة']]]);
    $lot = $svc->addLot($w->admin, $draft, ['title_en' => 'Vase', 'title_ar' => 'مزهرية', 'starting_price' => '10', 'min_increment' => '5']);
    $svc->deleteLot($w->admin, $lot);
    expect($draft->lots()->count())->toBe(0);
});

it('extends an open lot manually without touching anti-sniping counters', function () {
    $w = auctionWorld();
    $lots = app(LotService::class);
    $end = $w->lot->fresh()->ends_at;

    $lot = $lots->extend($w->lot, $w->admin, 600, 'Network problems at the venue');
    expect($lot->ends_at->equalTo($end->addSeconds(600)))->toBeTrue()->and($lot->extension_count)->toBe(0);

    $event = $lot->events()->reorder('sequence', 'desc')->first();
    expect($event->type)->toBe('extended')->and($event->payload['manual'])->toBeTrue()->and($event->payload['seconds'])->toBe(600);
    expect(AuditLog::where('action', 'auction.lot_extended')->exists())->toBeTrue();
});

it('extends the remaining time of a paused lot and refuses invalid or unauthorised extensions', function () {
    $w = auctionWorld();
    $lots = app(LotService::class);

    $lots->pause($w->lot, $w->admin, 'Technical break');
    $remaining = $w->lot->fresh()->paused_remaining_seconds;
    $lots->extend($w->lot->fresh(), $w->admin, 120, 'Give bidders time');
    expect($w->lot->fresh()->paused_remaining_seconds)->toBe($remaining + 120)->and($w->lot->fresh()->status)->toBe('paused');

    $lots->resume($w->lot->fresh(), $w->admin);
    expect(codeOf(fn () => $lots->extend($w->lot->fresh(), $w->admin, 5, 'too short')))->toBe('lot_invalid')
        ->and(codeOf(fn () => $lots->extend($w->lot->fresh(), $w->admin, 999999, 'too long')))->toBe('lot_invalid')
        ->and(codeOf(fn () => $lots->extend($w->lot->fresh(), staffWithRole('sales_employee'), 60, 'nope')))->toBe('branch_forbidden');

    // A lot whose time already ran out is closed by the clock, never revived by an extension.
    $w->lot->update(['ends_at' => now()->subSecond()]);
    expect(codeOf(fn () => $lots->extend($w->lot->fresh(), $w->admin, 60, 'late')))->toBe('invalid_state_transition');
});

it('exposes lot editing, deletion and extension through the admin API', function () {
    $w = auctionWorld();
    $lot = pendingLot($w);
    actingAsStaff($w->admin);

    $this->patchJson("/api/v1/admin/auctions/lots/{$lot->id}", ['title_en' => 'Lantern', 'starting_price' => '300.00'])->assertOk()
        ->assertJsonPath('data.title_en', 'Lantern')->assertJsonPath('data.reserve_price', '500.00');
    $this->patchJson("/api/v1/admin/auctions/lots/{$w->lot->id}", ['starting_price' => '1.00'])->assertStatus(422)->assertJsonPath('code', 'lot_field_locked');
    $this->patchJson("/api/v1/admin/auctions/lots/{$lot->id}", ['buy_now_price' => 'abc'])->assertStatus(422)->assertJsonPath('code', 'validation_failed');

    $this->postJson("/api/v1/admin/auctions/lots/{$w->lot->id}/extend", ['seconds' => 300, 'reason' => 'Technical issue'])->assertOk();
    $this->postJson("/api/v1/admin/auctions/lots/{$w->lot->id}/extend", ['seconds' => 300])->assertStatus(422);

    $this->deleteJson("/api/v1/admin/auctions/lots/{$lot->id}")->assertNoContent();
    $this->deleteJson("/api/v1/admin/auctions/lots/{$w->lot->id}")->assertStatus(409)->assertJsonPath('code', 'lot_not_deletable');

    actingAsStaff(staffWithRole('sales_employee'));
    $this->patchJson("/api/v1/admin/auctions/lots/{$w->lot->id}", ['title_en' => 'x'])->assertForbidden();
    actingAsCustomer();
    $this->deleteJson("/api/v1/admin/auctions/lots/{$w->lot->id}")->assertForbidden();
});
