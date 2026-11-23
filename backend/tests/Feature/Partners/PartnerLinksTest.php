<?php

use App\Domain\Fulfillment\Models\DriverLocation;
use App\Domain\Orders\Models\Order;
use App\Domain\Partners\Models\Partner;
use App\Domain\Partners\Services\PartnerAccess;
use App\Domain\Partners\Services\PartnerLinks;
use App\Livewire\Admin\Insights\PartnerAccessPage;
use App\Support\Geo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/** @return array{0: Partner, 1: string} */
function newPartner(?array $branchIds = null, ?DateTimeInterface $expires = null): array
{
    $made = app(PartnerLinks::class)->create('Gulf Logistics', 'ops@example.test', $branchIds ?? [], $branchIds === null, $expires ? CarbonImmutable::instance($expires) : null, null);

    return [$made['partner'], $made['password']];
}

function partnerUnlock(Partner $p, string $password): TestResponse
{
    return test()->postJson("/partner/{$p->token}/unlock", ['password' => $password]);
}

/** An order with private details, delivered by a driver who recorded a route past the customer's door. */
function privateDelivery(): object
{
    $d = deliveryAt('out_for_delivery', ['latitude' => 25.30123, 'longitude' => 51.50456]);
    Order::find($d->orderId)->update(['customer_name' => 'Zzyx Qwertyuiop', 'customer_phone' => '+97455987654']);
    foreach (range(0, 5) as $i) {
        DriverLocation::create(['driver_id' => $d->driver->id, 'delivery_id' => $d->delivery->id, 'lat' => 25.2901 + $i * 0.0021, 'lng' => 51.4903 + $i * 0.0023, 'recorded_at' => now()->addSeconds($i * 20)]);
    }

    return $d;
}

it('snaps a point to a 500 m grid: stable, close, and the same for every point of a cell', function () {
    $a = Geo::coarsen(25.30123, 51.50456);
    expect(Geo::coarsen(25.30123, 51.50456))->toBe($a)
        ->and(Geo::distanceM(25.30123, 51.50456, $a['lat'], $a['lng']))->toBeLessThan(400)
        ->and(Geo::coarsen(25.3014, 51.5047))->toBe($a);
    $far = Geo::coarsen(25.31, 51.52);
    expect(Geo::distanceM($a['lat'], $a['lng'], $far['lat'], $far['lng']))->toBeGreaterThan(400);
});

it('gives each partner an own link and password: the password is stored hashed and works only on its own link', function () {
    [$p, $password] = newPartner();
    [$other] = newPartner();
    expect($p->password_hash)->not->toContain($password)->and(DB::table('partners')->where('id', $p->id)->value('password_hash'))->not->toBe($password);

    $this->get("/partner/{$p->token}")->assertOk()->assertSee('Open statistics');
    partnerUnlock($p, 'wrong-password')->assertStatus(422);
    partnerUnlock($other, $password)->assertStatus(422);
    partnerUnlock($p, $password)->assertOk();
    $this->getJson("/partner/{$p->token}/data")->assertOk();
    $this->getJson("/partner/{$other->token}/data")->assertStatus(401); // unlocking one link opens only that link
    expect(DB::table('partner_access_logs')->where('partner_id', $p->id)->pluck('event')->all())->toContain('unlocked')->toContain('viewed_day');
});

it('answers 403 on a revoked link at once, even for a signed-in browser, and on an expired one', function () {
    [$p, $password] = newPartner();
    partnerUnlock($p, $password)->assertOk();
    $this->getJson("/partner/{$p->token}/data")->assertOk();

    app(PartnerLinks::class)->revoke($p, null);
    $this->getJson("/partner/{$p->token}/data")->assertForbidden();
    $this->getJson("/partner/{$p->token}/fleet")->assertForbidden();
    $this->postJson("/partner/{$p->token}/unlock", ['password' => $password])->assertForbidden();
    $this->get("/partner/{$p->token}")->assertForbidden()->assertSee('no longer active');

    [$e, $ePassword] = newPartner(null, now()->addDay());
    partnerUnlock($e, $ePassword)->assertOk();
    $this->travel(2)->days();
    $this->getJson("/partner/{$e->token}/data")->assertForbidden();
    $this->get("/partner/{$e->token}")->assertForbidden();
    $this->getJson('/partner/'.str_repeat('x', 40).'/data')->assertNotFound();
});

