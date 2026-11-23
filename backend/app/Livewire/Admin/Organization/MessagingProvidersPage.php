<?php

namespace App\Livewire\Admin\Organization;

use App\Domain\Identity\Services\FreshTwoFactor;
use App\Domain\Identity\Services\OtpTestMode;
use App\Domain\Notifications\Models\MessageProviderEvent;
use App\Domain\Notifications\Providers\MessagingGateway;
use App\Domain\Notifications\Providers\MessagingProviders;
use App\Domain\Notifications\Providers\MessagingTester;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Support\Phone;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * SMS and WhatsApp providers: choose one (a ready preset with the fields it needs, a fake example and a link to its console), save the keys
 * sealed, send a real test message and see the provider's answer, and, once a test was received on a phone, let the owner turn the test
 * sign-in codes off. Provider keys are write-only: they are never sent back to the page.
 */
class MessagingProvidersPage extends Component
{
    use InteractsWithAdmin;

    public string $smsProvider = 'none';

    public string $whatsappProvider = 'none';

    /** @var array<string, array<string, string>> provider => field => typed value (a saved secret stays empty here) */
    public array $fields = [];

    public string $testChannel = 'sms';

    public string $testNumber = '';

    public bool $showCode = false;

    /** on = switch the test sign-in codes back on, off = turn them off */
    #[Locked]
    public string $codeAction = 'off';

    public string $code = '';

    public function mount(MessagingProviders $providers): void
    {
        abort_unless($this->canGlobally('settings.view'), 403);
        $this->smsProvider = $providers->chosen('sms');
        $this->whatsappProvider = $providers->chosen('whatsapp');
        foreach ($providers->presets() as $key => $preset) {
            $saved = $providers->values($key);
            foreach ($preset->fields() as $f) {
                $this->fields[$key][$f['key']] = $f['secret'] ? '' : ($saved[$f['key']] ?? '');
            }
        }
    }

    /** Which fields of a provider the form shows: the ones its chosen channels use. @return list<array<string, mixed>> */
    private function shown(string $provider, MessagingProviders $providers): array
    {
        $channels = array_values(array_filter([$this->smsProvider === $provider ? 'sms' : null, $this->whatsappProvider === $provider ? 'whatsapp' : null]));

        return array_values(array_filter($providers->preset($provider)?->fields() ?? [], fn (array $f) => array_intersect($f['channels'], $channels) !== []));
    }

    public function save(MessagingProviders $providers, Settings $settings): void
    {
        abort_unless($this->canGlobally('settings.edit'), 403);
        $this->validate(['smsProvider' => 'in:none,http,twilio,unifonic,infobip', 'whatsappProvider' => 'in:none,twilio']);
        $errors = false;
        $chosen = array_unique(array_filter([$this->smsProvider, $this->whatsappProvider], fn ($p) => $p !== 'none'));
        foreach ($chosen as $provider) {
            foreach ($this->shown($provider, $providers) as $f) {
                $path = "fields.{$provider}.{$f['key']}";
                $value = trim((string) ($this->fields[$provider][$f['key']] ?? ''));
                $needed = $f['required'] || ($provider === 'twilio' && ($f['key'] === 'whatsapp_from' ? $this->whatsappProvider === 'twilio' : $this->smsProvider === 'twilio' && $f['key'] === 'sms_from'));
                if ($value === '' && ! ($f['secret'] && $providers->has($provider, $f['key']))) {
                    if ($needed) {
                        $this->addError($path, __('validation.required', ['attribute' => __("messaging_providers.f.{$provider}.{$f['key']}")]));
                        $errors = true;
                    }

                    continue;
                }
                if ($value !== '' && validator([$f['key'] => $value], [$f['key'] => $f['rule']])->fails()) {
                    $this->addError($path, __('messaging_providers.invalid_format'));
                    $errors = true;
                } elseif ($value !== '' && in_array($f['key'], ['url'], true) && ! MessagingGateway::safeUrl($value)) {
                    $this->addError($path, __('messaging_providers.unsafe_url'));
                    $errors = true;
                }
            }
        }
        if ($errors) {
            return;
        }
        foreach ($chosen as $provider) {
            foreach ($this->shown($provider, $providers) as $f) {
                $value = trim((string) ($this->fields[$provider][$f['key']] ?? ''));
                if ($value !== '' || ! $f['secret']) {
                    $settings->set(MessagingProviders::settingKey($provider, $f['key']), $value === '' ? null : $value, secret: $f['secret'] && $value !== '');
                }
                if ($f['secret']) {
                    $this->fields[$provider][$f['key']] = '';
                }
            }
        }
        $settings->set(MessagingProviders::SMS, $this->smsProvider);
        $settings->set(MessagingProviders::WHATSAPP, $this->whatsappProvider);
        $providers->touch();
        $this->toast(__('messaging_providers.saved'));
    }

