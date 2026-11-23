<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserRole;
use App\Domain\Reports\Services\SchedulerHeartbeat;
use App\Domain\Reports\Services\SystemHealth;
use App\Domain\Settings\Models\SystemSetting;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Organization\AuditIndex;
use App\Livewire\Admin\SystemHealthCard;
use App\Support\AdminNavigation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

/** Everything the array cache store holds, as one string, to look for a secret in. */
function oopsCacheDump(): string
{
    $store = Cache::store('array')->getStore();

    return serialize((fn () => $this->storage)->call($store));
}

describe('E · a secret setting is never written to the shared cache', function () {
    it('keeps decrypted provider secrets out of the cache, whatever is read', function () {
        $settings = app(Settings::class);
        $settings->set('payments.sadad.secret_key', 'SADAD-TOP-SECRET-VALUE-123', secret: true);
        $settings->set('ai.anthropic_api_key', 'sk-ant-ANOTHER-SECRET-456', secret: true);
        $settings->set('brand.tagline', 'Furniture for the majlis');

        // read it every way the application does
        expect($settings->get('payments.sadad.secret_key'))->toBe('SADAD-TOP-SECRET-VALUE-123')
            ->and($settings->get('ai.anthropic_api_key'))->toBe('sk-ant-ANOTHER-SECRET-456')
            ->and($settings->masked('payments.sadad.secret_key'))->toBe('••••••••-123'.'')
            ->and($settings->all()['payments.sadad.secret_key'])->toBe('SADAD-TOP-SECRET-VALUE-123')
            ->and($settings->get('brand.tagline'))->toBe('Furniture for the majlis');

        $dump = oopsCacheDump();
        expect($dump)->not->toContain('SADAD-TOP-SECRET-VALUE-123')->and($dump)->not->toContain('sk-ant-ANOTHER-SECRET-456')
            ->and($dump)->toContain('Furniture for the majlis');                  // ordinary settings are still cached
        $cached = Cache::get('system_settings:v2');
        // Only that they exist. The table is read without an order, so which comes first depends on where the database keeps the rows: not part of the promise.
        expect($cached['secrets'])->toEqual(['payments.sadad.secret_key' => true, 'ai.anthropic_api_key' => true])
            ->and($cached['values'])->not->toHaveKey('payments.sadad.secret_key');
    });

    it('does not read the old cache entry that still holds decrypted secrets, and overwrites it', function () {
        Cache::put('system_settings:v1', ['probe.secret' => 'LEAKED-FROM-THE-OLD-CACHE'], 300);
        $settings = app(Settings::class);

        expect($settings->get('probe.secret', 'none'))->toBe('none')                      // the old copy is not a source of settings
            ->and(oopsCacheDump())->not->toContain('LEAKED-FROM-THE-OLD-CACHE');                         // and the first rebuild replaced it
        $settings->set('payments.sadad.secret_key', 'the-real-one', secret: true);
        expect($settings->get('payments.sadad.secret_key'))->toBe('the-real-one')->and(oopsCacheDump())->not->toContain('LEAKED-FROM-THE-OLD-CACHE');
    });

    it('is emptied by forgetting the key the application has always forgotten, so older code that does so keeps working', function () {
        $settings = app(Settings::class);
        $settings->set('brand.tagline', 'Majlis');
        expect($settings->get('brand.tagline'))->toBe('Majlis');

        DB::table('system_settings')->where('key', 'tagline')->delete();                                  // a migration deletes the row...
        expect($settings->get('brand.tagline'))->toBe('Majlis');                                           // ...the cache still has it...
        Cache::forget('system_settings:v1');                                                              // ...until the key that has always been forgotten is
        expect($settings->get('brand.tagline'))->toBeNull();

        // and a server still on the previous release, reading that key as its own flat list, finds no setting in it instead of failing
        expect(array_key_exists('brand.tagline', Cache::get('system_settings:v1')))->toBeFalse();
    });

    it('decrypts a secret on demand in a fresh request, from its own row, and remembers it only for that request', function () {
        app(Settings::class)->set('payments.sadad.secret_key', 'SADAD-TOP-SECRET-VALUE-123', secret: true);
        app(Settings::class)->get('brand.warm');                                  // an earlier request has filled the shared cache
        app()->forgetScopedInstances();                                           // the next request or queue job: nothing remembered
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            if (str_contains($q->sql, 'system_settings')) {
                $queries[] = $q->sql;
            }
        });

        $settings = app(Settings::class);
        expect($settings->get('payments.sadad.secret_key'))->toBe('SADAD-TOP-SECRET-VALUE-123');
        $afterFirst = count($queries);
        expect($afterFirst)->toBe(1)                                              // the snapshot came from the cache; only the one secret row was read
            ->and($settings->get('payments.sadad.secret_key'))->toBe('SADAD-TOP-SECRET-VALUE-123')->and($settings->masked('payments.sadad.secret_key'))->not->toBeNull()
            ->and(count($queries))->toBe($afterFirst);                            // the second read in the same request costs nothing
        expect(app(Settings::class))->toBe($settings);                            // one instance per request: that is where the memory lives
        app()->forgetScopedInstances();
        expect(app(Settings::class))->not->toBe($settings);                       // and it does not outlive the request
    });

    it('applies a changed secret at once and a rotated value never reaches the cache', function () {
        $settings = app(Settings::class);
        $settings->set('payments.sadad.secret_key', 'first-secret-value', secret: true);
        expect($settings->get('payments.sadad.secret_key'))->toBe('first-secret-value');

        $settings->set('payments.sadad.secret_key', 'second-secret-value', secret: true);

        expect($settings->get('payments.sadad.secret_key'))->toBe('second-secret-value')->and(oopsCacheDump())->not->toContain('first-secret-value')->and(oopsCacheDump())->not->toContain('second-secret-value');
    });

    it('reads a secret that can no longer be decrypted as not set, without breaking the other settings', function () {
        $settings = app(Settings::class);
        $settings->set('brand.tagline', 'Still here');
        SystemSetting::create(['group' => 'payments', 'key' => 'broken', 'value' => ['encrypted' => 'not-a-valid-payload'], 'is_secret' => true]);
        $settings->forget();

        expect($settings->get('payments.broken', 'fallback'))->toBe('fallback')->and($settings->get('brand.tagline'))->toBe('Still here')
            ->and($settings->all())->toHaveKey('payments.broken');
    });

    it('still falls back to the config default and the given default for settings nobody saved', function () {
        $settings = app(Settings::class);
        expect($settings->get('returns.enabled'))->toBeTrue()->and($settings->get('nothing.here', 'x'))->toBe('x')->and($settings->get('inventory.low_stock_default'))->toBe(3);
    });
});

