<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserRole;
use App\Domain\Orders\Models\Order;
use App\Domain\Reports\Models\CustomerValueRollup;
use App\Domain\Reports\Services\CustomerValue;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Sales\CustomerIndex;
use App\Livewire\Admin\Sales\CustomerShow;
use App\Livewire\Admin\Sales\CustomerValuePage;
use Livewire\Livewire;

require_once __DIR__.'/OwnerNumbersSupport.php';

beforeEach(function () {
    $this->world = onWorld();                          // Thursday 19 Nov 2026, midday in Qatar
    $this->value = app(CustomerValue::class);
    $this->managerA = staffWithRole('branch_manager', $this->world->a);
    $this->managerB = staffWithRole('branch_manager', $this->world->b);
    $this->cust = fn (string $name = 'Noor Ali', array $extra = []) => User::factory()->create(['name' => $name, 'phone' => '+97455'.random_int(100000, 999999), 'email' => strtolower(str_replace(' ', '.', $name)).'@example.com'] + $extra);
    $this->buy = fn (User $u, array $parts, int $daysAgo = 0, array $attrs = []) => onOrder($parts, ['user_id' => $u->id, 'placed_at' => now()->subDays($daysAgo)] + $attrs);
    $this->rows = fn (?User $viewer = null, array $f = []) => $this->value->query($viewer ?? $this->world->admin, $f)->get()->map(fn ($u) => $this->value->row($u))->keyBy('name');
});

describe('what a customer is worth', function () {
    it('works out each customer once per scope, with the branch\'s own share of a split order, and is the same when run twice', function () {
        $w = $this->world;
        $noor = ($this->cust)();
        ($this->buy)($noor, [[$w->a, '100.00']], 5);
        ($this->buy)($noor, [[$w->b, '50.00']], 3);
        ($this->buy)($noor, [[$w->a, '300.00'], [$w->b, '100.00']], 1);                 // one order shared by two branches

        $first = $this->value->refresh();
        $second = $this->value->refresh();

        $row = fn (string $scope) => CustomerValueRollup::where('user_id', $noor->id)->where('scope', $scope)->first();
        expect($first['rows'])->toBe(3)->and($second['rows'])->toBe(3)
            ->and([$row('all')->orders, $row('all')->spend])->toBe([3, '550.00'])
            ->and([$row($w->a->id)->orders, $row($w->a->id)->spend])->toBe([2, '400.00'])
            ->and([$row($w->b->id)->orders, $row($w->b->id)->spend])->toBe([2, '150.00']);
    });

    it('keeps its own rows when a rebuild starts part-way through a second (a column that dropped the fraction deleted them all)', function () {
        $w = $this->world;
        ($this->buy)(($this->cust)('Fraction Farah'), [[$w->a, '10.00']], 2);
        $this->travelTo(now()->addMicroseconds(437215));                                    // 12:00:00.437215, as a real clock is

        $rows = $this->value->refresh()['rows'];

        expect($rows)->toBe(2)->and(CustomerValueRollup::count())->toBe(2);
    });

    it('leaves out cancelled orders, unpaid online orders and a customer whose every order was cancelled', function () {
        $w = $this->world;
        $gone = ($this->cust)('Gone Gail');
        ($this->buy)($gone, [[$w->a, '80.00']], 4);
        $this->value->refresh();
        expect(CustomerValueRollup::where('user_id', $gone->id)->count())->toBeGreaterThan(0);

        $this->travelTo(now()->addMinute());                                                // a later run
        Order::where('user_id', $gone->id)->update(['status' => 'cancelled']);
        ($this->buy)($gone, [[$w->a, '60.00']], 2, ['status' => 'pending_payment', 'payment_method' => 'sadad']);
        $this->value->refresh();

        expect(CustomerValueRollup::where('user_id', $gone->id)->count())->toBe(0);
    });

    it('says how many customers, repeat buyers, one-time buyers and new customers there are', function () {
        $w = $this->world;
        ($this->buy)(($this->cust)('Repeat Rana'), [[$w->a, '10.00']], 40);
        ($this->buy)(User::find(User::where('name', 'Repeat Rana')->value('id')), [[$w->a, '10.00']], 2);
        ($this->buy)(($this->cust)('Once Omar'), [[$w->a, '10.00']], 50);
        ($this->buy)(($this->cust)('New Nadia'), [[$w->a, '10.00']], 1);                // first order this month
        $this->value->refresh();

        $s = $this->value->summary($this->world->admin);

        expect($s['customers'])->toBe(3)->and($s['repeat'])->toBe(1)->and($s['one_time'])->toBe(2)->and($s['new_month'])->toBe(1)->and($s['spend'])->toBe('40.00');
    });
});

