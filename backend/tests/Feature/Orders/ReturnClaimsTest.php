<?php

require_once __DIR__.'/../Operations/OpsSupport.php';

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Models\NotificationRule;
use App\Domain\Notifications\Services\NotificationRules;
use App\Domain\Operations\Services\AttentionFeed;
use App\Domain\Operations\Services\OpsClock;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\ReturnRequest;
use App\Domain\Orders\Models\ReturnRequestPhoto;
use App\Domain\Orders\Services\ReturnService;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Organization\SettingsIndex;
use App\Livewire\Admin\Sales\ReturnIndex;
use App\Support\DomainException;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
| Return and damage claims: photos (private, re-encoded), the customer's timeline, the team's decisions and what the customer is told at each step.
| Money goes through the existing refund flow (its own tests); the claim only raises the request.
*/

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(qatar('2026-11-19 12:00'));
});

/** A delivered cash order of two sofas of the signed-in customer (both handed over yesterday): [world, order, line]. */
function claimWorld(): array
{
    $w = commerceWorld();
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 2])->assertCreated();
    $order = Order::find(quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->json('data.order.id'));
    $order->fulfillments()->update(['status' => 'delivered', 'delivered_at' => now()->subDay()]);
    $item = $order->items()->first();
    $item->forceFill(['quantity_handed_over' => 2])->save();

    return [$w, $order->fresh(), $item];
}

/** A real JPEG, optionally with a hidden "EXIF" segment (like the location a phone writes) in front of the picture. */
function claimPhoto(string $name = 'problem.jpg', int $w = 60, int $h = 40, ?string $secret = null, int $shade = 120): UploadedFile
{
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, $shade, 90, 60));
    ob_start();
    imagejpeg($img);
    $data = (string) ob_get_clean();
    if ($secret !== null) {
        $payload = "Exif\0\0".$secret;
        $data = substr($data, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($data, 2);
    }
    $path = tempnam(sys_get_temp_dir(), 'claim').'.jpg';
    file_put_contents($path, $data);

    return new UploadedFile($path, $name, 'image/jpeg', null, true);
}

/** Posts a claim as multipart (the apps send photos this way). */
function claimPost(Order $order, array $body, array $photos = [], array $headers = [])
{
    return test()->withHeaders(array_merge(['Accept' => 'application/json', 'Idempotency-Key' => ''], $headers))->post("/api/v1/orders/{$order->id}/returns", $body + ($photos ? ['photos' => $photos] : []));
}

function claimLine($item, int $qty = 1): array
{
    return ['items' => [['order_item_id' => $item->id, 'quantity' => $qty]]];
}

// ─── What a claim needs ─────────────────────────────────────────────────────────────────────────────────────────────────

it('refuses a damaged or wrong-item claim without a photo, and accepts the other kinds without one', function (string $type, bool $needsPhoto) {
    [, $order, $item] = claimWorld();

    $r = claimPost($order, claimLine($item) + ['reason_code' => $type]);
    if ($needsPhoto) {
        $r->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['errors' => ['photos']]);
        expect(ReturnRequest::count())->toBe(0);
        claimPost($order, claimLine($item) + ['reason_code' => $type], [claimPhoto()])->assertCreated()->assertJsonPath('data.kind', $type)->assertJsonPath('data.evidence_required', true);
    } else {
        $r->assertCreated()->assertJsonPath('data.evidence_required', false);
    }
})->with([
    'damaged' => ['damaged', true], 'wrong item' => ['wrong_item', true],
    'missing item' => ['missing_item', false], 'quality' => ['quality', false], 'changed mind' => ['changed_mind', false], 'other' => ['other', false],
    'an older app says not_as_described' => ['not_as_described', false],
]);

