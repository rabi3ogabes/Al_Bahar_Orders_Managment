<?php

use App\Broadcasting\BranchChannel;
use App\Broadcasting\LiveOrdersChannel;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Branches\Services\BranchEmployeeService;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserRole;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Identity\Support\BranchFeatureCatalog;
use App\Domain\Operations\Services\LiveBoard;
use App\Domain\Operations\Services\WallDisplaySession;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Auth\Login;
use App\Livewire\Admin\Operations\LiveBoardPage;
use App\Livewire\Admin\Organization\SettingsIndex;
use App\Support\DomainException;
use App\Support\StaffLanding;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

require_once __DIR__.'/OpsSupport.php';

// The view-only wall display account (docs/guides/SEARCH_AND_LIVE_BOARD.md, "Wall display account"): the live board and nothing else.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(qatar('2026-11-19 12:00'));
    $this->a = opsBranch(attributes: ['code' => 'AAA', 'name_en' => 'Doha']);
    $this->b = opsBranch(attributes: ['code' => 'BBB', 'name_en' => 'Wakra']);
    $this->wall = opsStaffWith($this->a, ['board.view']);
    $this->wall->forceFill(['name' => 'Showroom screen'])->save();
    $this->orderA = opsOrder($this->a, [], ['status' => 'pending'], now()->subMinutes(3));
    $this->orderB = opsOrder($this->b, [], ['status' => 'pending'], now()->subMinutes(3));
});

describe('who is a wall display account', function () {
    it('is an account whose only right is board.view, and nobody else', function () {
        $acl = app(AccessControl::class);
        $manager = opsStaff('branch_manager', $this->a);
        $both = opsStaffWith($this->a, ['board.view', 'orders.view']);

        expect($acl->isWallDisplay($this->wall))->toBeTrue()
            ->and($acl->isWallDisplay($manager))->toBeFalse()                                    // a manager holds board.view too, and a lot more
            ->and($acl->allows($manager, 'board.view', $this->a->id))->toBeTrue()
            ->and($acl->isWallDisplay($both))->toBeFalse()
            ->and($acl->isWallDisplay(User::factory()->superAdmin()->create()))->toBeFalse()
            ->and($acl->isWallDisplay(staffWithRole('admin')))->toBeFalse()
            ->and($acl->isWallDisplay(User::factory()->create()))->toBeFalse()                    // a customer
            ->and($acl->isWallDisplay(null))->toBeFalse();
    });

    it('stops being one the moment it is disabled', function () {
        $this->wall->forceFill(['status' => 'suspended'])->save();
        app(AccessControl::class)->forget($this->wall);

        expect(app(AccessControl::class)->isWallDisplay($this->wall))->toBeFalse();
    });
});

