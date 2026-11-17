<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Branches\Services\BranchEmployeeService;
use App\Domain\Branches\Services\EmployeeService;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserSession;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Identity\Services\TokenService;
use App\Domain\Identity\Support\BranchFeatureCatalog;
use App\Domain\Orders\Models\Order;
use App\Livewire\Admin\Auth\Login;
use App\Livewire\Admin\Profile;
use App\Livewire\Admin\Sales\OrderIndex;
use App\Support\AdminNavigation;
use App\Support\DomainException;
use App\Support\StaffLanding;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;

/**
 * What a branch employee can really do once an administrator has ticked their features: exactly that, at their own branch only,
 * on the dashboard (tablet, computer or phone: same server rules) and in the staff API the Flutter app uses.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->super = User::factory()->superAdmin()->create();
});

/** A branch employee with a known password, made the way the dashboard makes one. */
function branchStaff(Branch $branch, array $features, array $profile = []): User
{
    $u = app(BranchEmployeeService::class)->create(test()->super, $branch, $profile + [
        'name' => 'Branch Person', 'email' => 'person'.random_int(10000, 99999).'@albahar.qa', 'phone' => null, 'locale' => 'en', 'job_title' => null,
        'password' => 'TempPassw0rdXyz',
    ], $features);
    $u->forceFill(['must_change_password' => false])->save(); // past the first sign-in unless a test says otherwise

    return $u;
}

/** An order of branch A, placed as a customer; leaves the session as the customer. Returns [world, order id, fulfillment]. */
function orderAtBranchA(): array
{
    $w = commerceWorld();
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1]);
    $orderId = quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->json('data.order.id');

    return [$w, $orderId, Order::with('fulfillments')->find($orderId)->fulfillments->first()];
}