it('locks a link for 15 minutes after five wrong passwords, even for the right one', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'Asia/Qatar'));
    [$p, $password] = newPartner();
    foreach (range(1, 4) as $i) {
        partnerUnlock($p, "bad-{$i}")->assertStatus(422);
    }
    partnerUnlock($p, 'bad-5')->assertStatus(429)->assertJsonPath('message', 'too_many');
    expect($p->fresh()->status())->toBe('locked');
    partnerUnlock($p, $password)->assertStatus(429);
    expect(DB::table('partner_access_logs')->where('partner_id', $p->id)->pluck('event')->all())->toContain('locked_out');

    $this->travel(16)->minutes();
    $this->flushSession();
    RateLimiter::clear('partner-unlock:127.0.0.1');
    partnerUnlock($p, $password)->assertOk();
    expect($p->fresh()->failed_attempts)->toBe(0);
});

it('shows a partner no personal data: no names, phones, or exact places, in any response', function () {
    $d = privateDelivery();
    [$p, $password] = newPartner([$d->w->a->id]);
    partnerUnlock($p, $password)->assertOk();
    $day = now(config('platform.business_timezone'))->toDateString();

    $bodies = [
        $this->getJson("/partner/{$p->token}/data?date={$day}")->assertOk()->getContent(),
        $order = $this->getJson("/partner/{$p->token}/orders/{$d->orderId}")->assertOk()->getContent(),
        $this->getJson("/partner/{$p->token}/fleet")->assertOk()->getContent(),
        $this->getJson("/partner/{$p->token}/routes?date={$day}")->assertOk()->getContent(),
        $this->getJson("/partner/{$p->token}/events?since=".((int) (microtime(true) * 1000) - 600000))->assertOk()->getContent(),
        $this->get("/partner/{$p->token}")->assertOk()->getContent(),
    ];
    foreach ($bodies as $body) {
        expect($body)->not->toContain('Zzyx')->not->toContain('Qwertyuiop')->not->toContain('55987654')->not->toContain('55123456')->not->toContain('Noor')
            ->not->toContain('25.30123')->not->toContain('51.50456')->not->toContain($d->customer->name)->not->toContain($d->customer->email ?? '@@@');
    }
    $data = json_decode($order, true)['data'];
    expect($data['customer'])->toBeNull()->and($data['phone'])->toBeNull()
        ->and(Geo::distanceM(25.30123, 51.50456, $data['destination']['lat'], $data['destination']['lng']))->toBeLessThan(400)
        ->and($data['destination'])->toBe(Geo::coarsen(25.30123, 51.50456));
    foreach ($data['deliveries'][0]['route'] as $pt) {
        expect($pt)->toBe($pt + Geo::coarsen($pt['lat'], $pt['lng']))->and(Geo::coarsen($pt['lat'], $pt['lng']))->toMatchArray(['lat' => $pt['lat'], 'lng' => $pt['lng']]);
    }
    expect(count($data['deliveries'][0]['route']))->toBeGreaterThan(1)->and($data['deliveries'][0]['distance_m'])->toBeGreaterThan(500);
    $fleetJob = json_decode($bodies[2], true)['data']['drivers'][0]['job'];
    expect($fleetJob['destination'])->toBe(Geo::coarsen(25.30123, 51.50456));
});

it('limits a partner to their branches: orders, order detail, routes, live drivers and events of other branches never show', function () {
    $d = privateDelivery();
    [$onlyB, $pwB] = newPartner([$d->w->b->id]);
    [$onlyA, $pwA] = newPartner([$d->w->a->id]);
    [$all, $pwAll] = newPartner();
    $day = now(config('platform.business_timezone'))->toDateString();
    $since = (int) (microtime(true) * 1000) - 600000;

    partnerUnlock($onlyB, $pwB)->assertOk();
    $this->getJson("/partner/{$onlyB->token}/data?date={$day}")->assertOk()->assertJsonPath('data.totals.orders', 0);
    $this->getJson("/partner/{$onlyB->token}/orders/{$d->orderId}")->assertNotFound();
    $this->getJson("/partner/{$onlyB->token}/routes?date={$day}")->assertOk()->assertJsonPath('data.trips', []);
    $this->getJson("/partner/{$onlyB->token}/fleet")->assertOk()->assertJsonPath('data.drivers', []);
    $this->getJson("/partner/{$onlyB->token}/events?since={$since}")->assertOk()->assertJsonPath('data.events', []);
    $this->get("/partner/{$onlyB->token}")->assertDontSee($d->w->a->name_en, false);

    foreach ([[$onlyA, $pwA], [$all, $pwAll]] as [$p, $pw]) {
        $this->flushSession();
        partnerUnlock($p, $pw)->assertOk();
        $this->getJson("/partner/{$p->token}/data?date={$day}")->assertOk()->assertJsonPath('data.totals.orders', 1);
        $this->getJson("/partner/{$p->token}/orders/{$d->orderId}")->assertOk();
        expect($this->getJson("/partner/{$p->token}/routes?date={$day}")->json('data.trips'))->toHaveCount(1);
        expect($this->getJson("/partner/{$p->token}/fleet")->json('data.drivers'))->toHaveCount(1);
    }
});

