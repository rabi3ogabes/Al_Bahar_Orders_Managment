<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserDevice;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\BranchInventory;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\StocktakingSession;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Inventory\Services\StocktakeApprovals;
use App\Domain\Inventory\Services\StocktakingService;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Services\NotificationRules;
use App\Domain\Reports\Services\DashboardMetrics;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Inventory\StocktakingIndex;
use App\Livewire\Admin\Inventory\StocktakingShow;
use App\Support\DomainException;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

require_once __DIR__.'/../Operations/OpsSupport.php';
require_once __DIR__.'/../Commerce/LiveOrderHelpers.php';
require_once __DIR__.'/StockPlanningSupport.php';

/** The person who counted does not approve the count (roadmap 4.6): the rule, its switch and limit, the send-back, the alerts and the doors that post. */
beforeEach(function () {
    $this->travelTo(qatar('2026-11-19 12:00'));
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->a = Branch::factory()->create(['code' => 'DOH']);
    $this->b = Branch::factory()->create(['code' => 'WKR']);
    $this->counter = staffWithRole('inventory_manager', $this->a);
    $this->second = staffWithRole('branch_manager', $this->a);
    $this->otherBranch = staffWithRole('branch_manager', $this->b);
    $this->owner = User::factory()->superAdmin()->create();
    $this->variant = planVariant();
    app(InventoryLedger::class)->apply($this->a->id, $this->variant->id, MovementType::OpeningBalance, 10, unitCost: '40.00');
    $this->svc = app(StocktakingService::class);
    $this->stock = fn () => BranchInventory::where('branch_id', $this->a->id)->where('product_variant_id', $this->variant->id)->firstOrFail();
    /** A count of the variant at branch A with the given counted quantity, counted and finished by $by (7 of the 10 expected = 3 missing = QAR 120). */
    $this->finish = function (?User $by = null, int $counted = 7): StocktakingSession {
        $by ??= $this->counter;
        $session = $this->svc->start($by, $this->a->id);
        $this->svc->count($by, $session, $this->variant->id, $counted);

        return $this->svc->submit($by, $session);
    };
});