it('keeps at most five photos, JPEG PNG or WebP only, and a short note in any language', function () {
    [, $order, $item] = claimWorld();
    $six = array_map(fn ($i) => claimPhoto("p{$i}.jpg", 60, 40, null, 20 * $i), range(1, 6));

    claimPost($order, claimLine($item) + ['reason_code' => 'damaged'], $six)->assertStatus(422)->assertJsonStructure(['errors' => ['photos']]);
    $text = UploadedFile::fake()->createWithContent('proof.jpg', '<?php echo 1;');
    claimPost($order, claimLine($item) + ['reason_code' => 'damaged'], [$text])->assertStatus(422);
    $svg = UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    claimPost($order, claimLine($item) + ['reason_code' => 'damaged'], [$svg])->assertStatus(422);
    claimPost($order, claimLine($item) + ['reason_code' => 'changed_mind', 'reason' => str_repeat('ا', 501)])->assertStatus(422)->assertJsonStructure(['errors' => ['reason']]);
    expect(ReturnRequest::count())->toBe(0);

    $note = 'الكنبة وصلت مكسورة من الجانب 😞 — صورة مرفقة';
    $id = claimPost($order, claimLine($item) + ['reason_code' => 'damaged', 'reason' => $note], array_slice($six, 0, 5))->assertCreated()
        ->assertJsonPath('data.note', $note)->assertJsonCount(5, 'data.photos')->json('data.id');
    expect(ReturnRequestPhoto::where('return_request_id', $id)->count())->toBe(5);
});

it('strips the metadata of a photo, applies a size limit and stores it only on the private disk', function () {
    [, $order, $item] = claimWorld();
    $big = claimPhoto('huge.jpg', 3000, 2000, 'GPS-25.2854N-51.5310E');

    $data = claimPost($order, claimLine($item) + ['reason_code' => 'damaged'], [$big])->assertCreated()->json('data');
    $photo = ReturnRequestPhoto::sole();
    $bytes = Storage::disk('local')->get($photo->path);

    expect($photo->disk)->toBe('local')->and($photo->path)->toStartWith('return-claims/'.$data['id'].'/')->and($photo->mime)->toBe('image/jpeg')
        ->and($bytes)->not->toContain('GPS-25.2854N')->and($bytes)->not->toContain('Exif')
        ->and(max($photo->width, $photo->height))->toBe(1600)->and(getimagesizefromstring($bytes)['mime'])->toBe('image/jpeg')
        ->and($data['photos'][0])->not->toHaveKey('url')->and($data['photos'][0]['path'])->toBe('returns/'.$data['id'].'/photos/'.$photo->id);
});

it('answers a double tap with the claim already made, and a reused key on another order is refused', function () {
    [, $order, $item] = claimWorld();
    $body = claimLine($item) + ['reason_code' => 'damaged', 'reason' => 'Cracked leg'];

    $first = claimPost($order, $body, [claimPhoto()], ['Idempotency-Key' => 'claim-key-1'])->assertCreated()->json('data.id');
    claimPost($order, $body, [claimPhoto()], ['Idempotency-Key' => 'claim-key-1'])->assertOk()->assertJsonPath('data.id', $first);
    expect(ReturnRequest::count())->toBe(1)->and(ReturnRequestPhoto::count())->toBe(1);

    // No key at all: an identical claim a moment later is the same tap.
    $second = claimPost($order, claimLine($item) + ['reason_code' => 'changed_mind'])->assertCreated()->json('data.id');
    claimPost($order, claimLine($item) + ['reason_code' => 'changed_mind'])->assertOk()->assertJsonPath('data.id', $second);
    expect(ReturnRequest::count())->toBe(2);

    $this->postJson('/api/v1/cart/items', ['variant_id' => $item->product_variant_id, 'quantity' => 1])->assertCreated();
    $other = Order::find(quoteAndPlace(['address_id' => $order->user->addresses()->first()->id, 'payment_method' => 'cod'])->json('data.order.id'));
    $other->fulfillments()->update(['status' => 'delivered', 'delivered_at' => now()->subDay()]);
    $other->items()->update(['quantity_handed_over' => 1]);
    claimPost($other, ['items' => [['order_item_id' => $other->items()->first()->id, 'quantity' => 1]], 'reason_code' => 'changed_mind'], [], ['Idempotency-Key' => 'claim-key-1'])->assertStatus(409);
});

it('never lets two claims hold the same piece, and keeps one branch per claim', function () {
    [$w, $order, $item] = claimWorld();

    claimPost($order, claimLine($item, 2) + ['reason_code' => 'changed_mind'])->assertCreated();
    claimPost($order, claimLine($item, 1) + ['reason_code' => 'missing_item'], [], ['Idempotency-Key' => 'k2'])->assertStatus(422);
    expect(ReturnRequest::count())->toBe(1);

    // A claim another branch's line cannot join: build a second line at branch B in the same order.
    $line2 = $order->items()->first()->replicate();
    $line2->forceFill(['branch_id' => $w->b->id, 'quantity_returned' => 0, 'quantity_handed_over' => 1, 'quantity' => 1])->save();
    ReturnRequest::query()->update(['status' => 'rejected']);
    claimPost($order, ['items' => [['order_item_id' => $item->id, 'quantity' => 1], ['order_item_id' => $line2->id, 'quantity' => 1]], 'reason_code' => 'changed_mind'])
        ->assertStatus(422)->assertJsonStructure(['errors' => ['items']]);
});

