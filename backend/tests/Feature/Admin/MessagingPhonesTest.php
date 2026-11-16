<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Models\CustomerMessage;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Messaging\CustomerTimeline;
use App\Livewire\Admin\Messaging\MessageComposer;
use App\Livewire\Admin\Messaging\MessageHistory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

require_once __DIR__.'/MessagingSupport.php';
require_once __DIR__.'/../Push/PushSupport.php';
require_once __DIR__.'/../Push/PhoneStateSupport.php';

/*
 * The team is told when a push would be accepted by Google but not shown (Android 13+ hides it until the person allows notifications):
 * the "Phones" line and the warning in the message window, the note on sent messages in the customer's list and in the history, and the
 * count in a bulk send. The message itself is never held back: the inbox entry always arrives.
 */

/** A customer who agreed to marketing, with one phone per entry: 'granted', 'denied', 'not_asked' or null (never reported). */
function mpCustomer(array $permissions, array $attrs = []): User
{
    $customer = mcCustomer($attrs);
    foreach ($permissions as $permission) {
        npDevice($customer, $permission);
    }

    return $customer;
}

/** The customer places a pay-at-pickup order at the branch, so branch staff may see and message them. */
function mpOrderAt(object $w, Branch $branch, User $customer): void
{
    actingAsCustomer($customer);
    $customer->addresses()->create(['recipient_name' => $customer->name, 'recipient_phone' => '+97455123456', 'zone_number' => '66', 'street_number' => '850', 'building_number' => '12', 'is_default' => true]);
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1])->assertSuccessful();
    quoteAndPlace(['fulfillment_type' => 'pickup', 'branch_id' => $branch->id, 'payment_method' => 'pay_at_pickup'])->assertCreated();
}

/** @return int how many queries read the phones while the callback runs */
function mpPhoneQueries(Closure $run): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $run();
    $count = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'user_devices'))->count();
    DB::disableQueryLog();

    return $count;
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->staff = staffWithRole('marketing_manager', null, ['name' => 'Sara Team']);
    app(Settings::class)->set('messaging.cooldown_hours', 0);
    configureFirebase();
    npFake();
    $this->actingAs($this->staff);
});

// The five things the dashboard can say about one customer's phones: [phones, state, the line in English, does the notification option warn?]
dataset('phone states', [
    'no phone registered' => [[], 'none', 'No phone registered', true],
    'notifications on' => [['granted'], 'on', '1 phone · notifications on', false],
    'not reported yet (older app)' => [[null], 'unknown', '1 phone · notification setting not reported yet', false],
    'notifications off' => [['denied'], 'off', '1 phone · notifications off: a push will not show, the inbox message still arrives', true],
    'not asked yet' => [['not_asked'], 'not_asked', '1 phone · not asked yet: a push will not show until the customer allows notifications, the inbox message still arrives', true],
]);

// ── The message window, one customer ─────────────────────────────────────────────────────────────────────────────────

