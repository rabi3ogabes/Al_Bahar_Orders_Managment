<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Fulfillment\Models\DeliveryProof;
use App\Domain\Fulfillment\Services\AssemblyFacts;
use App\Domain\Fulfillment\Services\DeliveryService;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Operations\Services\AttentionFeed;
use App\Domain\Operations\Services\DeliveryProofs;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Sales\DeliveryFollowUp;
use App\Support\DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Operations/OpsSupport.php';

/*
| "Assembly done" in the driver app (docs/guides/DELIVERY_FOLLOW_UPS.md): a part with paid assembly is not delivered until the driver says whether the
| assembly was done; "not done" raises a line in "Needs attention" and tells the branch; the customer sees "Assembled on ...".
*/
beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(qatar('2026-11-19 12:00'));
});

/** A delivery on the road whose single line bought assembly (QAR 150). The session is left as the driver. */
function fuAssemblyJob(bool $paid = true): object
{
    $x = deliveryAt('out_for_delivery');
    if ($paid) {
        DB::table('order_items')->where('order_id', $x->orderId)->update(['assembly_fee' => '150.00', 'assembly_total' => '150.00']);
    }
    $x->url = "/api/v1/admin/deliveries/{$x->delivery->id}";
    $x->part = OrderFulfillment::findOrFail($x->delivery->order_fulfillment_id);
    actingAsStaff($x->driver);

    return $x;
}

function fuDeliverBody(object $x): array
{
    return ['recipient_name' => 'Noor', 'proof' => UploadedFile::fake()->image('door.jpg'), 'cod' => ['tender' => 'cash', 'amount' => (string) $x->delivery->cod_amount], 'answers_assembly' => '1'];
}

function fuAttention(object $user): array
{
    return app(AttentionFeed::class)->for($user)->items->where('category', 'assembly')->values()->all();
}

it('does not let a part with paid assembly be delivered before the driver answers', function () {
    $x = fuAssemblyJob();

    $this->postJson($x->url.'/deliver', fuDeliverBody($x))->assertStatus(422)->assertJsonPath('code', 'assembly_answer_required')
        ->assertJsonPath('message', 'Please say whether the assembly was done before you confirm the delivery.');
    expect($x->delivery->fresh()->status->value)->toBe('out_for_delivery')->and(DeliveryProof::count())->toBe(0);
});

it('still lets a driver app without the assembly step (and the dashboard driver view) deliver a part with paid assembly', function () {
    $x = fuAssemblyJob();
    $old = fuDeliverBody($x);
    unset($old['answers_assembly']); // build 90 and older never send it: refusing them would strand the parcel until the phone is updated

    $this->postJson($x->url.'/deliver', $old)->assertOk()->assertJsonPath('data.status', 'delivered');
    expect($x->part->fresh()->assembly_status)->toBeNull();
});

it('delivers once the driver said "done", and keeps who and when', function () {
    $x = fuAssemblyJob();

    $this->postJson($x->url.'/assembly', ['status' => 'done'])->assertOk();
    $this->postJson($x->url.'/deliver', fuDeliverBody($x))->assertOk()->assertJsonPath('data.status', 'delivered');

    $f = $x->part->fresh();
    expect($f->assembly_status)->toBe('done')->and($f->assembly_answered_by)->toBe($x->driver->id)->and($f->assembly_done_at->equalTo(qatar('2026-11-19 12:00')))->toBeTrue()
        ->and($f->assembly_reason)->toBeNull()->and($f->assembly_handled_at)->toBeNull();
    $audit = AuditLog::where('action', 'delivery.assembly_answered')->firstOrFail();
    expect($audit->actor_id)->toBe($x->driver->id)->and($audit->new_values['assembly_status'])->toBe('done');
    expect(fuAttention($x->mgr))->toBe([]);
});

it('shows the customer when the assembly was done, and the driver his own card with the answer and whether it is required', function () {
    $x = fuAssemblyJob();
    $card = $this->getJson("/api/v1/admin/dispatch/driver/jobs/{$x->delivery->id}")->assertOk()->json('data.assembly');
    expect($card)->toMatchArray(['ordered' => true, 'status' => 'pending', 'required' => true, 'reason' => null]);

    $this->postJson($x->url.'/assembly', ['status' => 'done', 'note' => 'Bed and wardrobe'])->assertOk();
    expect($this->getJson("/api/v1/admin/dispatch/driver/jobs/{$x->delivery->id}")->json('data.assembly'))->toMatchArray(['status' => 'done', 'note' => 'Bed and wardrobe']);

    actingAsCustomer($x->customer);
    $assembly = $this->getJson("/api/v1/orders/{$x->orderId}")->json('data.fulfillments.0.assembly');
    expect($assembly)->toMatchArray(['status' => 'done', 'reason_label' => null])->and($assembly['done_at'])->toStartWith('2026-11-19T12:00')->and($assembly)->not->toHaveKey('note');
});

