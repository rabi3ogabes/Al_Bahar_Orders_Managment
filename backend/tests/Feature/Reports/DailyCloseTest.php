<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Services\BranchService;
use App\Domain\Reports\Models\DailyClose;
use App\Domain\Reports\Services\DailyCloseService;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Insights\DailyClosePage;
use App\Support\DomainException;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

require_once __DIR__.'/OwnerNumbersSupport.php';

beforeEach(function () {
    $this->world = onWorld();                        // Thursday 19 Nov 2026, midday in Qatar
    $this->yesterday = '2026-11-18';
    $this->svc = app(DailyCloseService::class);
    $this->managerA = staffWithRole('branch_manager', $this->world->a);
    $this->managerB = staffWithRole('branch_manager', $this->world->b);
    onOrder([[$this->world->a, '100.00', '0.00', 'delivered']], ['placed_at' => qatarAt('2026-11-18 10:00')]);   // a finished order: nothing open
});

/** A moment on a Qatar day, as the app stores it. */
function qatarAt(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Asia/Qatar')->utc();
}

describe('when a day can be closed', function () {
    it('opens the day for closing once the branch is shut: a fixed time without hours, the last opening hour plus a few minutes with them', function () {
        $a = $this->world->a->load('hours');

        expect($this->svc->closesAt($a, '2026-11-19')->setTimezone('Asia/Qatar')->format('H:i'))->toBe('22:00')
            ->and($this->svc->state($a, '2026-11-19', false))->toBe('in_progress')
            ->and($this->svc->state($a, $this->yesterday, false))->toBe('ready');

        app(BranchService::class)->setWeeklyHours($a, array_map(fn ($d) => ['day_of_week' => $d, 'is_closed' => false, 'opens_at' => '09:00', 'closes_at' => '21:00'], range(0, 6)), audit: false);
        $a = Branch::with('hours')->find($a->id);

        expect($this->svc->closesAt($a, '2026-11-19')->setTimezone('Asia/Qatar')->format('H:i'))->toBe('21:30');
        $this->travelTo(qatarAt('2026-11-19 21:31'));
        expect($this->svc->state($a, '2026-11-19', false))->toBe('ready');
    });

    it('follows the two settings: the minutes after closing and the time for a branch with no hours', function () {
        app(Settings::class)->set('daily_close.fallback_time', '20:15');
        $a = $this->world->a->load('hours');

        expect($this->svc->closesAt($a, '2026-11-19')->setTimezone('Asia/Qatar')->format('H:i'))->toBe('20:15');

        app(Settings::class)->set('daily_close.fallback_time', 'garbage');
        expect($this->svc->fallbackTime())->toBe('22:00');
    });

    it('never lets today be closed before the branch is shut', function () {
        expect(fn () => $this->svc->close($this->managerA, $this->world->a->id, '2026-11-19', null))->toThrow(DomainException::class, '22:00');
        expect(DailyClose::count())->toBe(0);
    });

    it('refuses a day in the future, a made-up date and a day more than a month old', function () {
        foreach (['2026-11-20', '2026-13-45', 'yesterday', '2026-10-01'] as $bad) {
            expect(fn () => $this->svc->close($this->managerA, $this->world->a->id, $bad, null))->toThrow(DomainException::class);
        }
        expect(DailyClose::count())->toBe(0);
    });
});