describe('who has gone quiet', function () {
    it('lists customers who had something delivered and then ordered nothing for the set number of days, with their usual gap and how late they are', function () {
        $w = $this->world;
        $regular = ($this->cust)('Regular Reem');                                         // every 30 days, then nothing for 140 days
        foreach ([200, 170, 140] as $ago) {
            ($this->buy)($regular, [[$w->a, '100.00', '0.00', 'delivered']], $ago);
        }
        ($this->buy)(($this->cust)('Active Adam'), [[$w->a, '100.00', '0.00', 'delivered']], 10);                        // ordered recently
        ($this->buy)(($this->cust)('Never Nora'), [[$w->a, '100.00', '0.00', 'preparing']], 90);                         // nothing ever arrived
        ($this->buy)(($this->cust)('Banned Bader', ['status' => 'suspended']), [[$w->a, '100.00', '0.00', 'delivered']], 90);
        $this->value->refresh();

        $quiet = ($this->rows)(null, ['tab' => 'quiet']);
        $reem = $quiet['Regular Reem'];

        expect($quiet->keys()->all())->toBe(['Regular Reem'])
            ->and([$reem->orders, $reem->spend, $reem->days_quiet, $reem->usual_gap_days, $reem->late_by])->toBe([3, '300.00', 140, 30, 4.7]);
    });

    it('follows the number of days the owner sets, and puts the most valuable first', function () {
        $w = $this->world;
        ($this->buy)(($this->cust)('Small Sami'), [[$w->a, '20.00', '0.00', 'delivered']], 45);
        ($this->buy)(($this->cust)('Big Bilal'), [[$w->a, '900.00', '0.00', 'delivered']], 100);
        ($this->buy)(($this->cust)('Mid Maha'), [[$w->a, '200.00', '0.00', 'delivered']], 70);
        $this->value->refresh();

        expect(($this->rows)(null, ['tab' => 'quiet'])->keys()->all())->toBe(['Big Bilal', 'Mid Maha']);       // 60 days by default

        app(Settings::class)->set('customer_value.gone_quiet_days', 30);
        expect(($this->rows)(null, ['tab' => 'quiet'])->keys()->all())->toBe(['Big Bilal', 'Mid Maha', 'Small Sami'])
            ->and($this->value->summary($this->world->admin)['quiet'])->toBe(3)->and($this->value->summary($this->world->admin)['quiet_spend'])->toBe('1120.00');

        app(Settings::class)->set('customer_value.gone_quiet_days', 5);                                          // outside 14..730: the default
        expect($this->value->quietDays())->toBe(60);
    });

    it('sorts by orders, basket, last order, days quiet and lateness, and searches by name', function () {
        $w = $this->world;
        $a = ($this->cust)('Aaa Alpha');
        ($this->buy)($a, [[$w->a, '50.00', '0.00', 'delivered']], 100);
        ($this->buy)($a, [[$w->a, '50.00', '0.00', 'delivered']], 90);
        ($this->buy)(($this->cust)('Bbb Beta'), [[$w->a, '400.00', '0.00', 'delivered']], 80);
        $this->value->refresh();

        $by = fn (string $sort, array $f = []) => $this->value->query($this->world->admin, ['tab' => 'top', 'sort' => $sort] + $f)->pluck('name')->all();

        expect($by('orders'))->toBe(['Aaa Alpha', 'Bbb Beta'])->and($by('basket'))->toBe(['Bbb Beta', 'Aaa Alpha'])->and($by('last_order'))->toBe(['Bbb Beta', 'Aaa Alpha'])
            ->and($by('quiet_days'))->toBe(['Aaa Alpha', 'Bbb Beta'])->and($by('overdue'))->toBe(['Aaa Alpha', 'Bbb Beta'])->and($by('spend', ['search' => 'beta']))->toBe(['Bbb Beta'])
            ->and($by('nonsense'))->toBe(['Bbb Beta', 'Aaa Alpha']);                                              // an unknown sort is the default: most spent
    });
});

