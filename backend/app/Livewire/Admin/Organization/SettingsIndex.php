<?php

namespace App\Livewire\Admin\Organization;

use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Runtime settings backed by the Settings service (system_settings, falling back to config/platform.php).
 * Secrets are write-only: the UI shows a mask and only replaces the value when a new one is typed.
 */
class SettingsIndex extends Component
{
    use InteractsWithAdmin;

    #[Url]
    public string $tab = 'general';

    /** @var array<string, array<string, mixed>> group => [field => value]; field = setting key with "." → "__" */
    public array $values = [];

    /** @var array<string, string> new secret values typed by the user */
    public array $secrets = [];

    public function mount(Settings $settings): void
    {
        abort_unless($this->canGlobally('settings.view'), 403);
        if (! array_key_exists($this->tab, self::schema())) {
            $this->tab = 'general';
        }
        foreach (self::schema() as $group => $fields) {
            foreach ($fields as $key => $def) {
                if (($def['type'] ?? null) === 'secret') {
                    $this->secrets[self::field($key)] = '';

                    continue;
                }
                $v = $settings->get($key);
                $this->values[$group][self::field($key)] = $def['type'] === 'bool' ? (bool) $v : ($v === null ? '' : (string) $v);
            }
        }
    }

    public static function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    /** group => [setting key => definition] */
    public static function schema(): array
    {
        $money = ['required', 'numeric', 'min:0', 'max:100000', 'regex:/^\d+(\.\d{1,2})?$/'];
        $int = fn (int $min, int $max) => ['required', 'integer', "min:{$min}", "max:{$max}"];

        return [
            'general' => [
                'brand.name_en' => ['type' => 'text', 'rules' => ['required', 'string', 'max:120'], 'dir' => 'ltr'],
                'brand.name_ar' => ['type' => 'text', 'rules' => ['required', 'string', 'max:120'], 'dir' => 'rtl'],
                'contact.support_phone' => ['type' => 'text', 'rules' => ['nullable', 'string', 'max:32'], 'dir' => 'ltr'],
                'contact.support_email' => ['type' => 'text', 'rules' => ['nullable', 'email', 'max:191'], 'dir' => 'ltr'],
                'contact.whatsapp' => ['type' => 'text', 'rules' => ['nullable', 'string', 'max:32'], 'dir' => 'ltr'],
            ],
            'checkout' => [
                'checkout.service_fee' => ['type' => 'money', 'rules' => $money],
                'checkout.cod_fee' => ['type' => 'money', 'rules' => $money],
                'checkout.tax_rate_percent' => ['type' => 'percent', 'rules' => ['required', 'numeric', 'min:0', 'max:100']],
                'checkout.reservation_ttl_minutes' => ['type' => 'int', 'rules' => $int(5, 1440), 'suffix' => 'min'],
                'checkout.max_quantity_per_line' => ['type' => 'int', 'rules' => $int(1, 999)],
                'checkout.cart_ttl_days' => ['type' => 'int', 'rules' => $int(1, 365), 'suffix' => 'days'],
                'payments.online_payment_window_minutes' => ['type' => 'int', 'rules' => $int(5, 1440), 'suffix' => 'min'],
                'pickup.token_ttl_hours' => ['type' => 'int', 'rules' => $int(1, 720), 'suffix' => 'hours'],
            ],
            'loyalty' => [
                'loyalty.enabled' => ['type' => 'bool', 'rules' => ['boolean']],
                'loyalty.points_per_qar' => ['type' => 'int', 'rules' => $int(0, 1000)],
                'loyalty.redeem_value_per_point' => ['type' => 'money', 'rules' => ['required', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,4})?$/']],
                'loyalty.max_redeem_percent' => ['type' => 'percent', 'rules' => $int(0, 100)],
                'loyalty.points_expiry_days' => ['type' => 'int', 'rules' => $int(1, 3650), 'suffix' => 'days'],
            ],
            'auctions' => [
                'auctions.cod_allowed_default' => ['type' => 'bool', 'rules' => ['boolean']],
                'auctions.default_winner_payment_hours' => ['type' => 'int', 'rules' => $int(1, 720), 'suffix' => 'hours'],
                'auctions.default_extension_window_seconds' => ['type' => 'int', 'rules' => $int(0, 3600), 'suffix' => 'sec'],
                'auctions.default_extension_seconds' => ['type' => 'int', 'rules' => $int(0, 3600), 'suffix' => 'sec'],
                'auctions.unpaid_winner_policy' => ['type' => 'select', 'rules' => ['required', 'in:offer_next_bidder,relist,none'], 'options' => ['offer_next_bidder', 'relist', 'none']],
                'auctions.bid_rate_limit_per_minute' => ['type' => 'int', 'rules' => $int(1, 600)],
            ],
            'notifications' => [
                'notifications.abandoned_cart_after_minutes' => ['type' => 'int', 'rules' => $int(10, 10080), 'suffix' => 'min'],
                'notifications.abandoned_cart_max_messages' => ['type' => 'int', 'rules' => $int(0, 10)],
                'notifications.abandoned_cart_min_interval_hours' => ['type' => 'int', 'rules' => $int(1, 720), 'suffix' => 'hours'],
                'notifications.max_attempts' => ['type' => 'int', 'rules' => $int(1, 20)],
            ],
            'integrations' => [
                'payments.sadad.enabled' => ['type' => 'bool', 'rules' => ['boolean']],
                'payments.sadad.mode' => ['type' => 'select', 'rules' => ['required', 'in:sandbox,live'], 'options' => ['sandbox', 'live']],
                'payments.sadad.merchant_id' => ['type' => 'text', 'rules' => ['nullable', 'string', 'max:64'], 'dir' => 'ltr'],
                'payments.sadad.website' => ['type' => 'text', 'rules' => ['nullable', 'string', 'max:191'], 'dir' => 'ltr'],
                'payments.sadad.fee_percent' => ['type' => 'percent', 'rules' => ['nullable', 'numeric', 'min:0', 'max:100']],
                'payments.sadad.fee_fixed' => ['type' => 'money', 'rules' => ['nullable', 'numeric', 'min:0', 'max:1000']],
                'n8n.enabled' => ['type' => 'bool', 'rules' => ['boolean']],
                'n8n.webhook_url' => ['type' => 'text', 'rules' => ['nullable', 'url:https', 'max:300'], 'dir' => 'ltr'],
                'n8n.secret' => ['type' => 'secret', 'rules' => ['nullable', 'string', 'min:16', 'max:255']],
                'ai.anthropic_api_key' => ['type' => 'secret', 'rules' => ['nullable', 'string', 'min:20', 'max:255']],
                'ai.model' => ['type' => 'text', 'rules' => ['nullable', 'string', 'max:64'], 'dir' => 'ltr'],
                'payments.sadad.secret_key' => ['type' => 'secret', 'rules' => ['nullable', 'string', 'min:8', 'max:255']],
            ],
        ];
    }