describe('closing a day', function () {
    it('signs the day off with its figures, written to the audit log, and keeps them as they were', function () {
        $close = $this->svc->close($this->managerA, $this->world->a->id, $this->yesterday, 'All good.');

        expect($close->numbers['sales']['total'])->toBe('100.00')->and($close->note)->toBe('All good.')->and($close->closed_by)->toBe($this->managerA->id)
            ->and(AuditLog::where('action', 'daily_close.closed')->where('branch_id', $this->world->a->id)->count())->toBe(1);

        onOrder([[$this->world->a, '900.00', '0.00', 'delivered']], ['placed_at' => qatarAt('2026-11-18 11:00')]);   // a late change to the live figures

        $board = $this->svc->board($this->world->admin, $this->yesterday, $this->world->a->id);
        expect($board[0]['state'])->toBe('closed')->and($board[0]['numbers']['sales']['total'])->toBe('100.00');                   // the closed day does not move
    });

    it('asks for a note when something is still open, and takes it when it is written', function () {
        onOrder([[$this->world->a, '10.00', '0.00', 'pending']], ['placed_at' => qatarAt('2026-11-18 12:00')]);                   // nobody accepted it

        expect(fn () => $this->svc->close($this->managerA, $this->world->a->id, $this->yesterday, null))->toThrow(DomainException::class, 'note');
        expect(fn () => $this->svc->close($this->managerA, $this->world->a->id, $this->yesterday, '  '))->toThrow(DomainException::class);
        $close = $this->svc->close($this->managerA, $this->world->a->id, $this->yesterday, 'It is a call-back, handled tomorrow.');

        expect($close->flags)->toBe(['never_accepted' => 1]);
    });

    it('asks for a note for cash that is still with the people who took it', function () {
        $o = onOrder([[$this->world->a, '60.00', '0.00', 'delivered']], ['placed_at' => qatarAt('2026-11-18 12:00')]);
        onCashCollection($o, '60.00', ['collected_at' => qatarAt('2026-11-18 13:00')]);

        expect(fn () => $this->svc->close($this->managerA, $this->world->a->id, $this->yesterday, null))->toThrow(DomainException::class)
            ->and(DailyCloseService::flagsOf($this->svc->board($this->managerA, $this->yesterday)[0]['numbers']))->toBe(['cash_with_collectors' => '60.00']);
    });

    it('closes a day only once: a second close, a double tap, finds it done', function () {
        $this->svc->close($this->managerA, $this->world->a->id, $this->yesterday, null);

        expect(fn () => $this->svc->close($this->managerA, $this->world->a->id, $this->yesterday, null))->toThrow(DomainException::class, 'already');
        expect(DailyClose::count())->toBe(1)->and(AuditLog::where('action', 'daily_close.closed')->count())->toBe(1);
    });

    it('lets only a manager of that branch close it: not another branch, not someone who can only read the reports', function () {
        expect(fn () => $this->svc->close($this->managerB, $this->world->a->id, $this->yesterday, null))->toThrow(DomainException::class);
        $accountant = staffWithRole('accountant');                                            // sees every report, approves no order
        expect(fn () => $this->svc->close($accountant, $this->world->a->id, $this->yesterday, null))->toThrow(DomainException::class);
        expect(DailyClose::count())->toBe(0);
        $this->svc->close($this->world->admin, $this->world->a->id, $this->yesterday, null);
        expect(DailyClose::count())->toBe(1);
    });

    it('shows a manager only the branches they may see, and the owner every branch', function () {
        expect(array_map(fn ($b) => $b['branch']->code, $this->svc->board($this->managerA, $this->yesterday)))->toBe(['DOH'])
            ->and(array_map(fn ($b) => $b['branch']->code, $this->svc->board($this->world->admin, $this->yesterday)))->toEqualCanonicalizing(['DOH', 'WKR'])
            ->and($this->svc->board($this->managerA, $this->yesterday, $this->world->b->id))->toBe([]);          // a branch filter can only narrow
    });
});

