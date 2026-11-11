<?php

use App\Domain\Auctions\Events\AuctionOutbid;
use App\Domain\Auctions\Events\AuctionWon;
use App\Domain\Auctions\Events\LotUpdated;
use App\Domain\Identity\Models\User;
use Illuminate\Broadcasting\BroadcastManager;

beforeEach(function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    app(BroadcastManager::class)->purge('reverb');
    // Channel definitions are registered on the broadcaster resolved at boot (null in tests): register them on reverb.
    require base_path('routes/channels.php');
});

it('authorises a customer bearer token for their own private channel only', function () {
    $me = actingAsCustomer();
    $other = User::factory()->create();

    $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-users.'.$me->id])
        ->assertOk()->assertJsonStructure(['auth']);
    $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-users.'.$other->id])
        ->assertForbidden();
});

it('rejects unauthenticated channel auth', function () {
    $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-users.x'])
        ->assertUnauthorized();
});

it('uses the event names and channels the mobile app subscribes to', function () {
    $outbid = new AuctionOutbid('u1', 'lot1', 'Chair', 'QAR 100.00');
    expect($outbid->broadcastAs())->toBe('auction.outbid')
        ->and($outbid->broadcastOn()[0]->name)->toBe('private-users.u1')
        ->and($outbid->broadcastWith())->toMatchArray(['lot_id' => 'lot1']);

    $won = new AuctionWon('w1', 'u1', 'lot1');
    expect($won->broadcastAs())->toBe('auction.won')->and($won->broadcastWhen())->toBeTrue()
        ->and((new AuctionWon('w1'))->broadcastWhen())->toBeFalse();

    expect((new LotUpdated('lot1', 3, 'bid_accepted'))->broadcastAs())->toBe('bid.accepted')
        ->and((new LotUpdated('lot1', 3, 'extended'))->broadcastAs())->toBe('lot.extended')
        ->and((new LotUpdated('lot1', 3, 'closed'))->broadcastOn()[0]->name)->toBe('auction-lot.lot1');
});
