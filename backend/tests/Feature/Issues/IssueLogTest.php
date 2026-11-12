<?php

use App\Domain\Identity\Models\User;
use App\Domain\Issues\Models\IssueReport;
use App\Domain\Issues\Services\IssueService;
use App\Domain\Orders\Models\Order;
use App\Domain\Partners\Services\PartnerAccess;
use App\Livewire\Admin\Insights\IssueIndex;
use App\Livewire\Admin\Insights\PartnerAccessPage;
use App\Support\DomainException;
use Livewire\Livewire;

it('records an issue with a running reference and links the order and its payment', function () {
    $w = commerceWorld();
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1]);
    $orderId = quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->json('data.order.id');
    $number = Order::find($orderId)->number;
    $mgr = staffWithRole('order_manager', $w->a);

    $i = app(IssueService::class)->report($mgr, ['kind' => 'payment', 'app' => 'customer', 'severity' => 'high', 'title' => 'Paid but order stayed unpaid', 'order_number' => $number]);
    $j = app(IssueService::class)->report($mgr, ['kind' => 'bug', 'app' => 'driver', 'title' => 'Map does not open']);
    expect($i->reference)->toMatch('/^ISS-\d{6}$/')->and($j->reference)->not->toBe($i->reference)->and($i->order_id)->toBe($orderId)->and($j->status)->toBe('open');
});

it('refuses an unknown order number and a bad status', function () {
    $w = commerceWorld();
    $mgr = staffWithRole('order_manager', $w->a);
    expect(fn () => app(IssueService::class)->report($mgr, ['kind' => 'bug', 'app' => 'driver', 'title' => 'Something', 'order_number' => 'NOPE1']))->toThrow(DomainException::class);
    $i = app(IssueService::class)->report($mgr, ['kind' => 'bug', 'app' => 'driver', 'title' => 'Another thing']);
    expect(fn () => app(IssueService::class)->setStatus($i, 'banana', $mgr))->toThrow(DomainException::class);
});

it('walks an issue through investigating to solved with a note, and reopens it', function () {
    $w = commerceWorld();
    $mgr = staffWithRole('order_manager', $w->a);
    $svc = app(IssueService::class);
    $i = $svc->report($mgr, ['kind' => 'other', 'app' => 'server', 'title' => 'Slow reports page']);
    $svc->setStatus($i, 'investigating', $mgr);
    $svc->setStatus($i, 'resolved', $mgr, 'Added an index');
    expect($i->fresh()->status)->toBe('resolved')->and($i->fresh()->resolution)->toBe('Added an index')->and($i->fresh()->resolved_by)->toBe($mgr->id);
    $svc->setStatus($i, 'open', $mgr);
    expect($i->fresh()->resolved_at)->toBeNull()->and($i->fresh()->resolution)->toBeNull();
});

it('lets staff report and filter from the dashboard page, validating the form', function () {
    $w = commerceWorld();
    $mgr = staffWithRole('order_manager', $w->a);
    Livewire::actingAs($mgr)->test(IssueIndex::class)->assertOk()
        ->set('form.title', 'ab')->call('save')->assertHasErrors(['form.title'])
        ->set('form.title', 'Receipt does not print')->set('form.kind', 'bug')->set('form.app', 'staff')->call('save')->assertHasNoErrors()->assertSee('Receipt does not print');
    expect(IssueReport::count())->toBe(1);
    Livewire::actingAs($mgr)->test(IssueIndex::class)->set('app', 'driver')->assertDontSee('Receipt does not print')->set('app', 'staff')->assertSee('Receipt does not print');
});

it('keeps the issue page away from people without order access', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.issues.index'))->assertRedirect();
});

it('lets an admin control partner sharing: password first, then on, new link signs everyone out', function () {
    $admin = staffWithRole('super_admin');
    $c = Livewire::actingAs($admin)->test(PartnerAccessPage::class)->assertOk();
    $c->set('enabled', true)->assertSet('enabled', false); // no password yet
    $c->set('password', 'short')->set('password_confirmation', 'short')->call('savePassword')->assertHasErrors('password');
    $c->set('password', 'A-Long-Secret-1')->set('password_confirmation', 'A-Long-Secret-1')->call('savePassword')->assertHasNoErrors();
    $c->set('enabled', true)->assertSet('enabled', true);
    $old = app(PartnerAccess::class)->token();
    $c->call('rotate');
    expect(app(PartnerAccess::class)->token())->not->toBe($old);
});