it('closes the claim window at the number of days the admin chose and tells the customer the last day', function () {
    [, $order, $item] = claimWorld();
    $d = $this->getJson("/api/v1/orders/{$order->id}")->json('data');
    expect($d['return_window']['days'])->toBe(14)->and($d['return_window']['until'])->toStartWith('2026-12-02');   // delivered 18 Nov, 14 days

    app(Settings::class)->set('returns.window_days', 3);
    $d = $this->getJson("/api/v1/orders/{$order->id}")->json('data');
    expect($d['return_window'])->toMatchArray(['days' => 3])->and($d['return_window']['until'])->toStartWith('2026-11-21');

    $order->fulfillments()->update(['delivered_at' => now()->subDays(5)]);
    $d = $this->getJson("/api/v1/orders/{$order->id}")->json('data');
    expect($d['can_return'])->toBeFalse()->and($d['return_window']['until'])->toBeNull();
    claimPost($order, claimLine($item) + ['reason_code' => 'changed_mind'])->assertStatus(422);
});

// ─── The photos are private ─────────────────────────────────────────────────────────────────────────────────────────────

it('serves a photo only to its owner and to staff of the claim\'s branch', function () {
    [$w, $order, $item] = claimWorld();
    $id = claimPost($order, claimLine($item) + ['reason_code' => 'damaged'], [claimPhoto()])->assertCreated()->json('data.id');
    $photo = ReturnRequestPhoto::sole();
    $api = "/api/v1/returns/{$id}/photos/{$photo->id}";

    $this->get($api)->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
    actingAsCustomer();
    $this->getJson($api)->assertNotFound();
    $this->getJson("/api/v1/returns/{$id}")->assertNotFound();
    $this->postJson("/api/v1/returns/{$id}/cancel")->assertNotFound();
    $this->post("/api/v1/returns/{$id}/evidence", ['reason' => 'hi'], ['Accept' => 'application/json'])->assertNotFound();
    app('auth')->forgetGuards();
    $this->withHeaders(['Authorization' => ''])->getJson($api)->assertUnauthorized();

    $url = route('admin.returns.photo', [$id, $photo->id]);
    $this->actingAs(staffWithRole('branch_manager', $w->a))->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->actingAs(staffWithRole('branch_manager', $w->b))->get($url)->assertNotFound();
    $this->actingAs(staffWithRole('branch_manager', $w->a))->get(route('admin.returns.photo', [$id, (string) Str::uuid()]))->assertNotFound();
    app('auth')->forgetGuards();
    expect($this->get($url)->status())->toBeIn([302, 401]);
});

// ─── Adding evidence and cancelling ─────────────────────────────────────────────────────────────────────────────────────

it('lets the customer add photos and a note while the claim waits, once, and not after it is decided', function () {
    [, $order, $item] = claimWorld();
    $id = claimPost($order, claimLine($item) + ['reason_code' => 'damaged'], [claimPhoto('a.jpg', 60, 40, null, 10)])->assertCreated()->json('data.id');
    $more = fn (array $files, ?string $note = null) => $this->withHeaders(['Accept' => 'application/json'])->post("/api/v1/returns/{$id}/evidence", ['photos' => $files] + ($note ? ['reason' => $note] : []));

    $more([claimPhoto('b.jpg', 60, 40, null, 200)], 'It is cracked at the back too')->assertOk()->assertJsonCount(2, 'data.photos')->assertJsonPath('data.messages.0.text', 'It is cracked at the back too');
    // The same picture and the same words again change nothing (a retry after a lost answer).
    $more([claimPhoto('b-again.jpg', 60, 40, null, 200)], 'It is cracked at the back too')->assertOk()->assertJsonCount(2, 'data.photos')->assertJsonCount(1, 'data.messages');
    expect(ReturnRequest::find($id)->events()->where('kind', 'evidence_added')->count())->toBe(1);
    $this->post("/api/v1/returns/{$id}/evidence", [], ['Accept' => 'application/json'])->assertStatus(422);

    // Up to five: three more fit, a sixth does not.
    $more(array_map(fn ($i) => claimPhoto("c{$i}.jpg", 60, 40, null, 30 + $i * 25), [1, 2, 3]))->assertOk()->assertJsonCount(5, 'data.photos');
    $more([claimPhoto('d.jpg', 60, 40, null, 250)])->assertStatus(422)->assertJsonStructure(['errors' => ['photos']]);
    expect(ReturnRequestPhoto::count())->toBe(5);

    app(ReturnService::class)->decide(ReturnRequest::find($id), staffWithRole('super_admin'), true);
    actingAsCustomer($order->user);
    $more([claimPhoto('e.jpg')], 'late')->assertStatus(409)->assertJsonPath('code', 'claim_not_editable');
});

