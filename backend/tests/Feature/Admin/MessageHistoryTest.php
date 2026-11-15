<?php

use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Models\JourneyTemplate;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Messaging\Data\AudienceSelection;
use App\Domain\Messaging\Data\SendRequest;
use App\Domain\Messaging\Models\CustomerMessage;
use App\Domain\Messaging\Models\MessageBatch;
use App\Domain\Messaging\Services\CustomerMessaging;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Messaging\MessageHistory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Livewire;

const HX_SECRET = 'a-very-long-shared-secret-hx-1';

function hxN8n(): void
{
    $s = app(Settings::class);
    $s->set('n8n.enabled', true);
    $s->set('n8n.webhook_url', 'https://n8n.example.com/webhook/bahar');
    $s->set('n8n.secret', HX_SECRET, secret: true);
}

/** A customer who agreed to marketing messages and WhatsApp. */
function hxCustomer(string $name = 'Noor Ali', array $attrs = []): User
{
    $u = User::factory()->create($attrs + ['name' => $name, 'marketing_consent_at' => now(), 'phone' => '+9745'.random_int(5000000, 5999999), 'locale' => 'en']);
    NotificationPreference::create(['user_id' => $u->id, 'channel' => 'whatsapp', 'category' => 'marketing', 'is_enabled' => true]);

    return $u;
}

/** @param list<string> $ids */
function hxRequest(array $ids, array $over = []): SendRequest
{
    $content = ['title_en' => 'Hello {{name}}', 'title_ar' => 'مرحباً {{name}}', 'body_en' => 'A note for {{name}}', 'body_ar' => 'رسالة إلى {{name}}',
        'whatsapp_en' => '', 'whatsapp_ar' => '', 'deep_link' => 'albahar://home', 'coupon' => ['enabled' => false]];

    return new SendRequest(AudienceSelection::ofIds($ids), $over['channels'] ?? ['notification'], $over['kind'] ?? 'custom', ($over['content'] ?? []) + $content,
        $over['template'] ?? null, $over['scheduleAt'] ?? null, $over['ignoreCooldown'] ?? false);
}

/** Sends for real (queue runs inline in tests) and returns the saved send. */
function hxSend(User $staff, array $ids, array $over = []): MessageBatch
{
    RateLimiter::clear('customer-messages:bulk:'.$staff->id);
    RateLimiter::clear('customer-messages:single:'.$staff->id);

    return app(CustomerMessaging::class)->send($staff, hxRequest($ids, $over));
}

/** Moves a send (and its messages) back in time, as if it had gone out $days days ago. */
function hxAge(MessageBatch $batch, float $days): MessageBatch
{
    $at = CarbonImmutable::now()->subSeconds((int) ($days * 86400));
    MessageBatch::whereKey($batch->id)->update(['created_at' => $at, 'started_at' => $batch->scheduled_at ? null : $at, 'completed_at' => $batch->completed_at ? $at : null]);
    CustomerMessage::where('batch_id', $batch->id)->update(['created_at' => $at, 'sent_at' => DB::raw('CASE WHEN sent_at IS NULL THEN NULL ELSE \''.$at->toDateTimeString().'\'::timestamptz END')]);

    return $batch->fresh();
}

/** The customer places a pay-at-pickup order at the branch, so branch staff may see them. */
function hxOrderAt(object $w, $branch, User $customer): Order
{
    actingAsCustomer($customer);
    $customer->addresses()->create(['recipient_name' => $customer->name, 'recipient_phone' => '+97455123456', 'zone_number' => '66', 'street_number' => '850', 'building_number' => '12', 'is_default' => true]);
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1])->assertSuccessful();

    return Order::findOrFail(quoteAndPlace(['fulfillment_type' => 'pickup', 'branch_id' => $branch->id, 'payment_method' => 'pay_at_pickup'])->assertCreated()->json('data.order.id'));
}

beforeEach(function () {
    $this->staff = staffWithRole('marketing_manager');
    app(Settings::class)->set('messaging.cooldown_hours', 0);
    $this->actingAs($this->staff);
});

/** A send row written directly (no delivery), for the tests of paging, filtering and counting. */
function hxBatch(User $staff, array $attrs = []): MessageBatch
{
    $at = $attrs['created_at'] ?? now();

    return MessageBatch::create($attrs + ['created_by' => $staff->id, 'template_name' => 'Row '.Str::random(5), 'kind' => 'custom', 'source' => 'bulk', 'channels' => ['notification'],
        'content' => ['title_en' => 'T', 'title_ar' => 'ع', 'body_en' => 'B', 'body_ar' => 'ب', 'whatsapp_en' => '', 'whatsapp_ar' => '', 'deep_link' => null, 'coupon' => ['enabled' => false]],
        'audience' => ['mode' => 'ids', 'count' => 3], 'recipients_count' => 3, 'status' => 'done', 'sent_count' => 3, 'started_at' => $at, 'completed_at' => $at, 'created_at' => $at]);
}

/** The names of the sends on the current page, in the order shown. */
function hxNames($component): array
{
    return collect($component->viewData('batches')->items())->pluck('template_name')->all();
}