describe('a branch sees only what was spent at its own branches', function () {
    beforeEach(function () {
        $w = $this->world;
        $this->noor = ($this->cust)('Noor Ali');
        ($this->buy)($this->noor, [[$w->a, '100.00', '0.00', 'delivered']], 80);
        ($this->buy)($this->noor, [[$w->b, '5000.00', '0.00', 'delivered']], 75);
        $this->only_b = ($this->cust)('Only Bashir');
        ($this->buy)($this->only_b, [[$w->b, '70.00', '0.00', 'delivered']], 80);
        $this->value->refresh();
    });

    it('shows a branch manager the spend of their branch, never the rest, and not the customers of other branches', function () {
        $a = ($this->rows)($this->managerA);
        $all = ($this->rows)($this->world->admin);

        expect($a->keys()->all())->toBe(['Noor Ali'])->and([$a['Noor Ali']->orders, $a['Noor Ali']->spend])->toBe([1, '100.00'])
            ->and($all->keys()->all())->toEqualCanonicalizing(['Noor Ali', 'Only Bashir'])->and($all['Noor Ali']->spend)->toBe('5100.00')
            ->and($this->value->summary($this->managerA)['spend'])->toBe('100.00')->and($this->value->summary($this->world->admin)['spend'])->toBe('5170.00');
    });

    it('lets a branch filter narrow what a person sees, never widen it', function () {
        expect(($this->rows)($this->world->admin, ['branch' => $this->world->b->id])['Noor Ali']->spend)->toBe('5000.00')
            ->and(($this->rows)($this->managerA, ['branch' => $this->world->b->id])->all())->toBe([])
            ->and($this->value->summary($this->managerA, $this->world->b->id)['customers'])->toBe(0);
    });

    it('shows the same to a person who works at both branches as the sum of both, once', function () {
        $both = staffWithRole('branch_manager', $this->world->a);
        BranchEmployee::create(['branch_id' => $this->world->b->id, 'user_id' => $both->id, 'is_active' => true]);
        UserRole::create(['user_id' => $both->id, 'role_id' => Role::where('key', 'branch_manager')->value('id'), 'branch_id' => $this->world->b->id]);

        expect(($this->rows)($both)['Noor Ali']->spend)->toBe('5100.00');
    });

    it('works out one customer\'s lifetime live, through the viewer\'s branches (the customer\'s own page and the customers list)', function () {
        $lifetime = fn ($viewer) => $this->value->lifetimeOf($viewer, $this->noor->id);

        expect($lifetime($this->managerA)['spend'])->toBe('100.00')->and($lifetime($this->managerA)['orders'])->toBe(1)
            ->and($lifetime($this->world->admin)['spend'])->toBe('5100.00')->and($lifetime($this->managerB)['spend'])->toBe('5000.00');

        $this->actingAs($this->managerA);
        $stats = Livewire::test(CustomerShow::class, ['customer' => $this->noor])->viewData('stats');
        expect($stats->spend)->toBe('100.00')->and($stats->n)->toBe(1);

        $this->actingAs($this->world->admin);
        expect(Livewire::test(CustomerShow::class, ['customer' => $this->noor])->viewData('stats')->spend)->toBe('5100.00');

        $this->actingAs($this->managerA);
        $list = Livewire::test(CustomerIndex::class)->viewData('customers')->keyBy('name');
        expect($list->keys()->all())->toBe(['Noor Ali'])->and($list['Noor Ali']->spend)->toBe('100.00')->and($list['Noor Ali']->orders_count)->toBe(1);
        $this->actingAs($this->world->admin);
        expect(Livewire::test(CustomerIndex::class)->viewData('customers')->keyBy('name')['Noor Ali']->spend)->toBe('5100.00');
    });

    it('tells a branch manager the figures are theirs only, and nothing about other branches appears on their page', function () {
        $this->actingAs($this->managerA);

        Livewire::test(CustomerValuePage::class)->assertSee(__('customer_value.branch_only'))->assertSee('Noor Ali')->assertDontSee('Only Bashir')->assertDontSee('5,100')->assertDontSee('5,000');
    });
});