it('lets the customer cancel before the claim is approved, frees the pieces, and refuses afterwards', function () {
    [, $order, $item] = claimWorld();
    $id = claimPost($order, claimLine($item, 2) + ['reason_code' => 'changed_mind'])->assertCreated()->json('data.id');
    expect($this->getJson("/api/v1/orders/{$order->id}")->json('data.can_return'))->toBeFalse();

    $this->postJson("/api/v1/returns/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.can_cancel', false);
    $this->postJson("/api/v1/returns/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');   // twice is once
    $d = $this->getJson("/api/v1/orders/{$order->id}")->json('data');
    expect($d['can_return'])->toBeTrue()->and($d['items'][0]['returnable_quantity'])->toBe(2)->and(ReturnRequest::find($id)->events()->where('kind', 'cancelled')->count())->toBe(1);

    $second = claimPost($order, claimLine($item, 1) + ['reason_code' => 'changed_mind'], [], ['Idempotency-Key' => 'again'])->assertCreated()->json('data.id');
    app(ReturnService::class)->decide(ReturnRequest::find($second), staffWithRole('super_admin'), true);
    $this->postJson("/api/v1/returns/{$second}/cancel")->assertStatus(409)->assertJsonPath('code', 'claim_not_editable');
});

// ─── The customer's timeline ────────────────────────────────────────────────────────────────────────────────────────────

it('shows the steps from received to refunded, each in its own state', function () {
    [, $order, $item] = claimWorld();
    $id = claimPost($order, claimLine($item) + ['reason_code' => 'damaged', 'reason' => 'Broken'], [claimPhoto()])->assertCreated()->json('data.id');
    $steps = fn () => collect($this->getJson("/api/v1/returns/{$id}")->assertOk()->json('data.timeline'))->mapWithKeys(fn ($s) => [$s['key'] => $s['state']])->all();
    $staff = staffWithRole('super_admin');
    $svc = app(ReturnService::class);

    expect($steps())->toBe(['received' => 'current', 'under_review' => 'upcoming', 'decision' => 'upcoming', 'handover' => 'upcoming', 'item_received' => 'upcoming', 'refunded' => 'upcoming']);

    $svc->startReview(ReturnRequest::find($id), $staff);
    expect($steps())->toMatchArray(['received' => 'done', 'under_review' => 'current', 'decision' => 'upcoming']);

    $svc->decide(ReturnRequest::find($id), $staff, true, 'We are sorry about that.', 'pickup', 'Tomorrow 4-6 pm');
    $d = $this->getJson("/api/v1/returns/{$id}")->json('data');
    $by = collect($d['timeline'])->keyBy('key');
    expect($d['status'])->toBe('approved')->and($by['approved']['state'])->toBe('done')->and($by['approved']['detail'])->toBe('We are sorry about that.')
        ->and($by['pickup']['state'])->toBe('done')->and($by['pickup']['detail'])->toBe('Tomorrow 4-6 pm')->and($by['item_received']['state'])->toBe('current')
        ->and($d['method'])->toBe('pickup')->and($d['can_cancel'])->toBeFalse();

    $svc->receive(ReturnRequest::find($id), $staff);
    expect($steps())->toMatchArray(['item_received' => 'done', 'refunded' => 'current']);
});

it('ends the timeline at a rejection with the team\'s reply, and at a cancellation', function () {
    [, $order, $item] = claimWorld();
    $id = claimPost($order, claimLine($item) + ['reason_code' => 'changed_mind'])->assertCreated()->json('data.id');
    app(ReturnService::class)->decide(ReturnRequest::find($id), staffWithRole('super_admin'), false, 'A used sofa cannot be taken back.');

    $d = $this->getJson("/api/v1/returns/{$id}")->json('data');
    $keys = array_column($d['timeline'], 'key');
    expect($keys)->toBe(['received', 'under_review', 'rejected'])->and(collect($d['timeline'])->last())->toMatchArray(['state' => 'stopped', 'detail' => 'A used sofa cannot be taken back.'])
        ->and($d['decision_note'])->toBe('A used sofa cannot be taken back.');

    $other = claimPost($order, claimLine($item) + ['reason_code' => 'other'], [], ['Idempotency-Key' => 'k3'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/returns/{$other}/cancel")->assertOk();
    expect(array_column($this->getJson("/api/v1/returns/{$other}")->json('data.timeline'), 'key'))->toBe(['received', 'cancelled']);
});

it('shows the refund step with its amount and how, and finishes it when the money is sent', function () {
    [, $order, $item] = claimWorld();
    $id = claimPost($order, claimLine($item) + ['reason_code' => 'missing_item'])->assertCreated()->json('data.id');
    $svc = app(ReturnService::class);
    $staff = staffWithRole('super_admin');
    $svc->decide(ReturnRequest::find($id), $staff, true);                         // a missing item: nothing comes back

    $d = $this->getJson("/api/v1/returns/{$id}")->json('data');
    expect($d['method'])->toBe('none')->and(array_column($d['timeline'], 'key'))->toBe(['received', 'under_review', 'approved', 'refunded'])->and($d['refund'])->toBeNull();
});

// ─── The team's decisions ───────────────────────────────────────────────────────────────────────────────────────────────

it('keeps the team\'s rules: a reply to reject, a branch check, no receiving when nothing comes back, repeat-safe review', function () {
    [$w, $order, $item] = claimWorld();
    $id = claimPost($order, claimLine($item) + ['reason_code' => 'damaged'], [claimPhoto()])->assertCreated()->json('data.id');
    $svc = app(ReturnService::class);
    $mgr = staffWithRole('branch_manager', $w->a);
    $other = staffWithRole('branch_manager', $w->b);
    $claim = fn () => ReturnRequest::find($id);

    expect(fn () => $svc->decide($claim(), $mgr, false))->toThrow(DomainException::class, 'reply')
        ->and(fn () => $svc->decide($claim(), $mgr, false, 'no'))->toThrow(DomainException::class)
        ->and(fn () => $svc->decide($claim(), $mgr, true, null, 'teleport'))->toThrow(DomainException::class)
        ->and(fn () => $svc->startReview($claim(), $other))->toThrow(DomainException::class)
        ->and(fn () => $svc->decide($claim(), $other, true))->toThrow(DomainException::class);

    $svc->startReview($claim(), $mgr);
    $svc->startReview($claim(), $mgr);                                              // twice is once
    expect($claim()->events()->where('kind', 'review_started')->count())->toBe(1)->and($claim()->status)->toBe('under_review');

    $svc->decide($claim(), $mgr, true, null, 'none');
    expect(fn () => $svc->receive($claim(), $mgr))->toThrow(DomainException::class)
        ->and(fn () => $svc->decide($claim(), $mgr, true))->toThrow(DomainException::class);   // already decided
});

it('never restocks a damaged item by itself, restocks a returned one, and does what the manager chooses', function (string $type, ?bool $choice, bool $restocked) {
    [$w, $order, $item] = claimWorld();
    $before = stockOf($w->a, $w->v1)['on_hand'];
    $photos = in_array($type, ReturnRequest::EVIDENCE_REQUIRED, true) ? [claimPhoto()] : [];
    $id = claimPost($order, claimLine($item) + ['reason_code' => $type], $photos)->assertCreated()->json('data.id');
    $svc = app(ReturnService::class);
    $staff = staffWithRole('super_admin');
    $svc->decide(ReturnRequest::find($id), $staff, true);

    $svc->receive(ReturnRequest::find($id), $staff, $choice);

    $claim = ReturnRequest::find($id);
    expect(stockOf($w->a, $w->v1)['on_hand'])->toBe($before + ($restocked ? 1 : 0))->and($claim->stock_decision)->toBe($restocked ? 'restock' : 'write_off')
        ->and($item->fresh()->quantity_returned)->toBe(1)
        ->and(StockMovement::where('reference_type', 'return')->where('reference_id', $id)->count())->toBe($restocked ? 1 : 0);
})->with([
    'damaged, no choice: written off' => ['damaged', null, false],
    'damaged, manager restocks' => ['damaged', true, true],
    'quality, no choice: written off' => ['quality', null, false],
    'wrong item, no choice: written off' => ['wrong_item', null, false],
    'changed mind, no choice: restocked' => ['changed_mind', null, true],
    'changed mind, manager writes off' => ['changed_mind', false, false],
]);

it('raises the refund for a claim that never comes back, and for one that did, through the refund flow only', function () {
    [, $order, $item] = claimWorld();
    $svc = app(ReturnService::class);
    $staff = staffWithRole('super_admin');
    $id = claimPost($order, claimLine($item) + ['reason_code' => 'missing_item'])->assertCreated()->json('data.id');
    $svc->decide(ReturnRequest::find($id), $staff, true);

    // A cash order has nothing captured yet, so the refund flow itself says no (it is never bypassed).
    expect(fn () => $svc->refund(ReturnRequest::find($id), $staff, Money::of('100.00')))->toThrow(DomainException::class);
    expect(ReturnRequest::find($id)->status)->toBe('approved')->and(ReturnRequest::find($id)->refund_id)->toBeNull();
});

// ─── What the customer is told ──────────────────────────────────────────────────────────────────────────────────────────

it('tells the customer at every step, in their language, and the controls can switch a step off', function () {
    [, $order, $item] = claimWorld();
    $customer = $order->user;
    $customer->forceFill(['locale' => 'ar'])->save();
    $id = claimPost($order, claimLine($item) + ['reason_code' => 'damaged', 'reason' => 'x'], [claimPhoto()])->assertCreated()->json('data.id');
    $svc = app(ReturnService::class);
    $staff = staffWithRole('super_admin');
    $events = fn () => Notification::where('user_id', $customer->id)->where('event', 'like', 'return.%')->orderBy('created_at')->pluck('event')->all();

    expect($events())->toBe(['return.received']);
    $svc->startReview(ReturnRequest::find($id), $staff);
    $svc->decide(ReturnRequest::find($id), $staff, true, 'نعتذر عن ذلك', 'branch');
    $svc->receive(ReturnRequest::find($id), $staff);
    expect($events())->toBe(['return.received', 'return.under_review', 'return.approved', 'return.item_received']);

    $approved = Notification::where('event', 'return.approved')->sole();
    expect($approved->title)->toBe('تمت الموافقة على مطالبتك')->and($approved->body)->toContain(ReturnRequest::find($id)->number)->toContain('نعتذر عن ذلك')->toContain('يرجى إحضار القطعة إلى')
        ->and($approved->body)->not->toContain('{{')->and($approved->data['deep_link'])->toBe('albahar://orders/'.$order->id);

    // A rejection carries the team's words in English for an English customer.
    $customer->forceFill(['locale' => 'en'])->save();
    $this->postJson('/api/v1/cart/items', ['variant_id' => $item->product_variant_id, 'quantity' => 1])->assertCreated();
    $second = claimPost($order, claimLine($item) + ['reason_code' => 'other'], [], ['Idempotency-Key' => 'n2'])->assertCreated()->json('data.id');
    $svc->decide(ReturnRequest::find($second), $staff, false, 'Sorry, it is outside our policy.');
    expect(Notification::where('event', 'return.rejected')->sole()->body)->toContain('Message from the team: Sorry, it is outside our policy.');

    // The admin switches "approved" off: nothing is stored or sent for it.
    app(NotificationRules::class)->set('return.approved', false);
    $third = claimPost($order, claimLine($item) + ['reason_code' => 'other'], [], ['Idempotency-Key' => 'n3'])->assertCreated()->json('data.id');
    $svc->decide(ReturnRequest::find($third), $staff, true);
    expect(Notification::where('event', 'return.approved')->count())->toBe(1)->and(NotificationRule::where('event', 'return.approved')->exists())->toBeTrue();
});

// ─── The dashboard ──────────────────────────────────────────────────────────────────────────────────────────────────────

it('shows the claim in the side window with its photos, note and lines, and runs the whole flow from it', function () {
    [$w, $order, $item] = claimWorld();
    $id = claimPost($order, claimLine($item) + ['reason_code' => 'damaged', 'reason' => 'Cracked leg بالقدم'], [claimPhoto('a.jpg', 60, 40, null, 10), claimPhoto('b.jpg', 60, 40, null, 220)])->assertCreated()->json('data.id');
    $photoIds = ReturnRequestPhoto::orderBy('created_at')->pluck('id')->all();
    $mgr = staffWithRole('branch_manager', $w->a);
    $this->actingAs($mgr);

    $page = Livewire::test(ReturnIndex::class)->assertSee('Damaged on arrival')->call('select', $id)
        ->assertSee('Cracked leg بالقدم')->assertSee(route('admin.returns.photo', [$id, $photoIds[0]]), false)->assertSee(route('admin.returns.photo', [$id, $photoIds[1]]), false)
        ->assertSee($item->sku)->assertSet('stockChoice', 'write_off')->assertSet('method', 'branch');

    $page->call('startReview');
    expect(ReturnRequest::find($id)->status)->toBe('under_review');
    $page->call('decide', false)->assertHasErrors('note');
    $page->set('method', 'pickup')->set('methodNote', 'Tomorrow 4 to 6 pm')->set('note', 'We will collect it.')->call('decide', true);
    $claim = ReturnRequest::find($id);
    expect($claim->status)->toBe('approved')->and($claim->return_method)->toBe('pickup')->and($claim->method_note)->toBe('Tomorrow 4 to 6 pm')->and($claim->decision_note)->toBe('We will collect it.');
    $page->assertSee('Write off')->call('receive');
    expect(ReturnRequest::find($id)->status)->toBe('received')->and(ReturnRequest::find($id)->stock_decision)->toBe('write_off');
    expect(collect(AuditLog::where('action', 'like', 'return.%')->pluck('action')))->toContain('return.requested', 'return.review_started', 'return.approved', 'return.received');
});

it('keeps another branch\'s manager out of the claim window and its list', function () {
    [$w, $order, $item] = claimWorld();
    $id = claimPost($order, claimLine($item) + ['reason_code' => 'changed_mind'])->assertCreated()->json('data.id');

    $this->actingAs(staffWithRole('branch_manager', $w->b));
    expect(Livewire::test(ReturnIndex::class)->viewData('returns')->total())->toBe(0);
    Livewire::test(ReturnIndex::class)->call('select', $id)->assertForbidden();
});

// ─── Needs attention ────────────────────────────────────────────────────────────────────────────────────────────────────

it('lists a claim about the goods in Needs attention once it waited longer than the Operations clock says, and not a plain return', function () {
    [$w, $order, $item] = claimWorld();
    $damaged = claimPost($order, claimLine($item) + ['reason_code' => 'damaged'], [claimPhoto()])->assertCreated()->json('data.id');
    $changed = claimPost($order, ['items' => [['order_item_id' => $item->id, 'quantity' => 1]], 'reason_code' => 'changed_mind'], [], ['Idempotency-Key' => 'cm'])->assertCreated()->json('data.id');
    $mgr = staffWithRole('branch_manager', $w->a);
    $feed = fn () => app(AttentionFeed::class)->for($mgr)->items->where('category', 'claim')->values();

    expect($feed())->toHaveCount(0);                                                // just sent: nobody is late
    $this->travelTo(now()->addHours(3));
    expect($feed())->toHaveCount(0);                                                // inside the default amber limit (4 hours)
    $this->travelTo(now()->addHours(2));
    $rows = $feed();
    expect($rows)->toHaveCount(1)->and($rows[0]->id)->toBe('claim:'.$damaged)->and($rows[0]->subject)->toBe($order->number)->and($rows[0]->action['url'])->toContain('view='.$damaged)
        ->and($rows[0]->detail)->toContain('Damaged on arrival');

    app(ReturnService::class)->startReview(ReturnRequest::find($damaged), $mgr);
    app(ReturnService::class)->decide(ReturnRequest::find($damaged), $mgr, true);
    expect($feed())->toHaveCount(0);                                                // decided: it leaves the inbox
    expect(ReturnRequest::find($changed)->status)->toBe('requested');
});

it('has the claim limits in the Operations clock settings with a sensible default', function () {
    expect(OpsClock::DEFAULTS['claim'])->toBe([4, 24])->and(OpsClock::UNITS['claim'])->toBe('hours');
    $schema = SettingsIndex::schema();
    expect($schema['ops_clock'])->toHaveKeys(['ops_clock.claim_amber_hours', 'ops_clock.claim_red_hours'])->and($schema['checkout'])->toHaveKey('returns.window_days');
});
