<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Models\User;
use App\Domain\Operations\Services\BranchSchedules;
use App\Domain\Reports\Services\ScorecardBranchNumbers;
use App\Domain\Reports\Services\ScorecardCsv;
use App\Domain\Reports\Services\ScorecardPeriod;
use App\Domain\Reports\Services\ScorecardService;
use App\Domain\Reports\Services\ScorecardSettings;
use App\Livewire\Admin\Insights\ScorecardPage;
use App\Support\AdminNavigation;
use App\Support\DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/ScorecardSupport.php';

/**
 * The scorecard page: who may open it, what a manager sees, the period, the two tabs, the side windows, the CSV, the setting, Arabic, and the states.
 * "Today" is Thursday 19 Nov 2026, midday in Qatar; the default period (the last 30 days) is 20 Oct to 18 Nov.
 */
beforeEach(function () {
    $this->w = scWorld();
    $this->driver = scDriver($this->w->a, 'Omar Driver');
    $this->managerA = staffWithRole('branch_manager', $this->w->a);
    $this->managerB = staffWithRole('branch_manager', $this->w->b);
    // Some work: three orders at each branch, one delivered by the driver on the promised day.
    foreach ([$this->w->a, $this->w->b] as $branch) {
        foreach ([2, 4, 6] as $minutes) {
            scOrder($branch, '2026-11-13 10:00', $minutes, 12);
        }
    }
    $this->made = scDelivered($this->w->a, '2026-11-13 09:00', '2026-11-14 12:00', '2026-11-14');
    scTrip($this->made, $this->w->a, $this->driver, 'delivered', ['delivered_at' => '2026-11-14 12:00', 'out_for_delivery_at' => '2026-11-14 11:30']);
});

/** The page as someone sees it, opened with the given address parameters (?range=custom&from=...). @param array<string, string> $query */
function scPage(?User $as = null, array $query = [])
{
    test()->actingAs($as ?? test()->w->admin);

    return Livewire::withQueryParams($query)->test(ScorecardPage::class);
}

describe('who may open it', function () {
    it('lets the owner and a manager in, and keeps drivers and ordinary staff out: no menu entry and a 403', function (string $role) {
        $branch = $this->w->a;
        $user = $role === 'delivery_employee' ? $this->driver : staffWithRole($role, $branch);
        $this->actingAs($user);

        $this->get(route('admin.scorecard.index'))->assertForbidden();
        Livewire::test(ScorecardPage::class)->assertForbidden();
        expect(collect(AdminNavigation::for($user))->flatMap(fn ($g) => array_column($g['items'], 'route'))->all())->not->toContain('admin.scorecard.index');
    })->with(['delivery_employee', 'sales_employee', 'pickup_employee', 'order_manager']);

    it('shows the owner and a branch manager the page and the menu entry', function () {
        foreach ([$this->w->admin, $this->managerA] as $user) {
            $this->actingAs($user);
            $this->get(route('admin.scorecard.index'))->assertOk()->assertSee(__('scorecard.q_branches'));
            expect(collect(AdminNavigation::for($user))->flatMap(fn ($g) => array_column($g['items'], 'route'))->all())->toContain('admin.scorecard.index');
        }
    });

    it('sends a guest to the sign-in page', function () {
        $this->get(route('admin.scorecard.index'))->assertRedirect();
    });

    it('shows a manager only their own branch, and nothing of another one even when asked for it by id', function () {
        scPage($this->managerA)->assertSeeHtml('data-branch="DOH"')->assertDontSeeHtml('data-branch="WKR"')
            ->set('branch', $this->w->b->id)->assertSet('branch', '')->assertSeeHtml('data-branch="DOH"')->assertDontSeeHtml('data-branch="WKR"')
            ->call('openBranch', $this->w->b->id)->assertSet('showBranch', false)->assertDispatched('toast', type: 'error', message: __('scorecard.not_available'));
        scPage($this->managerA, ['branch' => $this->w->b->id])->assertSet('branch', '')->assertDontSeeHtml('data-branch="WKR"');
        scPage($this->w->admin)->assertSeeHtml('data-branch="DOH"')->assertSeeHtml('data-branch="WKR"');
    });
});

