<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Branches\Services\BranchEmployeeService;
use App\Domain\Branches\Services\EmployeeService;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserRole;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Identity\Services\TokenService;
use App\Domain\Identity\Services\TwoFactorService;
use App\Domain\Reports\Services\DashboardMetrics;
use App\Domain\Reports\Services\ReportService;
use App\Domain\Support\Models\SupportTicket;
use App\Livewire\Admin\Auth\Login;
use App\Livewire\Admin\Auth\TwoFactorChallenge;
use App\Livewire\Admin\Branches\BranchEditor;
use App\Livewire\Admin\Organization\EmployeeEditor;
use App\Livewire\Admin\Profile;
use App\Support\DomainException;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;

/**
 * The independent security review of branch employees, sign-in and two-factor: every defect it found, each with a test that fails without its fix.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    [$this->wb, $this->prl] = Branch::factory()->count(2)->create();
    $this->super = User::factory()->superAdmin()->create();
    $this->manager = staffWithRole('branch_manager', $this->wb);
});

/** A branch employee made through the service (password "TempPassw0rdXyz", the flag still set unless told otherwise). */
function rvStaff(Branch $branch, array $features, array $profile = [], bool $pastFirstSignIn = true): User
{
    $u = app(BranchEmployeeService::class)->create(test()->super, $branch, $profile + [
        'name' => 'Review Person', 'email' => 'rv'.random_int(10000, 99999).'@albahar.qa', 'phone' => null, 'locale' => 'en', 'job_title' => null,
        'password' => 'TempPassw0rdXyz',
    ], $features);
    if ($pastFirstSignIn) {
        $u->forceFill(['must_change_password' => false])->save();
    }

    return $u->fresh();
}

/** The Livewire snapshots (name => raw JSON) rendered on a page, as the browser would receive them. */
function rvSnapshots(string $url): array
{
    $html = test()->get($url)->getContent();
    preg_match_all('/wire:snapshot="([^"]*)"/', (string) $html, $m);
    $out = [];
    foreach ($m[1] as $raw) {
        $json = html_entity_decode($raw, ENT_QUOTES);
        $data = json_decode($json, true);
        if (is_array($data) && isset($data['memo']['name'])) {
            $out[$data['memo']['name']] = $json;
        }
    }

    return $out;
}