describe('access', function () {
    it('opens for people with notification rights and shows an empty state with the next step', function () {
        $this->get(route('admin.messages.index'))->assertOk()->assertSee(__('nav.customer_messages'))->assertSee(__('messaging_history.empty_title'))
            ->assertSee(__('messaging_history.choose_customers'))->assertSee(route('admin.customers.index'), false);
    });

    it('is closed to people without the right, and to guests', function () {
        $this->actingAs(staffWithRole('customer_support'));
        $this->get(route('admin.messages.index'))->assertForbidden();
        Livewire::test(MessageHistory::class)->assertForbidden();
        auth()->logout();
        $this->get(route('admin.messages.index'))->assertRedirect();
    });

    it('lets a view-only team member read the history but not send, retry or cancel', function () {
        $c = hxCustomer();
        $batch = hxSend($this->staff, [$c->id], ['scheduleAt' => CarbonImmutable::now()->addHours(3)]);
        $auditor = staffWithRole('auditor');
        $this->actingAs($auditor);
        $lw = Livewire::test(MessageHistory::class)->assertOk()->assertDontSee(__('messaging_history.choose_customers'))->call('openView', $batch->id)->assertSet('showView', true)
            ->assertDontSee(__('messaging_history.cancel_send'));
        $lw->call('askCancel')->assertForbidden();
        expect($batch->fresh()->status)->toBe('scheduled');
    });
});

describe('the list', function () {
    it('lists the sends newest first with what, to whom, how and the result', function () {
        $noor = hxCustomer('Noor Ali');
        $others = collect(range(1, 3))->map(fn ($i) => hxCustomer('Customer '.$i)->id)->all();
        $first = hxAge(hxSend($this->staff, [$noor->id], ['content' => ['title_en' => 'Welcome back']]), 3);
        MessageBatch::whereKey($first->id)->update(['template_name' => 'Welcome note']);
        $second = hxSend($this->staff, $others, ['kind' => 'offer']);
        MessageBatch::whereKey($second->id)->update(['template_name' => 'Eid offer']);

        $lw = Livewire::test(MessageHistory::class);
        expect(hxNames($lw))->toBe(['Eid offer', 'Welcome note']);
        $lw->assertSee('Welcome note')->assertSee('Eid offer')->assertSee('Noor Ali')->assertSee(__('messaging_history.customers', ['count' => 3], 'en') ? '3 customers' : '')
            ->assertSee(__('messaging_admin.kind.offer'))->assertSee(__('messaging_admin.channel.notification'))->assertSee($this->staff->name)->assertSee(__('messaging_admin.batch_status.done'))
            ->assertSee('3 days ago')->assertSeeHtml('role="img"');
    });

    it('shows how it went: sent, delivered, failed and not sent, with the plain numbers', function () {
        hxN8n();
        Http::fake(['https://n8n.example.com/*' => Http::response([], 500)]);
        $ok = hxCustomer('Reachable One');
        $noConsent = hxCustomer('No Consent', ['marketing_consent_at' => null]);
        $batch = hxSend($this->staff, [$ok->id, $noConsent->id], ['channels' => ['notification', 'whatsapp']]);
        expect($batch)->sent_count->toBe(1)->failed_count->toBe(1)->skipped_count->toBe(2);

        Livewire::test(MessageHistory::class)->assertSeeInOrder(['1', 'sent', '1', 'failed', '2', 'not sent'])
            ->assertSeeHtml('aria-label="1 sent, 1 failed, 2 not sent"');
    });

    it('totals the chosen period in the tiles: messages sent, WhatsApp delivered, failed, not sent, waiting', function () {
        hxN8n();
        Http::fake(['https://n8n.example.com/*' => Http::response(['id' => 'wamid.1'])]);
        $a = hxCustomer('Tile A');
        $b = hxCustomer('Tile B');
        $skipped = hxCustomer('Tile C', ['marketing_consent_at' => null]);
        $sent = hxSend($this->staff, [$a->id, $b->id, $skipped->id], ['channels' => ['whatsapp']]);
        CustomerMessage::where('batch_id', $sent->id)->where('user_id', $a->id)->first()->forceFill(['status' => 'delivered', 'delivered_at' => now()])->save();
        $sent->refreshCounts();
        $waiting = hxSend($this->staff, [hxCustomer('Tile D')->id], ['scheduleAt' => CarbonImmutable::now()->addDay()]);
        hxAge(hxSend($this->staff, [hxCustomer('Old One')->id]), 45); // outside the default 30 days

        $tiles = Livewire::test(MessageHistory::class)->viewData('tiles');
        expect($tiles)->sent->toBe(2)->and($tiles['wa_sent'])->toBe(2)->and($tiles['wa_delivered'])->toBe(1)->and($tiles['delivered_pct'])->toBe(50)
            ->and($tiles['failed'])->toBe(0)->and($tiles['skipped'])->toBe(1)->and($tiles['waiting'])->toBe(1)->and($tiles['waiting_sends'])->toBe(1)->and($tiles['sends'])->toBe(2);

        $all = Livewire::test(MessageHistory::class)->call('setWhen', 'all')->viewData('tiles');
        expect($all['sends'])->toBe(3)->and($all['sent'])->toBe(3);
        // the waiting tile jumps to the sends that have not gone out
        $lw = Livewire::test(MessageHistory::class)->call('showWaiting')->assertSet('filterStatus', 'scheduled')->assertSet('when', 'all');
        expect($lw->viewData('batches')->pluck('id')->all())->toBe([$waiting->id]);
    });

    it('says "no WhatsApp yet" instead of a made-up percentage', function () {
        hxSend($this->staff, [hxCustomer()->id]);
        $tiles = Livewire::test(MessageHistory::class)->assertSee(__('messaging_history.no_whatsapp_yet'))->viewData('tiles');
        expect($tiles['delivered_pct'])->toBeNull();
    });

    it('pages 20 at a time and keeps the order across pages', function () {
        foreach (range(1, 25) as $i) {
            hxBatch($this->staff, ['template_name' => sprintf('Send %02d', $i), 'created_at' => now()->subMinutes(30 - $i)]);
        }
        $lw = Livewire::test(MessageHistory::class);
        expect($lw->viewData('batches'))->toHaveCount(20)->and($lw->viewData('batches')->total())->toBe(25)->and(hxNames($lw)[0])->toBe('Send 25');
        $page2 = $lw->call('gotoPage', 2);
        expect(hxNames($page2))->toHaveCount(5)->and(hxNames($page2)[0])->toBe('Send 05');
    });

    it('does not run more queries when there are more sends (no N+1)', function () {
        hxN8n();
        $c = hxCustomer();
        foreach (range(1, 3) as $i) {
            hxBatch($this->staff, ['source' => 'single', 'template_name' => 'S'.$i, 'recipients_count' => 1]);
        }
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(MessageHistory::class)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $count(); // the first run also loads the viewer's rights
        $few = $count();
        foreach (range(4, 19) as $i) {
            $b = hxBatch($this->staff, ['source' => $i % 2 ? 'single' : 'bulk', 'template_name' => 'S'.$i, 'channels' => ['notification', 'whatsapp']]);
            CustomerMessage::create(['batch_id' => $b->id, 'user_id' => $c->id, 'channel' => 'notification', 'locale' => 'en', 'status' => 'sent', 'sent_at' => now()]);
        }
        $many = $count();
        expect($many)->toBe($few)->and($many)->toBeLessThan(40);
    });

    it('refreshes itself only while a send is running or about to go out', function () {
        hxBatch($this->staff);
        Livewire::test(MessageHistory::class)->assertDontSeeHtml('wire:poll');
        hxBatch($this->staff, ['status' => 'sending', 'completed_at' => null, 'queued_count' => 2]);
        Livewire::test(MessageHistory::class)->assertSeeHtml('wire:poll');
        MessageBatch::query()->delete();
        hxBatch($this->staff, ['status' => 'scheduled', 'scheduled_at' => now()->addDays(2), 'started_at' => null, 'completed_at' => null]);
        Livewire::test(MessageHistory::class)->assertDontSeeHtml('wire:poll');
        hxBatch($this->staff, ['status' => 'scheduled', 'scheduled_at' => now()->addMinute(), 'started_at' => null, 'completed_at' => null]);
        Livewire::test(MessageHistory::class)->assertSeeHtml('wire:poll');
    });

    it('shows a retry card instead of a blank page when the history cannot be read', function () {
        hxBatch($this->staff);
        DB::beforeExecuting(function ($sql) {
            if (str_contains($sql, 'customer_message_batches')) {
                throw new RuntimeException('database is away');
            }
        });
        Livewire::test(MessageHistory::class)->assertSee(__('messaging_history.error_title'))->assertSee(__('messaging_history.try_again'));
    });

    it('reads in Arabic with the same data', function () {
        hxSend($this->staff, [hxCustomer('نور علي')->id, hxCustomer('Sara')->id]);
        app()->setLocale('ar');
        Livewire::test(MessageHistory::class)->assertSee(__('nav.customer_messages'))->assertSee('عميلان')->assertSee(__('messaging_admin.kind.custom'))
            ->assertSee(__('messaging_history.when_30d'))->assertSee('منذ');
    });
});