describe('the message window for one customer', function () {
    it('says in plain words what the customer\'s phones can do, and warns on the notification option only when no phone can show a push', function (array $phones, string $state, string $line, bool $warns) {
        $c = mpCustomer($phones);

        $comp = mcOpen([$c->id])->assertSeeHtml('data-testid="phones-line"')->assertSeeHtml('data-state="'.$state.'"')->assertSee(__('messaging_phones.label'))->assertSee($line);

        if ($warns) {
            $comp->assertSeeHtml('data-testid="phones-warning"')->assertSeeHtml('data-state="'.$state.'"')->assertSee(__('messaging_phones.warn.'.$state.'_title'))
                ->assertSee('The message will still arrive in the app inbox.');
        } else {
            $comp->assertDontSeeHtml('data-testid="phones-warning"');
        }
    })->with('phone states');

    it('tells the team how to fix it: ask the customer to allow notifications in the app', function (string $permission, string $why) {
        $c = mpCustomer([$permission]);

        mcOpen([$c->id])->assertSee($why)->assertSee('ask the customer to allow notifications in the app: Settings → Notifications');
    })->with([
        'turned off' => ['denied', 'Noor has notifications turned off'],
        'never asked' => ['not_asked', 'Noor has not been asked to allow notifications yet'],
    ]);

    it('says there is nothing to push to when no phone is registered, with no instruction to fix', function () {
        $c = mpCustomer([]);

        mcOpen([$c->id])->assertSee('Noor has no phone registered, so there is nothing to push to.')->assertDontSee('Settings → Notifications');
    });

    it('words a customer with several phones by what they can do together', function () {
        $mixed = mpCustomer(['granted', 'denied', 'not_asked']);
        $allOff = mpCustomer(['denied', 'not_asked']);
        $unsure = mpCustomer(['denied', null]);

        mcOpen([$mixed->id])->assertSee('3 phones · a push shows on 1 of them')->assertDontSeeHtml('data-testid="phones-warning"');
        mcOpen([$allOff->id])->assertSeeHtml('data-state="off"')->assertSee('2 phones · notifications off')->assertSeeHtml('data-testid="phones-warning"');
        mcOpen([$unsure->id])->assertSeeHtml('data-state="unknown"')->assertDontSeeHtml('data-testid="phones-warning"'); // one phone may still show it
    });

    it('still sends: the inbox message arrives and the phone is pushed anyway', function () {
        $c = mpCustomer(['denied']);

        mcOpen([$c->id])->assertSeeHtml('data-testid="phones-warning"')->call('requestSend')->assertSet('step', 'sent')->assertSee(__('messaging_composer.done_sent'));

        expect(Notification::where('user_id', $c->id)->where('event', 'staff.message')->count())->toBe(1)
            ->and(CustomerMessage::where('user_id', $c->id)->first())->status->toBe('sent')
            ->and(CustomerMessage::where('user_id', $c->id)->first()->meta)->toMatchArray(['devices' => 1, 'pushed' => 1, 'silent' => 1]);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'messages:send'));
    });

    it('does not warn about phones when push is not set up at all (that has its own note), or when notification is not ticked', function () {
        $c = mpCustomer(['denied']);
        configureFirebase(enabled: false);

        mcOpen([$c->id])->assertSee(__('messaging_composer.push_off'))->assertDontSeeHtml('data-testid="phones-warning"')->assertSeeHtml('data-testid="phones-line"');

        configureFirebase();
        mcConnectN8n();
        mcOpen([$c->id])->assertSeeHtml('data-testid="phones-warning"')->set('channels', ['whatsapp'])->assertDontSeeHtml('data-testid="phones-warning"');
    });

    it('reads the phones fresh each time it is drawn, with a single query for the one customer', function () {
        $c = mpCustomer(['not_asked']);
        $comp = mcOpen([$c->id])->assertSeeHtml('data-state="not_asked"');

        npDevice($c, 'granted');
        $queries = mpPhoneQueries(fn () => $comp->call('refreshPlan'));

        $comp->assertSeeHtml('data-state="on"')->assertDontSeeHtml('data-testid="phones-warning"');
        expect($queries)->toBe(1);
    });

    it('speaks Arabic', function () {
        app()->setLocale('ar');
        $off = mpCustomer(['denied'], ['name' => 'نور العلي', 'locale' => 'ar']);
        $none = mpCustomer([], ['name' => 'سارة منصور', 'locale' => 'ar']);

        mcOpen([$off->id])->assertSee(__('messaging_phones.label'))->assertSee('هاتف واحد · الإشعارات متوقفة')->assertSee('لن يظهر الإشعار الفوري')
            ->assertSee('الإعدادات ← الإشعارات')->assertSeeHtml('data-testid="phones-warning"');
        mcOpen([$none->id])->assertSee('لا يوجد هاتف مسجّل')->assertSee('لا يوجد هاتف للإشعار');
        expect(__('messaging_phones.label'))->toBe('الهواتف');
    });
});

// ── A bulk send ──────────────────────────────────────────────────────────────────────────────────────────────────────