/** A real Livewire update request, as the browser sends it (headers, signed snapshot, one call). */
function rvLivewireCall(string $snapshot, string $method, array $updates = [], array $params = [])
{
    return test()->postJson(route('default-livewire.update'), ['components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => [['path' => '', 'method' => $method, 'params' => $params]]]]],
        ['X-Livewire' => 'true']);
}

describe('1 · choosing a new password cuts off whoever knew the temporary one', function () {
    it('kills every refresh token, app token and other browser session when the password is changed on the dashboard', function () {
        config(['session.driver' => 'database']);
        $emp = rvStaff($this->wb, ['home', 'orders_view'], pastFirstSignIn: false);
        $other = app(TokenService::class)->issue($emp, 'manager phone', 'android'); // somebody who knew the temporary password and kept these
        DB::table('sessions')->insert(['id' => 'someone-elses-session', 'user_id' => $emp->id, 'payload' => 'x', 'last_activity' => time()]);

        $this->actingAs($emp);
        Livewire::test(Profile::class)->set('current_password', 'TempPassw0rdXyz')->set('password', 'BrandNewPassw0rd!')->set('password_confirmation', 'BrandNewPassw0rd!')
            ->call('changePassword')->assertHasNoErrors();

        expect(fn () => app(TokenService::class)->refresh($other['refresh_token']))->toThrow(DomainException::class);
        expect(PersonalAccessToken::where('tokenable_id', $emp->id)->count())->toBe(0)
            ->and(DB::table('sessions')->where('id', 'someone-elses-session')->exists())->toBeFalse();
        expect($emp->fresh()->session_stamp)->not->toBeNull();
    });

    it('refuses an old browser session cookie after the change, and keeps the browser that made the change signed in', function () {
        $emp = rvStaff($this->wb, ['home', 'orders_view']);
        $oldStamp = (string) $emp->session_stamp; // what every session of this account carried so far
        $this->withSession(['session_stamp' => $oldStamp])->actingAs($emp)->get(route('admin.orders.index'))->assertOk();

        $this->actingAs($emp);
        Livewire::test(Profile::class)->set('current_password', 'TempPassw0rdXyz')->set('password', 'BrandNewPassw0rd!')->set('password_confirmation', 'BrandNewPassw0rd!')
            ->call('changePassword')->assertHasNoErrors();
        $fresh = $emp->fresh();
        expect((string) $fresh->session_stamp)->not->toBe($oldStamp)->and($fresh->session_stamp)->not->toBeNull()->and(session('session_stamp'))->toBe($fresh->session_stamp); // this browser adopted the new stamp

        // the old cookie (a session started before the change, e.g. with the temporary password) is dead, on pages and on Livewire calls
        auth()->guard('web')->logout();
        $this->withSession(['session_stamp' => $oldStamp])->actingAs($fresh)->get(route('admin.orders.index'))->assertRedirect(route('admin.login'));
        // ... and the browser that changed it still works
        $this->withSession(['session_stamp' => $fresh->session_stamp])->actingAs($fresh)->get(route('admin.orders.index'))->assertOk();
    });

    it('refuses an old browser session on Livewire calls too (the persistent middleware sees the session)', function () {
        $emp = rvStaff($this->wb, ['home', 'orders_view']);
        $this->actingAs($emp);
        $orders = rvSnapshots(route('admin.orders.index'));
        $old = (string) $emp->session_stamp;
        $stamp = app(TokenService::class)->forgetWebSessions($emp); // e.g. the password was changed elsewhere
        auth()->guard('web')->logout();
        $component = array_values($orders)[0];

        $this->withSession(['session_stamp' => $old])->actingAs($emp->fresh());
        rvLivewireCall($component, '$refresh')->assertForbidden();
        $this->withSession(['session_stamp' => $stamp])->actingAs($emp->fresh());
        rvLivewireCall($component, '$refresh')->assertOk();
    });

    it('refuses a new password that equals the current one', function () {
        $emp = rvStaff($this->wb, ['home']);
        $this->actingAs($emp);
        Livewire::test(Profile::class)->set('current_password', 'TempPassw0rdXyz')->set('password', 'TempPassw0rdXyz')->set('password_confirmation', 'TempPassw0rdXyz')
            ->call('changePassword')->assertHasErrors(['password']);
        expect(Hash::check('TempPassw0rdXyz', $emp->fresh()->password))->toBeTrue();
    });

    it('does the same when the password is chosen from the staff app: other devices and every browser are out, this device stays', function () {
        $emp = rvStaff($this->wb, ['orders_view'], pastFirstSignIn: false);
        $mine = app(TokenService::class)->issue($emp, 'my phone', 'android');
        $theirs = app(TokenService::class)->issue($emp, 'manager phone', 'android');
        $oldStamp = (string) $emp->session_stamp;

        $this->withToken($mine['access_token'])->putJson('/api/v1/admin/me/password', ['current_password' => 'TempPassw0rdXyz', 'password' => 'BrandNewPassw0rd!', 'password_confirmation' => 'BrandNewPassw0rd!'])->assertOk();

        expect(fn () => app(TokenService::class)->refresh($theirs['refresh_token']))->toThrow(DomainException::class);
        app('auth')->forgetGuards();
        $this->withToken($theirs['access_token'])->getJson('/api/v1/admin/me')->assertUnauthorized();
        app('auth')->forgetGuards();
        $this->withToken($mine['access_token'])->getJson('/api/v1/admin/me')->assertOk()->assertJsonPath('data.must_change_password', false);
        $again = app(TokenService::class)->refresh($mine['refresh_token']); // this device's own refresh token still works
        expect($again['access_token'])->not->toBeEmpty();
        expect((string) $emp->fresh()->session_stamp)->not->toBe($oldStamp); // the browsers are out too
    });

    it('keeps an old browser cookie dead even after the account is switched off and on again', function () {
        $emp = rvStaff($this->wb, ['home', 'orders_view']);
        $oldStamp = (string) $emp->session_stamp;
        $this->withSession(['session_stamp' => $oldStamp])->actingAs($emp)->get(route('admin.orders.index'))->assertOk();

        app(EmployeeService::class)->setStatus($this->super, $emp, 'suspended');
        app(EmployeeService::class)->setStatus($this->super, $emp->fresh(), 'active');

        $this->withSession(['session_stamp' => $oldStamp])->actingAs($emp->fresh())->get(route('admin.orders.index'))->assertRedirect(route('admin.login'));
        // signing in again works and carries the new stamp
        Livewire::test(Login::class)->set('email', $emp->email)->set('password', 'TempPassw0rdXyz')->call('login')->assertHasNoErrors();
        expect(session('session_stamp'))->toBe((string) $emp->fresh()->session_stamp);
    });

    it('ends every session and refresh token when an administrator resets the password', function () {
        $emp = rvStaff($this->wb, ['orders_view']);
        $tokens = app(TokenService::class)->issue($emp, 'phone', 'android');
        $oldStamp = (string) $emp->session_stamp;
        $this->actingAs($this->manager);
        Livewire::test(EmployeeEditor::class, ['employee' => $emp])->set('newPassword', 'An0therGoodPass')->call('resetPassword')->assertHasNoErrors();
        expect(fn () => app(TokenService::class)->refresh($tokens['refresh_token']))->toThrow(DomainException::class)
            ->and((string) $emp->fresh()->session_stamp)->not->toBe($oldStamp);
    });
});

describe('2 · the first-step gates look at the real route and the signed snapshots, never at headers', function () {
    it('refuses a page request that only claims to be a Livewire call from the profile page (password gate)', function () {
        $emp = rvStaff($this->wb, ['home', 'orders_view'], pastFirstSignIn: false);
        $this->actingAs($emp);
        $spoof = ['X-Livewire' => 'true', 'Referer' => route('admin.profile')];
        $this->withHeaders($spoof)->get(route('admin.orders.index'))->assertRedirect(route('admin.profile'));
        $this->withHeaders($spoof)->get(route('admin.dashboard'))->assertRedirect(route('admin.profile'));
        $this->withHeaders($spoof)->post(route('admin.logout'))->assertRedirect(); // signing out is allowed, nothing else
    });

    it('refuses a page request that only claims to be a Livewire call (two-factor enrolment gate)', function () {
        config(['platform.auth.staff_two_factor_required' => true]);
        $emp = rvStaff($this->wb, ['home', 'orders_view']);
        $this->actingAs($emp);
        $this->withHeaders(['X-Livewire' => 'true'])->get(route('admin.orders.index'))->assertRedirect(route('admin.profile'));
        $this->withHeaders(['X-Livewire' => 'true', 'Referer' => route('admin.profile')])->get(route('admin.dashboard'))->assertRedirect(route('admin.profile'));
    });

    it('lets only the profile component through while the password is still temporary, and the real password change works', function () {
        $emp = rvStaff($this->wb, ['home', 'orders_view', 'orders_create', 'products_view']);
        $this->actingAs($emp);
        $orders = rvSnapshots(route('admin.orders.index')); // a genuine snapshot of another page's component, signed by the server
        $profile = rvSnapshots(route('admin.profile'));
        expect($profile)->toHaveKey('admin.profile')->and($orders)->not->toBeEmpty();
        $emp->forceFill(['must_change_password' => true])->save();

        // a genuine call to another page's component is refused, even with the profile page as Referer
        foreach ($orders as $name => $snapshot) {
            rvLivewireCall($snapshot, 'closeOrderPeek')->assertForbidden();
        }
        $this->withHeaders(['Referer' => route('admin.profile')]);
        foreach ($orders as $snapshot) {
            rvLivewireCall($snapshot, '$refresh')->assertForbidden();
        }
        // a profile call that sneaks another component into the same request is refused as a whole
        $other = array_values($orders)[0];
        $this->postJson(route('default-livewire.update'), ['components' => [
            ['snapshot' => $profile['admin.profile'], 'updates' => [], 'calls' => []],
            ['snapshot' => $other, 'updates' => [], 'calls' => []],
        ]], ['X-Livewire' => 'true'])->assertForbidden();

        // the profile component's own call works: the password is changed
        rvLivewireCall($profile['admin.profile'], 'changePassword', ['current_password' => 'TempPassw0rdXyz', 'password' => 'BrandNewPassw0rd!', 'password_confirmation' => 'BrandNewPassw0rd!'])->assertOk();
        expect($emp->fresh()->must_change_password)->toBeFalse()->and(Hash::check('BrandNewPassw0rd!', $emp->fresh()->password))->toBeTrue();
    });

    it('lets only the profile component through while two-factor enrolment is pending', function () {
        $emp = rvStaff($this->wb, ['home', 'orders_view']);
        $this->actingAs($emp);
        $orders = rvSnapshots(route('admin.orders.index'));
        $profile = rvSnapshots(route('admin.profile'));
        config(['platform.auth.staff_two_factor_required' => true]);

        foreach ($orders as $snapshot) {
            rvLivewireCall($snapshot, '$refresh')->assertForbidden();
        }
        rvLivewireCall($profile['admin.profile'], 'startTwoFactor')->assertOk();
    });

    it('shows no cart components on the profile page while a first step is pending (their calls would be refused)', function () {
        $emp = rvStaff($this->wb, ['home', 'orders_view', 'orders_create', 'products_view'], pastFirstSignIn: false);
        $this->actingAs($emp);
        expect(array_keys(rvSnapshots(route('admin.profile'))))->toBe(['admin.profile']);
        $emp->forceFill(['must_change_password' => false])->save();
        $after = array_keys(rvSnapshots(route('admin.profile')));
        expect($after)->toContain('admin.profile')->and(count($after))->toBeGreaterThan(1);
    });
});

describe('3 · the dashboard sign-in limiter', function () {
    it('counts "+974 5555 1234", "55551234", "0097455551234" and Arabic digits as one person', function () {
        RateLimiter::clear('admin-login:+97455551234|127.0.0.1');
        RateLimiter::clear('admin-login-ip:127.0.0.1');
        foreach (['+974 5555 1234', '55551234', '0097455551234', '٥٥٥٥١٢٣٤', '974 5555-1234'] as $spelling) {
            Livewire::test(Login::class)->set('email', $spelling)->set('password', 'wrong-password')->call('login')->assertHasErrors(['email']);
        }
        // the sixth, in yet another spelling, is throttled (the message names the wait), not "wrong password"
        $msg = Livewire::test(Login::class)->set('email', '+97455551234')->set('password', 'wrong-password')->call('login')->assertHasErrors(['email'])->errors()->get('email')[0];
        expect($msg)->toBe(__('auth.throttle', ['seconds' => RateLimiter::availableIn('admin-login:+97455551234|127.0.0.1')]));
    });

    it('limits one machine guessing across many different accounts', function () {
        RateLimiter::clear('admin-login-ip:127.0.0.1');
        for ($i = 0; $i < 20; $i++) {
            Livewire::test(Login::class)->set('email', "nobody{$i}@albahar.qa")->set('password', 'wrong-password')->call('login')->assertHasErrors(['email']);
        }
        $known = rvStaff($this->wb, ['home'], ['email' => 'real@albahar.qa']);
        // even the right password of a real account is refused once the machine has used up its attempts
        Livewire::test(Login::class)->set('email', 'real@albahar.qa')->set('password', 'TempPassw0rdXyz')->call('login')->assertHasErrors(['email']);
        expect(auth()->guard('web')->check())->toBeFalse() && expect($known->isStaff())->toBeTrue();
    });

    it('checks a password even when the identifier matches no account, so the answer takes as long either way', function () {
        Hash::partialMock()->shouldReceive('check')->once()->andReturn(false);
        Livewire::test(Login::class)->set('email', 'nobody-at-all@albahar.qa')->set('password', 'whatever-it-is')->call('login')->assertHasErrors(['email']);
    });
});

describe('4 · editing an assignment keeps the personal feature checklist', function () {
    it('keeps the checklist and updates only the job title and driver flag from the dashboard form', function () {
        $emp = rvStaff($this->wb, ['home', 'orders_view', 'orders_prepare']);
        $before = app(BranchEmployeeService::class)->featuresOf($emp, $this->wb->id);
        $this->actingAs($this->manager);
        Livewire::test(EmployeeEditor::class, ['employee' => $emp, 'tab' => 'access'])
            ->call('editAssignment', $this->wb->id)->assertSet('assign.roles', [])
            ->set('assign.job_title', 'Counter lead')->set('assign.is_delivery_driver', true)->call('saveAssignment')->assertHasNoErrors();
        expect(app(BranchEmployeeService::class)->featuresOf($emp, $this->wb->id))->toEqualCanonicalizing($before)
            ->and(Role::where('personal_user_id', $emp->id)->count())->toBe(1)
            ->and(UserRole::where('user_id', $emp->id)->where('branch_id', $this->wb->id)->count())->toBe(1);
        $a = BranchEmployee::where('user_id', $emp->id)->first();
        expect($a->job_title)->toBe('Counter lead')->and($a->is_delivery_driver)->toBeTrue()->and($a->is_active)->toBeTrue();
        // the person can still use what they could
        expect(app(AccessControl::class)->allows($emp->fresh(), 'orders.edit', $this->wb->id))->toBeTrue();
    });

    it('refuses to swap the checklist for roles from that form, with a clear message, and changes nothing', function () {
        $emp = rvStaff($this->wb, ['home', 'orders_view']);
        $this->actingAs($this->manager);
        Livewire::test(EmployeeEditor::class, ['employee' => $emp, 'tab' => 'access'])
            ->set('assign.branch_id', $this->wb->id)->set('assign.roles', ['sales_employee'])->call('saveAssignment')
            ->assertDispatched('toast', type: 'error', message: __('branch_access.error_personal_role_replace'));
        expect(Role::where('personal_user_id', $emp->id)->count())->toBe(1)
            ->and(app(BranchEmployeeService::class)->featuresOf($emp, $this->wb->id))->toEqualCanonicalizing(['home', 'orders_view']);
    });

    it('does the same through the staff API', function () {
        $emp = rvStaff($this->wb, ['home', 'orders_view', 'orders_prepare']);
        actingAsStaff($this->manager);
        $this->postJson("/api/v1/admin/branches/{$this->wb->id}/employees", ['user_id' => $emp->id, 'roles' => [], 'job_title' => 'Lead'])->assertCreated();
        expect(app(BranchEmployeeService::class)->featuresOf($emp, $this->wb->id))->toEqualCanonicalizing(['home', 'orders_view', 'orders_prepare']);

        $this->postJson("/api/v1/admin/branches/{$this->wb->id}/employees", ['user_id' => $emp->id, 'roles' => ['sales_employee']])
            ->assertStatus(422)->assertJsonPath('code', 'personal_role_replace');
        expect(app(BranchEmployeeService::class)->featuresOf($emp, $this->wb->id))->toEqualCanonicalizing(['home', 'orders_view', 'orders_prepare']);

        // the branch's staff list never shows the personal role's key, only that custom access exists
        $rows = $this->getJson("/api/v1/admin/branches/{$this->wb->id}/employees")->assertOk()->json('data');
        $row = collect($rows)->firstWhere('user_id', $emp->id);
        expect($row['roles'])->toBe([])->and($row['custom_access'])->toBeTrue()->and(json_encode($rows))->not->toContain('personal_');
    });
});

describe('5 · after two-factor, people without the Home feature do not land on a 403', function () {
    it('lands a stock-only employee on their first allowed page', function () {
        $emp = rvStaff($this->wb, ['stock_view']);
        $tf = app(TwoFactorService::class);
        $secret = $tf->generateSecret();
        $emp->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($emp->fresh());
        Livewire::test(TwoFactorChallenge::class)->set('code', $tf->totp($secret, (int) floor(time() / 30)))->call('verify')->assertHasNoErrors()->assertRedirect(route('admin.inventory.index'));
    });
});

describe('6 · the live channels need the staff policy too', function () {
    it('refuses channel authorisation to a staff member who has not chosen their own password (apps and dashboard)', function () {
        $emp = rvStaff($this->wb, ['home', 'orders_view'], pastFirstSignIn: false);
        actingAsStaff($emp);
        $body = ['socket_id' => '123.456', 'channel_name' => 'private-users.'.$emp->id];
        $this->postJson('/api/v1/broadcasting/auth', $body)->assertForbidden()->assertJsonPath('code', 'password_change_required');

        auth()->guard('web')->logout();
        $this->actingAs($emp->fresh())->post('/broadcasting/auth', $body)->assertRedirect(route('admin.profile'));

        // once the password is their own the same request is no longer stopped by the staff policy
        $emp->forceFill(['must_change_password' => false])->save();
        app('auth')->forgetGuards();
        actingAsStaff($emp->fresh());
        expect($this->postJson('/api/v1/broadcasting/auth', $body)->status())->not->toBe(403);
    });

    it('does not get in a customer’s way', function () {
        $customer = actingAsCustomer();
        // (the test broadcaster answers an empty 200 when nothing stops the request)
        $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-users.'.$customer->id])->assertOk();
    });
});

describe('9 · opening an account needs employees.create at that branch', function () {
    it('refuses somebody who may create staff at one branch but only assign at another', function () {
        $create = Role::create(['key' => 'staff_creator', 'name_en' => 'Creator', 'name_ar' => 'منشئ', 'scope' => 'branch']);
        $create->permissions()->sync(Permission::whereIn('key', ['employees.create', 'employees.view'])->pluck('id'));
        $assign = Role::create(['key' => 'staff_assigner', 'name_en' => 'Assigner', 'name_ar' => 'معيّن', 'scope' => 'branch']);
        $assign->permissions()->sync(Permission::whereIn('key', ['employees.assign', 'employees.view', 'orders.view'])->pluck('id'));
        $actor = User::factory()->staff()->create();
        foreach ([[$create, $this->prl], [$assign, $this->wb]] as [$role, $branch]) {
            BranchEmployee::create(['branch_id' => $branch->id, 'user_id' => $actor->id, 'is_active' => true]);
            UserRole::create(['user_id' => $actor->id, 'role_id' => $role->id, 'branch_id' => $branch->id]);
        }
        $profile = ['name' => 'X', 'email' => 'nine@albahar.qa', 'password' => 'TempPassw0rdXyz'];
        // create at the Westbay (assign there, but create only at Pearl) and at Pearl (create there, but assign only at Westbay): both refused
        expect(fn () => app(BranchEmployeeService::class)->create($actor, $this->wb, $profile, ['orders_view']))->toThrow(DomainException::class);
        expect(fn () => app(BranchEmployeeService::class)->create($actor, $this->prl, $profile, ['orders_view']))->toThrow(DomainException::class);
        expect(User::where('email', 'nine@albahar.qa')->exists())->toBeFalse();
    });
});

describe('10 · current-password checks are rate limited', function () {
    it('stops guessing the current password on the change-password form, and the budget is shared with turning two-factor off', function () {
        config(['platform.auth.staff_two_factor_required' => false]);
        $emp = rvStaff($this->wb, ['home']);
        RateLimiter::clear('profile-current-password:'.$emp->id);
        $this->actingAs($emp);
        $page = Livewire::test(Profile::class);
        for ($i = 0; $i < 3; $i++) {
            $page->set('current_password', 'wrong-'.$i)->set('password', 'BrandNewPassw0rd!')->set('password_confirmation', 'BrandNewPassw0rd!')->call('changePassword')->assertHasErrors(['current_password']);
        }
        for ($i = 0; $i < 2; $i++) {
            $page->set('current_password', 'wrong-b'.$i)->call('disableTwoFactor')->assertHasErrors(['current_password']);
        }
        // five failures used up: even the right password is refused, with the plain message
        $page->set('current_password', 'TempPassw0rdXyz')->set('password', 'BrandNewPassw0rd!')->set('password_confirmation', 'BrandNewPassw0rd!')->call('changePassword')
            ->assertHasErrors(['current_password']);
        expect($page->errors()->get('current_password')[0])->toBe(__('errors.too_many_requests'))
            ->and(Hash::check('TempPassw0rdXyz', $emp->fresh()->password))->toBeTrue();
        RateLimiter::clear('profile-current-password:'.$emp->id);
        $page->set('current_password', 'TempPassw0rdXyz')->call('changePassword')->assertHasNoErrors(['current_password']);
    });
});

describe('7 · features that look company-wide stay branch-scoped', function () {
    it('never shows a branch-only grant the company-wide sign-up count, ticket count or refund count', function () {
        $emp = rvStaff($this->wb, ['home', 'orders_view', 'customers_view', 'reports_view', 'support_view', 'refunds_request']);
        $customer = User::factory()->create();
        foreach ([[$this->wb, 'T1'], [$this->prl, 'T2'], [$this->prl, 'T3']] as [$b, $n]) {
            SupportTicket::create(['number' => $n, 'user_id' => $customer->id, 'branch_id' => $b->id, 'category' => 'order', 'subject' => 'Where is my order', 'priority' => 'normal', 'status' => 'open']);
        }
        $mine = app(DashboardMetrics::class)->for($emp);
        $all = app(DashboardMetrics::class)->for($this->super);
        expect($mine['open_tickets'])->toBe(1)->and($all['open_tickets'])->toBe(3);

        $rows = fn (User $u) => collect(app(ReportService::class)->run($u, 'customers', now()->subDay()->toDateString(), now()->addDay()->toDateString())['rows'])->pluck('metric')->all();
        expect($rows($emp))->not->toContain('new_registrations')->and($rows($this->super))->toContain('new_registrations');
    });
});

describe('12 · personal roles do not leak into the branch editor', function () {
    it('shows custom access as a neutral marker, never the role key', function () {
        $emp = rvStaff($this->wb, ['home'], ['name' => 'Marker Person']);
        $key = Role::where('personal_user_id', $emp->id)->value('key');
        $this->actingAs($this->super);
        Livewire::test(BranchEditor::class, ['branch' => $this->wb, 'tab' => 'team'])->assertSee('Marker Person')->assertDontSee($key)
            ->assertSee(__('branch_access.personal_role'))->assertDontSee('Personal access'); // the neutral marker, not the internal role name
        expect($key)->toStartWith('personal_');
    });
});
