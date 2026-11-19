<?php

use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Services\ParcelShares;
use App\Domain\Payments\Models\CashCollection;
use App\Domain\Payments\Models\Payment;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Sales\DriverConsole;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Orders/OpsFixtures.php';

/**
 * A cash on delivery order that comes from two branches, both parts ready and each given to a driver of its branch.
 * Returns the split order object plus `da` / `db` (the deliveries) and `driverA` / `driverB`; shares `sa` / `sb` are what each driver must collect.
 */
function opsSplitWithDrivers(): object
{
    $s = opsSplitOrder();
    $s->driverA = driverFor($s->w->a);
    $s->driverB = driverFor($s->w->b);
    foreach ([[$s->fa, $s->mgrA, $s->driverA, 'da'], [$s->fb, $s->mgrB, $s->driverB, 'db']] as [$f, $mgr, $driver, $key]) {
        opsWalk($f, [FS::Accepted, FS::Preparing, FS::Ready]);
        actingAsStaff($mgr);
        test()->postJson("/api/v1/admin/dispatch/fulfillments/{$f->id}/assign", ['driver_id' => $driver->id])->assertOk();
        $s->{$key} = Delivery::where('order_fulfillment_id', $f->id)->firstOrFail();
    }
    $shares = ParcelShares::split(Money::of($s->order->grand_total), $s->order->fulfillments);
    $s->sa = $shares[$s->fa->id];
    $s->sb = $shares[$s->fb->id];

    return $s;
}

/** The driver takes the delivery to the door (accept, pick up, out); the session is left as that driver. */
function opsToTheDoor(Delivery $d, object $driver): void
{
    actingAsStaff($driver);
    foreach (['accept', 'pickup', 'out-for-delivery'] as $step) {
        test()->postJson("/api/v1/admin/deliveries/{$d->id}/{$step}")->assertOk();
    }
}

function opsDeliver(Delivery $d, string $amount)
{
    return test()->postJson("/api/v1/admin/deliveries/{$d->id}/deliver", ['recipient_name' => 'Noor', 'proof' => UploadedFile::fake()->image('door.jpg'), 'cod' => ['tender' => 'cash', 'amount' => $amount]]);
}

// ── Bug 7: a driver of a split order collects his own part ────────────────────────────────────────────────────────────

it('gives each branch\'s delivery its own part of the cash to collect, and the parts add up to the order total exactly', function () {
    $s = opsSplitWithDrivers();
    $total = Money::of($s->order->grand_total);
    expect($s->sa->plus($s->sb)->toDecimal())->toBe($total->toDecimal())->and($s->sa->isPositive() && $s->sb->isPositive())->toBeTrue()
        ->and($s->da->fresh()->cod_amount)->toBe($s->sa->toDecimal())->and($s->db->fresh()->cod_amount)->toBe($s->sb->toDecimal())
        ->and($s->da->fresh()->cod_amount)->not->toBe($total->toDecimal());
    // 3 sofas (+ its delivery fee 15) against 2 sofas (+ 25): the bigger part owes more.
    expect($s->sa->greaterThan($s->sb))->toBeTrue();

    // What each driver sees on his job.
    actingAsStaff($s->driverB);
    $this->getJson('/api/v1/admin/dispatch/driver/jobs')->assertOk()->assertJsonPath('data.0.cod_amount', $s->sb->toDecimal());
});