it('has no assembly card for a part where nothing was bought, and refuses an answer for it', function () {
    $x = fuAssemblyJob(paid: false);

    expect($this->getJson("/api/v1/admin/dispatch/driver/jobs/{$x->delivery->id}")->json('data.assembly'))->toBeNull();
    $this->postJson($x->url.'/assembly', ['status' => 'done'])->assertStatus(422)->assertJsonPath('code', 'assembly_not_ordered');
    $this->postJson($x->url.'/deliver', fuDeliverBody($x))->assertOk(); // no answer needed
    actingAsCustomer($x->customer);
    expect($this->getJson("/api/v1/orders/{$x->orderId}")->json('data.fulfillments.0.assembly'))->toBeNull();
});

it('lets the owner switch the rule off: the delivery then needs no answer', function () {
    app(Settings::class)->set('delivery.assembly_answer_required', false);
    $x = fuAssemblyJob();

    expect($this->getJson("/api/v1/admin/dispatch/driver/jobs/{$x->delivery->id}")->json('data.assembly.required'))->toBeFalse();
    $this->postJson($x->url.'/deliver', fuDeliverBody($x))->assertOk();
});

it('needs a reason from the short list for "not done", and a note for "other"', function () {
    $x = fuAssemblyJob();

    $this->postJson($x->url.'/assembly', ['status' => 'not_done'])->assertStatus(422);
    $this->postJson($x->url.'/assembly', ['status' => 'not_done', 'reason' => 'because'])->assertStatus(422);
    $this->postJson($x->url.'/assembly', ['status' => 'not_done', 'reason' => 'other'])->assertStatus(422);
    $this->postJson($x->url.'/assembly', ['status' => 'maybe'])->assertStatus(422);
    $this->postJson($x->url.'/assembly', ['status' => 'not_done', 'reason' => 'other', 'note' => 'ab'])->assertOk();
    expect($x->part->fresh()->assembly_status)->toBe('not_done');
});

it('raises a line in "Needs attention" and tells the branch when the assembly was not done, then lets the delivery go through', function () {
    $x = fuAssemblyJob();

    $this->postJson($x->url.'/assembly', ['status' => 'not_done', 'reason' => 'missing_parts', 'note' => 'Two screws missing'])->assertOk();
    $this->postJson($x->url.'/deliver', fuDeliverBody($x))->assertOk()->assertJsonPath('data.status', 'delivered');

    $items = fuAttention($x->mgr);
    expect($items)->toHaveCount(1)->and($items[0]->subject)->toBe($x->delivery->fresh()->fulfillment->order->number)->and($items[0]->label)->toBe('Assembly not done')
        ->and($items[0]->detail)->toContain('Parts or tools were missing')->and($items[0]->note)->toBe('Two screws missing')->and($items[0]->action['type'])->toBe('peek');
    $alert = Notification::where('event', 'staff.assembly_not_done')->where('user_id', $x->mgr->id)->firstOrFail();
    expect($alert->title)->toContain('assembly not done')->and($alert->body)->toContain('Parts or tools were missing')->not->toContain('Noor');

    // the customer is told plainly, with the reason but not the driver's note
    actingAsCustomer($x->customer);
    $assembly = $this->getJson("/api/v1/orders/{$x->orderId}")->json('data.fulfillments.0.assembly');
    expect($assembly)->toMatchArray(['status' => 'not_done', 'reason_label' => 'Parts or tools were missing', 'done_at' => null]);
});

it('does the same thing twice for the same answer: one alert, one audit row', function () {
    $x = fuAssemblyJob();
    $body = ['status' => 'not_done', 'reason' => 'no_time'];

    $this->postJson($x->url.'/assembly', $body)->assertOk();
    $this->postJson($x->url.'/assembly', $body)->assertOk();

    expect(AuditLog::where('action', 'delivery.assembly_answered')->count())->toBe(1)->and(Notification::where('event', 'staff.assembly_not_done')->where('user_id', $x->mgr->id)->count())->toBe(1);
});

it('lets the driver correct his answer: "done" after "not done" clears the line', function () {
    $x = fuAssemblyJob();
    $this->postJson($x->url.'/assembly', ['status' => 'not_done', 'reason' => 'no_time'])->assertOk();
    expect(fuAttention($x->mgr))->toHaveCount(1);

    $this->postJson($x->url.'/assembly', ['status' => 'done'])->assertOk();

    expect(fuAttention($x->mgr))->toBe([])->and($x->part->fresh()->assembly_reason)->toBeNull()->and(AuditLog::where('action', 'delivery.assembly_answered')->count())->toBe(2);
});

