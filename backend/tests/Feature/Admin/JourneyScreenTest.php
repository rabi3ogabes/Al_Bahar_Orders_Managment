<?php

use App\Domain\Marketing\Models\Journey;
use App\Domain\Marketing\Models\JourneyStep;
use App\Domain\Marketing\Models\JourneyTemplate;
use App\Domain\Marketing\Services\JourneyService;
use App\Domain\Notifications\Models\Notification;
use App\Livewire\Admin\Marketing\JourneyIndex;
use Database\Factories\BranchFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->marketing = staffWithRole('marketing_manager');
    $this->actingAs($this->marketing);
    app(JourneyService::class)->ensureDefaults();
});

it('opens for marketing staff and seeds both journeys paused', function () {
    $this->get(route('admin.journeys.index'))->assertOk()->assertSee(__('journeys_admin.title'));
    expect(Journey::count())->toBe(2)->and(Journey::where('is_enabled', true)->count())->toBe(0);
});

it('is closed to staff without marketing access', function () {
    $this->actingAs(staffWithRole('delivery_employee', BranchFactory::new()->create()))->get(route('admin.journeys.index'))->assertForbidden();
});

it('switches a journey on and off at once and refuses when it has no usable step', function () {
    Livewire::test(JourneyIndex::class)->call('toggle');
    expect(Journey::where('key', 'abandoned_cart')->value('is_enabled'))->toBeTrue();
    Livewire::test(JourneyIndex::class)->call('toggle');
    expect(Journey::where('key', 'abandoned_cart')->value('is_enabled'))->toBeFalse();

    JourneyStep::query()->delete();
    Livewire::test(JourneyIndex::class)->call('toggle');
    expect(Journey::where('key', 'abandoned_cart')->value('is_enabled'))->toBeFalse();
});

it('adds, edits and reorders cart steps by their delay', function () {
    $tpl = JourneyTemplate::first();
    Livewire::test(JourneyIndex::class)->call('editStep')->set('step.label', 'Late nudge')->set('step.delay_value', 2)->set('step.delay_unit', 'hours')
        ->set('step.channels', ['push', 'whatsapp'])->set('step.template_id', $tpl->id)->call('saveStep')->assertHasNoErrors();
    $s = JourneyStep::where('label', 'Late nudge')->first();
    expect($s->delay_minutes)->toBe(120)->and($s->position)->toBe(2); // 30m, 2h, 4h, 3d

    Livewire::test(JourneyIndex::class)->call('editStep')->set('step.delay_value', 8)->set('step.delay_unit', 'days')->set('step.channels', ['push'])->set('step.template_id', $tpl->id)
        ->call('saveStep')->assertHasErrors('step.delay_value');
    Livewire::test(JourneyIndex::class)->call('editStep')->set('step.delay_value', 5)->set('step.channels', [])->set('step.template_id', $tpl->id)->call('saveStep')->assertHasErrors('step.channels');
});

it('keeps birthday steps ordered by day and time', function () {
    $tpl = JourneyTemplate::first();
    Livewire::test(JourneyIndex::class, ['tab' => 'birthday'])->call('editStep')->set('step.offset_days', -3)->set('step.send_time', '09:30')->set('step.channels', ['push'])->set('step.template_id', $tpl->id)
        ->call('saveStep')->assertHasNoErrors();
    expect(JourneyStep::where('offset_days', -3)->value('position'))->toBe(1)->and(JourneyStep::where('offset_days', 0)->value('position'))->toBe(2);
});

it('saves quiet hours and the daily limit', function () {
    Livewire::test(JourneyIndex::class)->set('rules.quiet_start', '23:00')->set('rules.quiet_end', '07:00')->set('rules.max_per_day', 2)->call('saveRules')->assertHasNoErrors();
    $j = Journey::where('key', 'abandoned_cart')->first();
    expect($j->setting('quiet_start'))->toBe('23:00')->and($j->setting('max_per_day'))->toBe(2);
    Livewire::test(JourneyIndex::class)->set('rules.quiet_start', '23:00')->set('rules.quiet_end', '')->call('saveRules')->assertHasErrors('rules.quiet_end');
});

it('creates a template, test-sends it to the admin and protects templates in use', function () {
    $c = Livewire::test(JourneyIndex::class, ['tab' => 'templates'])->call('editTemplate')->set('tpl.name', 'Spring')->set('tpl.title_en', 'Spring picks')->set('tpl.title_ar', 'اختيارات الربيع')
        ->set('tpl.body_en', 'Hello {{name}}')->set('tpl.body_ar', 'مرحباً {{name}}')->call('saveTemplate')->assertHasNoErrors();
    $t = JourneyTemplate::where('name', 'Spring')->firstOrFail();
    $c->call('editTemplate', $t->id)->set('previewLocale', 'ar')->set('testChannels', ['in_app'])->call('sendTest');
    expect(Notification::where('user_id', $this->marketing->id)->value('title'))->toBe('[TEST] اختيارات الربيع');

    $used = JourneyStep::first()->template;
    Livewire::test(JourneyIndex::class, ['tab' => 'templates'])->call('deleteTemplate', $used->id);
    expect(JourneyTemplate::whereKey($used->id)->exists())->toBeTrue();
    Livewire::test(JourneyIndex::class, ['tab' => 'templates'])->call('deleteTemplate', $t->id);
    expect(JourneyTemplate::whereKey($t->id)->exists())->toBeFalse();
});

it('rejects a malformed deep link and renders the activity tab', function () {
    Livewire::test(JourneyIndex::class, ['tab' => 'templates'])->call('editTemplate')->set('tpl.name', 'X')->set('tpl.title_en', 'a')->set('tpl.title_ar', 'ب')->set('tpl.body_en', 'a')->set('tpl.body_ar', 'ب')
        ->set('tpl.deep_link', 'javascript:alert(1)')->call('saveTemplate')->assertHasErrors('tpl.deep_link');
    Livewire::test(JourneyIndex::class, ['tab' => 'activity'])->assertOk();
    app(JourneyService::class)->ensureDefaults();
});
