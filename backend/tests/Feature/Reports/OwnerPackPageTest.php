<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Notifications\Services\NotificationChannelStatus;
use App\Domain\Reports\Mail\OwnerPackMail;
use App\Domain\Reports\Models\OwnerPackSend;
use App\Domain\Reports\Services\OwnerPackSettings;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Insights\OwnerPackPage;
use App\Support\AdminNavigation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

require_once __DIR__.'/OwnerNumbersSupport.php';

beforeEach(function () {
    $this->world = onWorld();
    $this->settings = app(Settings::class);
    onOrder([[$this->world->a, '250.00']], ['placed_at' => CarbonImmutable::parse('2026-11-10 12:00', 'Asia/Qatar')->utc()]);
    $this->mailReady = function (bool $ready) {
        $this->app->instance(NotificationChannelStatus::class, new class($ready) extends NotificationChannelStatus
        {
            public function __construct(private bool $ready) {}

            public function ready(): array
            {
                return ['email' => $this->ready, 'whatsapp' => false];
            }
        });
        $this->app->forgetInstance(OwnerPackSettings::class);
    };
    ($this->mailReady)(true);
    $this->owner = staffWithRole('admin', null, ['email' => 'me@albahar.test']);
});

describe('who sees it', function () {
    it('is for people who see every branch\'s reports and the settings, and is in their menu', function () {
        $this->actingAs($this->owner);
        Livewire::test(OwnerPackPage::class)->assertOk();
        $this->get(route('admin.owner-pack.index'))->assertOk();
        expect(collect(AdminNavigation::for($this->owner))->flatMap(fn ($g) => array_column($g['items'], 'route'))->all())->toContain('admin.owner-pack.index', 'admin.daily-close.index');

        foreach ([staffWithRole('branch_manager', $this->world->a), staffWithRole('accountant'), staffWithRole('marketing_manager')] as $other) {
            $this->actingAs($other);
            Livewire::test(OwnerPackPage::class)->assertForbidden();
            $this->get(route('admin.owner-pack.index'))->assertForbidden();
            expect(collect(AdminNavigation::for($other))->flatMap(fn ($g) => array_column($g['items'], 'route'))->all())->not->toContain('admin.owner-pack.index');
        }
    });

    it('lets a read-only person look at the pack and the log but not change anything', function () {
        $this->actingAs(staffWithRole('auditor'));

        Livewire::test(OwnerPackPage::class)->assertOk()->assertDontSeeHtml('data-testid="open-settings"')->call('openSettings')->assertForbidden();
        Livewire::test(OwnerPackPage::class)->call('save')->assertForbidden();
    });
});

describe('the first screen', function () {
    it('says what is going to happen: off until switched on, and the pack as it will arrive', function () {
        $this->actingAs($this->owner);

        Livewire::test(OwnerPackPage::class)->assertSee(__('owner_pack.off_hint'))->assertSee(__('owner_pack.send_test'))->assertSeeHtml('data-testid="pack-preview"')
            ->assertSee('QAR 250.00')->assertSee(__('owner_pack.attention_title'))->assertSeeHtml('data-metric="sales"')
            ->assertSeeHtml('data-testid="pack-assembly"')->assertSee(__('owner_pack.assembly_title'));
    });

    it('says when the next pack goes and to how many, once it is on', function () {
        $this->settings->set('owner_pack.enabled', true);
        $this->settings->set('owner_pack.emails', ['a@albahar.test', 'b@albahar.test']);
        $this->actingAs($this->owner);

        Livewire::test(OwnerPackPage::class)->assertSee(__('owner_pack.on'))->assertSee(__('owner_pack.next', ['week' => 46, 'when' => 'Monday 16 Nov, 08:00']))
            ->assertSee(trans_choice('owner_pack.recipients', 2, ['count' => 2]));
    });

    it('shows the pack of another week and in the other language, right to left in Arabic', function () {
        $this->actingAs($this->owner);

        Livewire::test(OwnerPackPage::class)->set('week', '2026-W45')->assertSee('QAR 0.00')->set('lang', 'ar')->assertSee('حزمة المالك الأسبوعية')->assertSeeHtml('dir="rtl"')
            ->set('week', 'bogus')->assertOk();                                                // an unknown week shows the latest, never an error page
    });

    it('says WhatsApp is not connected yet, as a state and not as a failure, and disables its fields', function () {
        $this->actingAs($this->owner);

        Livewire::test(OwnerPackPage::class)->assertSeeHtml('data-testid="whatsapp-state"')->assertSee(__('owner_pack.not_connected'))->assertSee(__('owner_pack.whatsapp_not_connected_hint'))
            ->call('openSettings')->assertSeeHtml('data-testid="whatsapp-note"');
    });

    it('warns that this server cannot send e-mail yet', function () {
        ($this->mailReady)(false);
        $this->actingAs($this->owner);

        Livewire::test(OwnerPackPage::class)->assertSeeHtml('data-testid="mail-not-ready"')->call('sendTest')->assertDispatched('toast', type: 'error', message: __('owner_pack.errors.mail_not_ready'));
    });

    it('is calm with an empty log, and lists every send, newest first, with why one failed', function () {
        $this->actingAs($this->owner);
        Livewire::test(OwnerPackPage::class)->assertSee(__('owner_pack.log_empty'));

        OwnerPackSend::create(['iso_week' => '2026-W45', 'channel' => 'email', 'kind' => 'scheduled', 'status' => 'partial', 'recipients_count' => 3, 'sent_count' => 2, 'failed_count' => 1, 'error' => 'some_failed']);
        OwnerPackSend::create(['iso_week' => '2026-W46', 'channel' => 'whatsapp', 'kind' => 'scheduled', 'status' => 'not_connected', 'recipients_count' => 1, 'error' => 'whatsapp_not_connected']);

        Livewire::test(OwnerPackPage::class)->assertSee('2026-W45')->assertSee(__('owner_pack.status.partial'))->assertSee('2 / 3')->assertSee(__('owner_pack.error.whatsapp_not_connected'))
            ->assertSee(__('owner_pack.status.not_connected'))->assertDontSee(__('owner_pack.log_empty'));
    });
});