describe('C · the scheduler', function () {
    it('puts a short lock expiry on every overlap guard and a heartbeat hook on every artisan task', function () {
        Artisan::call('list');                                                    // loads routes/console.php
        $events = collect(app(Schedule::class)->events());
        $names = $events->map(fn ($e) => preg_match('/artisan[\'"]?\s+([A-Za-z0-9:_\-]+)/', (string) $e->command, $m) ? $m[1] : null)->filter()->values()->all();

        expect($names)->toContain(...array_keys(SchedulerHeartbeat::TASKS));          // every task the health card expects is really scheduled
        foreach ($events->filter(fn ($e) => $e->withoutOverlapping) as $e) {
            // withoutOverlapping() with no argument holds its lock for 1440 minutes: a deploy that kills a run would stall the task for a day
            expect($e->expiresAt)->toBeLessThanOrEqual(10)->and($e->expiresAt)->toBeGreaterThan(0);
        }
        expect($events->filter(fn ($e) => $e->withoutOverlapping)->count())->toBeGreaterThanOrEqual(8);
        // a hook on every event: running any of them leaves a heartbeat, known to the card or not
        foreach ($events as $e) {
            $before = count((new ReflectionProperty($e, 'beforeCallbacks'))->getValue($e));
            expect($before)->toBeGreaterThanOrEqual(1);
        }
    });

    it('reads how often a task runs from its schedule, so a task added later is watched without being listed anywhere', function () {
        $every = fn (string $cron) => SchedulerHeartbeat::everyMinutes(app(Schedule::class)->command('x')->cron($cron));
        expect($every('* * * * *'))->toBe(1)->and($every('*/5 * * * *'))->toBe(5)->and($every('15 2 * * *'))->toBe(1440)->and($every('0 9 * * 1'))->toBe(10080);

        // an unknown task with a heartbeat shows on the card, judged by its own interval
        DB::table('scheduler_heartbeats')->insert(['task' => 'orders:remind-unaccepted', 'last_started_at' => now()->subMinutes(30), 'last_finished_at' => now()->subMinutes(30), 'last_status' => 'ok',
            'every_minutes' => 1, 'run_count' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $task = collect(app(SystemHealth::class)->snapshot()['tasks'])->firstWhere('task', 'orders:remind-unaccepted');
        expect($task['state'])->toBe('overdue')->and($task['every_minutes'])->toBe(1);
    });

    it('records a heartbeat for every task when the scheduler runs it, and a failure when it fails', function () {
        Artisan::call('list');
        foreach (app(Schedule::class)->events() as $event) {
            if (! str_contains((string) $event->command, 'orders:expire-unpaid') && ! str_contains((string) $event->command, 'payments:reconcile-pending')) {
                continue;
            }
            $task = str_contains((string) $event->command, 'orders:expire-unpaid') ? 'orders:expire-unpaid' : 'payments:reconcile-pending';
            $event->callBeforeCallbacks(app());
            $running = DB::table('scheduler_heartbeats')->where('task', $task)->first();
            expect($running->last_status)->toBe('running')->and($running->last_started_at)->not->toBeNull();

            $event->exitCode = $task === 'orders:expire-unpaid' ? 0 : 1;
            $event->callAfterCallbacks(app());
        }

        $ok = DB::table('scheduler_heartbeats')->where('task', 'orders:expire-unpaid')->first();
        $failed = DB::table('scheduler_heartbeats')->where('task', 'payments:reconcile-pending')->first();
        expect($ok->last_status)->toBe('ok')->and($ok->last_finished_at)->not->toBeNull()->and($ok->last_error)->toBeNull()->and($ok->run_count)->toBe(1)
            ->and($failed->last_status)->toBe('failed')->and($failed->last_error)->toBe('command_failed');
    });

    it('counts runs and never lets a heartbeat problem break the task', function () {
        SchedulerHeartbeat::started('orders:expire-unpaid');
        SchedulerHeartbeat::finished('orders:expire-unpaid', true);
        SchedulerHeartbeat::started('orders:expire-unpaid');
        SchedulerHeartbeat::finished('orders:expire-unpaid', true);
        expect(DB::table('scheduler_heartbeats')->where('task', 'orders:expire-unpaid')->value('run_count'))->toBe(2);

        Schema::drop('scheduler_heartbeats');                                     // the table is gone: the task must still go on
        SchedulerHeartbeat::started('orders:expire-unpaid');
        SchedulerHeartbeat::finished('orders:expire-unpaid', false);
        expect(true)->toBeTrue();
    });

    it('writes a frequent task’s heartbeat at most twice a minute', function () {
        SchedulerHeartbeat::started('auctions:tick');
        SchedulerHeartbeat::finished('auctions:tick', true);
        $first = DB::table('scheduler_heartbeats')->where('task', 'auctions:tick')->first();
        SchedulerHeartbeat::started('auctions:tick');                              // five seconds later: skipped
        SchedulerHeartbeat::finished('auctions:tick', true);

        expect(DB::table('scheduler_heartbeats')->where('task', 'auctions:tick')->value('run_count'))->toBe(1)->and($first->last_status)->toBe('ok');
    });
});

describe('C · the system health card', function () {
    $beat = function (string $task, string $status, int $minutesAgo, ?int $startedMinutesAgo = null) {
        DB::table('scheduler_heartbeats')->insert(['task' => $task, 'last_started_at' => now()->subMinutes($startedMinutesAgo ?? $minutesAgo), 'last_finished_at' => $status === 'running' ? null : now()->subMinutes($minutesAgo),
            'last_status' => $status, 'run_count' => 5, 'created_at' => now(), 'updated_at' => now()]);
    };

    it('shows each task as on time, running, overdue, failed or never seen, by how often it should run', function () use ($beat) {
        $beat('orders:expire-unpaid', 'ok', 1);                // every minute, ran a minute ago
        $beat('inventory:expire-reservations', 'ok', 6);       // every minute, silent for 6: overdue
        $beat('payments:reconcile-pending', 'ok', 12);         // every 5 minutes, 12 minutes ago: still on time
        $beat('deals:refresh-feed', 'failed', 1);
        $beat('marketing:send-scheduled', 'running', 1);
        $beat('journeys:tick', 'running', 20, 20);             // started 20 minutes ago and never finished: the process died
        $beat('loyalty:expire-points', 'ok', 20 * 60);         // daily, 20 hours ago: on time
        $beat('banners:purge-deleted', 'ok', 40 * 60);         // daily, 40 hours ago: overdue

        $tasks = collect(app(SystemHealth::class)->snapshot()['tasks'])->pluck('state', 'task');

        expect($tasks['orders:expire-unpaid'])->toBe('ok')->and($tasks['inventory:expire-reservations'])->toBe('overdue')->and($tasks['payments:reconcile-pending'])->toBe('ok')
            ->and($tasks['deals:refresh-feed'])->toBe('failed')->and($tasks['marketing:send-scheduled'])->toBe('running')->and($tasks['journeys:tick'])->toBe('overdue')
            ->and($tasks['loyalty:expire-points'])->toBe('ok')->and($tasks['banners:purge-deleted'])->toBe('overdue')->and($tasks['auctions:tick'])->toBe('never')
            ->and(app(SystemHealth::class)->snapshot()['ok'])->toBeFalse();
    });

    it('says everything is fine only when every task is on time and the queue is healthy', function () use ($beat) {
        foreach (array_keys(SchedulerHeartbeat::TASKS) as $task) {
            $beat($task, 'ok', 1);
        }
        $snapshot = app(SystemHealth::class)->snapshot();
        expect($snapshot['scheduler_ok'])->toBeTrue()->and($snapshot['queue']['state'])->toBe('inline')->and($snapshot['ok'])->toBeTrue();
    });

    it('reads the queue that is configured: depth per queue, a backlog, and failed jobs', function () use ($beat) {
        foreach (array_keys(SchedulerHeartbeat::TASKS) as $task) {
            $beat($task, 'ok', 1);
        }
        config(['queue.default' => 'database']);
        $job = fn (string $queue) => ['queue' => $queue, 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()];
        DB::table('jobs')->insert([$job('default'), $job('default'), $job('notifications'), $job('exports')]);

        $q = app(SystemHealth::class)->snapshot()['queue'];
        expect($q['connection'])->toBe('database')->and($q['depth'])->toBe(['default' => 2, 'notifications' => 1, 'exports' => 1])->and($q['waiting'])->toBe(4)->and($q['failed'])->toBe(0)->and($q['state'])->toBe('ok');

        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);
        $q = app(SystemHealth::class)->snapshot()['queue'];
        expect($q['failed'])->toBe(1)->and($q['state'])->toBe('failed_jobs')->and(app(SystemHealth::class)->snapshot()['ok'])->toBeFalse();

        DB::table('jobs')->insert(array_fill(0, SystemHealth::QUEUE_BACKLOG + 1, $job('notifications')));
        expect(app(SystemHealth::class)->snapshot()['queue']['state'])->toBe('backlog');
    });

    it('is shown on the dashboard to a super admin and to a company-wide settings editor, and to no one else', function () use ($beat) {
        $beat('orders:expire-unpaid', 'ok', 1);
        $super = User::factory()->superAdmin()->create();
        $role = Role::create(['key' => 'settings_editor', 'name_en' => 'Settings editor', 'name_ar' => 'محرر الإعدادات', 'scope' => 'global', 'is_system' => false]);
        $role->permissions()->attach(Permission::whereIn('key', ['dashboard.view', 'settings.view', 'settings.edit'])->pluck('id'));
        $editor = User::factory()->staff()->create();
        UserRole::create(['user_id' => $editor->id, 'role_id' => $role->id, 'branch_id' => null]);
        $viewerOnly = staffWithRole('accountant');                                  // dashboard + finance, no settings
        $manager = staffWithRole('branch_manager', Branch::factory()->create());

        foreach ([$super, $editor] as $who) {
            $this->actingAs($who);
            Livewire::test(Dashboard::class)->assertSeeLivewire(SystemHealthCard::class);
            Livewire::test(SystemHealthCard::class)->assertSee(__('owner_numbers.health_title'))->assertSee(__('owner_numbers.task.orders:expire-unpaid'))->assertSeeHtml('data-system-health');
        }
        foreach ([$viewerOnly, $manager] as $who) {
            $this->actingAs($who);
            Livewire::test(Dashboard::class)->assertDontSeeLivewire(SystemHealthCard::class)->assertDontSee(__('owner_numbers.health_title'));
            Livewire::test(SystemHealthCard::class)->assertForbidden();
        }
    });

    it('speaks plainly in Arabic, shows when each task last ran and never anything secret', function () use ($beat) {
        $beat('orders:expire-unpaid', 'ok', 3);
        $beat('payments:reconcile-pending', 'failed', 2);
        $beat('journeys:tick', 'ok', 10);
        $this->actingAs(User::factory()->superAdmin()->create());
        app()->setLocale('ar');

        $page = Livewire::test(SystemHealthCard::class)->assertSee('سلامة النظام')->assertSee('فشل آخر تشغيل')->assertSee('متأخرة')->assertSee('لم تُرصد بعد');
        $html = $page->html();
        expect($html)->not->toContain('APP_KEY')->and($html)->not->toContain(config('app.key'))->and($html)->not->toContain(config('database.connections.pgsql.password') ?: 'no-password-set-x');
    });
});

describe('F · the indexes', function () {
    it('has an index on every foreign key the dashboard and the reports join on, and uses it', function () {
        $wanted = ['orders' => 'placed_at', 'order_fulfillments' => 'order_id', 'order_items' => 'order_id', 'payments' => 'order_id', 'refunds' => 'order_id', 'support_tickets' => 'branch_id'];
        foreach ($wanted as $table => $column) {
            $index = DB::selectOne('select i.relname as name, x.indisvalid as valid from pg_class t join pg_index x on x.indrelid = t.oid join pg_class i on i.oid = x.indexrelid
                join pg_attribute a on a.attrelid = t.oid and a.attnum = x.indkey[0] where t.relname = ? and a.attname = ? and x.indisvalid', [$table, $column]);
            expect($index)->not->toBeNull("{$table}.{$column} has no valid index");
        }

        // and the planner picks them for the lookups that matter (sequential scans are switched off so a tiny test table cannot hide a missing index)
        DB::statement('set local enable_seqscan = off');
        $plan = fn (string $sql) => collect(DB::select('explain '.$sql))->pluck('QUERY PLAN')->implode(' ');
        $id = (string) Str::uuid();
        expect($plan("select * from order_fulfillments where order_id = '{$id}'"))->toContain('order_fulfillments_order_id_index')
            ->and($plan("select * from order_items where order_id = '{$id}'"))->toMatch('/order_items_order_(id|report)_index/')   // the covering index of the reports starts with the same column
            ->and($plan("select * from payments where order_id = '{$id}'"))->toContain('payments_order_id_index')
            ->and($plan("select * from refunds where order_id = '{$id}'"))->toContain('refunds_order_id_index')
            ->and($plan("select * from support_tickets where branch_id = '{$id}'"))->toContain('support_tickets_branch_id_index')
            ->and($plan("select id from orders where placed_at >= now() - interval '1 day'"))->toContain('orders_placed_at_index');
    });
});

describe('G · small things', function () {
    it('never offers a menu entry whose page does not exist', function () {
        $super = User::factory()->superAdmin()->create();
        $routes = collect(AdminNavigation::for($super))->flatMap(fn ($g) => array_column($g['items'], 'route'));

        expect($routes->reject(fn ($r) => Route::has($r))->all())->toBe([]);
        if (! Route::has('admin.delivery-providers.index')) {
            expect($routes->all())->not->toContain('admin.delivery-providers.index');
        }
    });

    it('shows the last 30 days of the audit log unless dates are picked, and says so', function () {
        $this->actingAs(User::factory()->superAdmin()->create());
        $row = fn (string $action, $when) => AuditLog::create(['action' => $action, 'created_at' => $when]);
        $row('settings.recent', now()->subDays(5));
        $row('settings.old', now()->subDays(45));

        $default = Livewire::test(AuditIndex::class)->assertSee(__('owner_numbers.audit_window_default'));
        expect($default->viewData('logs')->pluck('action')->all())->toBe(['settings.recent']);

        $picked = Livewire::test(AuditIndex::class)->set('from', now()->subDays(60)->toDateString())->assertDontSee(__('owner_numbers.audit_window_default'));
        expect($picked->viewData('logs')->pluck('action')->sort()->values()->all())->toBe(['settings.old', 'settings.recent']);

        // only a last day: thirty days before that day
        $to = Livewire::test(AuditIndex::class)->set('to', now()->subDays(40)->toDateString());
        expect($to->viewData('logs')->pluck('action')->all())->toBe(['settings.old']);

        // one record's own trail has no date limit: the record bounds it
        $record = AuditLog::create(['action' => 'order.placed', 'auditable_type' => 'Order', 'auditable_id' => 'abc', 'created_at' => now()->subDays(200)]);
        $trail = Livewire::test(AuditIndex::class)->set('subject', 'Order:abc');
        expect($trail->viewData('logs')->pluck('id')->all())->toBe([$record->id]);
        $trail->assertDontSee(__('owner_numbers.audit_window_default'));
    });

    it('counts the audit log only up to 1,000 and shows 1,000+ instead of counting every row', function () {
        $this->actingAs(User::factory()->superAdmin()->create());
        $rows = [];
        for ($i = 0; $i < 1100; $i++) {
            $rows[] = ['action' => 'settings.bulk', 'created_at' => now()->subMinutes($i)];
        }
        DB::table('audit_logs')->insert($rows);
        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            $sql[] = $q->sql;
        });

        $page = Livewire::test(AuditIndex::class)->assertSee('1,000+')->assertSeeHtml('data-audit-capped')->assertSee(__('owner_numbers.audit_more', ['count' => '1,000']));

        expect($page->viewData('logs')->total())->toBe(1000)->and($page->viewData('logs')->lastPage())->toBe(50)->and($page->viewData('logs'))->toHaveCount(20);
        expect(collect($sql)->contains(fn ($q) => str_contains($q, 'select count(*) as aggregate from "audit_logs"')))->toBeFalse()      // never the unbounded count
            ->and(collect($sql)->contains(fn ($q) => str_contains($q, 'limit 1001')))->toBeTrue();
        $page->call('nextPage');
        expect($page->viewData('logs')->currentPage())->toBe(2);
        $page->set('search', 'no-such-action-anywhere')->assertDontSee('1,000+');
    });

    it('does not mention a cap when the whole log fits, and shows the exact count of a page', function () {
        $this->actingAs(User::factory()->superAdmin()->create());
        DB::table('audit_logs')->insert(array_map(fn ($i) => ['action' => 'settings.few', 'created_at' => now()->subMinutes($i)], range(1, 45)));

        $page = Livewire::test(AuditIndex::class)->assertDontSeeHtml('data-audit-capped')->assertSee(__('owner_numbers.audit_showing', ['from' => 1, 'to' => 20, 'total' => 45]));
        expect($page->viewData('logs')->total())->toBe(45);
    });
});