describe('opening a closed day again', function () {
    it('is for whoever manages the company settings, needs a reason, and is audited', function () {
        $this->svc->close($this->managerA, $this->world->a->id, $this->yesterday, null);

        expect(fn () => $this->svc->reopen($this->managerA, $this->world->a->id, $this->yesterday, 'I made a mistake'))->toThrow(DomainException::class);
        expect(fn () => $this->svc->reopen($this->world->admin, $this->world->a->id, $this->yesterday, ' '))->toThrow(DomainException::class);
        $this->svc->reopen($this->world->admin, $this->world->a->id, $this->yesterday, 'Closed the wrong day');

        expect(DailyClose::count())->toBe(0)->and(AuditLog::where('action', 'daily_close.reopened')->count())->toBe(1);
        expect(fn () => $this->svc->reopen($this->world->admin, $this->world->a->id, $this->yesterday, 'Again'))->toThrow(DomainException::class, 'not closed');
    });
});

describe('the page', function () {
    it('answers "How did yesterday go?" first: the sales, who closed, and one card per branch', function () {
        $this->actingAs($this->world->admin);

        Livewire::test(DailyClosePage::class)
            ->assertSee(__('daily_close.q_yesterday'))->assertSee('QAR 100.00')->assertSee(__('daily_close.tally', ['closed' => 0, 'total' => 2]))
            ->assertSeeHtml('data-branch="DOH"')->assertSeeHtml('data-branch="WKR"');
    });

    it('counts an order shared by two branches once in the line of the whole company, and money exactly', function () {
        onOrder([[$this->world->a, '300.00'], [$this->world->b, '100.00']], ['placed_at' => qatarAt('2026-11-18 13:00')]);
        $this->actingAs($this->world->admin);

        $page = Livewire::test(DailyClosePage::class);

        expect($page->viewData('orders'))->toBe(2)                                   // the finished order of the fixture and the shared one, not three
            ->and($page->viewData('sales')->toDecimal())->toBe('500.00');
        $this->svc->close($this->managerA, $this->world->a->id, $this->yesterday, 'Shared order handled.');
        expect(Livewire::test(DailyClosePage::class)->viewData('orders'))->toBe(3);   // once a branch is closed its own count is frozen, so the counts are added
    });

    it('lets the manager review the day and close it with one tap, once', function () {
        $this->actingAs($this->managerA);

        Livewire::test(DailyClosePage::class)->assertDontSeeHtml('data-branch="WKR"')
            ->call('openReview', $this->world->a->id)->assertSet('showReview', true)->assertSee(__('daily_close.close_day'))
            ->call('closeDay')->assertDispatched('toast')
            ->call('closeDay')->assertDispatched('toast', type: 'error', message: __('daily_close.errors.already_closed'));

        expect(DailyClose::count())->toBe(1);
    });

    it('keeps another branch\'s day closed to a manager, even by id', function () {
        $this->actingAs($this->managerA);

        Livewire::test(DailyClosePage::class)->call('openReview', $this->world->b->id)->assertSet('showReview', false)->assertSet('reviewId', '')
            ->call('openReview', 'not-a-branch')->assertSet('showReview', false);
    });

    it('is closed to people who may not see reports', function () {
        $this->actingAs(staffWithRole('pickup_employee', $this->world->a));

        Livewire::test(DailyClosePage::class)->assertForbidden();
        $this->get(route('admin.daily-close.index'))->assertForbidden();
        $this->get(route('admin.daily-close.print'))->assertForbidden();
    });

    it('says in Arabic what it says in English', function () {
        app()->setLocale('ar');
        $this->actingAs($this->world->admin);

        Livewire::test(DailyClosePage::class)->assertSee(__('daily_close.q_yesterday', [], 'ar'))->assertSee('لم يُقفل بعد');
    });

    it('prints one page per branch the viewer may see, and nothing for a branch they may not', function () {
        $this->actingAs($this->managerA);
        $this->get(route('admin.daily-close.print', ['date' => $this->yesterday]))->assertOk()->assertSee('DOH')->assertDontSee('WKR')->assertSee('QAR 100.00');
        $this->get(route('admin.daily-close.print', ['date' => $this->yesterday, 'branch' => $this->world->b->id]))->assertNotFound();
        $this->get(route('admin.daily-close.print', ['date' => 'nonsense']))->assertNotFound();
    });
});