it('keeps the old shared link working exactly as before until the owner turns it off', function () {
    $d = privateDelivery();
    $access = app(PartnerAccess::class);
    $access->setPassword('Correct-Horse-9');
    $access->setEnabled(true);
    $token = $access->token();
    $this->postJson("/partner/{$token}/unlock", ['password' => 'Correct-Horse-9'])->assertOk();

    $order = $this->getJson("/partner/{$token}/orders/{$d->orderId}")->assertOk()->json('data');
    expect($order['customer'])->toBe('Zzyx Qwertyuiop')->and($order['destination'])->toBe(['lat' => 25.30123, 'lng' => 51.50456]);

    $access->setEnabled(false);
    $this->getJson("/partner/{$token}/data")->assertNotFound();
    [$p, $password] = newPartner(); // per-partner links do not depend on the old switch
    partnerUnlock($p, $password)->assertOk();
    $this->getJson("/partner/{$p->token}/data")->assertOk();
});

it('stores a hash of the address, never the address, in the access log of a partner', function () {
    [$p, $password] = newPartner();
    partnerUnlock($p, $password)->assertOk();
    $row = DB::table('partner_access_logs')->where('partner_id', $p->id)->where('event', 'unlocked')->first();
    expect($row->ip)->toBeNull()->and($row->ip_hash)->toBe(app(PartnerLinks::class)->ipHash('127.0.0.1'))->and(strlen($row->ip_hash))->toBe(64);
    $this->getJson("/partner/{$p->token}/data")->assertOk();
    $this->getJson("/partner/{$p->token}/data")->assertOk();
    expect(DB::table('partner_access_logs')->where('partner_id', $p->id)->where('event', 'viewed_day')->count())->toBe(1); // polling does not flood the log
});

it('lets the owner add, change, regenerate and revoke partners in the dashboard, with the password shown once', function () {
    $d = deliveryAt();
    $this->actingAs(staffWithRole('admin'));

    $c = Livewire::test(PartnerAccessPage::class)->call('openCreate')->call('savePartner')->assertHasErrors(['name', 'branchIds'])
        ->set('name', 'Gulf Logistics')->set('branchIds', [$d->w->a->id])->call('savePartner')->assertHasNoErrors();
    $reveal = $c->get('reveal');
    $p = Partner::firstOrFail();
    expect($reveal['password'])->toMatch('/^\w{4}-\w{4}-\w{4}-\w{4}$/')->and($reveal['url'])->toBe(url('/partner/'.$p->token))
        ->and($p->branches()->pluck('branches.id')->all())->toBe([$d->w->a->id]);
    $c->call('closeReveal')->assertSet('reveal', null)->assertDontSee($reveal['password']);

    $c->call('openEdit', $p->id)->assertSet('name', 'Gulf Logistics')->set('allBranches', true)->set('expiresOn', now()->addDays(10)->toDateString())->call('savePartner')->assertHasNoErrors();
    expect($p->fresh()->all_branches)->toBeTrue()->and($p->fresh()->expires_at)->not->toBeNull();

    $oldToken = $p->token;
    $c->call('ask', 'regenerate', $p->id)->call('runConfirm');
    $new = $c->get('reveal');
    expect($p->fresh()->token)->not->toBe($oldToken)->and($new['password'])->not->toBe($reveal['password']);
    $this->postJson("/partner/{$oldToken}/unlock", ['password' => $reveal['password']])->assertNotFound();

    $c->call('closeReveal')->call('ask', 'revoke', $p->id)->call('runConfirm');
    expect($p->fresh()->status())->toBe('revoked');
    $c->assertSee(__('partner_admin.status.revoked'));
    expect(DB::table('audit_logs')->whereIn('action', ['partner.created', 'partner.updated', 'partner.regenerated', 'partner.revoked'])->count())->toBe(4);
});

it('refuses partner management without the settings right and shows the page read-only with view only', function () {
    $this->actingAs(staffWithRole('customer_support'));
    Livewire::test(PartnerAccessPage::class)->assertForbidden();

    $this->actingAs(staffWithRole('auditor'));
    Livewire::test(PartnerAccessPage::class)->assertOk()->assertDontSeeHtml('wire:click="openCreate"')->call('openCreate')->assertForbidden();
});

it('shows the empty state and the old-link banner in Arabic too', function () {
    $this->actingAs(staffWithRole('admin'));
    app()->setLocale('ar');
    Livewire::test(PartnerAccessPage::class)->assertSee(__('partner_admin.none'))->assertSee('لا يوجد شركاء بعد')->assertSee('الرابط المشترك القديم');
});