describe('filters', function () {
    beforeEach(function () {
        hxN8n();
        Http::fake(['https://n8n.example.com/*' => Http::response(['id' => 'w'])]);
        $this->noor = hxCustomer('Noor Ali', ['phone' => '+97455123456', 'email' => 'noor@example.com']);
        $this->omar = hxCustomer('Omar Hassan', ['phone' => '+97466987654']);
        $this->reminder = hxSend($this->staff, [$this->noor->id], ['kind' => 'reminder', 'channels' => ['notification']]);
        MessageBatch::whereKey($this->reminder->id)->update(['template_name' => 'Forgotten order reminder']);
        $this->offer = hxSend($this->staff, [$this->omar->id], ['kind' => 'offer', 'channels' => ['whatsapp']]);
        MessageBatch::whereKey($this->offer->id)->update(['template_name' => 'Special offer for you']);
        $this->other = staffWithRole('marketing_manager', null, ['name' => 'Layla Karim']);
        $this->custom = hxSend($this->other, [$this->noor->id, $this->omar->id], ['kind' => 'custom', 'channels' => ['notification', 'whatsapp']]);
        MessageBatch::whereKey($this->custom->id)->update(['template_name' => 'Ramadan hours']);
        $this->all = fn () => hxNames(Livewire::test(MessageHistory::class));
    });

    it('filters by kind, channel and team member', function () {
        expect(hxNames(Livewire::test(MessageHistory::class)->set('filterKind', 'offer')))->toBe(['Special offer for you'])
            ->and(hxNames(Livewire::test(MessageHistory::class)->set('filterKind', 'reminder')))->toBe(['Forgotten order reminder'])
            ->and(hxNames(Livewire::test(MessageHistory::class)->set('filterChannel', 'whatsapp')))->toEqualCanonicalizing(['Special offer for you', 'Ramadan hours'])
            ->and(hxNames(Livewire::test(MessageHistory::class)->set('filterChannel', 'notification')))->toEqualCanonicalizing(['Forgotten order reminder', 'Ramadan hours'])
            ->and(hxNames(Livewire::test(MessageHistory::class)->set('filterStaff', $this->other->id)))->toBe(['Ramadan hours'])
            ->and(hxNames(Livewire::test(MessageHistory::class)->set('filterKind', 'offer')->set('filterChannel', 'notification')))->toBe([]);
        Livewire::test(MessageHistory::class)->assertSee('Layla Karim'); // offered in the "sent by" list
    });

    it('filters by status: the send itself, or what it contains', function () {
        MessageBatch::whereKey($this->offer->id)->update(['failed_count' => 2]);
        $scheduled = hxSend($this->staff, [hxCustomer()->id], ['scheduleAt' => CarbonImmutable::now()->addDay()]);
        MessageBatch::whereKey($scheduled->id)->update(['template_name' => 'Tomorrow note']);
        expect(hxNames(Livewire::test(MessageHistory::class)->set('filterStatus', 'scheduled')))->toBe(['Tomorrow note'])
            ->and(hxNames(Livewire::test(MessageHistory::class)->set('filterStatus', 'has_failed')))->toBe(['Special offer for you'])
            ->and(hxNames(Livewire::test(MessageHistory::class)->set('filterStatus', 'done')))->toHaveCount(3)
            ->and(hxNames(Livewire::test(MessageHistory::class)->set('filterStatus', 'cancelled')))->toBe([])
            ->and(hxNames(Livewire::test(MessageHistory::class)->set('filterStatus', 'has_skipped')))->toBe(['Forgotten order reminder']); // no order in the cart: skipped
        MessageBatch::whereKey($this->reminder->id)->update(['delivered_count' => 1]);
        expect(hxNames(Livewire::test(MessageHistory::class)->set('filterStatus', 'has_delivered')))->toBe(['Forgotten order reminder']);
    });

    it('ignores values nobody could pick (a tampered link shows everything instead of breaking)', function () {
        expect(hxNames(Livewire::test(MessageHistory::class)->set('filterKind', 'nonsense')->set('filterStatus', 'x')->set('filterStaff', 'not-a-uuid')->set('when', 'someday')))->toHaveCount(3);
    });

    it('finds sends by template name, team member, or a customer who was in them', function () {
        $find = fn (string $s) => hxNames(Livewire::test(MessageHistory::class)->set('search', $s));
        expect($find('ramadan'))->toBe(['Ramadan hours'])
            ->and($find('layla'))->toBe(['Ramadan hours'])
            ->and($find('Omar'))->toEqualCanonicalizing(['Special offer for you', 'Ramadan hours'])          // the customer's name
            ->and($find('noor@example'))->toEqualCanonicalizing(['Forgotten order reminder', 'Ramadan hours']) // e-mail
            ->and($find('5512 3456'))->toEqualCanonicalizing(['Forgotten order reminder', 'Ramadan hours'])    // phone, spaces ignored
            ->and($find('zzzz'))->toBe([])
            ->and($find('%%'))->toBe([])                                                                         // wildcards typed by staff are plain text
            ->and($find('a'))->toHaveCount(3);                                                                   // one letter is too little to search: nothing is filtered
        Livewire::test(MessageHistory::class)->set('search', 'zzzz')->assertSee(__('messaging_history.none_title'))->assertSee(__('messaging_history.show_everything'));
    });

    it('clears everything back to "show all" in one step', function () {
        $lw = Livewire::test(MessageHistory::class)->set('filterKind', 'offer')->set('search', 'x')->set('filterStatus', 'done')->call('clearFilters')
            ->assertSet('filterKind', '')->assertSet('search', '')->assertSet('filterStatus', '')->assertSet('when', 'all');
        expect(hxNames($lw))->toHaveCount(3);
    });

    it('keeps the filters in the address, and starts from them when a link is opened', function () {
        $lw = Livewire::withQueryParams(['filterKind' => 'offer', 'filterChannel' => 'whatsapp', 'search' => 'special', 'when' => '90d'])->test(MessageHistory::class)
            ->assertSet('filterKind', 'offer')->assertSet('when', '90d');
        expect(hxNames($lw))->toBe(['Special offer for you']);
        Livewire::withQueryParams([])->test(MessageHistory::class)->assertSet('when', '30d')->assertSeeHtml('aria-pressed="true"');
    });

    it('hides the "sent by" filter from people who only see their own sends', function () {
        $w = commerceWorld();
        $branchStaff = staffWithRole('marketing_manager', $w->a);
        $this->actingAs($branchStaff);
        Livewire::test(MessageHistory::class)->assertDontSee(__('messaging_history.all_staff'));
        $this->actingAs($this->staff);
        Livewire::test(MessageHistory::class)->assertSee(__('messaging_history.all_staff'));
    });
});