describe('a send to several customers', function () {
    it('counts, among the customers who will get the notification, who can see a push, who has it off or not asked, who has no phone and who is unknown', function () {
        $ids = [
            mpCustomer(['granted'])->id,
            mpCustomer(['denied'])->id,
            mpCustomer(['not_asked'])->id,
            mpCustomer([])->id,
            mpCustomer([null])->id,
            mpCustomer(['granted', 'denied'])->id,
            mpCustomer(['denied'], ['marketing_consent_at' => null])->id, // will be skipped: not counted anywhere here
        ];

        $comp = mcOpen($ids);

        expect($comp->get('plan')['phones'])->toBe(['can_show' => 2, 'silent' => 2, 'none' => 1, 'unknown' => 1]);
        $comp->assertSeeHtml('data-testid="plan-phones"')->assertSee(__('messaging_phones.bulk.title'))->assertSee('2 customers can see a push')
            ->assertSee('2 have notifications off or not asked yet')->assertSee('1 has no phone registered')->assertSee('1 has not reported the notification setting yet')
            ->assertDontSeeHtml('data-testid="phones-line"')->assertDontSeeHtml('data-testid="phones-warning"');
    });

    it('leaves out the lines that are zero', function () {
        $comp = mcOpen([mpCustomer(['granted'])->id, mpCustomer(['granted'])->id]);

        $comp->assertSee('2 customers can see a push')->assertDontSeeHtml('data-testid="plan-phones-silent"')->assertDontSeeHtml('data-testid="plan-phones-none"')
            ->assertDontSeeHtml('data-testid="plan-phones-unknown"');
    });

    it('reads every phone with one grouped query, however many customers', function () {
        $ids = collect(range(1, 12))->map(fn ($i) => mpCustomer([$i % 2 ? 'denied' : 'granted', null])->id)->all();
        $comp = Livewire::test(MessageComposer::class)->dispatch('compose-message', mode: 'ids', ids: $ids);

        $queries = mpPhoneQueries(fn () => $comp->call('choose', 'custom'));

        expect($queries)->toBe(1)->and($comp->get('plan')['phones'])->toBe(['can_show' => 6, 'silent' => 0, 'none' => 0, 'unknown' => 6]);
    });

    it('says nothing about phones when push is not set up, or notification is not part of the send', function () {
        $ids = [mpCustomer(['denied'])->id, mpCustomer(['granted'])->id];

        configureFirebase(enabled: false);
        mcOpen($ids)->assertSet('plan.phones', null)->assertDontSeeHtml('data-testid="plan-phones"');

        configureFirebase();
        mcConnectN8n();
        mcOpen($ids)->assertSeeHtml('data-testid="plan-phones"')->set('channels', ['whatsapp'])->assertSet('plan.phones', null)->assertDontSeeHtml('data-testid="plan-phones"');
    });

    it('speaks Arabic', function () {
        app()->setLocale('ar');
        $comp = mcOpen([mpCustomer(['granted'], ['locale' => 'ar'])->id, mpCustomer(['denied'], ['locale' => 'ar'])->id]);

        $comp->assertSee(__('messaging_phones.bulk.title'))->assertSee('عميل واحد يظهر له الإشعار الفوري')->assertSee('عميل واحد إشعاراته متوقفة أو لم يُسأل بعد');
    });
});

// ── What a sent message did ──────────────────────────────────────────────────────────────────────────────────────────