it('lets the first driver collect only his part, then the second collects the rest and the payment closes to the dirham', function () {
    Storage::fake('local');
    $s = opsSplitWithDrivers();
    $total = Money::of($s->order->grand_total);
    $payment = Payment::where('order_id', $s->order->id)->firstOrFail();

    opsToTheDoor($s->da, $s->driverA);
    // The whole order amount is not what this parcel owes.
    opsDeliver($s->da, $total->toDecimal())->assertStatus(422)->assertJsonPath('code', 'collection_amount_mismatch');
    opsDeliver($s->da, $s->sa->minus(Money::of('1.00'))->toDecimal())->assertStatus(422)->assertJsonPath('code', 'collection_amount_mismatch');
    expect($s->da->fresh()->status->value)->toBe('out_for_delivery')->and(CashCollection::count())->toBe(0);
    opsDeliver($s->da, $s->sa->toDecimal())->assertOk()->assertJsonPath('data.status', 'delivered');

    $payment->refresh();
    expect($payment->captured_amount)->toBe($s->sa->toDecimal())->and($payment->status->value)->toBe('pending')->and($s->order->fresh()->payment_status->value)->toBe('pending')
        ->and($s->order->fresh()->status->value)->toBe('partially_fulfilled')
        ->and($s->db->fresh()->cod_amount)->toBe($s->sb->toDecimal()) // what is left, to the dirham
        ->and($s->da->fresh()->cod_amount)->toBe($s->sa->toDecimal()); // a finished delivery keeps what it collected

    // A replay of the first driver's call changes nothing.
    actingAsStaff($s->driverA);
    opsDeliver($s->da, $s->sa->toDecimal())->assertOk();
    expect(CashCollection::count())->toBe(1);

    opsToTheDoor($s->db, $s->driverB);
    opsDeliver($s->db, $s->sa->toDecimal())->assertStatus(422)->assertJsonPath('code', 'collection_amount_mismatch');
    opsDeliver($s->db, $s->sb->toDecimal())->assertOk();
    $payment->refresh();
    expect($payment->status->value)->toBe('paid')->and($payment->captured_amount)->toBe($total->toDecimal())->and($s->order->fresh()->payment_status->value)->toBe('paid')
        ->and($s->order->fresh()->paid_total)->toBe($total->toDecimal())->and($s->order->fresh()->status->value)->toBe('completed')
        ->and(CashCollection::where('status', 'collected')->count())->toBe(2)
        ->and(Money::of((string) CashCollection::where('status', 'collected')->sum('amount_collected'))->toDecimal())->toBe($total->toDecimal())
        ->and(CashCollection::where('collected_by', $s->driverA->id)->value('amount_collected'))->toBe($s->sa->toDecimal())
        ->and(CashCollection::where('collected_by', $s->driverB->id)->value('amount_collected'))->toBe($s->sb->toDecimal());
});

it('heals a stale amount: a delivery that still says the whole order is corrected when the driver delivers', function () {
    Storage::fake('local');
    $s = opsSplitWithDrivers();
    $total = $s->order->grand_total;
    Delivery::whereIn('id', [$s->da->id, $s->db->id])->update(['cod_amount' => $total]); // how deliveries were stored before the split rules
    opsToTheDoor($s->da, $s->driverA);
    // The driver is told what is really due (this part's share), even though his job still said the whole amount.
    opsDeliver($s->da, $total)->assertStatus(422)->assertJsonPath('code', 'collection_amount_mismatch')->assertJsonPath('context.due', $s->sa->format());
    opsDeliver($s->da, $s->sa->toDecimal())->assertOk();
    expect($s->da->fresh()->cod_amount)->toBe($s->sa->toDecimal())->and($s->db->fresh()->cod_amount)->toBe($s->sb->toDecimal());
});

it('takes a cancelled part\'s share off the amount due: the other driver collects only what is left', function () {
    Storage::fake('local');
    $s = opsSplitWithDrivers();
    $payment = Payment::where('order_id', $s->order->id)->firstOrFail();
    actingAsStaff($s->mgrB);
    $this->postJson("/api/v1/admin/fulfillments/{$s->fb->id}/transition", ['status' => 'cancelled', 'reason' => 'out of stock'])->assertOk();

    expect($payment->fresh()->amount)->toBe($s->sa->toDecimal())->and($s->da->fresh()->cod_amount)->toBe($s->sa->toDecimal())->and($s->db->fresh()->status->value)->toBe('cancelled');
    opsToTheDoor($s->da, $s->driverA);
    opsDeliver($s->da, $s->order->grand_total)->assertStatus(422)->assertJsonPath('code', 'collection_amount_mismatch');
    opsDeliver($s->da, $s->sa->toDecimal())->assertOk();
    expect($payment->fresh()->status->value)->toBe('paid')->and($s->order->fresh()->payment_status->value)->toBe('paid')->and($s->order->fresh()->paid_total)->toBe($s->sa->toDecimal())
        ->and($s->order->fresh()->status->value)->toBe('partially_cancelled');
});

it('closes the payment when the other part is cancelled after the first one was already collected', function () {
    Storage::fake('local');
    $s = opsSplitWithDrivers();
    opsToTheDoor($s->da, $s->driverA);
    opsDeliver($s->da, $s->sa->toDecimal())->assertOk();
    $payment = Payment::where('order_id', $s->order->id)->firstOrFail();
    expect($payment->status->value)->toBe('pending');

    actingAsStaff($s->mgrB);
    $this->postJson("/api/v1/admin/fulfillments/{$s->fb->id}/transition", ['status' => 'cancelled', 'reason' => 'customer changed mind'])->assertOk();
    $payment->refresh();
    expect($payment->amount)->toBe($s->sa->toDecimal())->and($payment->captured_amount)->toBe($s->sa->toDecimal())->and($payment->status->value)->toBe('paid')
        ->and($s->order->fresh()->payment_status->value)->toBe('paid')->and($s->order->fresh()->status->value)->toBe('partially_cancelled');
});

