<?php

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\FreshTwoFactor;
use App\Domain\Identity\Services\TwoFactorService;
use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\Services\RefundService;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Finance\RefundIndex;
use App\Livewire\Admin\Organization\SettingsIndex;
use App\Support\DomainException;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['platform.auth.staff_two_factor_required' => true]);
});

/** A captured payment and a refund the given person asked for. */
function dualControlRefund(User $requester): Refund
{
    $w = commerceWorld();
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1]);
    $order = Order::find(quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->json('data.order.id'));
    $payment = Payment::where('order_id', $order->id)->firstOrFail();
    $payment->forceFill(['status' => 'paid', 'captured_amount' => $payment->amount, 'paid_at' => now()])->save();

    return app(RefundService::class)->request($payment->fresh(), Money::of('10.00'), 'damaged', null, $requester, 'dc-'.uniqid());
}

function dualControlEnrol(User $user): string
{
    $secret = app(TwoFactorService::class)->generateSecret();
    $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();

    return $secret;
}

function dualControlCode(string $secret, int $drift = 0): string
{
    return app(TwoFactorService::class)->totp($secret, intdiv(time(), 30) + $drift);
}

it('never lets the requester approve while another team member holds the approval right (a super admin included)', function () {
    $owner = User::factory()->superAdmin()->create();
    $other = staffWithRole('accountant');
    $refund = dualControlRefund($owner);

    expect(fn () => app(RefundService::class)->approve($refund, $owner, 'I need to approve this myself now', true))
        ->toThrow(fn (DomainException $e) => expect($e->errorCode)->toBe('approval_segregation')->and($e->status)->toBe(403));
    expect($refund->fresh()->status)->toBe('requested')->and($refund->fresh()->self_approved)->toBeFalse();

    app(RefundService::class)->approve($refund->fresh(), $other);
    expect($refund->fresh()->status)->toBe('approved')->and($refund->fresh()->self_approved)->toBeFalse();
});

it('lets a lone requester approve only with a written reason and a fresh code, and flags it self-approved', function () {
    $owner = User::factory()->superAdmin()->create();
    $refund = dualControlRefund($owner);
    $svc = app(RefundService::class);

    expect(fn () => $svc->approve($refund, $owner))->toThrow(fn (DomainException $e) => expect($e->errorCode)->toBe('self_approval_reason_required'));
    expect(fn () => $svc->approve($refund, $owner, 'short'))->toThrow(fn (DomainException $e) => expect($e->errorCode)->toBe('self_approval_reason_required'));
    expect(fn () => $svc->approve($refund, $owner, 'Customer called, goods were damaged', false))->toThrow(fn (DomainException $e) => expect($e->errorCode)->toBe('self_approval_two_factor_required'));
    expect($refund->fresh()->status)->toBe('requested');

    $svc->approve($refund, $owner, 'Customer called, goods were damaged', true);
    $fresh = $refund->fresh();
    expect($fresh->status)->toBe('approved')->and($fresh->self_approved)->toBeTrue()->and($fresh->self_approval_reason)->toBe('Customer called, goods were damaged');
    $audit = DB::table('audit_logs')->where('action', 'refund.self_approved')->first();
    expect($audit)->not->toBeNull()->and($audit->new_values)->toContain('Customer called');
    expect(DB::table('audit_logs')->where('action', 'refund.approved')->value('new_values'))->toContain('self_approved');
});

it('refuses self-approval when the shop switched it off', function () {
    $owner = User::factory()->superAdmin()->create();
    $refund = dualControlRefund($owner);
    app(Settings::class)->set('refunds.allow_self_approval', false);

    expect(fn () => app(RefundService::class)->approve($refund, $owner, 'Customer called, goods were damaged', true))
        ->toThrow(fn (DomainException $e) => expect($e->errorCode)->toBe('approval_segregation'));
    expect($refund->fresh()->status)->toBe('requested');
});

it('counts a deactivated colleague as nobody', function () {
    $owner = User::factory()->superAdmin()->create();
    $gone = staffWithRole('accountant', null, ['status' => 'deactivated']);
    $refund = dualControlRefund($owner);

    app(RefundService::class)->approve($refund, $owner, 'Only person left in the shop', true);
    expect($refund->fresh()->self_approved)->toBeTrue()->and($gone->fresh()->status->value)->toBe('deactivated');
});

it('walks the self-approval dialog in the refund queue: reason, wrong code, right code', function () {
    $owner = User::factory()->superAdmin()->create();
    $secret = dualControlEnrol($owner);
    $refund = dualControlRefund($owner);
    $this->actingAs($owner);

    $c = Livewire::test(RefundIndex::class)->assertSee(__('finance_admin.approve_own'))
        ->call('open', 'self', $refund->id)->call('confirmDialog')->assertHasErrors('selfReason')
        ->set('selfReason', 'Only person in the shop, damaged goods')->set('code', '000000')->call('confirmDialog')->assertHasErrors('code');
    expect($refund->fresh()->status)->toBe('requested');
    RateLimiter::clear('2fa-fresh:'.$owner->id);
    $c->set('code', dualControlCode($secret))->call('confirmDialog')->assertHasNoErrors();
    expect($refund->fresh()->status)->toBe('approved')->and($refund->fresh()->self_approved)->toBeTrue();
    Livewire::test(RefundIndex::class, ['tab' => 'to_process'])->assertSee(__('finance_admin.self_approved'));
});