describe('the customer\'s message list', function () {
    it('shows the Phones line for each of the five states, even before any message was sent', function (array $phones, string $state, string $line) {
        $c = mpCustomer($phones);

        Livewire::test(CustomerTimeline::class, ['customer' => $c])->assertSee(__('messaging_history.tl_empty_title'))->assertSeeHtml('data-state="'.$state.'"')->assertSee($line);
    })->with('phone states');

    it('says on a sent message how many phones it reached and where notifications are off', function () {
        $c = mpCustomer(['denied', 'granted', null]);
        npSend($c, $this->staff);

        Livewire::test(CustomerTimeline::class, ['customer' => $c])->assertSeeHtml('data-kind="silent"')->assertSee('Reached 3 phones, notifications off on 1');
    });

    it('says nothing extra in the normal case, and no phone when the message reached only the inbox', function () {
        $fine = mpCustomer(['granted', null]);
        $nobody = mpCustomer([]);
        npSend($fine, $this->staff);
        npSend($nobody, $this->staff);

        Livewire::test(CustomerTimeline::class, ['customer' => $fine])->assertDontSeeHtml('data-testid="phones-note"')->assertDontSee('Reached');
        Livewire::test(CustomerTimeline::class, ['customer' => $nobody])->assertSeeHtml('data-kind="no_phone"')->assertSee('No phone registered');
    });

    it('does not claim "no phone" when no push was attempted, and keeps the reason of a skipped message as it was', function () {
        $optedOut = mpCustomer(['denied']);
        NotificationPreference::create(['user_id' => $optedOut->id, 'channel' => 'push', 'category' => 'marketing', 'is_enabled' => false]);
        $noInbox = mpCustomer([]);
        NotificationPreference::create(['user_id' => $noInbox->id, 'channel' => 'in_app', 'category' => 'marketing', 'is_enabled' => false]);
        npSend($optedOut, $this->staff);
        npSend($noInbox, $this->staff);

        Livewire::test(CustomerTimeline::class, ['customer' => $optedOut])->assertDontSeeHtml('data-testid="phones-note"');
        Livewire::test(CustomerTimeline::class, ['customer' => $noInbox])->assertDontSeeHtml('data-testid="phones-note"')->assertSee(__('messaging_admin.reason.no_devices'));
    });

    it('treats a message sent before this existed as "no phone" when it recorded none', function () {
        $c = mpCustomer([]);
        $row = npSend($c, $this->staff);
        $row->update(['meta' => ['in_app' => true, 'devices' => 0, 'pushed' => 0]]);

        Livewire::test(CustomerTimeline::class, ['customer' => $c])->assertSeeHtml('data-kind="no_phone"');

        $row->update(['meta' => ['in_app' => true, 'devices' => 2, 'pushed' => 2]]);
        Livewire::test(CustomerTimeline::class, ['customer' => $c])->assertDontSeeHtml('data-testid="phones-note"');
    });

    it('reads the phones with one query however many messages are listed', function () {
        $c = mpCustomer(['denied']);
        foreach (range(1, 4) as $i) {
            npSend($c, $this->staff);
        }

        $queries = mpPhoneQueries(fn () => Livewire::test(CustomerTimeline::class, ['customer' => $c])->assertSeeHtml('data-testid="phones-note"'));

        expect($queries)->toBe(1);
    });

    it('speaks Arabic', function () {
        app()->setLocale('ar');
        $c = mpCustomer(['denied', 'granted']);
        npSend($c, $this->staff);

        Livewire::test(CustomerTimeline::class, ['customer' => $c])->assertSee(__('messaging_phones.label'))->assertSee('هاتفان · يظهر الإشعار الفوري على 1 منهما')
            ->assertSee('وصلت إلى هاتفين، والإشعارات متوقفة على 1');
    });
});

describe('the history of a send', function () {
    it('marks each customer row: phones reached with notifications off, no phone, and nothing for the normal case', function () {
        $customers = [mpCustomer(['denied']), mpCustomer(['granted']), mpCustomer([]), mpCustomer(['denied', 'granted']), mpCustomer([null])];
        $batch = npSendMany($this->staff, $customers);

        $lw = Livewire::test(MessageHistory::class)->call('openView', $batch->id)->assertSet('showView', true)
            ->assertSee('Reached 1 phone, notifications off on 1')->assertSee('Reached 2 phones, notifications off on 1')->assertSee('No phone registered');

        expect(substr_count($lw->html(), 'data-testid="phones-note"'))->toBe(3)
            ->and(substr_count($lw->html(), 'data-kind="silent"'))->toBe(2)->and(substr_count($lw->html(), 'data-kind="no_phone"'))->toBe(1);
    });

    it('explains in the row\'s hint why a push can be accepted and still not be seen', function () {
        $batch = npSendMany($this->staff, [mpCustomer(['denied']), mpCustomer([])]);

        Livewire::test(MessageHistory::class)->call('openView', $batch->id)->assertSee(__('messaging_phones.note.hint'))->assertSee(__('messaging_phones.note.no_phone_hint'));
    });

    it('speaks Arabic', function () {
        app()->setLocale('ar');
        $batch = npSendMany($this->staff, [mpCustomer(['denied']), mpCustomer([])]);

        Livewire::test(MessageHistory::class)->call('openView', $batch->id)->assertSee('وصلت إلى هاتف واحد، والإشعارات متوقفة على 1')->assertSee('لا يوجد هاتف مسجّل');
    });
});

// ── Who may see and send, unchanged ──────────────────────────────────────────────────────────────────────────────────