describe('dates', function () {
    beforeEach(function () {
        // Sunday 15 November 2026, 09:00 in Qatar (06:00 UTC): "today" began at 21:00 UTC the evening before.
        $this->travelTo(CarbonImmutable::parse('2026-11-15 09:00:00', 'Asia/Qatar'));
        $this->at = fn (string $name, float $days) => MessageBatch::whereKey(hxAge(hxSend($this->staff, [hxCustomer($name)->id]), $days)->id)->update(['template_name' => $name]);
        ($this->at)('two hours ago', 2 / 24);
        ($this->at)('yesterday', 1);
        ($this->at)('five days ago', 5);
        ($this->at)('twenty days ago', 20);
        ($this->at)('fifty days ago', 50);
        ($this->at)('hundred days ago', 100);
        $this->names = fn (string $when, array $more = []) => hxNames(collect($more)->reduce(fn ($lw, $v, $k) => $lw->set($k, $v), Livewire::test(MessageHistory::class)->call('setWhen', $when)));
    });

    it('has quick chips for today, 7, 30 and 90 days in Qatar time', function () {
        expect(($this->names)('today'))->toBe(['two hours ago'])
            ->and(($this->names)('7d'))->toBe(['two hours ago', 'yesterday', 'five days ago'])
            ->and(($this->names)('30d'))->toBe(['two hours ago', 'yesterday', 'five days ago', 'twenty days ago'])
            ->and(($this->names)('90d'))->toBe(['two hours ago', 'yesterday', 'five days ago', 'twenty days ago', 'fifty days ago'])
            ->and(($this->names)('all'))->toHaveCount(6);
        Livewire::test(MessageHistory::class)->assertSet('when', '30d')->assertSee('twenty days ago')->assertDontSee('fifty days ago');
    });

    it('finds what was sent before a time: more than 1, 7, 30 or 90 days ago', function () {
        expect(($this->names)('older_1d'))->toBe(['yesterday', 'five days ago', 'twenty days ago', 'fifty days ago', 'hundred days ago'])
            ->and(($this->names)('older_7d'))->toBe(['twenty days ago', 'fifty days ago', 'hundred days ago'])
            ->and(($this->names)('older_30d'))->toBe(['fifty days ago', 'hundred days ago'])
            ->and(($this->names)('older_90d'))->toBe(['hundred days ago']);
        Livewire::test(MessageHistory::class)->call('setWhen', 'older_30d')->assertSee('Showing sends from before');
    });

    it('takes a custom range, both days included, or just one end', function () {
        $between = fn ($from, $to) => ($this->names)('custom', ['dateFrom' => $from, 'dateTo' => $to]);
        expect($between('2026-11-14', '2026-11-15'))->toBe(['two hours ago', 'yesterday'])
            ->and($between('2026-11-10', '2026-11-10'))->toBe(['five days ago'])            // one whole day
            ->and($between('2026-11-15', '2026-11-14'))->toBe(['two hours ago', 'yesterday']) // reversed dates still work
            ->and($between('', '2026-11-09'))->toBe(['twenty days ago', 'fifty days ago', 'hundred days ago'])  // everything up to that day
            ->and($between('2026-10-01', ''))->toBe(['two hours ago', 'yesterday', 'five days ago', 'twenty days ago'])
            ->and($between('not-a-date', '2026-02-31'))->toHaveCount(6);                    // unusable dates filter nothing
    });

    it('starts the date pickers from the last week when "Pick dates" is chosen', function () {
        Livewire::test(MessageHistory::class)->call('setWhen', 'custom')->assertSet('dateTo', '2026-11-15')->assertSet('dateFrom', '2026-11-09')
            ->assertSee(__('messaging_history.from'))->assertSee(__('messaging_history.to'));
    });

    it('keeps upcoming scheduled sends in the quick ranges, but not in "older than"', function () {
        $later = hxSend($this->staff, [hxCustomer('Later')->id], ['scheduleAt' => CarbonImmutable::now()->addDays(3)]);
        MessageBatch::whereKey($later->id)->update(['template_name' => 'next week']);
        expect(($this->names)('today'))->toBe(['next week', 'two hours ago'])
            ->and(($this->names)('older_1d'))->not->toContain('next week');
    });
});