it('keeps an optional photo of the assembly as a private proof photo that is not the proof of the handover', function () {
    $x = fuAssemblyJob();

    $this->postJson($x->url.'/assembly', ['status' => 'done', 'photo' => UploadedFile::fake()->image('assembled.jpg')])->assertOk();

    $row = DeliveryProof::where('delivery_id', $x->delivery->id)->firstOrFail();
    expect($row->type)->toBe('assembly')->and($row->disk)->toBe('local');
    Storage::disk('local')->assertExists($row->path);
    // it does not satisfy the handover's own photo
    $this->postJson($x->url.'/deliver', ['recipient_name' => 'Noor', 'cod' => ['tender' => 'cash', 'amount' => (string) $x->delivery->cod_amount]])->assertStatus(422)->assertJsonPath('code', 'delivery_proof_required');
    // the branch sees it, labelled
    $proof = app(DeliveryProofs::class)->for($x->delivery->fresh(), $x->mgr);
    expect($proof['photos'][0]['kind'])->toBe('assembly')->and($proof['recipient'])->toBeNull();
    // the picture of a wrong type is refused
    $this->postJson($x->url.'/assembly', ['status' => 'done', 'photo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertStatus(422);
});

it('answers only for the driver\'s own job and only while the goods are with him', function () {
    $x = fuAssemblyJob();
    $other = driverFor($x->w->a);
    actingAsStaff($other);
    $this->postJson($x->url.'/assembly', ['status' => 'done'])->assertStatus(403);

    actingAsStaff($x->driver);
    $this->postJson($x->url.'/assembly', ['status' => 'done'])->assertOk();
    $this->postJson($x->url.'/deliver', fuDeliverBody($x))->assertOk();
    $this->postJson($x->url.'/assembly', ['status' => 'not_done', 'reason' => 'no_time'])->assertStatus(409);
    expect($x->part->fresh()->assembly_status)->toBe('done');
});

it('lets the branch mark a "not done" as handled: it leaves the inbox, once, with an audit row, for the right branch only', function () {
    $x = fuAssemblyJob();
    $this->postJson($x->url.'/assembly', ['status' => 'not_done', 'reason' => 'not_possible'])->assertOk();
    $svc = app(DeliveryService::class);

    expect(fuAttention(staffWithRole('branch_manager', $x->w->b)))->toBe([]); // another branch sees nothing
    expect(fn () => $svc->handleAssembly($x->part->fresh(), staffWithRole('branch_manager', $x->w->b)))->toThrow(DomainException::class);
    expect(fn () => $svc->handleAssembly($x->part->fresh(), $x->driver))->toThrow(DomainException::class); // a driver cannot close it

    $svc->handleAssembly($x->part->fresh(), $x->mgr);
    $svc->handleAssembly($x->part->fresh(), $x->mgr);

    $f = $x->part->fresh();
    expect($f->assembly_handled_by)->toBe($x->mgr->id)->and(fuAttention($x->mgr))->toBe([])->and(AuditLog::where('action', 'delivery.assembly_handled')->count())->toBe(1);
    expect(fn () => $svc->handleAssembly(fuAssemblyDone($x), $x->mgr))->toThrow(DomainException::class);
});

function fuAssemblyDone(object $x): OrderFulfillment
{
    $f = $x->part->fresh();
    $f->update(['assembly_status' => 'done', 'assembly_reason' => null]);

    return $f;
}

it('shows the answer in the order window with a button to handle it, and the button works', function () {
    $x = fuAssemblyJob();
    $this->postJson($x->url.'/assembly', ['status' => 'not_done', 'reason' => 'customer_not_ready', 'note' => 'Room was empty'])->assertOk();

    Livewire::actingAs($x->mgr)->test(DeliveryFollowUp::class, ['fulfillmentId' => $x->part->id])
        ->assertSee('Assembly and installation')->assertSee('Not done: The customer was not ready')->assertSee('Room was empty')->assertSee('Mark as handled')
        ->call('handleAssembly')->assertSee('handled on')->assertDontSee('Mark as handled');
    expect($x->part->fresh()->assembly_handled_at)->not->toBeNull();

    app()->setLocale('ar');
    Livewire::actingAs($x->mgr)->test(DeliveryFollowUp::class, ['fulfillmentId' => $x->part->id])->assertSee('التركيب والتجهيز')->assertSee('العميل لم يكن مستعداً');
});

it('keeps another branch out of the order window component', function () {
    $x = fuAssemblyJob();

    Livewire::actingAs(staffWithRole('branch_manager', $x->w->b))->test(DeliveryFollowUp::class, ['fulfillmentId' => $x->part->id])->assertForbidden();
});

it('defines the same reasons in both languages', function () {
    foreach (AssemblyFacts::REASONS as $reason) {
        expect(__('delivery_followup.assembly_reason.'.$reason, [], 'en'))->not->toStartWith('delivery_followup')->and(__('delivery_followup.assembly_reason.'.$reason, [], 'ar'))->not->toBe(__('delivery_followup.assembly_reason.'.$reason, [], 'en'));
    }
});