describe('permissions', function () {
    it('shows nothing about phones to a team member who may not message or see messages', function () {
        $c = mpCustomer(['denied']);
        $this->actingAs(staffWithRole('customer_support'));

        Livewire::test(MessageComposer::class)->assertForbidden();
        Livewire::test(CustomerTimeline::class, ['customer' => $c])->assertForbidden();
    });

    it('never reaches a customer outside a branch member\'s own customers, so their phones are not shown either', function () {
        $c = mpCustomer(['denied']);
        $scoped = mcStaff(['customers.view', 'notifications.create'], Branch::factory()->create());
        $this->actingAs($scoped);

        Livewire::test(MessageComposer::class)->dispatch('compose-message', mode: 'ids', ids: [$c->id])->assertSet('open', false)->assertSet('singleId', null)
            ->assertDontSeeHtml('data-testid="phones-line"')->assertDontSeeHtml('data-testid="plan-phones"');
        Livewire::test(CustomerTimeline::class, ['customer' => $c])->assertForbidden();
    });

    it('lets a branch member see the phones of their own customer, and nobody else\'s', function () {
        $w = commerceWorld();
        $mine = mpCustomer(['not_asked']);
        mpOrderAt($w, $w->a, $mine);
        $other = mpCustomer(['denied']);
        $this->actingAs(mcStaff(['customers.view', 'notifications.create', 'notifications.view'], $w->a));

        Livewire::test(MessageComposer::class)->dispatch('compose-message', mode: 'ids', ids: [$mine->id, $other->id])->assertSet('count', 1)->assertSet('singleId', $mine->id)
            ->call('choose', 'custom')->assertSeeHtml('data-state="not_asked"')->assertSeeHtml('data-testid="phones-warning"');
        Livewire::test(CustomerTimeline::class, ['customer' => $mine])->assertSeeHtml('data-state="not_asked"');
        Livewire::test(CustomerTimeline::class, ['customer' => $other])->assertForbidden();
    });

    it('does not change who may send: the same refusals as before for someone who lost the right', function () {
        $c = mpCustomer(['denied']);
        $comp = mcOpen([$c->id]);
        $this->actingAs(staffWithRole('customer_support'));

        $comp->call('requestSend')->assertForbidden();
        expect(CustomerMessage::count())->toBe(0);
    });
});

// ── Both languages ───────────────────────────────────────────────────────────────────────────────────────────────────

describe('the words', function () {
    it('has every string in both languages, and none left in English in the Arabic file', function () {
        $en = require lang_path('en/messaging_phones.php');
        $ar = require lang_path('ar/messaging_phones.php');

        expect(mcKeys($ar))->toBe(mcKeys($en));
        foreach (mcKeys($en) as $key) {
            expect(data_get($ar, $key))->not->toBe(data_get($en, $key), "Arabic text of {$key} is still English")->and(data_get($ar, $key))->not->toBeEmpty();
        }
    });

    it('fills every count and name in every plural form of both languages', function (string $locale) {
        app()->setLocale($locale);
        $keys = ['line.on', 'line.unknown', 'line.off', 'line.not_asked', 'bulk.can_show', 'bulk.silent', 'bulk.none', 'bulk.unknown', 'note.silent'];
        foreach ([1, 2, 3, 7, 10, 11, 25, 100, 1000] as $n) {
            foreach ($keys as $key) {
                $text = trans_choice('messaging_phones.'.$key, $n, ['count' => number_format($n), 'silent' => 1]);
                expect($text)->not->toStartWith('messaging_phones.')->and($text)->not->toContain(':count')->and($text)->not->toContain(':silent')->and($text)->not->toBeEmpty();
            }
        }
        foreach ([2, 3, 10, 11, 100] as $n) {
            $text = trans_choice('messaging_phones.line.on_some', $n, ['count' => number_format($n), 'on' => 1]);
            expect($text)->not->toStartWith('messaging_phones.')->and($text)->not->toContain(':count')->and($text)->not->toContain(':on');
        }
        foreach (['none', 'off', 'not_asked'] as $state) {
            expect(__('messaging_phones.warn.'.$state, ['name' => 'Noor']))->toContain('Noor')->and(__('messaging_phones.warn.'.$state.'_title'))->not->toStartWith('messaging_phones.');
        }
    })->with(['en', 'ar']);
});