describe('the page', function () {
    it('answers "Who should we win back?" first, with the count and what they were worth, and opens the most valuable customer', function () {
        $w = $this->world;
        ($this->buy)(($this->cust)('Big Bilal'), [[$w->a, '900.00', '0.00', 'delivered']], 100);
        ($this->buy)(($this->cust)('Mid Maha'), [[$w->a, '200.00', '0.00', 'delivered']], 70);
        $this->actingAs($this->world->admin);

        $page = Livewire::test(CustomerValuePage::class);

        $page->assertSee(__('customer_value.q_quiet'))->assertSee('QAR 1,100.00')->assertSee('Big Bilal')->assertSeeHtml('data-tab="quiet"')
            ->call('startWithFirst')->assertSet('showView', true);
        expect($page->get('shownId'))->toBe(User::where('name', 'Big Bilal')->value('id'));
    });

    it('shows a calm empty state when nobody has gone quiet, and an empty branch with nothing', function () {
        $this->actingAs($this->world->admin);

        Livewire::test(CustomerValuePage::class)->assertSeeHtml('data-empty="quiet"')->assertSee(__('customer_value.empty_quiet'))
            ->set('tab', 'top')->assertSee(__('customer_value.empty_top'));
    });

    it('opens only customers the viewer may see', function () {
        $w = $this->world;
        $b = ($this->cust)('Only Bashir');
        ($this->buy)($b, [[$w->b, '70.00', '0.00', 'delivered']], 80);
        $this->value->refresh();
        $this->actingAs($this->managerA);

        Livewire::test(CustomerValuePage::class)->call('openView', $b->id)->assertSet('showView', false);
    });

    it('is closed to staff who may not see customers', function () {
        $this->actingAs(staffWithRole('delivery_employee', $this->world->a));

        Livewire::test(CustomerValuePage::class)->assertForbidden();
        $this->get(route('admin.customer-value.index'))->assertForbidden();
    });

    it('builds the figures the first time the page opens, and not again for a while', function () {
        ($this->buy)(($this->cust)('First Fatima'), [[$this->world->a, '10.00']], 2);
        $this->actingAs($this->world->admin);
        expect(CustomerValueRollup::count())->toBe(0);

        Livewire::test(CustomerValuePage::class);
        $built = CustomerValueRollup::count();
        ($this->buy)(($this->cust)('Later Lina'), [[$this->world->a, '10.00']], 1);
        Livewire::test(CustomerValuePage::class);

        expect($built)->toBeGreaterThan(0)->and(CustomerValueRollup::count())->toBe($built);                   // the second visit did not rebuild
    });

    it('lets the owner refresh at once, but not over and over, and change the quiet days only with the settings right', function () {
        $this->actingAs($this->world->admin);

        Livewire::test(CustomerValuePage::class)->call('refreshNow')->assertDispatched('toast', type: 'success', message: __('customer_value.refreshed'))
            ->call('refreshNow')->assertDispatched('toast', type: 'error', message: __('customer_value.refresh_wait'))
            ->set('quietDays', '90')->call('saveSettings');
        expect(app(Settings::class)->get('customer_value.gone_quiet_days'))->toBe(90);

        Livewire::test(CustomerValuePage::class)->set('quietDays', '5')->call('saveSettings')->assertHasErrors('quietDays');

        $this->actingAs($this->managerA);
        Livewire::test(CustomerValuePage::class)->call('saveSettings')->assertForbidden();
    });

    it('says in Arabic what it says in English', function () {
        app()->setLocale('ar');
        $this->actingAs($this->world->admin);

        Livewire::test(CustomerValuePage::class)->assertSee(__('customer_value.q_quiet', [], 'ar'))->assertSee(__('customer_value.tile_top', [], 'ar'));
    });
});

describe('the CSV', function () {
    function exporter(array $keys): User
    {
        $role = Role::create(['key' => 'exporter-'.uniqid(), 'name_en' => 'Exporter', 'name_ar' => 'مصدّر', 'scope' => 'global', 'is_system' => false]);
        $role->permissions()->attach(Permission::whereIn('key', $keys)->pluck('id'));
        $u = User::factory()->staff()->create();
        UserRole::create(['user_id' => $u->id, 'role_id' => $role->id, 'branch_id' => null]);

        return $u;
    }

    it('needs the export right, is audited, and carries masked contact details unless the viewer may edit customers', function () {
        $w = $this->world;
        ($this->buy)(($this->cust)('Export Esra'), [[$w->a, '120.00', '0.00', 'delivered']], 90);
        $this->value->refresh();

        $this->actingAs($this->managerA);
        Livewire::test(CustomerValuePage::class)->call('export')->assertForbidden();                          // a branch manager may not export customers

        $reader = exporter(['customers.view', 'customers.export']);
        $this->actingAs($reader);
        $esra = User::where('name', 'Export Esra')->first();
        $masked = Livewire::test(CustomerValuePage::class)->call('export');
        $masked->assertFileDownloaded();
        $csv = (string) $masked->effects['download']['content'] ?? '';
        $log = AuditLog::where('action', 'customer_value.exported')->latest('id')->first();

        $editor = exporter(['customers.view', 'customers.export', 'customers.edit']);
        $this->actingAs($editor);
        $full = Livewire::test(CustomerValuePage::class)->call('export')->effects['download']['content'] ?? '';

        expect(base64_decode((string) $csv))->toContain('Export Esra')->not->toContain((string) $esra->phone)->and(base64_decode((string) $full))->toContain((string) $esra->phone)
            ->and($log->new_values['rows'])->toBe(1)->and($log->new_values['masked'])->toBeTrue();
    });
});