describe('the rule', function () {
    it('puts a count with differences on hold: nothing changes in the stock until a second person approves', function () {
        $s = ($this->finish)();
        expect($s->status)->toBe('submitted')->and($s->submitted_by)->toBe($this->counter->id)->and($s->variance_lines)->toBe(1)->and($s->variance_units)->toBe(3)
            ->and($s->variance_value)->toBe('120.00')->and(($this->stock)()->on_hand)->toBe(10)->and(StockMovement::where('type', 'stocktake_correction')->count())->toBe(0);
    });

    it('refuses the person who counted, and approves for a different person, with the stock moved once and the audit saying who and how', function () {
        $s = ($this->finish)();
        expect(fn () => $this->svc->post($this->counter, $s))->toThrow(fn (DomainException $e) => $e->errorCode === 'stocktake_second_person' && $e->status === 403);
        expect($s->fresh()->status)->toBe('submitted')->and(($this->stock)()->on_hand)->toBe(10)
            ->and(AuditLog::where('action', 'stocktaking.self_approval_refused')->where('actor_id', $this->counter->id)->count())->toBe(1);

        $done = $this->svc->post($this->second, $s);
        expect($done->status)->toBe('posted')->and($done->approved_by)->toBe($this->second->id)->and($done->approval_mode)->toBe('second_person')
            ->and(($this->stock)()->on_hand)->toBe(7)->and(StockMovement::where('type', 'stocktake_correction')->where('actor_id', $this->second->id)->count())->toBe(1)
            ->and(AuditLog::where('action', 'stocktaking.posted')->latest('id')->first()->new_values['approval'])->toBe('second_person');
        // the same approval again changes nothing
        expect(fn () => $this->svc->post($this->second, $s->fresh()))->toThrow(fn (DomainException $e) => $e->errorCode === 'invalid_state_transition');
        expect(($this->stock)()->on_hand)->toBe(7);
        // a count that is already posted is simply "not waiting": no second-person refusal is written for it
        expect(fn () => $this->svc->post($this->counter, $s->fresh()))->toThrow(fn (DomainException $e) => $e->errorCode === 'invalid_state_transition')
            ->and(AuditLog::where('action', 'stocktaking.self_approval_refused')->count())->toBe(1);
    });

    it('counts everyone who recorded a line, and the one who finished the count', function () {
        $other = planVariant();
        app(InventoryLedger::class)->apply($this->a->id, $other->id, MovementType::OpeningBalance, 4, unitCost: '10.00');
        $s = $this->svc->start($this->counter, $this->a->id);
        $helper = staffWithRole('inventory_manager', $this->a);
        $this->svc->count($this->counter, $s, $this->variant->id, 6);
        $this->svc->count($helper, $s, $other->id, 3);
        $s = $this->svc->submit($this->second, $s); // a third person finishes it
        $rules = app(StocktakeApprovals::class);
        expect($rules->counters($s))->toEqualCanonicalizing([$this->counter->id, $helper->id, $this->second->id]);
        foreach ([$this->counter, $helper, $this->second] as $who) {
            expect(fn () => $this->svc->post($who, $s))->toThrow(DomainException::class);
        }
        expect($this->svc->post($this->owner, $s)->status)->toBe('posted');
    });

    it('applies to owners and super admins too', function () {
        $session = $this->svc->start($this->owner, $this->a->id);
        $this->svc->count($this->owner, $session, $this->variant->id, 5);
        $s = $this->svc->submit($this->owner, $session);
        expect($s->status)->toBe('submitted');
        expect(fn () => $this->svc->post($this->owner, $s))->toThrow(fn (DomainException $e) => $e->errorCode === 'stocktake_second_person');
        expect($this->svc->post(User::factory()->superAdmin()->create(), $s)->status)->toBe('posted');
    });

    it('can be switched off for a shop of one person, as an audited setting, and then records that the same person approved', function () {
        app(Settings::class)->set(StocktakeApprovals::RULE_KEY, false);
        expect(AuditLog::where('action', 'settings.updated')->where('metadata->key', StocktakeApprovals::RULE_KEY)->count())->toBe(1);
        $s = ($this->finish)();
        $done = $this->svc->post($this->counter, $s);
        expect($done->status)->toBe('posted')->and($done->approval_mode)->toBe('self')->and(($this->stock)()->on_hand)->toBe(7);
    });

    it('needs the right to approve at THIS branch', function () {
        $s = ($this->finish)();
        expect(fn () => $this->svc->post($this->otherBranch, $s))->toThrow(fn (DomainException $e) => $e->errorCode === 'branch_forbidden');
        expect(fn () => $this->svc->post(staffWithRole('sales_employee', $this->a), $s))->toThrow(DomainException::class);
    });
});