describe('the window of one send', function () {
    it('opens from ?view=, shows the texts as sent with a deep link, and closes by clearing the address', function () {
        $promo = Promotion::create(['name_en' => 'Ten percent', 'name_ar' => 'عشرة بالمئة', 'type' => 'percentage', 'value' => '10', 'scope' => 'order', 'requires_coupon' => true, 'is_active' => true]);
        $c = hxCustomer('Noor Ali');
        $batch = hxSend($this->staff, [$c->id], ['kind' => 'offer', 'content' => ['title_en' => 'Hello {{name}}', 'body_en' => 'Use {{coupon}} before {{expires}}', 'body_ar' => 'استخدم {{coupon}} قبل {{expires}}',
            'deep_link' => 'albahar://products/12', 'coupon' => ['enabled' => true, 'promotion_id' => $promo->id, 'prefix' => 'EID', 'valid_hours' => 48]]]);
        MessageBatch::whereKey($batch->id)->update(['template_name' => 'Eid offer']);

        $lw = Livewire::withQueryParams(['view' => $batch->id])->test(MessageHistory::class)->assertSet('showView', true)->assertSet('shownId', $batch->id)
            ->assertSee('Eid offer')->assertSee($this->staff->name)->assertSee('albahar://products/12')->assertSee('Ten percent')->assertSee('EID-…')->assertSee('valid for 48 hours')
            ->assertSee(__('messaging_admin.placeholder.name'))->assertSee(__('messaging_admin.placeholder.coupon'))->assertSee('العربية')->assertSee('English')
            ->assertSee(__('messaging_history.texts'))->assertSee('Noor Ali');
        $lw->call('closeView')->assertSet('showView', false)->assertSet('viewId', '');
        $lw->call('openView', $batch->id)->assertSet('showView', true);
    });

    it('shows the WhatsApp text and tells when the notification text was used instead', function () {
        hxN8n();
        Http::fake(['https://n8n.example.com/*' => Http::response(['id' => 'x'])]);
        $with = hxSend($this->staff, [hxCustomer()->id], ['channels' => ['whatsapp'], 'content' => ['whatsapp_en' => 'Salam from the store', 'whatsapp_ar' => 'سلام من المتجر']]);
        Livewire::test(MessageHistory::class)->call('openView', $with->id)->assertSee('Salam from the store')->assertSee('سلام من المتجر')->assertDontSee(__('messaging_history.whatsapp_fallback'));
        $without = hxSend($this->staff, [hxCustomer()->id], ['channels' => ['whatsapp']]);
        Livewire::test(MessageHistory::class)->call('openView', $without->id)->assertSee(__('messaging_history.whatsapp_fallback'))->assertSee('A note for');
    });

    it('opens nothing for an id that does not exist, is not a uuid, or belongs to someone else', function () {
        $mine = hxSend($this->staff, [hxCustomer()->id]);
        $w = commerceWorld();
        $branchStaff = staffWithRole('marketing_manager', $w->a);
        $this->actingAs($branchStaff);
        Livewire::withQueryParams(['view' => $mine->id])->test(MessageHistory::class)->assertSet('showView', false)->assertSet('viewId', '');
        Livewire::withQueryParams([])->test(MessageHistory::class)->call('openView', $mine->id)->assertSet('showView', false)->assertDispatched('toast', type: 'error')
            ->call('openView', 'nope')->assertSet('showView', false)->call('openView', (string) Str::uuid())->assertSet('showView', false);
    });

    it('says plainly when the template was deleted since, using the name it had', function () {
        $template = JourneyTemplate::where('name', 'Special offer for you')->firstOrFail();
        $batch = hxSend($this->staff, [hxCustomer()->id], ['kind' => 'offer', 'template' => $template]);
        expect($batch->template_name)->toBe('Special offer for you');
        Livewire::test(MessageHistory::class)->call('openView', $batch->id)->assertSee('Special offer for you')->assertDontSee(__('messaging_history.template_removed'));
        $template->delete();
        expect($batch->fresh()->template_id)->toBeNull();
        Livewire::test(MessageHistory::class)->call('openView', $batch->id)->assertSee('Special offer for you')->assertSee(__('messaging_history.template_removed'));
        // a custom message never had a template: nothing to apologise for
        $custom = hxSend($this->staff, [hxCustomer()->id]);
        Livewire::test(MessageHistory::class)->call('openView', $custom->id)->assertDontSee(__('messaging_history.template_removed'));
    });

    it('notes a send that ignored the quiet period, and a former team member', function () {
        $c = hxCustomer();
        app(Settings::class)->set('messaging.cooldown_hours', 24);
        hxSend($this->staff, [$c->id]);
        $forced = hxSend($this->staff, [$c->id], ['ignoreCooldown' => true]);
        Livewire::test(MessageHistory::class)->call('openView', $forced->id)->assertSee(__('messaging_history.sent_anyway'));
        MessageBatch::whereKey($forced->id)->update(['created_by' => null]);
        Livewire::test(MessageHistory::class)->call('openView', $forced->id)->assertSee(__('messaging_history.former_staff'));
    });
});

