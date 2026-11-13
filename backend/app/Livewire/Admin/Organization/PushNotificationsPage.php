<?php

namespace App\Livewire\Admin\Organization;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\UserDevice;
use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Notifications\Services\FcmClient;
use App\Domain\Notifications\Services\FirebaseConfig;
use App\Domain\Notifications\Services\ServiceAccountKey;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Support\BusinessTime;
use Illuminate\Support\Str;
use Livewire\Component;
use Throwable;

/**
 * Dashboard page for Firebase push: the master switch (acts at once), the setup checklist, the guided steps, the Firebase form with
 * the sealed server key, "Test connection", and the signed-in person's own browsers ("Enable on this browser" + a real test push).
 * Every value is saved through the Settings service (audited there); the server key is write-only and never sent back to the page.
 */
class PushNotificationsPage extends Component
{
    use InteractsWithAdmin;

    public bool $enabled = false;

    public string $projectId = '';

    public string $apiKey = '';

    public string $authDomain = '';

    public string $senderId = '';

    public string $appId = '';

    public string $vapidKey = '';

    /** The service-account file typed or uploaded in this visit; cleared after every save. */
    public string $serviceAccountJson = '';

    public bool $replacingKey = false;

    public bool $confirmRemoveKey = false;

    /** @var array{ok: bool, code: string, detail: ?string}|null */
    public ?array $connection = null;

    /** @var list<array{label: string, outcome: string, detail: ?string}> */
    public array $testResults = [];

    /** setting key => form property */
    private const FIELDS = [
        FirebaseConfig::PROJECT_ID => 'projectId',
        FirebaseConfig::API_KEY => 'apiKey',
        FirebaseConfig::AUTH_DOMAIN => 'authDomain',
        FirebaseConfig::SENDER_ID => 'senderId',
        FirebaseConfig::APP_ID => 'appId',
        FirebaseConfig::VAPID_KEY => 'vapidKey',
    ];

    public function mount(Settings $settings, FirebaseConfig $config): void
    {
        abort_unless($this->canGlobally('settings.view'), 403);
        $this->enabled = $config->enabled();
        foreach (self::FIELDS as $key => $prop) {
            $this->{$prop} = (string) $settings->get($key);
        }
    }

    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return [
            'projectId' => ['nullable', 'string', 'regex:/^[a-z][a-z0-9-]{4,28}[a-z0-9]$/', 'required_with:apiKey,senderId,appId,vapidKey,serviceAccountJson'],
            'apiKey' => ['nullable', 'regex:/^AIza[0-9A-Za-z_-]{35}$/', 'required_with:senderId,appId,vapidKey'],
            'authDomain' => ['nullable', 'regex:/^(?=.{4,100}$)[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i'],
            'senderId' => ['nullable', 'regex:/^\d{6,20}$/', 'required_with:apiKey,appId,vapidKey'],
            'appId' => ['nullable', 'regex:/^1:\d{6,20}:web:[0-9a-f]{8,40}$/i', 'required_with:apiKey,senderId,vapidKey'],
            'vapidKey' => ['nullable', 'regex:/^B[A-Za-z0-9_-]{86}$/', 'required_with:apiKey,senderId,appId'],
            'serviceAccountJson' => ['nullable', 'string', 'max:'.ServiceAccountKey::MAX_BYTES],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        $out = [];
        foreach (['projectId' => 'project_id', 'apiKey' => 'api_key', 'authDomain' => 'auth_domain', 'senderId' => 'sender_id', 'appId' => 'app_id', 'vapidKey' => 'vapid_key'] as $prop => $lang) {
            $out["$prop.regex"] = __('push_admin.err.'.$lang);
            $out["$prop.required_with"] = __('push_admin.err.required');
        }
        $out['serviceAccountJson.max'] = __('push_admin.key_error.too_large');

        return $out;
    }

    /** Shows a field's format problem as soon as the person leaves it (or pastes the file). */
    public function updated(string $name): void
    {
        if (! array_key_exists($name, $this->rules())) {
            return;
        }
        if ($name !== 'serviceAccountJson') {
            $this->{$name} = $name === 'vapidKey' ? rtrim(trim($this->{$name}), '=') : trim($this->{$name});
        }
        $this->validateOnly($name);
        if ($name === 'serviceAccountJson' && trim($this->serviceAccountJson) !== '') {
            $this->checkKeyFile();
        }
    }

    /** The master switch acts at once (no Save button). It cannot be turned on before a server key exists. */
    public function toggleEnabled(Settings $settings, FirebaseConfig $config): void
    {
        abort_unless($this->canGlobally('settings.edit'), 403);
        $on = ! $this->enabled;
        if ($on && ! $config->hasServerCredentials()) {
            $this->toast(__('push_admin.need_setup'), 'error');

            return;
        }
        $settings->set(FirebaseConfig::ENABLED, $on);
        $this->enabled = $on;
        app(AuditLogger::class)->log($on ? 'push.switched_on' : 'push.switched_off', 'push');
        $this->toast($on ? __('push_admin.switched_on') : __('push_admin.switched_off'));
    }