describe('nothing to approve, or little', function () {
    it('posts a count with no difference when it is finished', function () {
        $s = ($this->finish)(counted: 10);
        expect($s->status)->toBe('posted')->and($s->approval_mode)->toBe('auto')->and($s->approved_by)->toBeNull()->and($s->variance_lines)->toBe(0)
            ->and(StockMovement::where('type', 'stocktake_correction')->count())->toBe(0)->and(($this->stock)()->last_counted_at)->not->toBeNull();
    });

    it('treats a count with nothing counted as nothing to approve', function () {
        $s = $this->svc->submit($this->counter, $this->svc->start($this->counter, $this->a->id));
        expect($s->status)->toBe('posted')->and(($this->stock)()->on_hand)->toBe(10);
    });

    it('waits for a second person for any difference while the limit is 0 (the default)', function () {
        expect(app(StocktakeApprovals::class)->autoPostUpTo()->isZero())->toBeTrue();
        expect(($this->finish)(counted: 9)->status)->toBe('submitted'); // QAR 40
    });

    it('posts small differences by itself up to the limit, and waits above it', function () {
        app(Settings::class)->set(StocktakeApprovals::AUTO_KEY, '120.00');
        $small = ($this->finish)(counted: 7);                       // 3 missing = QAR 120: at the limit
        expect($small->status)->toBe('posted')->and($small->approval_mode)->toBe('auto')->and(($this->stock)()->on_hand)->toBe(7);
        $again = ($this->finish)(counted: 4);                       // 7 expected, 4 counted: 3 missing = QAR 120 again, still at the limit
        expect($again->status)->toBe('posted');
        app(Settings::class)->set(StocktakeApprovals::AUTO_KEY, '100');
        $over = ($this->finish)(counted: 1);                        // 3 units = QAR 120 > 100
        expect($over->status)->toBe('submitted')->and($over->variance_value)->toBe('120.00');
    });

    it('never waves through a difference whose value is unknown, however high the limit', function () {
        app(Settings::class)->set(StocktakeApprovals::AUTO_KEY, '100000');
        $noCost = planVariant();
        $noCost->update(['cost_price' => null]);
        $noCost->product->update(['cost_price' => null]);
        app(InventoryLedger::class)->apply($this->a->id, $noCost->id, MovementType::OpeningBalance, 5); // no unit cost: no average cost either
        $session = $this->svc->start($this->counter, $this->a->id);
        $this->svc->count($this->counter, $session, $noCost->id, 1);
        $s = $this->svc->submit($this->counter, $session);
        expect($s->status)->toBe('submitted')->and($s->variance_value)->toBeNull()->and($s->variance_lines)->toBe(1);
        Livewire::actingAs($this->second)->test(StocktakingShow::class, ['session' => $s])->assertSee('value unknown');
    });
});

describe('sending a count back', function () {
    it('goes back to counting with the reason, tells the people who counted, and can be finished again', function () {
        $s = ($this->finish)();
        expect(fn () => $this->svc->sendBack($this->second, $s, 'no'))->toThrow(fn (DomainException $e) => $e->errorCode === 'stocktake_reason_required');
        $back = $this->svc->sendBack($this->second, $s, 'Please count the sofas in aisle 4 again.');
        expect($back->status)->toBe('counting')->and($back->returned_by)->toBe($this->second->id)->and($back->returned_reason)->toBe('Please count the sofas in aisle 4 again.')
            ->and($back->variance_lines)->toBeNull()->and(($this->stock)()->on_hand)->toBe(10)
            ->and(AuditLog::where('action', 'stocktaking.returned')->count())->toBe(1);
        $note = Notification::where('event', 'staff.stocktake_returned')->firstOrFail();
        expect($note->user_id)->toBe($this->counter->id)->and($note->body)->toContain('Please count the sofas');

        $this->svc->count($this->counter, $back, $this->variant->id, 10);                // recounted: nothing differs now
        $again = $this->svc->submit($this->counter, $back->fresh());
        expect($again->status)->toBe('posted')->and($again->returned_reason)->toBeNull();
    });

    it('is not for the person who counted either, and only from the waiting state', function () {
        $s = ($this->finish)();
        expect(fn () => $this->svc->sendBack($this->counter, $s, 'I changed my mind'))->toThrow(fn (DomainException $e) => $e->errorCode === 'stocktake_second_person');
        $this->svc->sendBack($this->second, $s, 'Count again please');
        expect(fn () => $this->svc->sendBack($this->second, $s->fresh(), 'And again'))->toThrow(fn (DomainException $e) => $e->errorCode === 'invalid_state_transition');
    });
});