describe('where a send sits on the clock', function () {
    it('puts a send cancelled before it started where it was created, not where it would have gone out', function () {
        $batch = hxSend($this->staff, [hxCustomer()->id], ['scheduleAt' => CarbonImmutable::now()->addDays(2)]);
        MessageBatch::whereKey($batch->id)->update(['template_name' => 'Called off']);
        app(CustomerMessaging::class)->cancel($this->staff, $batch->fresh());
        MessageBatch::whereKey($batch->id)->update(['created_at' => now()->subDays(4)]);

        $lw = Livewire::test(MessageHistory::class);
        expect($lw->instance()->clockTime($batch->fresh())->toDateString())->toBe(now()->subDays(4)->toDateString());
        expect(hxNames($lw->call('setWhen', 'older_1d')))->toBe(['Called off']);            // it counts as sent 4 days ago
        expect(hxNames(Livewire::test(MessageHistory::class)->call('setWhen', 'today')))->toBe([]); // and not as something for the day after tomorrow
        Livewire::test(MessageHistory::class)->assertSee('4 days ago')->assertDontSee(__('messaging_history.goes_out'));
    });

    it('keeps a waiting send at the time it will go out', function () {
        $batch = hxSend($this->staff, [hxCustomer()->id], ['scheduleAt' => CarbonImmutable::now()->addDays(2)]);
        $lw = Livewire::test(MessageHistory::class);
        expect($lw->instance()->clockTime($batch->fresh())->toDateString())->toBe(now()->addDays(2)->toDateString());
        $lw->assertSee(__('messaging_history.goes_out'));
    });

    it('does not claim a message written from scratch lost its template', function () {
        $batch = hxBatch($this->staff, ['template_name' => 'Written by hand', 'kind' => 'custom']);
        Livewire::test(MessageHistory::class)->call('openView', $batch->id)->assertSee('Written by hand')->assertDontSee(__('messaging_history.template_removed'));
    });
});