it('shows a second-approver badge, not a button, while someone else can approve', function () {
    $owner = User::factory()->superAdmin()->create();
    staffWithRole('accountant');
    $refund = dualControlRefund($owner);
    $this->actingAs($owner);

    Livewire::test(RefundIndex::class)->assertSee(__('finance_admin.awaiting_second_approver'))->assertDontSee(__('finance_admin.approve_own'))
        ->call('open', 'self', $refund->id)->set('selfReason', 'I really want to approve it')->call('confirmDialog');
    expect($refund->fresh()->status)->toBe('requested');
});

it('applies the same rule through the staff API', function () {
    $owner = User::factory()->superAdmin()->create();
    $secret = dualControlEnrol($owner);
    $refund = dualControlRefund($owner);
    actingAsStaff($owner);

    $this->postJson("/api/v1/admin/refunds/{$refund->id}/approve")->assertStatus(422)->assertJsonPath('code', 'self_approval_reason_required');
    $this->postJson("/api/v1/admin/refunds/{$refund->id}/approve", ['reason' => 'Only person in the shop, damaged'])->assertStatus(403)->assertJsonPath('code', 'self_approval_two_factor_required');
    $this->postJson("/api/v1/admin/refunds/{$refund->id}/approve", ['reason' => 'Only person in the shop, damaged', 'two_factor_code' => dualControlCode($secret)])->assertOk();
    expect($refund->fresh()->self_approved)->toBeTrue();
});

// ─── Security settings ──────────────────────────────────────────────────────────────────────────────────────────────────────────

it('refuses switching off mandatory two-factor to anyone but the owner, even with the settings right', function () {
    $admin = staffWithRole('admin');
    $this->actingAs($admin);
    session(['two_factor_passed' => true]);
    app(FreshTwoFactor::class)->stamp();

    Livewire::test(SettingsIndex::class, ['tab' => 'security'])->set('values.security.auth__staff_two_factor_required', false)->call('save')
        ->assertDispatched('toast', type: 'error');
    expect(app(TwoFactorService::class)->required())->toBeTrue();
});

it('asks the owner for a fresh code, saves only after it, and audit-logs old and new', function () {
    $owner = User::factory()->superAdmin()->create();
    $secret = dualControlEnrol($owner);
    $this->actingAs($owner);
    session(['two_factor_passed' => true, 'two_factor_confirmed_at' => time() - 301]); // the proof is 5 minutes and 1 second old

    $c = Livewire::test(SettingsIndex::class, ['tab' => 'security'])->set('values.security.auth__staff_two_factor_required', false)->call('save')
        ->assertSet('confirmSecurity', true);
    expect(app(TwoFactorService::class)->required())->toBeTrue('nothing saved before the code');

    $c->set('securityCode', '111111')->call('confirmSecurityChange')->assertHasErrors('securityCode');
    expect(app(TwoFactorService::class)->required())->toBeTrue();

    RateLimiter::clear('2fa-fresh:'.$owner->id);
    $c->set('securityCode', dualControlCode($secret))->call('confirmSecurityChange')->assertSet('confirmSecurity', false);
    expect(app(TwoFactorService::class)->required())->toBeFalse();
    $row = DB::table('audit_logs')->where('action', 'security.setting_changed')->first();
    expect($row->old_values)->toContain('true')->and($row->new_values)->toContain('false')->and($row->metadata)->toContain('auth.staff_two_factor_required');
});

it('saves at once while the proof is fresh, and never asks when a setting becomes stricter', function () {
    $owner = User::factory()->superAdmin()->create();
    dualControlEnrol($owner);
    $this->actingAs($owner);
    session(['two_factor_passed' => true, 'two_factor_confirmed_at' => time() - 60]);

    Livewire::test(SettingsIndex::class, ['tab' => 'security'])->set('values.security.auth__staff_two_factor_required', false)->call('save')->assertSet('confirmSecurity', false);
    expect(app(TwoFactorService::class)->required())->toBeFalse();

    session(['two_factor_confirmed_at' => time() - 3600]);
    Livewire::test(SettingsIndex::class, ['tab' => 'security'])->set('values.security.auth__staff_two_factor_required', true)->call('save')->assertSet('confirmSecurity', false);
    expect(app(TwoFactorService::class)->required())->toBeTrue();
});

it('guards the wall display hours and the self-approval switch the same way', function () {
    $owner = User::factory()->superAdmin()->create();
    dualControlEnrol($owner);
    $this->actingAs($owner);
    session(['two_factor_passed' => true, 'two_factor_confirmed_at' => time() - 3600]);

    Livewire::test(SettingsIndex::class, ['tab' => 'security'])->set('values.security.operations__wall_session_hours', 40)->call('save')->assertSet('confirmSecurity', true);
    Livewire::test(SettingsIndex::class, ['tab' => 'security'])->set('values.security.refunds__allow_self_approval', false)->call('save')->assertSet('confirmSecurity', false); // stricter: no code
    expect(app(Settings::class)->get('refunds.allow_self_approval'))->toBeFalse();
    Livewire::test(SettingsIndex::class, ['tab' => 'security'])->set('values.security.refunds__allow_self_approval', true)->call('save')->assertSet('confirmSecurity', true);
});

it('asks a person without two-factor to enrol instead of opening a code box', function () {
    $owner = User::factory()->superAdmin()->create();
    $this->actingAs($owner);
    session(['two_factor_passed' => true]);

    Livewire::test(SettingsIndex::class, ['tab' => 'security'])->set('values.security.auth__staff_two_factor_required', false)->call('save')
        ->assertSet('confirmSecurity', false)->assertDispatched('toast', type: 'error');
    expect(app(TwoFactorService::class)->required())->toBeTrue();
});
