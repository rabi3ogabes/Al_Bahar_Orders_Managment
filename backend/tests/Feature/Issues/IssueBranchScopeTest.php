<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserRole;
use App\Domain\Identity\Support\BranchScope;
use App\Domain\Issues\Models\IssueReport;
use App\Domain\Issues\Services\IssueService;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Payments\Models\Payment;
use App\Livewire\Admin\Insights\IssueIndex;
use App\Support\DomainException;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

require_once __DIR__.'/../Operations/OpsSupport.php';

/** The issue log for a branch-limited member (roadmap 4.6): only issues about their branches' orders, and the ones they reported themselves. */
beforeEach(function () {
    $this->travelTo(qatar('2026-11-19 12:00'));
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->a = Branch::factory()->create(['code' => 'DOH']);
    $this->b = Branch::factory()->create(['code' => 'WKR']);
    $this->mgrA = staffWithRole('order_manager', $this->a);
    $this->mgrB = staffWithRole('order_manager', $this->b);
    $this->admin = staffWithRole('admin');
    $this->order = function (array $branches): Order {
        static $n = 0;
        $n++;
        $o = Order::create(['number' => 'ISS'.$n, 'user_id' => null, 'source' => 'app', 'status' => 'confirmed', 'payment_status' => 'pending', 'payment_method' => 'cod', 'fulfillment_type' => 'delivery',
            'currency' => 'QAR', 'subtotal' => '100.00', 'grand_total' => '100.00', 'customer_name' => 'Noor Ali', 'placed_at' => now()]);
        foreach ($branches as $i => $branch) {
            OrderFulfillment::create(['order_id' => $o->id, 'branch_id' => $branch->id, 'number' => $o->number.'-'.$i, 'type' => 'delivery', 'status' => 'pending', 'subtotal' => '50.00', 'delivery_fee' => '0.00']);
        }

        return $o;
    };
    $this->issue = fn (string $title, ?Order $order = null, ?string $by = null, array $attrs = []) => IssueReport::create($attrs + ['kind' => 'payment', 'app' => 'dashboard', 'severity' => 'high', 'title' => $title,
        'order_id' => $order?->id, 'reported_by' => $by ?? $this->admin->id]);
    $this->orderA = ($this->order)([$this->a]);
    $this->orderB = ($this->order)([$this->b]);
    $this->orderAB = ($this->order)([$this->a, $this->b]);
    $this->forA = ($this->issue)('Issue about the A order', $this->orderA);
    $this->forB = ($this->issue)('Issue about the B order', $this->orderB);
    $this->forAB = ($this->issue)('Issue about the shared order', $this->orderAB);
    $this->company = ($this->issue)('Bug in the driver app', null, attrs: ['kind' => 'bug', 'app' => 'driver']);
});

it('lists a branch only the issues about its own orders, the shared ones, and its own reports', function () {
    $own = ($this->issue)('Reported by the A manager', null, $this->mgrA->id, ['kind' => 'bug']);
    $c = Livewire::actingAs($this->mgrA)->test(IssueIndex::class)->set('tab', 'all');
    expect($c->viewData('issues')->pluck('title')->sort()->values()->all())->toBe(['Issue about the A order', 'Issue about the shared order', 'Reported by the A manager'])
        ->and($c->viewData('counts')['active'])->toBe(3);
    $c->assertDontSee('Issue about the B order')->assertDontSee('Bug in the driver app');
    // the other branch sees the shared one and its own, not A's
    $b = Livewire::actingAs($this->mgrB)->test(IssueIndex::class)->set('tab', 'all');
    expect($b->viewData('issues')->pluck('title')->sort()->values()->all())->toBe(['Issue about the B order', 'Issue about the shared order']);
    // a member with every branch reads the whole log
    $all = Livewire::actingAs($this->admin)->test(IssueIndex::class)->set('tab', 'all');
    expect($all->viewData('issues')->total())->toBe(5)->and($all->viewData('counts')['active'])->toBe(5);
    expect($own->reported_by)->toBe($this->mgrA->id);
});

it('counts an issue about a payment through the order of that payment', function () {
    $payment = Payment::create(['payable_type' => 'order', 'payable_id' => $this->orderB->id, 'user_id' => null, 'order_id' => $this->orderB->id, 'method' => 'sadad', 'provider' => 'sadad', 'status' => 'paid',
        'currency' => 'QAR', 'amount' => '100.00', 'captured_amount' => '100.00', 'paid_at' => now(), 'provider_reference' => 'PAY-B-1']);
    $viaPayment = ($this->issue)('Double charge on the B payment', null, attrs: ['payment_id' => $payment->id]);
    $a = Livewire::actingAs($this->mgrA)->test(IssueIndex::class)->set('tab', 'all');
    expect($a->viewData('issues')->pluck('id')->all())->not->toContain($viaPayment->id);
    $b = Livewire::actingAs($this->mgrB)->test(IssueIndex::class)->set('tab', 'all');
    expect($b->viewData('issues')->pluck('id')->all())->toContain($viaPayment->id);
});