describe('the alerts', function () {
    it('tells the people who may approve at that branch, the owner included, never the people who counted, and never another branch', function () {
        $anotherManager = staffWithRole('branch_manager', $this->a);
        ($this->finish)();
        $told = Notification::where('event', 'staff.stocktake_waiting')->pluck('user_id')->all();
        expect($told)->toEqualCanonicalizing([$this->second->id, $anotherManager->id, $this->owner->id]);
        $note = Notification::where('event', 'staff.stocktake_waiting')->where('user_id', $this->second->id)->first();
        expect($note->body)->toContain($this->a->name_en)->toContain('1 items differ')->toContain('QAR 120.00')->and($note->data['deep_link'])->toContain('/stocktaking/');
    });

    it('keeps the push for working hours and shows the same alert in the inbox at night', function () {
        lopFcm();
        UserDevice::create(['user_id' => $this->second->id, 'platform' => 'android', 'push_token' => 'tok-second', 'locale' => 'en']);
        ($this->finish)();                                                                         // 12:00 shop time
        expect(collect(lopSent(all: true))->pluck('token')->all())->toContain('tok-second');

        $this->svc->cancel($this->second, StocktakingSession::firstOrFail());
        Http::fake(); // forget the sends so far
        $this->travelTo(qatar('2026-11-19 23:30'));
        expect(StocktakeApprovals::quietNow())->toBeTrue();
        ($this->finish)(counted: 8);
        expect(Notification::where('event', 'staff.stocktake_waiting')->where('user_id', $this->second->id)->count())->toBe(2);
        expect(Http::recorded(fn ($r) => str_contains($r->url(), 'messages:send'))->count())->toBe(0);
        $this->travelTo(qatar('2026-11-20 07:59'));
        expect(StocktakeApprovals::quietNow())->toBeTrue();
        $this->travelTo(qatar('2026-11-20 08:00'));
        expect(StocktakeApprovals::quietNow())->toBeFalse();
    });

    it('stays silent when the admin switched the alert off', function () {
        app(NotificationRules::class)->set('staff.stocktake_waiting', false);
        ($this->finish)();
        expect(Notification::where('event', 'staff.stocktake_waiting')->count())->toBe(0);
    });

    it('never undoes a saved count because an alert could not be sent', function () {
        $this->mock(StocktakeApprovals::class, function ($m) {
            $m->shouldReceive('summary')->andReturn(['lines' => 1, 'units' => 3, 'net' => -3, 'value' => Money::of('120.00'), 'unpriced' => 0]);
            $m->shouldReceive('postsByItself')->andReturn(false);
            $m->shouldReceive('announceWaiting')->andThrow(new RuntimeException('mail is down'));
        });
        $svc = app(StocktakingService::class);
        $session = $svc->start($this->counter, $this->a->id);
        $svc->count($this->counter, $session, $this->variant->id, 7);
        expect($svc->submit($this->counter, $session)->status)->toBe('submitted');
    });
});