    public function sendTest(MessagingTester $tester, MessagingProviders $providers): void
    {
        abort_unless($this->canGlobally('settings.edit'), 403);
        $this->resetErrorBag('testNumber');
        $channel = $this->testChannel === 'whatsapp' ? 'whatsapp' : 'sms';
        $number = Phone::normalize($this->testNumber);
        if ($number === null) {
            $this->addError('testNumber', __('errors.phone_invalid'));

            return;
        }
        if (! $providers->configured($channel)) {
            $this->addError('testNumber', __('messaging_providers.not_connected_test'));

            return;
        }
        $event = $this->attempt(fn () => $tester->send($channel, $number, auth()->user()));
        if ($event) {
            $this->toast($event->status === 'failed' ? __('messaging_providers.test_failed') : __('messaging_providers.test_sent'), $event->status === 'failed' ? 'error' : 'success');
        }
    }

    public function refreshStatus(string $id, MessagingTester $tester): void
    {
        abort_unless($this->canGlobally('settings.view'), 403);
        $tester->refresh(MessageProviderEvent::where('purpose', 'test')->findOrFail($id));
    }

    public function confirmReceived(string $id, MessagingTester $tester): void
    {
        abort_unless($this->canGlobally('settings.edit'), 403);
        $this->attempt(fn () => $tester->confirmReceived(MessageProviderEvent::where('purpose', 'test')->findOrFail($id), auth()->user()), __('messaging_providers.confirmed'));
    }

    public function askCode(string $action): void
    {
        abort_unless($this->canGlobally('settings.edit') && auth()->user()->isSuperAdmin(), 403);
        abort_unless(in_array($action, ['on', 'off'], true), 422);
        $this->codeAction = $action;
        $this->code = '';
        $this->resetErrorBag('code');
        if (! app(FreshTwoFactor::class)->canConfirm(auth()->user())) {
            $this->toast(__('org_admin.sec_enrol'), 'error');

            return;
        }
        $this->showCode = true;
    }

    public function switchTestCodes(OtpTestMode $mode, FreshTwoFactor $fresh): void
    {
        abort_unless($this->canGlobally('settings.edit') && auth()->user()->isSuperAdmin(), 403);
        $this->resetErrorBag('code');
        if (! $fresh->isFresh() && ! $fresh->confirm(auth()->user(), $this->code)) {
            $this->addError('code', __('errors.two_factor_invalid'));

            return;
        }
        $done = $this->attempt(fn () => $this->codeAction === 'off' ? $mode->turnOff(auth()->user()) : $mode->turnOn(auth()->user()));
        $this->showCode = false;
        $this->code = '';
        if ($done !== null) {
            $this->toast($this->codeAction === 'off' ? __('messaging_providers.codes_off_done') : __('messaging_providers.codes_on_done'));
        }
    }

    public function render(MessagingProviders $providers, OtpTestMode $mode)
    {
        $shown = [];
        foreach (array_unique(array_filter([$this->smsProvider, $this->whatsappProvider], fn ($p) => $p !== 'none')) as $p) {
            $shown[$p] = $this->shown($p, $providers);
        }

        return view('livewire.admin.organization.messaging-providers', [
            'presets' => $providers->presets(),
            'shown' => $shown,
            'saved' => collect($shown)->map(fn ($fs, $p) => collect($fs)->filter(fn ($f) => $f['secret'] && $providers->has($p, $f['key']))->pluck('key')->all())->all(),
            'smsReady' => $providers->configured('sms'),
            'whatsappReady' => $providers->configured('whatsapp'),
            'whatsappMeta' => config('services.whatsapp.phone_number_id') && config('services.whatsapp.access_token'),
            'smsEnv' => config('services.sms.driver') === 'http',
            'events' => MessageProviderEvent::query()->whereIn('purpose', ['test', 'otp', 'message'])->orderByDesc('created_at')->orderByDesc('id')->limit(8)->get(),
            'canEdit' => $this->canGlobally('settings.edit'),
            'isOwner' => (bool) auth()->user()?->isSuperAdmin(),
            'codes' => ['active' => $mode->active(), 'switchedOff' => $mode->switchedOff(), 'envSet' => $mode->envCode() !== '', 'proven' => $mode->providerProven(), 'canOff' => $mode->canTurnOff(), 'smsEnv' => config('services.sms.driver') === 'http'],
            'needsCode' => ! app(FreshTwoFactor::class)->isFresh(),
        ])->title(__('messaging_providers.title'));
    }
}