describe('the first screen', function () {
    it('answers the question first: the branches together, then one card per branch with its four main numbers', function () {
        scPage()->assertSee(__('scorecard.q_branches'))->assertSee(__('scorecard.together', ['count' => 2]))
            ->assertSeeHtml('data-testid="head"')->assertSeeHtml('data-branch="DOH"')->assertSeeHtml('data-branch="WKR"')
            ->assertSee(__('scorecard.m_orders'))->assertSee(__('scorecard.m_on_time'))->assertSee(__('scorecard.m_accept'))->assertSee(__('scorecard.m_prepare'))
            ->assertSee(__('scorecard.read_title'));
    });

    it('says "not enough data yet" for a figure below the minimum and shows the figure from the minimum on', function () {
        app(ScorecardSettings::class)->setMinSample(10);
        scPage()->assertSee(__('scorecard.not_enough'))->assertSeeHtml('data-metric-state="few"')->assertDontSeeHtml('data-metric-state="ok" data-metric="on_time"');
        app(ScorecardSettings::class)->setMinSample(1);
        Cache::flush();
        scPage()->assertSeeHtml('data-metric-state="ok"')->assertDontSee(__('scorecard.not_enough'));
    });

    it('explains how each number is measured, and says in every window that nothing here ranks people', function () {
        scPage()->call('openBranch', $this->w->a->id)->assertSet('showBranch', true)->assertDontSee('scorecard.', false)
            ->assertSee(__('scorecard.n_accept'))->assertSee(__('scorecard.n_on_time'))->assertSee(__('scorecard.n_failed'))->assertSee(__('scorecard.n_cash_net'))
            ->assertSee(__('scorecard.read_4'));
    });

    it('sets every figure of a window beside the period before, the cash too', function () {
        scHandIn($this->w->a, $this->driver, '2026-11-14 18:00', '-5.00');
        scHandIn($this->w->a, $this->driver, '2026-11-08 18:00', '-1.00');                       // the week before

        scPage(null, ['range' => '7'])->call('openBranch', $this->w->a->id)->assertSee(__('scorecard.vs', ['value' => 'QAR -1.00']))
            ->set('tab', 'drivers')->call('openDriver', $this->driver->id)->assertSee(__('scorecard.vs', ['value' => 'QAR -1.00']));
    });

    it('opens one branch in a side window with every number, and the address remembers it', function () {
        scPage()->call('openBranch', $this->w->a->id)->assertSet('showBranch', true)->assertSet('branchCardId', $this->w->a->id)
            ->assertSee(__('scorecard.s_speed'))->assertSee(__('scorecard.s_ontime'))->assertSee(__('scorecard.s_problems'))->assertSee(__('scorecard.s_cash'))
            ->assertSeeHtml('data-testid="fail-reasons"')->assertSeeHtml('data-testid="cancel-reasons"')
            ->set('showBranch', false)->assertSet('branchCardId', '');
        scPage($this->w->admin, ['card' => $this->w->a->id])->assertSet('showBranch', true)->assertSee(__('scorecard.s_speed'));       // reload and sharing: the address opens the window
        scPage($this->managerA, ['card' => $this->w->b->id])->assertSet('showBranch', false)->assertSet('branchCardId', '')->assertDontSee(__('scorecard.s_speed'));
    });
});

describe('the period', function () {
    it('offers 7, 30 and 90 days and a custom range, always compared with the same number of days before', function () {
        scPage()->assertSet('range', '30')->assertSee('20 Oct – 18 Nov 2026')->assertSee('20 Sep – 19 Oct 2026')
            ->call('setRange', '7')->assertSet('range', '7')->assertSee('12 Nov – 18 Nov 2026')->assertSee('5 Nov – 11 Nov 2026')
            ->call('setRange', '90')->assertSet('range', '90')->assertSeeHtml('data-range="90"')
            ->call('setRange', 'bogus')->assertSet('range', '30');
    });

    it('takes a custom range, and says today is counted so far when it ends today', function () {
        scPage(null, ['range' => 'custom', 'from' => '2026-11-10', 'to' => '2026-11-19'])->assertSee(__('scorecard.includes_today'))->assertDontSeeHtml('data-testid="notice"')
            ->set('to', '2026-11-18')->assertSee(__('scorecard.complete_days'));
    });

    it('refuses a range longer than a year, one that runs backwards and one in the future, and shows the last 30 days with the reason', function (string $from, string $to, string $why) {
        scPage(null, ['range' => 'custom', 'from' => $from, 'to' => $to])->assertSee(__('scorecard.fell_back', ['why' => $why]))->assertSee('20 Oct – 18 Nov 2026');
    })->with([
        'too long' => ['2025-11-17', '2026-11-18', 'A range can be at most 366 days long.'],
        'backwards' => ['2026-11-18', '2026-11-12', 'The first day must not be after the last day.'],
        'future' => ['2026-11-12', '2026-11-25', 'The last day cannot be in the future.'],
        'nonsense' => ['2026-13-45', '2026-11-18', 'Choose two real days.'],
    ]);

    it('takes exactly a year', function () {
        scPage(null, ['range' => 'custom', 'from' => '2025-11-18', 'to' => '2026-11-18'])->assertDontSeeHtml('data-testid="notice"')->assertSee('18 Nov 2025 – 18 Nov 2026');
    });
});