describe('the customers of a send', function () {
    beforeEach(function () {
        hxN8n();
        Http::fake(['https://n8n.example.com/*' => Http::sequence()->push(['id' => 'a'])->push([], 500)->push(['id' => 'c'])->whenEmpty(Http::response(['id' => 'z']))]);
        $this->people = collect(['Aisha Q', 'Bilal Q', 'Chitra Q'])->map(fn ($n) => hxCustomer($n));
        $this->noConsent = hxCustomer('Dina Q', ['marketing_consent_at' => null]);
        $this->batch = hxSend($this->staff, $this->people->pluck('id')->push($this->noConsent->id)->all(), ['channels' => ['notification', 'whatsapp']]);
    });

    it('lists every customer with the channel, status, reason and time, and filters by status', function () {
        $lw = Livewire::test(MessageHistory::class)->call('openView', $this->batch->id);
        $rows = $lw->instance()->recipients;
        expect($rows->total())->toBe(8)->and($rows->items()[0]->customer_name)->toBe('Aisha Q');
        $lw->assertSee('Dina Q')->assertSee(__('messaging_admin.reason.no_marketing_consent'))->assertSee(__('messaging_admin.reason.n8n_http', ['code' => 500]))
            ->assertSee(__('messaging_history.show_text'));

        $failed = $lw->call('setRecipientStatus', 'failed');
        expect($failed->instance()->recipients->pluck('customer_name')->all())->toBe(['Bilal Q'])->and($failed->instance()->recipients->first()->channel)->toBe('whatsapp');
        $skipped = $lw->call('setRecipientStatus', 'skipped');
        expect($skipped->instance()->recipients->pluck('customer_name')->unique()->all())->toBe(['Dina Q'])->and($skipped->instance()->recipients)->toHaveCount(2);
        expect($lw->call('setRecipientStatus', 'delivered')->instance()->recipients)->toHaveCount(0);
        $lw->assertSee(__('messaging_history.no_rows_filtered'));
        $lw->call('setRecipientStatus', 'bogus')->assertSet('recipientStatus', 'delivered'); // unknown choice ignored
        $lw->call('setRecipientStatus', '')->assertSet('recipientStatus', '');
        expect($lw->instance()->recipients->total())->toBe(8);
    });

    it('finds a customer inside the send by name or phone', function () {
        $lw = Livewire::test(MessageHistory::class)->call('openView', $this->batch->id)->set('recipientSearch', 'bilal');
        expect($lw->instance()->recipients->pluck('customer_name')->unique()->all())->toBe(['Bilal Q']);
        $phone = $this->people[2]->phone;
        $lw->set('recipientSearch', substr($phone, 4));
        expect($lw->instance()->recipients->pluck('customer_name')->unique()->all())->toBe(['Chitra Q']);
        $lw->set('recipientSearch', '100%');
        expect($lw->instance()->recipients)->toHaveCount(0);
    });

    it('pages 50 at a time and stays fast for a big send', function () {
        $big = hxBatch($this->staff, ['template_name' => 'Big send', 'recipients_count' => 120, 'sent_count' => 120]);
        $users = User::factory()->count(120)->create(['type' => 'customer']);
        foreach ($users->chunk(60) as $chunk) {
            CustomerMessage::insert($chunk->map(fn ($u) => ['id' => (string) Str::uuid7(), 'batch_id' => $big->id, 'user_id' => $u->id, 'channel' => 'notification', 'locale' => 'en', 'status' => 'sent',
                'title' => 'T', 'body' => 'B', 'sent_at' => now(), 'created_at' => now(), 'updated_at' => now()])->all());
        }
        $lw = Livewire::test(MessageHistory::class)->call('openView', $big->id);
        expect($lw->instance()->recipients)->toHaveCount(50)->and($lw->instance()->recipients->total())->toBe(120);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $lw->call('nextPage', 'rp');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        expect($lw->instance()->recipients)->toHaveCount(50)->and($lw->instance()->recipients->currentPage())->toBe(2)->and($queries)->toBeLessThan(25);
        $lw->call('gotoPage', 3, 'rp');
        expect($lw->instance()->recipients)->toHaveCount(20);
        $lw->set('recipientSearch', 'zzz')->assertSet('recipientSearch', 'zzz'); // a new search starts from page one
        expect($lw->instance()->recipients->currentPage())->toBe(1);
        $lw->call('closeView');
        $lw->call('openView', $big->id);
        expect($lw->instance()->recipients->currentPage())->toBe(1);
    });

    it('never shows a customer outside the viewer’s own branches', function () {
        $w = commerceWorld();
        $mine = hxCustomer('Branch Customer');
        $theirs = hxCustomer('Faraway Customer');
        $order = hxOrderAt($w, $w->a, $mine);
        $branchStaff = staffWithRole('marketing_manager', $w->a);
        $this->actingAs($branchStaff);
        $batch = hxSend($branchStaff, [$mine->id]);
        // the same send also holds a customer who is not (or no longer) theirs: written directly, as if their orders had moved away
        CustomerMessage::create(['batch_id' => $batch->id, 'user_id' => $theirs->id, 'channel' => 'notification', 'locale' => 'en', 'status' => 'sent', 'sent_at' => now()]);

        $lw = Livewire::test(MessageHistory::class)->call('openView', $batch->id);
        expect($lw->instance()->recipients->pluck('customer_name')->all())->toBe(['Branch Customer']);
        $lw->assertSee('Branch Customer')->assertDontSee('Faraway Customer');
        expect($lw->instance()->statusCounts)->toBe(['sent' => 1]);
        // and a search cannot reveal them either
        MessageBatch::whereKey($batch->id)->update(['template_name' => 'Branch note']);
        expect(hxNames(Livewire::test(MessageHistory::class)->set('search', 'Faraway')))->toBe([])
            ->and(hxNames(Livewire::test(MessageHistory::class)->set('search', 'Branch Customer')))->toBe(['Branch note']);
        // a single send to a customer who has since left their branches shows no name at all
        OrderFulfillment::where('order_id', $order->id)->update(['branch_id' => $w->b->id]);
        Livewire::test(MessageHistory::class)->assertDontSee('Branch Customer')->assertSee('1 customer');
    });

    it('shows each person only their own sends unless they have company-wide rights', function () {
        $w = commerceWorld();
        $branchStaff = staffWithRole('marketing_manager', $w->a);
        $c = hxCustomer('Branch Customer');
        hxOrderAt($w, $w->a, $c);
        $own = hxSend($branchStaff, [$c->id]);
        MessageBatch::whereKey($own->id)->update(['template_name' => 'Their own']);
        MessageBatch::whereKey($this->batch->id)->update(['template_name' => 'Head office send']);
        $this->actingAs($branchStaff);
        expect(hxNames(Livewire::test(MessageHistory::class)))->toBe(['Their own'])
            ->and(Livewire::test(MessageHistory::class)->viewData('tiles')['sends'])->toBe(1);
        Livewire::test(MessageHistory::class)->call('openView', $this->batch->id)->assertSet('showView', false);
        $this->actingAs($this->staff);
        expect(hxNames(Livewire::test(MessageHistory::class)))->toEqualCanonicalizing(['Their own', 'Head office send']);
    });
});