it('keeps a cancelled part off the amount due when the other part\'s items are adjusted afterwards', function () {
    $s = opsSplitOrder();
    $payment = Payment::where('order_id', $s->order->id)->firstOrFail();
    opsWalk($s->fa, [FS::Accepted, FS::Preparing]);
    opsWalk($s->fb, [FS::Accepted, FS::Preparing]);
    $total = Money::of($payment->amount);
    $shares = ParcelShares::split($total, $s->order->fulfillments);

    actingAsStaff($s->mgrB);
    $this->postJson("/api/v1/admin/fulfillments/{$s->fb->id}/transition", ['status' => 'cancelled'])->assertOk();
    expect(Money::of($payment->fresh()->amount)->toDecimal())->toBe($total->minus($shares[$s->fb->id])->toDecimal());

    // Branch A's manager finds one sofa short: the amount due goes down by that sofa, and the cancelled part does not come back.
    actingAsStaff($s->mgrA);
    $line = OrderItem::where('order_fulfillment_id', $s->fa->id)->firstOrFail();
    $this->postJson("/api/v1/admin/fulfillments/{$s->fa->id}/adjustments", ['changes' => [['order_item_id' => $line->id, 'action' => 'reduce', 'new_quantity' => $line->quantity - 1]], 'reason_code' => 'damaged'])->assertOk();
    $order = $s->order->fresh();
    $expected = Money::of($order->grand_total)->minus($shares[$s->fb->id]);
    expect(Money::of($payment->fresh()->amount)->toDecimal())->toBe($expected->toDecimal())->and(Money::of($order->grand_total)->lessThan($total))->toBeTrue();
});

it('records a failed delivery against the part\'s own amount, not the whole order', function () {
    $s = opsSplitWithDrivers();
    opsToTheDoor($s->db, $s->driverB);
    $this->postJson("/api/v1/admin/deliveries/{$s->db->id}/fail", ['reason_code' => 'customer_absent'])->assertOk();
    expect(CashCollection::where('status', 'failed')->value('amount_due'))->toBe($s->sb->toDecimal());
});

it('lets a manual collection of everything still due settle the order and zero the amounts the drivers see', function () {
    $s = opsSplitWithDrivers();
    $payment = Payment::where('order_id', $s->order->id)->firstOrFail();
    actingAsStaff(staffWithRole('branch_manager', $s->w->a));
    $this->postJson("/api/v1/admin/payments/{$payment->id}/collections", ['fulfillment_id' => $s->fa->id, 'tender' => 'cash', 'amount' => $s->order->grand_total])->assertCreated();
    expect($payment->fresh()->status->value)->toBe('paid')->and($s->da->fresh()->cod_amount)->toBe('0.00')->and($s->db->fresh()->cod_amount)->toBe('0.00');
});

it('allows a short collection only when the business allows partial collections, and the remainder stays owed', function () {
    Storage::fake('local');
    $s = opsSplitWithDrivers();
    $short = $s->sa->minus(Money::of('10.00'));
    opsToTheDoor($s->da, $s->driverA);
    opsDeliver($s->da, $short->toDecimal())->assertStatus(422);

    app(Settings::class)->set('payments.allow_partial_collection', true);
    opsDeliver($s->da, $short->toDecimal())->assertOk();
    $payment = Payment::where('order_id', $s->order->id)->firstOrFail();
    expect($payment->captured_amount)->toBe($short->toDecimal())->and($payment->status->value)->toBe('pending')->and(CashCollection::where('status', 'partial')->count())->toBe(1)
        ->and($s->db->fresh()->cod_amount)->toBe($s->sb->plus(Money::of('10.00'))->toDecimal()); // the 10.00 that was not collected is still owed: the last driver asks for it
});

it('does not change a single-part order: its delivery collects the whole amount', function () {
    Storage::fake('local');
    $d = opsAssignedDelivery('accepted');
    expect($d->delivery->fresh()->cod_amount)->toBe($d->order->grand_total);
    actingAsStaff($d->driver);
    $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/pickup")->assertOk();
    $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/out-for-delivery")->assertOk();
    opsDeliver($d->delivery, $d->order->grand_total)->assertOk();
    expect($d->order->fresh()->payment_status->value)->toBe('paid');
});

it('pre-fills the web driver console with this parcel\'s own share, not what is left of the whole order', function () {
    $s = opsSplitWithDrivers();
    opsToTheDoor($s->da, $s->driverA);
    $this->actingAs($s->driverA);

    expect($s->sa->toDecimal())->not->toBe(Money::of($s->order->grand_total)->toDecimal());
    Livewire::test(DriverConsole::class)->call('openSheet', $s->da->id, 'deliver')->assertSet('cod.amount', $s->sa->toDecimal());
});