describe('the drivers', function () {
    it('lists the drivers alphabetically, never ranked, with a sortable table', function () {
        $z = scDriver($this->w->a, 'Zaid Driver');
        scDelivered($this->w->a, '2026-11-13 09:00', '2026-11-14 12:00', '2026-11-14');
        scTrip(scDelivered($this->w->a, '2026-11-13 09:00', '2026-11-14 13:00', '2026-11-14'), $this->w->a, $z, 'delivered', ['delivered_at' => '2026-11-14 13:00']);
        scTrip(scDelivered($this->w->a, '2026-11-13 09:00', '2026-11-14 14:00', '2026-11-14'), $this->w->a, $z, 'delivered', ['delivered_at' => '2026-11-14 14:00']);

        scPage()->set('tab', 'drivers')->assertSee(__('scorecard.q_drivers'))->assertSeeInOrder(['Omar Driver', 'Zaid Driver'])->assertSeeHtml('aria-sort="ascending"')
            ->call('sortBy', 'delivered')->assertSet('sort', 'delivered')->assertSet('dir', 'asc')->assertSeeInOrder(['Omar Driver', 'Zaid Driver'])
            ->call('sortBy', 'delivered')->assertSet('dir', 'desc')->assertSeeInOrder(['Zaid Driver', 'Omar Driver'])
            ->call('sortBy', 'name')->assertSet('dir', 'asc')->call('sortBy', 'not-a-column')->assertSet('sort', 'name');
    });

    it('opens a driver in a side window with the orders behind his numbers', function () {
        scPage()->set('tab', 'drivers')->call('openDriver', $this->driver->id)->assertSet('showDriver', true)->assertSet('driverId', $this->driver->id)
            ->assertSee('Omar Driver')->assertSee($this->made['number'])->assertSee(__('scorecard.orders_behind'))->assertSee(__('scorecard.ledger.on_time'))
            ->assertDontSee('Noor')->assertDontSee('+97455123456')
            ->set('showDriver', false)->assertSet('driverId', '');
    });

    it('opens nobody a manager may not see, not even by a made-up or foreign id', function () {
        $far = scDriver($this->w->b, 'Wakra Driver');
        scPage($this->managerA)->set('tab', 'drivers')->assertSee('Omar Driver')->assertDontSee('Wakra Driver')
            ->call('openDriver', $far->id)->assertSet('showDriver', false)->assertSet('driverId', '')->assertDispatched('toast', type: 'error', message: __('scorecard.not_available'))
            ->call('openDriver', 'not-an-id')->assertSet('showDriver', false);
    });

    it('has an empty state when there is no driver, instead of a blank table', function () {
        DB::table('deliveries')->delete();
        DB::table('branch_employees')->where('is_delivery_driver', true)->delete();

        scPage()->set('tab', 'drivers')->assertSeeHtml('data-empty="drivers"')->assertSee(__('scorecard.no_drivers_title'));
    });
});

describe('states', function () {
    it('says there is nothing to show when the viewer sees no branch at all', function () {
        $lonely = staffWithRole('accountant');
        Branch::query()->update(['status' => 'inactive']);

        scPage($lonely)->assertSeeHtml('data-empty="branches"')->assertSee(__('scorecard.empty_title'));
    });

    it('shows a plain error with a retry button when the numbers cannot be worked out, and changes nothing', function () {
        $this->instance(ScorecardBranchNumbers::class, new class(app(BranchSchedules::class)) extends ScorecardBranchNumbers
        {
            public function forPeriod(array $branchIds, ScorecardPeriod $period): array
            {
                throw new RuntimeException('the database went away');
            }
        });
        scPage()->assertSeeHtml('data-testid="error"')->assertSee(__('scorecard.error_title'))->assertSee(__('scorecard.retry'));
    });
});