describe('the live board, in wall mode, for the branches it was given', function () {
    it('opens the board in wall mode even when the address does not ask for it, with no menu, no search and no buttons', function () {
        $html = $this->actingAs($this->wall)->get(route('admin.operations.board'))->assertOk()->getContent();

        expect($html)->toContain('data-board')->toContain('data-wall-signout')->toContain('Sign out')
            ->not->toContain('<aside')->not->toContain('<header class="sticky')->not->toContain('data-command-palette')->not->toContain('data-build-footer')
            ->not->toContain('wire:click="acceptPart(')->not->toContain('wire:click="peekOrder(')->not->toContain('wire:click="setBranch(')->not->toContain('wire:click="toggleLane(')
            ->not->toContain('x-on:click="toggleSound()"')->not->toContain(__('ops_board.wall.exit'))->not->toContain('data-sound-toggle')
            ->and($this->actingAs($this->wall)->get(route('admin.operations.board', ['wall' => 0]))->getContent())->toContain('data-wall-signout');
    });

    it('shows its own branch and no other, and no customer name or phone', function () {
        $html = $this->actingAs($this->wall)->get(route('admin.operations.board'))->assertOk()->getContent();

        expect($html)->toContain($this->orderA['number'])->not->toContain($this->orderB['number'])->not->toContain('Noor Al-Thani')->not->toContain('+97455123456')->not->toContain('97455123456');
    });

    it('shows every branch it was given, and a branch it was not given stays out whatever the address asks', function () {
        $both = opsStaffWith($this->a, ['board.view']);
        // the same account at a second branch
        $role = Role::create(['key' => 'wall_b', 'name_en' => 'Wall B', 'name_ar' => 'جدار', 'scope' => 'branch']);
        $role->permissions()->attach(Permission::where('key', 'board.view')->pluck('id'));
        BranchEmployee::create(['branch_id' => $this->b->id, 'user_id' => $both->id, 'is_active' => true]);
        UserRole::create(['user_id' => $both->id, 'role_id' => $role->id, 'branch_id' => $this->b->id]);
        app(AccessControl::class)->forget();

        $board = app(LiveBoard::class)->for($both);
        $numbers = collect($board->lanes)->flatMap(fn ($l) => $l['cards']->pluck('number'))->all();
        $only = app(LiveBoard::class)->for($this->wall, $this->b->id);          // asking for a branch it was not given only narrows, it never widens

        expect($numbers)->toContain($this->orderA['number'], $this->orderB['number'])->and(collect($only->lanes)->flatMap(fn ($l) => $l['cards']->pluck('number'))->all())->toBe([$this->orderA['number']])
            ->and(collect($only->branches)->pluck('code')->all())->toBe(['AAA']);
    });

    it('lands there after signing in, remembered, and signs in once', function () {
        expect(StaffLanding::url($this->wall))->toBe(route('admin.operations.board', ['wall' => 1]));
        $this->wall->forceFill(['password' => 'a-long-wall-password-1', 'email' => 'wall@example.test'])->save();

        Livewire::test(Login::class)->set('email', 'wall@example.test')->set('password', 'a-long-wall-password-1')->call('login')
            ->assertRedirect(route('admin.operations.board', ['wall' => 1]));

        expect(auth()->guard('web')->viaRemember())->toBeFalse()->and($this->wall->fresh()->remember_token)->not->toBeNull();    // a remember token was written: the screen stays signed in
    });

    it('works in Arabic', function () {
        $this->wall->forceFill(['locale' => 'ar'])->save();
        $html = $this->withSession(['locale' => 'ar'])->actingAs($this->wall)->get(route('admin.operations.board'))->assertOk()->getContent();

        expect($html)->toContain('dir="rtl"')->toContain('تسجيل الخروج');
    });
});