describe('send test to me', function () {
    it('sends the shown week to the person who pressed it and says so', function () {
        Mail::fake();
        $this->actingAs($this->owner);

        Livewire::test(OwnerPackPage::class)->call('sendTest')->assertDispatched('toast', type: 'success', message: __('owner_pack.test_sent', ['email' => 'me@albahar.test']));

        Mail::assertSent(OwnerPackMail::class, fn ($m) => $m->hasTo('me@albahar.test'));
        expect(OwnerPackSend::first()->kind)->toBe('test');
    });
});

describe('the settings', function () {
    it('saves who, when and how, in clean form, through the audited settings', function () {
        $this->actingAs($this->owner);

        Livewire::test(OwnerPackPage::class)->call('openSettings')->assertSet('showSettings', true)
            ->set('form.enabled', true)->set('form.emails', "Owner@AlBahar.test, partner@albahar.test;\nowner@albahar.test")->set('form.day', '3')->set('form.time', '09:30')->set('form.language', 'ar')
            ->call('save')->assertHasNoErrors()->assertSet('showSettings', false);

        $s = app(OwnerPackSettings::class);
        expect([$s->enabled(), $s->emails(), $s->day(), $s->time(), $s->language(), $s->emailOn(), $s->whatsappOn()])->toBe([true, ['owner@albahar.test', 'partner@albahar.test'], 3, '09:30', 'ar', true, false])
            ->and(AuditLog::where('action', 'settings.updated')->where('metadata->key', 'owner_pack.emails')->count())->toBe(1);
        Livewire::test(OwnerPackPage::class)->call('openSettings')->call('save')->assertDispatched('toast', type: 'success', message: __('owner_pack.unchanged'));   // saving the same again changes nothing
    });

    it('refuses what cannot work: a bad address, too many, nobody to send to, a bad time, and says which field', function () {
        $this->actingAs($this->owner);
        $page = Livewire::test(OwnerPackPage::class)->call('openSettings');

        $page->set('form.emails', 'not-an-email')->call('save')->assertHasErrors('form.emails');
        $page->set('form.emails', str_repeat('a', 60).'.'.str_repeat('b', 60).'@'.str_repeat('c', 60).'.'.str_repeat('d', 60).'.test')->call('save')->assertHasErrors('form.emails');   // longer than the log can hold
        $page->set('form.emails', implode(',', array_map(fn ($i) => "o{$i}@albahar.test", range(1, 11))))->call('save')->assertHasErrors('form.emails');
        $page->set('form.emails', '')->set('form.enabled', true)->call('save')->assertHasErrors('form.emails');
        $page->set('form.enabled', false)->set('form.time', '25:99')->call('save')->assertHasErrors('form.time');
        $page->set('form.time', '08:00')->set('form.day', '9')->call('save')->assertHasErrors('form.day');
        $page->set('form.day', '1')->set('form.language', 'fr')->call('save')->assertHasErrors('form.language');
        $page->set('form.language', 'en')->set('form.numbers', 'abc')->call('save')->assertHasErrors('form.numbers');
        expect($this->settings->get('owner_pack.enabled'))->toBeNull();
    });

    it('turns a typed WhatsApp number into international form and keeps only valid ones', function () {
        $this->actingAs($this->owner);

        Livewire::test(OwnerPackPage::class)->call('openSettings')->set('form.numbers', "5512 3456\n+974 6612 3456")->set('form.emails', 'a@albahar.test')->call('save')->assertHasNoErrors();

        expect(app(OwnerPackSettings::class)->numbers())->toBe(['+97455123456', '+97466123456']);
    });
});