describe('the cash and the setting', function () {
    it('lets the owner change the minimum sample, within its range, and writes it to the audit log', function () {
        scPage()->set('showSettings', true)->set('minSample', '12')->call('saveSettings')->assertHasNoErrors()->assertSet('showSettings', false);

        expect(app(ScorecardSettings::class)->minSample())->toBe(12)->and(AuditLog::where('action', 'settings.updated')->where('metadata->key', 'scorecard.min_sample')->exists())->toBeTrue();
    });

    it('refuses a minimum that is not a whole number between 1 and 200', function (string $value) {
        scPage()->set('minSample', $value)->call('saveSettings')->assertHasErrors('minSample');

        expect(app(ScorecardSettings::class)->minSample())->toBe(1);
    })->with(['0', '201', '-3', 'abc', '2.5', '']);

    it('keeps the setting from a branch manager: no button, and a 403 if they call it', function () {
        scPage($this->managerA)->assertDontSeeHtml('data-testid="open-settings"')->set('minSample', '50')->call('saveSettings')->assertForbidden();

        expect(app(ScorecardSettings::class)->minSample())->toBe(1);
    });
});

describe('the CSV', function () {
    it('gives a manager their own branch only, writes the export to the audit log, and says "not enough data yet" instead of a number', function () {
        app(ScorecardSettings::class)->setMinSample(10);
        $component = scPage($this->managerA);
        $csv = (function () use ($component) {
            $response = $component->instance()->export(app(ScorecardService::class));
            ob_start();
            $response->sendContent();

            return ob_get_clean();
        })();
        $lines = array_map('str_getcsv', array_filter(explode("\n", ltrim($csv, "\xEF\xBB\xBF"))));

        expect($lines[0][0])->toBe(__('scorecard.csv.branch'))->and($lines)->toHaveCount(2)                       // the header and Doha; no "all together" line for one branch
            ->and($lines[1][0])->toBe('Doha')->and($lines[1][1])->toBe('DOH')->and($lines[1][4])->toBe('4')      // 3 orders and the delivered one
            ->and($lines[1])->toContain(__('scorecard.csv.not_enough'))->and($csv)->not->toContain('Wakra')
            ->and(AuditLog::where('action', 'scorecard.exported')->count())->toBe(1)
            ->and(AuditLog::where('action', 'scorecard.exported')->first()->new_values)->toMatchArray(['tab' => 'branches', 'rows' => 1, 'from' => '2026-10-20', 'to' => '2026-11-18']);
    });

    it('gives the owner every branch and a line for all of them together', function () {
        $data = $this->w->svc->export($this->w->admin, 'branches', scWeek())['data'];
        $csv = ScorecardCsv::rows('branches', $data, 'en');

        expect(array_column($csv['rows'], 0))->toBe(['Doha', 'Wakra', __('scorecard.together', ['count' => 2])])->and(array_column($csv['rows'], 1))->toBe(['DOH', 'WKR', ''])
            ->and($csv['rows'][2][4])->toBe(7);
    });

    it('has a real heading for every column, in both languages, and as many cells as headings in every row', function () {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            foreach (['branches', 'drivers'] as $tab) {
                $csv = ScorecardCsv::rows($tab, $this->w->svc->export($this->w->admin, $tab, scWeek())['data'], $locale);

                expect($csv['header'])->each->not->toStartWith('scorecard.')->and($csv['rows'])->not->toBe([]);
                foreach ($csv['rows'] as $row) {
                    expect(count($row))->toBe(count($csv['header']));
                }
            }
        }
    });

    it('writes the branch names in Arabic for an Arabic dashboard, and the drivers\' names safe for a spreadsheet', function () {
        scDriver($this->w->a, '=HYPERLINK("http://x")');
        $branches = ScorecardCsv::rows('branches', $this->w->svc->export($this->w->admin, 'branches', scWeek())['data'], 'ar');
        app()->setLocale('ar');
        $drivers = ScorecardCsv::rows('drivers', $this->w->svc->export($this->w->admin, 'drivers', scWeek())['data'], 'ar');

        expect($branches['rows'][0][0])->toBe('الدوحة')->and($drivers['header'][0])->toBe('السائق')->and(array_column($drivers['rows'], 0))->toContain("'=HYPERLINK(\"http://x\")");
    });

    it('is closed to people who may see reports but not export them, and to everyone else', function () {
        $reader = staffWithRole('inventory_manager', $this->w->a);                   // reports.view, no reports.export

        scPage($reader)->assertDontSeeHtml('data-testid="export"')->call('export')->assertForbidden();
        expect(fn () => $this->w->svc->export($reader, 'branches', scWeek()))->toThrow(DomainException::class)
            ->and(AuditLog::where('action', 'scorecard.exported')->count())->toBe(0);
        scPage($this->driver)->assertForbidden();
    });
});