    public function save(Settings $settings): void
    {
        abort_unless($this->canGlobally('settings.edit'), 403);
        $fields = self::schema()[$this->tab] ?? abort(404);
        $rules = [];
        $names = [];
        foreach ($fields as $key => $def) {
            $path = $def['type'] === 'secret' ? 'secrets.'.self::field($key) : "values.{$this->tab}.".self::field($key);
            $rules[$path] = $def['rules'];
            $names[$path] = __('org_admin.setting.'.self::field($key));
        }
        $this->validate($rules, [], $names);

        $changed = 0;
        foreach ($fields as $key => $def) {
            $f = self::field($key);
            if ($def['type'] === 'secret') {
                if (($this->secrets[$f] ?? '') !== '') {
                    $settings->set($key, $this->secrets[$f], secret: true);
                    $this->secrets[$f] = '';
                    $changed++;
                }

                continue;
            }
            $new = $this->values[$this->tab][$f];
            $new = match ($def['type']) {
                'bool' => (bool) $new,
                'int', 'percent' => is_numeric($new) && (string) (int) $new === (string) $new ? (int) $new : (string) $new,
                default => $new === '' ? null : (string) $new,
            };
            $current = $settings->get($key);
            $same = $def['type'] === 'bool' ? (bool) $current === $new : (string) $current === (string) $new;
            if (! $same) {
                $settings->set($key, $new);
                $changed++;
            }
        }
        $this->toast($changed ? trans_choice('org_admin.settings_saved', $changed, ['count' => $changed]) : __('org_admin.settings_unchanged'));
    }

    public function render(Settings $settings)
    {
        $masked = [];
        foreach (self::schema() as $fields) {
            foreach ($fields as $key => $def) {
                if ($def['type'] === 'secret') {
                    $masked[self::field($key)] = $settings->masked($key);
                }
            }
        }

        return view('livewire.admin.organization.settings', [
            'schema' => self::schema(),
            'masked' => $masked,
            'canEdit' => $this->canGlobally('settings.edit'),
        ])->title(__('nav.settings'));
    }
}