    public function save(Settings $settings, FirebaseConfig $config): void
    {
        abort_unless($this->canGlobally('settings.edit'), 403);
        foreach (self::FIELDS as $prop) {
            $this->{$prop} = $prop === 'vapidKey' ? rtrim(trim($this->{$prop}), '=') : trim($this->{$prop});
        }
        $this->validate();

        $newKey = null;
        if (trim($this->serviceAccountJson) !== '') {
            $newKey = $this->checkKeyFile();
            if ($newKey === null) {
                return;
            }
        } elseif ($config->serviceAccountSource() === 'dashboard') {
            $stored = $config->serviceAccount();
            if ($this->projectId === '') {
                $this->addError('projectId', __('push_admin.err.required'));

                return;
            }
            if (($stored['project_id'] ?? null) !== $this->projectId) {
                $this->addError('projectId', __('push_admin.key_error.project_mismatch_saved'));

                return;
            }
        }

        $changed = 0;
        foreach (self::FIELDS as $key => $prop) {
            $value = $this->{$prop} === '' ? null : $this->{$prop};
            if ((string) $settings->get($key) !== (string) $value) {
                $settings->set($key, $value);
                $changed++;
            }
        }
        if ($newKey !== null) {
            $settings->set(FirebaseConfig::SERVICE_ACCOUNT, json_encode($newKey, JSON_UNESCAPED_SLASHES), secret: true);
            app(AuditLogger::class)->log('push.service_account_replaced', 'push', [], [], ['client_email' => $newKey['client_email'], 'project_id' => $newKey['project_id']]);
            $changed++;
        }
        $this->reset('serviceAccountJson', 'replacingKey');
        $this->connection = null;
        $this->toast($changed ? __('push_admin.saved') : __('push_admin.unchanged'));
    }

    public function removeServiceAccount(Settings $settings, FirebaseConfig $config): void
    {
        abort_unless($this->canGlobally('settings.edit'), 403);
        $this->confirmRemoveKey = false;
        if ($config->serviceAccountSource() !== 'dashboard') {
            return;
        }
        $email = $config->serviceAccount()['client_email'] ?? null;
        $settings->set(FirebaseConfig::SERVICE_ACCOUNT, null, secret: true);
        app(AuditLogger::class)->log('push.service_account_removed', 'push', [], [], ['client_email' => $email]);
        $this->connection = null;
        $this->toast(__('push_admin.key_removed'));
    }

    /** Asks Firebase to validate a message with the SAVED key; nothing is delivered to any device. */
    public function testConnection(FcmClient $fcm): void
    {
        abort_unless($this->canGlobally('settings.edit'), 403);
        try {
            $result = $fcm->check();
        } catch (Throwable $e) {
            report($e);
            $result = ['ok' => false, 'code' => 'oauth_failed', 'status' => null, 'detail' => null];
        }
        $this->connection = ['ok' => $result['ok'], 'code' => $result['code'], 'detail' => $result['detail'] ?? null];
        app(AuditLogger::class)->log('push.connection_tested', 'push', [], [], ['ok' => $result['ok'], 'code' => $result['code'], 'status' => $result['status'] ?? null]);
        $this->toast($result['ok'] ? __('push_admin.conn_ok') : __('push_admin.conn.'.$result['code']), $result['ok'] ? 'success' : 'error');
    }