describe('on the dashboard', function () {
    it('opens exactly the pages that were ticked and answers 403 to everything else', function () {
        [$w] = orderAtBranchA();
        $emp = branchStaff($w->a, ['home', 'orders_view', 'orders_prepare']);
        $order = Order::latest('created_at')->firstOrFail();
        $this->actingAs($emp);

        foreach (['admin.dashboard', 'admin.orders.index', 'admin.sales.abandoned', 'admin.returns.index'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('admin.orders.show', $order))->assertOk();
        foreach (['admin.inventory.index', 'admin.adjustments.index', 'admin.transfers.index', 'admin.cash.index', 'admin.payments.index', 'admin.refunds.index',
            'admin.pickup.index', 'admin.deliveries.index', 'admin.customers.index', 'admin.products.index', 'admin.reports.index', 'admin.exports.index',
            'admin.employees.index', 'admin.employees.create', 'admin.roles.index', 'admin.settings.index', 'admin.audit.index', 'admin.branches.index',
            'admin.promotions.index', 'admin.homepage.index', 'admin.push.index'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
    });

    it('shows a menu with only the permitted items, and the same items open', function () {
        $w = commerceWorld();
        $emp = branchStaff($w->a, ['home', 'orders_view', 'stock_view', 'products_view']);
        $acl = app(AccessControl::class);
        $everything = collect(AdminNavigation::for($this->super))->flatMap(fn ($g) => $g['items']);
        $mine = collect(AdminNavigation::for($emp))->flatMap(fn ($g) => $g['items']);

        // every visible item is permitted, every hidden one is not
        foreach ($everything as $item) {
            expect($mine->contains('route', $item['route']))->toBe($acl->allows($emp, $item['permission']), $item['route']);
        }
        expect($mine->pluck('route')->all())->toContain('admin.orders.index', 'admin.inventory.index', 'admin.products.index')
            ->not->toContain('admin.cash.index', 'admin.employees.index', 'admin.roles.index', 'admin.settings.index', 'admin.audit.index', 'admin.promotions.index');

        // a hidden item is also closed when typed into the address bar
        $this->actingAs($emp);
        foreach ($everything as $item) {
            if (! $mine->contains('route', $item['route']) && ! str_contains($item['route'], '{')) {
                $this->get(route($item['route']))->assertForbidden();
            }
        }
    });

    it('keeps a branch employee out of another branch’s orders, stock and cash', function () {
        [$w, $orderId] = orderAtBranchA();
        $other = branchStaff($w->b, ['home', 'orders_view', 'orders_prepare', 'stock_view', 'cash_view']);
        $this->actingAs($other);
        $this->get(route('admin.orders.show', $orderId))->assertStatus(403);
        Livewire::test(OrderIndex::class)->assertDontSee(Order::find($orderId)->number);
        // ... and the same person at the right branch sees it
        $mine = branchStaff($w->a, ['home', 'orders_view']);
        $this->actingAs($mine)->get(route('admin.orders.show', $orderId))->assertOk();
    });

    it('signs a person out of the dashboard the moment they are disabled', function () {
        $w = commerceWorld();
        $emp = branchStaff($w->a, ['home', 'orders_view']);
        $this->actingAs($emp)->get(route('admin.orders.index'))->assertOk();
        app(EmployeeService::class)->setStatus($this->super, $emp, 'suspended');
        $this->actingAs($emp->fresh())->get(route('admin.orders.index'))->assertRedirect(route('admin.login'));
        app(EmployeeService::class)->setStatus($this->super, $emp->fresh(), 'active');
        // switched on again: a browser that signs in afresh (it carries the account's new stamp) works; the old cookie does not (StaffSecurityReviewTest)
        $this->withSession(['session_stamp' => (string) $emp->fresh()->session_stamp])->actingAs($emp->fresh())->get(route('admin.orders.index'))->assertOk();
    });
});

describe('in the staff API (the staff app)', function () {
    it('lets an employee with only "see + accept orders" read and move orders but reach neither stock nor the cash drawer', function () {
        [$w, $orderId, $f] = orderAtBranchA();
        $emp = branchStaff($w->a, ['orders_view', 'orders_prepare']);
        actingAsStaff($emp);

        $me = $this->getJson('/api/v1/admin/me')->assertOk()->json('data');
        expect($me['permissions'])->toEqualCanonicalizing(['orders.view', 'orders.edit'])->and($me['is_super_admin'])->toBeFalse()
            ->and(collect($me['branches'])->pluck('id')->all())->toBe([$w->a->id]);

        $this->getJson('/api/v1/admin/dispatch/overview')->assertOk();
        $this->getJson('/api/v1/admin/dispatch/queue')->assertOk();
        $this->getJson("/api/v1/admin/orders/{$orderId}")->assertOk();
        $this->postJson("/api/v1/admin/fulfillments/{$f->id}/transition", ['status' => 'accepted'])->assertOk();

        foreach ([
            ['getJson', '/api/v1/admin/inventory/stock'], ['getJson', '/api/v1/admin/inventory/movements'], ['postJson', '/api/v1/admin/inventory/adjustments'],
            ['getJson', '/api/v1/admin/cash/outstanding'], ['getJson', '/api/v1/admin/cash/handovers'], ['postJson', '/api/v1/admin/cash/handovers'],
            ['getJson', '/api/v1/admin/inventory/transfers'], ['postJson', "/api/v1/admin/orders/{$orderId}/cancel"], ['getJson', '/api/v1/admin/dispatch/drivers'],
            ['postJson', "/api/v1/admin/dispatch/fulfillments/{$f->id}/assign"], ['getJson', '/api/v1/admin/branches'], ['postJson', '/api/v1/admin/exports'],
            ['getJson', '/api/v1/admin/catalog/products'],
        ] as [$method, $url]) {
            $this->{$method}($url)->assertForbidden();
        }
    });

    it('lets a different branch’s employee neither read nor move this branch’s orders', function () {
        [$w, $orderId, $f] = orderAtBranchA();
        $other = branchStaff($w->b, ['orders_view', 'orders_prepare']);
        actingAsStaff($other);
        expect($this->getJson("/api/v1/admin/orders/{$orderId}")->status())->toBeIn([403, 404]);
        $this->postJson("/api/v1/admin/fulfillments/{$f->id}/transition", ['status' => 'accepted'])->assertForbidden();
        expect($this->getJson('/api/v1/admin/orders')->json('data'))->toBeEmpty();
        expect($this->getJson('/api/v1/admin/dispatch/queue')->json('data'))->toBeEmpty();
    });

    it('grants a stock keeper the stock pages and nothing on orders', function () {
        $w = commerceWorld();
        $keeper = branchStaff($w->a, BranchFeatureCatalog::presets()['stock_keeper']);
        actingAsStaff($keeper);
        $this->getJson('/api/v1/admin/inventory/stock')->assertOk();
        $this->getJson('/api/v1/admin/inventory/movements')->assertOk();
        $this->getJson('/api/v1/admin/orders')->assertForbidden();
        $this->getJson('/api/v1/admin/dispatch/queue')->assertForbidden();
        $this->getJson('/api/v1/admin/cash/outstanding')->assertForbidden();
        // and the other branch's stock is empty for them
        $rows = collect($this->getJson('/api/v1/admin/inventory/stock')->json('data'));
        expect($rows->pluck('branch_id')->unique()->filter()->all())->each->toBe($w->a->id);
    });

    it('signs in with a mobile number alone, and the answer says the password is temporary', function () {
        $w = commerceWorld();
        $emp = app(BranchEmployeeService::class)->create($this->super, $w->a, ['name' => 'Phone Only', 'email' => null, 'phone' => '+97455123456', 'locale' => 'en', 'password' => 'TempPassw0rdXyz'], ['orders_view']);
        $this->postJson('/api/v1/admin/auth/login', ['email' => '5512 3456', 'password' => 'TempPassw0rdXyz', 'platform' => 'android'])
            ->assertOk()->assertJsonPath('data.must_change_password', true)->assertJsonPath('data.user.phone', '+97455123456');
        $this->postJson('/api/v1/admin/auth/login', ['phone' => '+974 5512 3456', 'password' => 'TempPassw0rdXyz'])->assertOk();
        $this->postJson('/api/v1/admin/auth/login', ['email' => '5512 3456', 'password' => 'wrong-password'])->assertUnauthorized();
        expect($emp->fresh()->last_login_at)->not->toBeNull();
    });

    it('holds everything back until the person has chosen their own password', function () {
        [$w, $orderId] = orderAtBranchA();
        $emp = app(BranchEmployeeService::class)->create($this->super, $w->a, ['name' => 'First Day', 'email' => 'first@albahar.qa', 'phone' => null, 'locale' => 'en', 'password' => 'TempPassw0rdXyz'], ['orders_view']);
        $token = $this->postJson('/api/v1/admin/auth/login', ['email' => 'first@albahar.qa', 'password' => 'TempPassw0rdXyz'])->assertOk()->json('data.tokens.access_token');
        $auth = ['Authorization' => 'Bearer '.$token];
        app('auth')->forgetGuards(); // the customer from the order fixture is still "signed in" inside this test

        $this->getJson('/api/v1/admin/me', $auth)->assertOk()->assertJsonPath('data.must_change_password', true);
        $this->getJson("/api/v1/admin/orders/{$orderId}", $auth)->assertForbidden()->assertJsonPath('code', 'password_change_required');
        $this->getJson('/api/v1/admin/dispatch/queue', $auth)->assertForbidden()->assertJsonPath('code', 'password_change_required');

        // wrong current password, too weak, same as before, not confirmed: all refused
        $this->putJson('/api/v1/admin/me/password', ['current_password' => 'nope', 'password' => 'BrandNewPassw0rd!', 'password_confirmation' => 'BrandNewPassw0rd!'], $auth)->assertStatus(422);
        $this->putJson('/api/v1/admin/me/password', ['current_password' => 'TempPassw0rdXyz', 'password' => 'weak', 'password_confirmation' => 'weak'], $auth)->assertStatus(422);
        $this->putJson('/api/v1/admin/me/password', ['current_password' => 'TempPassw0rdXyz', 'password' => 'TempPassw0rdXyz', 'password_confirmation' => 'TempPassw0rdXyz'], $auth)->assertStatus(422);
        $this->putJson('/api/v1/admin/me/password', ['current_password' => 'TempPassw0rdXyz', 'password' => 'BrandNewPassw0rd!', 'password_confirmation' => 'other'], $auth)->assertStatus(422);
        expect($emp->fresh()->must_change_password)->toBeTrue();

        $this->putJson('/api/v1/admin/me/password', ['current_password' => 'TempPassw0rdXyz', 'password' => 'BrandNewPassw0rd!', 'password_confirmation' => 'BrandNewPassw0rd!'], $auth)->assertOk();
        expect($emp->fresh()->must_change_password)->toBeFalse();
        app('auth')->forgetGuards(); // a test keeps the first request's user in the guard; a real request starts fresh
        $this->getJson("/api/v1/admin/orders/{$orderId}", $auth)->assertOk();
        $this->postJson('/api/v1/admin/auth/login', ['email' => 'first@albahar.qa', 'password' => 'BrandNewPassw0rd!'])->assertOk();
        $this->postJson('/api/v1/admin/auth/login', ['email' => 'first@albahar.qa', 'password' => 'TempPassw0rdXyz'])->assertUnauthorized();
    });
});

describe('first sign-in on the dashboard', function () {
    it('sends the person to their profile until they have chosen their own password', function () {
        $w = commerceWorld();
        $emp = app(BranchEmployeeService::class)->create($this->super, $w->a, ['name' => 'First Day', 'email' => 'first@albahar.qa', 'phone' => null, 'locale' => 'en', 'password' => 'TempPassw0rdXyz'], ['home', 'orders_view']);
        $this->actingAs($emp);
        foreach (['admin.dashboard', 'admin.orders.index', 'admin.customers.index'] as $route) {
            $this->get(route($route))->assertRedirect(route('admin.profile'));
        }
        $this->get(route('admin.profile'))->assertOk()->assertSee(__('branch_access.must_change_title'));
        // a dashboard action from any other page is refused too; only the profile page's own actions pass
        // a page request that merely claims to be a Livewire call (headers are client-supplied) is not let through (see StaffSecurityReviewTest for real Livewire calls)
        $this->withHeaders(['X-Livewire' => 'true', 'Referer' => route('admin.orders.index')])->get(route('admin.orders.index'))->assertRedirect(route('admin.profile'));
        $this->withHeaders(['X-Livewire' => 'true', 'Referer' => route('admin.profile')])->get(route('admin.orders.index'))->assertRedirect(route('admin.profile'));

        Livewire::test(Profile::class)->set('current_password', 'TempPassw0rdXyz')->set('password', 'BrandNewPassw0rd!')->set('password_confirmation', 'BrandNewPassw0rd!')
            ->call('changePassword')->assertHasNoErrors()->assertRedirect(route('admin.dashboard'));
        expect($emp->fresh()->must_change_password)->toBeFalse();
        $this->get(route('admin.orders.index'))->assertOk();
    });

    it('signs in with an e-mail or a mobile number', function () {
        $w = commerceWorld();
        $emp = branchStaff($w->a, ['home', 'orders_view'], ['email' => 'both@albahar.qa', 'phone' => '+97455123456', 'password' => 'TempPassw0rdXyz']);
        foreach (['both@albahar.qa', 'BOTH@albahar.qa', '+974 5512 3456', '55123456'] as $who) {
            auth()->guard('web')->logout();
            Livewire::test(Login::class)->set('email', $who)->set('password', 'TempPassw0rdXyz')->call('login')->assertHasNoErrors()->assertRedirect();
            expect(auth()->guard('web')->id())->toBe($emp->id);
        }
        auth()->guard('web')->logout();
        Livewire::test(Login::class)->set('email', '55123456')->set('password', 'wrong')->call('login')->assertHasErrors(['email']);
        Livewire::test(Login::class)->set('email', '12345')->set('password', 'TempPassw0rdXyz')->call('login')->assertHasErrors(['email']);
    });
});

describe('landing page', function () {
    it('lands on the dashboard when the features allow it, otherwise on the first page of the person’s own menu', function () {
        $w = commerceWorld();
        $stockOnly = branchStaff($w->a, ['stock_view'], ['phone' => '+97455123457', 'password' => 'TempPassw0rdXyz']);
        $home = branchStaff($w->a, ['home', 'orders_view']);
        $driver = driverFor($w->a);
        expect(StaffLanding::url($stockOnly))->toBe(route('admin.inventory.index'))
            ->and(StaffLanding::url($home))->toBe(route('admin.dashboard'))
            ->and(StaffLanding::url($driver))->toBe(route('admin.dashboard'))      // drivers keep the dashboard (deliveries.view)
            ->and(StaffLanding::url($this->super))->toBe(route('admin.dashboard'));

        // signing in, and choosing the first password, both go there instead of a 403 page
        Livewire::test(Login::class)->set('email', '5512 3457')->set('password', 'TempPassw0rdXyz')->call('login')->assertRedirect(route('admin.inventory.index'));
        auth()->guard('web')->logout();
        $stockOnly->forceFill(['must_change_password' => true])->save();
        $this->actingAs($stockOnly->fresh());
        Livewire::test(Profile::class)->set('current_password', 'TempPassw0rdXyz')->set('password', 'BrandNewPassw0rd!')->set('password_confirmation', 'BrandNewPassw0rd!')
            ->call('changePassword')->assertRedirect(route('admin.inventory.index'));
    });
});

describe('disabling and removing', function () {
    it('revokes every token and session at once, and refresh no longer works', function () {
        config(['session.driver' => 'database']);
        $w = commerceWorld();
        $emp = branchStaff($w->a, ['orders_view']);
        $tokens = app(TokenService::class)->issue($emp, 'phone', 'android');
        DB::table('sessions')->insert(['id' => 'sess-'.$emp->id, 'user_id' => $emp->id, 'payload' => 'x', 'last_activity' => time()]);
        expect(PersonalAccessToken::where('tokenable_id', $emp->id)->count())->toBe(1);

        app(EmployeeService::class)->setStatus($this->super, $emp, 'suspended');

        expect(PersonalAccessToken::where('tokenable_id', $emp->id)->count())->toBe(0)
            ->and(UserSession::where('user_id', $emp->id)->whereNull('revoked_at')->count())->toBe(0)
            ->and(DB::table('sessions')->where('user_id', $emp->id)->count())->toBe(0);
        expect(fn () => app(TokenService::class)->refresh($tokens['refresh_token']))->toThrow(DomainException::class);
        // the old bearer token is dead
        app('auth')->forgetGuards();
        $this->withToken($tokens['access_token'])->getJson('/api/v1/admin/me')->assertUnauthorized();
    });

    it('ends the sessions when the last branch is taken away or switched off, but not while another branch still works', function () {
        $w = commerceWorld();
        $emp = branchStaff($w->a, ['orders_view']);
        app(EmployeeService::class)->assignToBranch($this->super, $emp, $w->b, ['sales_employee']);
        app(TokenService::class)->issue($emp, 'phone', 'android');

        app(EmployeeService::class)->setBranchAssignmentActive($this->super, BranchEmployee::with('user')->where('user_id', $emp->id)->where('branch_id', $w->a->id)->first(), false);
        expect(PersonalAccessToken::where('tokenable_id', $emp->id)->count())->toBe(1); // still works at the other branch

        app(EmployeeService::class)->removeFromBranch($this->super, $emp, $w->b);
        expect(PersonalAccessToken::where('tokenable_id', $emp->id)->count())->toBe(0);
        app(AccessControl::class)->forget();
        expect(app(AccessControl::class)->allows($emp, 'orders.view'))->toBeFalse();
    });

    it('drops the permissions of a disabled branch assignment straight away', function () {
        $w = commerceWorld();
        $emp = branchStaff($w->a, ['orders_view', 'orders_prepare']);
        $acl = app(AccessControl::class);
        expect($acl->allows($emp, 'orders.edit', $w->a->id))->toBeTrue();
        BranchEmployee::where('user_id', $emp->id)->update(['is_active' => false]);
        $acl->forget();
        expect($acl->allows($emp, 'orders.edit', $w->a->id))->toBeFalse();
    });
});