describe('Arabic', function () {
    it('says what it says in English, with Arabic branch names, and no English left on the cards', function () {
        app()->setLocale('ar');
        app(ScorecardSettings::class)->setMinSample(10);

        scPage()->assertSee(__('scorecard.q_branches', [], 'ar'))->assertSee('كيف حال الفروع؟')->assertSee('الدوحة')->assertSee('لا توجد بيانات كافية بعد')
            ->assertSee(__('scorecard.m_on_time', [], 'ar'))
            ->assertDontSee('Not enough data yet')->assertDontSee('How are the branches doing?')
            ->call('openBranch', $this->w->a->id)->assertSee('سرعة عمل الفرع')->assertSee(__('scorecard.n_accept', [], 'ar'))->assertDontSee('scorecard.', false);
    });

    it('writes the drivers tab and the ledger in Arabic', function () {
        app()->setLocale('ar');

        scPage()->set('tab', 'drivers')->assertSee('كيف حال السائقين؟')->call('openDriver', $this->driver->id)->assertSee('الطلبات التي تقف وراء هذه الأرقام')->assertSee('سُلّمت في اليوم الموعود')->assertDontSee('scorecard.', false);
    });
});

describe('speed', function () {
    it('keeps the answer for a short time so opening the page twice asks the database once, and forgets it on "refresh"', function () {
        $viewer = $this->w->admin;
        $period = scWeek();
        Cache::flush();
        $count = fn () => collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'order_fulfillments'))->count();
        DB::enableQueryLog();

        $this->w->svc->branches($viewer, $period);
        $first = $count();
        DB::flushQueryLog();
        $this->w->svc->branches($viewer, $period);
        $second = $count();
        scOrder($this->w->a, '2026-11-14 10:00', 1, 1);
        $stale = $this->w->svc->branches($viewer, $period)['rows'][0]['orders']['value'];
        $this->w->svc->forget($viewer, $period);
        $fresh = $this->w->svc->branches($viewer, $period)['rows'][0]['orders']['value'];

        expect($first)->toBeGreaterThan(0)->and($second)->toBe(0)->and($stale)->toBe(4)->and($fresh)->toBe(5);
    });

    it('does not mix up two viewers: the answer is kept for the branches asked about, never for the person', function () {
        $period = scWeek();
        Cache::flush();

        $mine = $this->w->svc->branches($this->managerA, $period);
        $owner = $this->w->svc->branches($this->w->admin, $period);
        $other = $this->w->svc->branches($this->managerB, $period);

        expect(array_column($mine['rows'], 'code'))->toBe(['DOH'])->and(array_column($owner['rows'], 'code'))->toBe(['DOH', 'WKR'])->and(array_column($other['rows'], 'code'))->toBe(['WKR']);
    });

    it('runs one grouped query per family of figures, not one per branch or per order', function () {
        foreach (range(1, 6) as $i) {
            $branch = Branch::factory()->create(['code' => 'X'.$i]);
            scOrder($branch, '2026-11-13 10:00', 3, 4);
        }
        Cache::flush();
        DB::enableQueryLog();

        $this->w->svc->branches($this->w->admin, scWeek());

        // 8 branches, yet the same number of queries as for 2 (this period and the one before, plus the cash that stands now): well under 40.
        expect(count(DB::getQueryLog()))->toBeLessThan(40);
    });
});