    /** A real push to every active device of the signed-in person (browsers and the staff app), in the dashboard language. */
    public function sendTest(FcmClient $fcm, FirebaseConfig $config): void
    {
        abort_unless($this->canGlobally('settings.edit'), 403);
        $this->testResults = [];
        if (! $config->configured()) {
            $this->toast(__('push_admin.test.not_ready'), 'error');

            return;
        }
        $user = auth()->user();
        $devices = $user->devices()->whereNull('disabled_at')->orderByDesc('last_seen_at')->get();
        if ($devices->isEmpty()) {
            $this->toast(__('push_admin.test.no_devices'), 'error');

            return;
        }
        $title = __('push_admin.test.title');
        $body = __('push_admin.test.body', ['time' => BusinessTime::display(now())]);
        $ok = 0;
        $lastError = null;
        foreach ($devices as $device) {
            try {
                $res = $fcm->send($device->push_token, $title, $body, ['event' => 'push.test', 'kind' => 'test', 'deep_link' => '/admin/push'], $device);
            } catch (Throwable $e) {
                report($e);
                $res = ['ok' => false, 'error' => 'REQUEST_FAILED'];
            }
            if ($res['unregistered'] ?? false) {
                $device->update(['disabled_at' => now()]);
            }
            $outcome = ($res['ok'] ?? false) ? 'sent' : (($res['unregistered'] ?? false) ? 'removed' : 'failed');
            $ok += $outcome === 'sent' ? 1 : 0;
            $lastError = $outcome === 'sent' ? $lastError : ($res['error'] ?? null);
            $this->testResults[] = ['label' => $this->deviceLabel($device), 'outcome' => $outcome, 'detail' => $outcome === 'failed' ? ($res['error'] ?? null) : null];
        }
        NotificationLog::create([
            'user_id' => $user->id, 'event' => 'push.test', 'channel' => 'push', 'provider' => 'fcm', 'recipient' => $devices->count().' device(s)',
            'status' => $ok > 0 ? 'sent' : 'failed', 'attempts' => 1, 'sent_at' => $ok > 0 ? now() : null,
            'error' => $ok > 0 ? null : substr((string) ($lastError ?? 'failed'), 0, 490), 'response' => ['delivered_to' => $ok, 'devices' => $devices->count()],
        ]);
        app(AuditLogger::class)->log('push.test_sent', 'push', [], [], ['delivered' => $ok, 'devices' => $devices->count()]);
        $this->toast(__('push_admin.test.sent_n', ['ok' => $ok, 'total' => $devices->count()]), $ok > 0 ? 'success' : 'error');
    }

    /** Stops pushes to one of the signed-in person's own devices (never anybody else's). */
    public function removeDevice(string $id): void
    {
        abort_unless($this->canGlobally('settings.view'), 403);
        if (! Str::isUuid($id)) {
            return;
        }
        $device = UserDevice::where('user_id', auth()->id())->whereKey($id)->whereNull('disabled_at')->first();
        if ($device === null) {
            return;
        }
        $device->update(['disabled_at' => now()]);
        app(AuditLogger::class)->log('push.device_removed', $device, [], ['platform' => $device->platform, 'browser' => $device->browser]);
        $this->toast(__('push_admin.device_removed'));
    }

    /** Validates the pasted/uploaded file against the Project ID; returns the cleaned key or null after showing why not. */
    private function checkKeyFile(): ?array
    {
        $this->resetErrorBag('serviceAccountJson');
        if (trim($this->projectId) === '') {
            $this->addError('serviceAccountJson', __('push_admin.key_error.need_project'));

            return null;
        }
        $check = ServiceAccountKey::check($this->serviceAccountJson, trim($this->projectId));
        if (! $check['ok']) {
            $this->addError('serviceAccountJson', __('push_admin.key_error.'.$check['error']));

            return null;
        }

        return $check['key'];
    }

    private function deviceLabel(UserDevice $d): string
    {
        $kind = $d->platform === 'web' ? ($d->browser ?: __('push_admin.device_kind.web')) : __('push_admin.device_kind.'.$d->platform);

        return $d->app ? $kind.' · '.__('push_admin.app.'.$d->app) : $kind;
    }

    private function isDirty(Settings $settings): bool
    {
        foreach (self::FIELDS as $key => $prop) {
            if (trim($this->{$prop}) !== (string) $settings->get($key)) {
                return true;
            }
        }

        return trim($this->serviceAccountJson) !== '';
    }

    public function render(Settings $settings, FirebaseConfig $config)
    {
        $status = $config->status();
        $mine = UserDevice::where('user_id', auth()->id())->whereNull('disabled_at')->orderByDesc('last_seen_at')->limit(20)->get();
        $web = $config->webConfig();
        $lastOk = NotificationLog::where('channel', 'push')->where('status', 'sent')->orderByDesc('sent_at')->first(['sent_at', 'event']);
        $lastError = NotificationLog::where('channel', 'push')->where('status', 'failed')->orderByDesc('updated_at')->first(['updated_at', 'error', 'event']);

        return view('livewire.admin.organization.push', [
            'status' => $status,
            'canEdit' => $this->canGlobally('settings.edit'),
            'dirty' => $this->isDirty($settings),
            'counts' => UserDevice::whereNull('disabled_at')->selectRaw('platform, count(*) as n')->groupBy('platform')->pluck('n', 'platform')->all(),
            'mine' => $mine,
            'lastOk' => $lastOk?->sent_at,
            'lastError' => $lastError,
            'boot' => [
                'config' => $config->configured() ? $web : null,
                'endpoint' => route('admin.push.devices.store'),
                'text' => __('push_admin.browser_err'),
                'done' => __('push_admin.enabled_browser'),
            ],
            'allReady' => $status['server'] && $status['web'] && $this->enabled,
        ])->title(__('push_admin.title'));
    }
}