it('does not find another branch\'s issue by search (title, reference or order number)', function () {
    $c = Livewire::actingAs($this->mgrA)->test(IssueIndex::class)->set('tab', 'all');
    foreach (['Issue about the B order', $this->forB->reference, $this->orderB->number] as $needle) {
        $c->set('search', $needle);
        expect($c->viewData('issues')->total())->toBe(0);
    }
    $c->set('search', $this->orderA->number);
    expect($c->viewData('issues')->total())->toBe(1);
});

it('does not open another branch\'s issue by id, deep link or tampered window, and cannot change its status', function () {
    Livewire::actingAs($this->mgrA)->test(IssueIndex::class)->call('openView', $this->forB->id)->assertSet('showView', false)->assertSet('viewId', '')->assertDontSee('Issue about the B order');
    Livewire::actingAs($this->mgrA)->withQueryParams(['view' => $this->forB->id])->test(IssueIndex::class)->assertSet('showView', false)->assertSet('viewId', '');
    Livewire::actingAs($this->mgrA)->withQueryParams(['view' => $this->company->id])->test(IssueIndex::class)->assertSet('showView', false);
    Livewire::actingAs($this->mgrA)->withQueryParams(['view' => $this->forA->id])->test(IssueIndex::class)->assertSet('showView', true)->assertSee('Issue about the A order');
    // a made-up window id for an issue they cannot see is refused when the status is changed
    Livewire::actingAs($this->mgrA)->test(IssueIndex::class)->set('viewId', $this->forB->id)->call('setStatus', 'resolved')->assertNotFound();
    expect($this->forB->fresh()->status)->toBe('open');
    Livewire::actingAs($this->mgrA)->test(IssueIndex::class)->call('openView', $this->forA->id)->set('resolution', 'Fixed')->call('setStatus', 'resolved');
    expect($this->forA->fresh()->status)->toBe('resolved');
});

it('lets a branch report an issue for its own order and refuses another branch\'s order number as "not found"', function () {
    $svc = app(IssueService::class);
    $ok = $svc->report($this->mgrA, ['kind' => 'payment', 'app' => 'dashboard', 'title' => 'Customer paid twice', 'order_number' => $this->orderA->number]);
    expect($ok->order_id)->toBe($this->orderA->id);
    $shared = $svc->report($this->mgrA, ['kind' => 'payment', 'app' => 'dashboard', 'title' => 'Shared order problem', 'order_number' => $this->orderAB->number]);
    expect($shared->order_id)->toBe($this->orderAB->id);
    expect(fn () => $svc->report($this->mgrA, ['kind' => 'payment', 'app' => 'dashboard', 'title' => 'Not mine', 'order_number' => $this->orderB->number]))
        ->toThrow(fn (DomainException $e) => $e->errorCode === 'issue_order_not_found');
    // a payment reference of another branch's order is not linked either
    Payment::create(['payable_type' => 'order', 'payable_id' => $this->orderB->id, 'user_id' => null, 'order_id' => $this->orderB->id, 'method' => 'sadad', 'provider' => 'sadad', 'status' => 'paid',
        'currency' => 'QAR', 'amount' => '100.00', 'captured_amount' => '100.00', 'paid_at' => now(), 'provider_reference' => 'PAY-B-2']);
    $noLink = $svc->report($this->mgrA, ['kind' => 'payment', 'app' => 'dashboard', 'title' => 'Mystery payment', 'payment_reference' => 'PAY-B-2']);
    expect($noLink->payment_id)->toBeNull();
    $admin = $svc->report($this->admin, ['kind' => 'payment', 'app' => 'dashboard', 'title' => 'Any order for the owner', 'order_number' => $this->orderB->number, 'payment_reference' => 'PAY-B-2']);
    expect($admin->order_id)->toBe($this->orderB->id)->and($admin->payment_id)->not->toBeNull();
    // the reporter always sees their own report, even one that is about nothing of their branch
    $mine = Livewire::actingAs($this->mgrA)->test(IssueIndex::class)->set('tab', 'all');
    expect($mine->viewData('issues')->pluck('id')->all())->toContain($noLink->id);
});

it('shows nothing to a member with no branch at all', function () {
    $nobody = staffWithRole('order_manager', null);
    UserRole::where('user_id', $nobody->id)->delete();
    $other = User::factory()->staff()->create();
    expect(IssueReport::query()->visibleTo(BranchScope::none(), (string) $other->id)->count())->toBe(0);
});