describe('retry and cancel', function () {
    it('asks first, then puts only the failed messages back in the queue and tells how many', function () {
        hxN8n();
        Http::fake(['https://n8n.example.com/*' => Http::sequence()->push([], 500)->push(['id' => 'wamid.2'])->whenEmpty(Http::response(['id' => 'wamid.9']))]);
        $failing = hxCustomer('Retry Me');
        $fine = hxCustomer('Already Fine');
        $batch = hxSend($this->staff, [$failing->id, $fine->id], ['channels' => ['whatsapp']]);
        expect($batch)->failed_count->toBe(1)->sent_count->toBe(1);

        $lw = Livewire::test(MessageHistory::class)->call('openView', $batch->id)->assertSee('Retry 1 failed message')->assertSeeHtml('wire:click="askRetry"');
        $lw->call('askRetry')->assertSet('showConfirm', true)->assertSet('confirming', 'retry')->assertSee(__('messaging_history.q_retry'));
        expect($batch->fresh()->failed_count)->toBe(1); // nothing happens before the "yes"
        $lw->call('confirm')->assertSet('showConfirm', false)->assertDispatched('toast', type: 'success', message: '1 message is being sent again.');
        expect($batch->fresh())->failed_count->toBe(0)->sent_count->toBe(2)->status->toBe('done');
        $lw->assertDontSeeHtml('wire:click="askRetry"');
        $lw->call('askRetry')->assertSet('showConfirm', false); // nothing failed any more: nothing to ask
        Http::assertSentCount(3);
    });

    it('does nothing when "yes" arrives without a question, or from someone who may not send', function () {
        $batch = hxSend($this->staff, [hxCustomer()->id]);
        Livewire::test(MessageHistory::class)->call('openView', $batch->id)->call('confirm')->assertNotDispatched('toast');
        $this->actingAs(staffWithRole('auditor'));
        Livewire::test(MessageHistory::class)->call('openView', $batch->id)->call('askRetry')->assertForbidden();
        Livewire::test(MessageHistory::class)->call('openView', $batch->id)->call('confirm')->assertForbidden();
    });

    it('cancels a scheduled send after an explanation, and nothing goes out later', function () {
        $c = hxCustomer('Waiting Wanda');
        $batch = hxSend($this->staff, [$c->id, hxCustomer('Waiting Wael')->id], ['scheduleAt' => CarbonImmutable::now()->addHours(5)]);
        $lw = Livewire::test(MessageHistory::class)->call('openView', $batch->id)->assertSeeHtml('wire:click="askCancel"')->assertSee(__('messaging_admin.batch_status.scheduled'))
            ->assertDontSeeHtml('wire:click="askRetry"');
        $lw->call('askCancel')->assertSet('confirming', 'cancel')->assertSee(__('messaging_history.q_cancel'))->assertSee('Everything still waiting (2) will not be sent.');
        $lw->call('confirm')->assertSet('showConfirm', false)->assertDispatched('toast', type: 'success', message: __('messaging_history.cancel_done'));
        expect($batch->fresh())->status->toBe('cancelled')->completed_at->not->toBeNull();
        expect(CustomerMessage::where('batch_id', $batch->id)->pluck('status')->unique()->all())->toBe(['cancelled']);
        $lw->assertDontSeeHtml('wire:click="askCancel"')->assertSee(__('messaging_admin.batch_status.cancelled'))->assertSee(__('messaging_admin.reason.cancelled_by_staff'));
        $this->travel(6)->hours();
        expect(app(CustomerMessaging::class)->dispatchDue()['started'])->toBe(0);
    });

    it('cancels the part of a running send that has not gone out yet, and keeps what was sent', function () {
        $sentAlready = hxCustomer('Went Out');
        $waiting = hxCustomer('Still Waiting');
        $batch = hxSend($this->staff, [$sentAlready->id, $waiting->id]);
        // one customer is still in the queue
        CustomerMessage::where('batch_id', $batch->id)->where('user_id', $waiting->id)->update(['status' => 'queued', 'sent_at' => null]);
        $batch->forceFill(['status' => 'sending', 'completed_at' => null])->save();
        $batch->refreshCounts();

        $lw = Livewire::test(MessageHistory::class)->call('openView', $batch->id)->assertSeeHtml('wire:poll')->call('askCancel')->assertSee('Everything still waiting (1) will not be sent.');
        $lw->call('confirm');
        expect($batch->fresh())->status->toBe('cancelled')->sent_count->toBe(1)->skipped_count->toBe(1);
        expect(CustomerMessage::where('batch_id', $batch->id)->where('user_id', $sentAlready->id)->value('status'))->toBe('sent');
    });

    it('offers neither action on a finished send, and refuses the call', function () {
        $batch = hxSend($this->staff, [hxCustomer()->id]);
        Livewire::test(MessageHistory::class)->call('openView', $batch->id)->assertDontSeeHtml('wire:click="askCancel"')->assertDontSeeHtml('wire:click="askRetry"')
            ->call('askCancel')->assertSet('showConfirm', false);
    });

    it('cannot touch a send that belongs to someone else when rights stop at one’s own', function () {
        $w = commerceWorld();
        $batch = hxSend($this->staff, [hxCustomer()->id], ['scheduleAt' => CarbonImmutable::now()->addHours(2)]);
        $this->actingAs(staffWithRole('marketing_manager', $w->a));
        $lw = Livewire::test(MessageHistory::class)->call('openView', $batch->id)->assertSet('showView', false);
        $lw->call('askCancel')->assertSet('showConfirm', false);
        expect($batch->fresh()->status)->toBe('scheduled');
    });
});