describe('it can open nothing else', function () {
    it('answers 403 to every other page of the dashboard', function () {
        $checked = [];
        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            if (! str_starts_with($name, 'admin.') || ! in_array('GET', $route->methods(), true) || str_contains($route->uri(), '{')
                || in_array($name, ['admin.operations.board', 'admin.login', 'admin.two-factor.challenge'], true)) {
                continue;
            }
            $this->actingAs($this->wall)->get(route($name))->assertForbidden();
            $checked[] = $name;
        }

        expect($checked)->toContain('admin.dashboard', 'admin.orders.index', 'admin.profile', 'admin.cash.index', 'admin.operations.attention', 'admin.customers.index')->and(count($checked))->toBeGreaterThan(40);
    });

    it('answers 403 to the search, the profile and a page that has a parameter', function () {
        $this->actingAs($this->wall)->getJson(route('admin.search', ['q' => 'AB9000']))->assertForbidden();
        $this->actingAs($this->wall)->get(route('admin.profile'))->assertForbidden();
        $this->actingAs($this->wall)->get('/admin/orders?view='.$this->orderA['order'])->assertForbidden();
        $this->actingAs($this->wall)->get(route('admin.orders.show', $this->orderA['order']))->assertForbidden();
    });

    it('cannot use the staff API, with a token or without', function () {
        Sanctum::actingAs($this->wall, ['staff']);

        $this->getJson(route('api.v1.admin.me'))->assertForbidden()->assertJsonPath('code', 'wall_display_only');
        $this->getJson(route('api.v1.admin.reports.run', ['report' => 'sales_summary']).'?from=2026-11-01&to=2026-11-30')->assertForbidden()->assertJsonPath('code', 'wall_display_only');
    });

    it('can still sign out, and join the live channels of its branches, but not of another one', function () {
        $this->actingAs($this->wall)->post(route('admin.logout'))->assertRedirect(route('admin.login'));
        $this->assertGuest();

        expect(app(BranchChannel::class)->join($this->wall, $this->a->id))->toBeTrue()->and(app(BranchChannel::class)->join($this->wall, $this->b->id))->toBeFalse()
            ->and(app(LiveOrdersChannel::class)->join($this->wall, $this->a->id))->toBeTrue()->and(app(LiveOrdersChannel::class)->join($this->wall, $this->b->id))->toBeFalse()
            ->and(app(LiveOrdersChannel::class)->join($this->wall))->toBeFalse();                  // not "every branch"
    });

    it('refuses a Livewire call to any other page, and accepts the board\'s own', function () {
        // the real page of the orders list, as a person with the right gets it: its snapshot is what the browser sends back with every call
        $page = fn (string $route, $who) => $this->actingAs($who)->get(route($route))->assertOk()->getContent();
        $snapshot = function (string $html): string {
            preg_match('/wire:snapshot="([^"]+)"/', $html, $m);

            return html_entity_decode($m[1], ENT_QUOTES);
        };
        $call = fn (string $snap) => ['components' => [['snapshot' => $snap, 'updates' => [], 'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]]]]];
        $orders = $snapshot($page('admin.orders.index', staffWithRole('admin')));
        $board = $snapshot($page('admin.operations.board', $this->wall));

        $this->actingAs($this->wall)->withHeaders(['X-Livewire' => 'true'])->postJson(Livewire::getUpdateUri(), $call($orders))->assertForbidden();
        $this->actingAs($this->wall)->withHeaders(['X-Livewire' => 'true'])->postJson(Livewire::getUpdateUri(), $call($board))->assertOk();
    });
});

describe('it can do nothing on the board', function () {
    it('has no button, and the server refuses every action of the page', function () {
        $screen = Livewire::actingAs($this->wall)->test(LiveBoardPage::class)->assertSet('wall', true)->assertOk();

        $screen->call('acceptPart', $this->orderA['part'])->assertForbidden();
        Livewire::actingAs($this->wall)->test(LiveBoardPage::class)->call('peekOrder', $this->orderA['order'])->assertForbidden();
        Livewire::actingAs($this->wall)->test(LiveBoardPage::class)->call('setBranch', $this->a->id)->assertForbidden();
        Livewire::actingAs($this->wall)->test(LiveBoardPage::class)->call('toggleLane', 'new')->assertForbidden();
        Livewire::actingAs($this->wall)->test(LiveBoardPage::class)->set('branch', $this->b->id)->assertForbidden();
        Livewire::actingAs($this->wall)->test(LiveBoardPage::class)->set('open', ['new'])->assertForbidden();
        expect(OrderFulfillment::find($this->orderA['part'])->status->value)->toBe('pending');          // nothing was accepted
    });

    it('is forced into wall mode by the server, whatever the address says', function () {
        Livewire::withQueryParams(['wall' => '0', 'view' => $this->orderA['order']])->actingAs($this->wall)->test(LiveBoardPage::class)
            ->assertSet('wall', true)->assertSet('showOrder', false)->assertSet('viewId', '');
    });

    it('leaves a normal manager exactly as before: the buttons and Accept still work', function () {
        $manager = opsStaff('branch_manager', $this->a);

        Livewire::actingAs($manager)->test(LiveBoardPage::class)->assertSet('wall', false)->call('setBranch', $this->a->id)->assertOk();
        Livewire::actingAs($manager)->test(LiveBoardPage::class)->call('acceptPart', $this->orderA['part'])->assertDispatched('toast', type: 'success');
    });
});

describe('it stays signed in for the shift', function () {
    it('stays in for the configured hours without being seen, and is out after them', function () {
        $sessions = app(WallDisplaySession::class);
        expect($sessions->hours())->toBe(16)->and($sessions->touch($this->wall))->toBeTrue();

        $this->travel(15)->hours();
        $this->travel(59)->minutes();
        expect($sessions->touch($this->wall))->toBeTrue();                                           // 15 h 59 min: still in, and seen again now

        $this->travel(16)->hours();
        $this->travel(1)->minutes();
        expect($sessions->touch($this->wall))->toBeFalse();                                          // 16 h 01 min without a request: out
    });

    it('is refreshed by every request the board makes, so a screen in use never runs out', function () {
        $sessions = app(WallDisplaySession::class);
        $sessions->touch($this->wall);

        foreach (range(1, 6) as $i) {                                                                // 6 x 10 h: 60 hours in all, never more than 10 h apart
            $this->travel(10)->hours();
            expect($sessions->touch($this->wall))->toBeTrue();
        }
    });

    it('signs the screen out on its next request after its time, and sends it to the sign-in page', function () {
        $this->actingAs($this->wall)->get(route('admin.operations.board'))->assertOk();
        $this->travel(16)->hours();
        $this->travel(5)->minutes();

        $this->get(route('admin.operations.board'))->assertRedirect(route('admin.login'));
        $this->assertGuest();
    });

    it('follows the setting, and a bad stored value falls back to 16 hours', function () {
        $settings = app(Settings::class);
        $settings->set(WallDisplaySession::HOURS_KEY, 2);
        $sessions = app(WallDisplaySession::class);
        $sessions->touch($this->wall);

        $this->travel(3)->hours();
        expect($sessions->hours())->toBe(2)->and($sessions->touch($this->wall))->toBeFalse();

        foreach (['0', '73', 'lots', '-4', ''] as $bad) {
            $settings->set(WallDisplaySession::HOURS_KEY, $bad);
            expect($sessions->hours())->toBe(16);
        }
    });

    it('is never locked out by an empty cache (a flush counts as "just seen")', function () {
        $this->actingAs($this->wall)->get(route('admin.operations.board'))->assertOk();
        Cache::flush();

        $this->get(route('admin.operations.board'))->assertOk();
    });

    it('starts a new shift at every sign-in and ends it at sign-out', function () {
        $sessions = app(WallDisplaySession::class);
        $sessions->touch($this->wall);
        $this->travel(20)->hours();                                                                  // the screen was off for a day: its old shift ran out
        $this->wall->forceFill(['password' => 'a-long-wall-password-1', 'email' => 'wall@example.test'])->save();

        Livewire::test(Login::class)->set('email', 'wall@example.test')->set('password', 'a-long-wall-password-1')->call('login');

        $this->get(route('admin.operations.board'))->assertOk();                                      // a fresh shift, not "expired at once"
        $this->post(route('admin.logout'));
        $sessions->touch($this->wall);
        expect(Cache::has('wall-display-seen:'.$this->wall->id))->toBeTrue();
    });

    it('has its own setting under Settings > Security, with words in both languages', function () {
        $admin = User::factory()->superAdmin()->create();
        $page = Livewire::actingAs($admin)->test(SettingsIndex::class)->set('tab', 'security')->assertSet('values.security.operations__wall_session_hours', '16')
            ->assertSee('Wall display stays signed in for');
        $page->set('values.security.operations__wall_session_hours', '8')->call('save')->assertHasNoErrors();
        $page->set('values.security.operations__wall_session_hours', '0')->call('save')->assertHasErrors('values.security.operations__wall_session_hours');
        $page->set('values.security.operations__wall_session_hours', '73')->call('save')->assertHasErrors('values.security.operations__wall_session_hours');

        expect(app(WallDisplaySession::class)->hours())->toBe(8);
        app()->setLocale('ar');
        Livewire::actingAs($admin)->test(SettingsIndex::class)->set('tab', 'security')->assertSee('تبقى شاشة الجدار مسجّلة الدخول لمدة');
    });
});

describe('creating one from Branch employees', function () {
    beforeEach(function () {
        $this->manager = opsStaff('branch_manager', $this->a);
        $this->service = app(BranchEmployeeService::class);
        $this->profile = ['name' => 'Wall screen', 'email' => 'wall-aaa@example.test', 'phone' => null, 'locale' => 'en', 'password' => 'a-long-wall-password-1'];
    });

    it('offers the wall display as its own feature and ready-made set, with words in both languages', function () {
        expect(BranchFeatureCatalog::exists('wall_display'))->toBeTrue()->and(BranchFeatureCatalog::presets()['wall_display'])->toBe(['wall_display'])
            ->and(BranchFeatureCatalog::permissionKeys(['wall_display']))->toBe(['board.view'])->and(BranchFeatureCatalog::presetFor(['wall_display']))->toBe('wall_display');
        foreach (['en', 'ar'] as $locale) {
            foreach (['group_wall', 'wall_display', 'wall_display_hint', 'preset_wall_display', 'preset_wall_display_hint', 'error_wall_display_alone'] as $key) {
                expect(__('branch_access.'.$key, [], $locale))->not->toBe('branch_access.'.$key);
            }
        }
    });

    it('lets a branch manager create one for their own branch, and the account is a wall display account', function () {
        $user = $this->service->create($this->manager, $this->a, $this->profile, ['wall_display']);

        expect(app(AccessControl::class)->isWallDisplay($user))->toBeTrue()->and(app(AccessControl::class)->permissionKeys($user))->toBe(['board.view']);
        $this->actingAs($user)->get(route('admin.operations.board'))->assertOk()->assertSee($this->orderA['number']);
        $this->actingAs($user)->get(route('admin.orders.index'))->assertForbidden();
    });

    it('refuses to mix the wall display with another feature, and says why', function () {
        try {
            $this->service->create($this->manager, $this->a, $this->profile, ['wall_display', 'orders_view']);
            $this->fail('expected a refusal');
        } catch (DomainException $e) {
            expect($e->errorCode)->toBe('wall_display_alone')->and($e->getMessage())->toContain('Untick the other features');
        }
        expect(User::where('email', 'wall-aaa@example.test')->exists())->toBeFalse();
    });

    it('is closed to a manager of another branch and to someone who does not hold the board right themselves', function () {
        expect(fn () => $this->service->create(opsStaff('branch_manager', $this->b), $this->a, $this->profile, ['wall_display']))->toThrow(DomainException::class);
        $noBoard = opsStaffWith($this->a, ['employees.view', 'employees.create', 'employees.assign']);

        expect(fn () => $this->service->create($noBoard, $this->a, $this->profile, ['wall_display']))->toThrow(DomainException::class);
    });

    it('turns an ordinary employee into a wall account and back, and the first-password rule follows', function () {
        $user = $this->service->create($this->manager, $this->a, $this->profile, ['orders_view']);
        expect(app(AccessControl::class)->isWallDisplay($user))->toBeFalse();

        $this->service->setFeatures($this->manager, $user, $this->a, ['wall_display']);
        app(AccessControl::class)->forget($user);
        $this->actingAs($user)->get(route('admin.operations.board'))->assertOk();                  // the temporary-password gate does not lock a screen out
        $this->actingAs($user)->get(route('admin.profile'))->assertForbidden();

        $this->service->setFeatures($this->manager, $user, $this->a, ['orders_view']);
        app(AccessControl::class)->forget($user);
        $this->actingAs($user)->get(route('admin.operations.board'))->assertRedirect(route('admin.profile'));   // an ordinary account again: it must choose its own password
    });
});

it('is never a toast: the wall account listens for the live signal and shows no customer on screen', function () {
    $html = $this->actingAs($this->wall)->get(route('admin.operations.board'))->assertOk()->getContent();
    preg_match('/data-boot="([^"]+)"/', $html, $m);
    $boot = json_decode(html_entity_decode($m[1]), true);

    expect($boot['quiet'])->toBeTrue()->and($boot['channels'])->toBe(['live-orders.'.$this->a->id]);
});