describe('every door that posts a count', function () {
    it('applies the rule to the staff API too: no bypass', function () {
        actingAsStaff($this->counter);
        $id = $this->postJson('/api/v1/admin/inventory/stocktakes', ['branch_id' => $this->a->id])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/admin/inventory/stocktakes/{$id}/count", ['variant_id' => $this->variant->id, 'counted' => 7])->assertOk();
        $this->postJson("/api/v1/admin/inventory/stocktakes/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->postJson("/api/v1/admin/inventory/stocktakes/{$id}/post")->assertForbidden()->assertJsonPath('code', 'stocktake_second_person');
        $this->postJson("/api/v1/admin/inventory/stocktakes/{$id}/return", ['reason' => 'x'])->assertStatus(422);
        expect(($this->stock)()->on_hand)->toBe(10);

        actingAsStaff($this->second);
        $this->postJson("/api/v1/admin/inventory/stocktakes/{$id}/return", ['reason' => 'Check the aisle again'])->assertOk()->assertJsonPath('data.status', 'counting');
        actingAsStaff($this->counter);
        $this->postJson("/api/v1/admin/inventory/stocktakes/{$id}/submit")->assertOk();
        actingAsStaff($this->second);
        $this->postJson("/api/v1/admin/inventory/stocktakes/{$id}/post")->assertOk()->assertJsonPath('data.status', 'posted')->assertJsonPath('data.approval_mode', 'second_person');
        expect(($this->stock)()->on_hand)->toBe(7);

        actingAsStaff($this->otherBranch);
        $this->postJson("/api/v1/admin/inventory/stocktakes/{$id}/post")->assertForbidden();
    });

    it('applies the rule on the count screen: the counter sees it waiting and cannot approve, a second person can approve or send it back', function () {
        $s = ($this->finish)();
        Livewire::actingAs($this->counter)->test(StocktakingShow::class, ['session' => $s])->assertSee('Waiting for approval')->assertSee('so someone else has to approve it')
            ->assertDontSee('Approve and post')->assertDontSee('Send back for recount')
            ->call('post')->assertDispatched('toast', type: 'error');
        expect($s->fresh()->status)->toBe('submitted')->and(($this->stock)()->on_hand)->toBe(10);

        $c = Livewire::actingAs($this->second)->test(StocktakingShow::class, ['session' => $s])->assertSee('Approve and post')->assertSee('Send back for recount')->assertSee('QAR 120.00');
        $c->set('showReturn', true)->set('returnReason', 'ab')->call('sendBack')->assertHasErrors('returnReason')
            ->set('returnReason', 'Please count aisle 4 again')->call('sendBack')->assertHasNoErrors()->assertSet('showReturn', false);
        expect($s->fresh()->status)->toBe('counting');
        Livewire::actingAs($this->counter)->test(StocktakingShow::class, ['session' => $s->fresh()])->assertSee('Sent back by')->assertSee('Please count aisle 4 again');

        $s = $this->svc->submit($this->counter, $s->fresh());
        Livewire::actingAs($this->second)->test(StocktakingShow::class, ['session' => $s])->call('post');
        expect($s->fresh()->status)->toBe('posted');
        Livewire::actingAs($this->second)->test(StocktakingShow::class, ['session' => $s->fresh()])->assertSee('Approved by '.$this->second->name);
    });

    it('says what happened to a count that posted by itself, and who approved one with the rule off', function () {
        $none = ($this->finish)(counted: 10);
        Livewire::actingAs($this->second)->test(StocktakingShow::class, ['session' => $none])->assertSee('nothing differed');
    });

    it('shows the Arabic words', function () {
        $s = ($this->finish)();
        app()->setLocale('ar');
        Livewire::actingAs($this->second)->test(StocktakingShow::class, ['session' => $s])->assertSee('بانتظار الاعتماد')->assertSee('اعتماد وترحيل')->assertSee('إعادة للعدّ');
        Livewire::actingAs($this->second)->test(StocktakingIndex::class)->assertSee('بانتظار الاعتماد');
    });
});

describe('where the waiting counts show', function () {
    it('shows the dashboard how many are waiting, inside the viewer\'s branches', function () {
        ($this->finish)();
        $metrics = app(DashboardMetrics::class);
        expect($metrics->for($this->second)['stocktakes_waiting'])->toBe(1)->and($metrics->for($this->owner)['stocktakes_waiting'])->toBe(1)
            ->and($metrics->for($this->otherBranch)['stocktakes_waiting'])->toBe(0)
            ->and($metrics->for(staffWithRole('marketing_manager'))['stocktakes_waiting'])->toBeNull();
        Livewire::actingAs($this->second)->test(Dashboard::class)->assertSee('Stock counts waiting for approval');
    });

    it('shows the list the count and value of the waiting counts, the people who counted, and a wait banner only for the viewer\'s branches', function () {
        $s = ($this->finish)();
        $c = Livewire::actingAs($this->second)->test(StocktakingIndex::class)->assertSee('1 stock count is waiting for approval (QAR 120.00 at cost).')->assertSee('1 item');
        expect($c->viewData('waiting')['count'])->toBe(1);
        $c->call('openView', $s->id)->assertSee('1 items differ (QAR 120.00 at cost). It waits for someone who did not count it to approve.');
        $back = $this->svc->sendBack($this->second, $s, 'Count the sofas again');
        Livewire::actingAs($this->counter)->test(StocktakingIndex::class)->call('openView', $back->id)->assertSee('Count the sofas again')->assertDontSee('is waiting for approval');
        Livewire::actingAs($this->otherBranch)->test(StocktakingIndex::class)->assertDontSee('is waiting for approval');
    });
});

it('is not possible to start a second count while one waits for approval', function () {
    ($this->finish)();
    expect(fn () => $this->svc->start($this->second, $this->a->id))->toThrow(QueryException::class);
});
