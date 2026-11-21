<?php

namespace App\Livewire\Admin\Organization;

use App\Domain\Fulfillment\Services\DispatchSettings;
use App\Domain\Inventory\Services\StockPlanningSettings;
use App\Domain\Inventory\Services\StocktakeApprovals;
use App\Domain\Operations\Services\OpsClock;
use App\Domain\Orders\Printing\PrintSettings;
use App\Domain\Payments\Services\CashShiftSettings;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Rules\MapServiceUrl;
use App\Rules\MapTileUrl;
use App\Support\IntroSlides;
use App\Support\MapConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\On;
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
                $v = $settings->get($key, $def['default'] ?? null);
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
                'customer_app.designed_by_en' => ['type' => 'text', 'rules' => ['nullable', 'string', 'max:80'], 'dir' => 'ltr'],
                'customer_app.designed_by_ar' => ['type' => 'text', 'rules' => ['nullable', 'string', 'max:80'], 'dir' => 'rtl'],
                'customer_app.alternate_phone_enabled' => ['type' => 'bool', 'rules' => ['boolean']],
                // The words of the customer app's three intro slides: drawn by the "Intro screens" block, not by the generic field list.
                ...IntroSlides::schema(),
            ],
            'checkout' => [
                'checkout.service_fee' => ['type' => 'money', 'rules' => $money],
                'checkout.tax_rate_percent' => ['type' => 'percent', 'rules' => ['required', 'numeric', 'min:0', 'max:100']],
                'checkout.reservation_ttl_minutes' => ['type' => 'int', 'rules' => $int(5, 1440), 'suffix' => 'min'],
                'checkout.max_quantity_per_line' => ['type' => 'int', 'rules' => $int(1, 999)],
                'checkout.cart_ttl_days' => ['type' => 'int', 'rules' => $int(1, 365), 'suffix' => 'days'],
                'checkout.show_split_shipment' => ['type' => 'bool', 'rules' => ['boolean']],
                // The delivery / pickup date customers see before they pay, and paid assembly and installation (docs/guides/DELIVERY_PROMISE_ASSEMBLY_AND_SHARING.md).
                'checkout.promise_enabled' => ['type' => 'bool', 'rules' => ['boolean']],
                'checkout.same_day_cutoff' => ['type' => 'text', 'rules' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'], 'dir' => 'ltr'],
                'checkout.assembly_enabled' => ['type' => 'bool', 'rules' => ['boolean']],
                'checkout.assembly_extra_days' => ['type' => 'int', 'rules' => $int(0, 30), 'suffix' => 'days'],
                'returns.enabled' => ['type' => 'bool', 'rules' => ['boolean']],
                'substitutions.consent_enabled' => ['type' => 'bool', 'rules' => ['boolean'], 'default' => true],
                'substitutions.response_minutes' => ['type' => 'int', 'rules' => $int(5, 1440), 'suffix' => 'min', 'default' => 30],
                'substitutions.fallback' => ['type' => 'select', 'rules' => ['required', 'in:remove,keep'], 'options' => ['remove', 'keep'], 'default' => 'remove'],
                'returns.window_days' => ['type' => 'int', 'rules' => $int(1, 90), 'suffix' => 'days', 'default' => 14],
                'ops.status_style' => ['type' => 'select', 'rules' => ['required', 'in:buttons,slide'], 'options' => ['buttons', 'slide']],
                'payments.online_payment_window_minutes' => ['type' => 'int', 'rules' => $int(5, 1440), 'suffix' => 'min'],
                'pickup.token_ttl_hours' => ['type' => 'int', 'rules' => $int(1, 720), 'suffix' => 'hours'],
                'orders.default_prep_minutes' => ['type' => 'int', 'rules' => $int(1, 240), 'suffix' => 'min'],
                'orders.default_delivery_minutes' => ['type' => 'int', 'rules' => $int(1, 240), 'suffix' => 'min'],
            ],
            'security' => [
                'auth.staff_two_factor_required' => ['type' => 'bool', 'rules' => ['boolean']],
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
            'stock' => self::stockSchema(),
            'cash' => self::cashSchema(),
            'notifications' => [
                'notifications.abandoned_cart_min_interval_hours' => ['type' => 'int', 'rules' => $int(1, 720), 'suffix' => 'hours'],
                'notifications.max_attempts' => ['type' => 'int', 'rules' => $int(1, 20)],
                'messaging.cooldown_hours' => ['type' => 'int', 'rules' => $int(0, 720), 'suffix' => 'hours'],
                'messaging.whatsapp_per_minute' => ['type' => 'int', 'rules' => $int(1, 600)],
                'messaging.bulk_max' => ['type' => 'int', 'rules' => $int(1, 5000)],
                // Back in stock and price alerts (docs/guides/ALERTS_AFTER_DELIVERY_AND_ROOMS.md); the limits mirror App\Domain\Catalog\Services\StockAlertSettings.
                'alerts.back_in_stock_enabled' => ['type' => 'bool', 'rules' => ['boolean']],
                'alerts.price_drop_enabled' => ['type' => 'bool', 'rules' => ['boolean']],
                'alerts.price_drop_percent' => ['type' => 'percent', 'rules' => $int(1, 90)],
                'alerts.price_drop_min_amount' => ['type' => 'money', 'rules' => $money],
                'alerts.max_price_pushes_per_week' => ['type' => 'int', 'rules' => $int(1, 14)],
                'alerts.retention_days' => ['type' => 'int', 'rules' => $int(7, 365), 'suffix' => 'days'],
                'alerts.quiet_start' => ['type' => 'text', 'rules' => ['nullable', 'date_format:H:i', 'required_with:values.notifications.alerts__quiet_end'], 'dir' => 'ltr'],
                'alerts.quiet_end' => ['type' => 'text', 'rules' => ['nullable', 'date_format:H:i', 'required_with:values.notifications.alerts__quiet_start'], 'dir' => 'ltr'],
            ],
            'ops_clock' => self::opsClockSchema() + self::dispatchSchema(),
            'printing' => self::printingSchema(),
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
                'map.provider' => ['type' => 'select', 'rules' => ['required', 'in:'.implode(',', MapConfig::PROVIDERS)], 'options' => MapConfig::PROVIDERS],
                'map.api_key' => ['type' => 'secret', 'rules' => ['nullable', 'string', 'min:8', 'max:255']],
                'map.tile_url_light' => ['type' => 'text', 'rules' => ['nullable', 'string', 'max:500', new MapTileUrl], 'dir' => 'ltr'],
                'map.tile_url_dark' => ['type' => 'text', 'rules' => ['nullable', 'string', 'max:500', new MapTileUrl], 'dir' => 'ltr'],
                'map.attribution' => ['type' => 'text', 'rules' => ['nullable', 'string', 'max:300']],
                'map.retina' => ['type' => 'bool', 'rules' => ['boolean']],
                'map.max_zoom' => ['type' => 'int', 'rules' => ['nullable', 'integer', 'min:1', 'max:22']],
                'map.center_lat' => ['type' => 'text', 'rules' => ['nullable', 'numeric', 'between:-85,85', 'required_with:values.integrations.map__center_lng'], 'dir' => 'ltr'],
                'map.center_lng' => ['type' => 'text', 'rules' => ['nullable', 'numeric', 'between:-180,180', 'required_with:values.integrations.map__center_lat'], 'dir' => 'ltr'],
                'map.zoom' => ['type' => 'int', 'rules' => ['nullable', 'integer', 'min:3', 'max:19']],
                'map.routing_url' => ['type' => 'text', 'rules' => ['nullable', 'string', 'max:300', new MapServiceUrl], 'dir' => 'ltr'],
            ],
        ];
    }

    /**
     * Settings > Stock: the numbers behind Stock planning (docs/guides/STOCK_PLANNING_AND_STOCKTAKE.md) and the second-person rule of stock counts. The words
     * come from lang/*\/stock_planning.php and the factory defaults from {@see StockPlanningSettings} and {@see StocktakeApprovals}.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function stockSchema(): array
    {
        $schema = [];
        foreach (StockPlanningSettings::KEYS as $field => $key) {
            [$default, $min, $max] = StockPlanningSettings::LIMITS[$field];
            $schema[$key] = ['type' => 'int', 'rules' => ['required', 'integer', "min:{$min}", "max:{$max}"], 'suffix' => 'days', 'default' => $default,
                'label' => 'stock_planning.setting.'.$field, 'hint' => 'stock_planning.setting_hint.'.$field, 'messages' => []];
        }
        $schema[StocktakeApprovals::RULE_KEY] = ['type' => 'bool', 'rules' => ['boolean'], 'default' => true,
            'label' => 'stock_planning.setting.second_person', 'hint' => 'stock_planning.setting_hint.second_person', 'messages' => []];
        $schema[StocktakeApprovals::AUTO_KEY] = ['type' => 'money', 'rules' => ['required', 'numeric', 'min:0', 'max:100000', 'regex:/^\d+(\.\d{1,2})?$/'], 'default' => '0',
            'label' => 'stock_planning.setting.auto_post', 'hint' => 'stock_planning.setting_hint.auto_post', 'messages' => []];

        return $schema;
    }

    /**
     * Settings > Cash: how the cash shifts of the branches work (docs/guides/CASH_SHIFT.md). The words come from lang/*\/cash_shift.php and the
     * factory defaults from {@see CashShiftSettings}.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function cashSchema(): array
    {
        $money = ['required', 'numeric', 'min:0', 'max:100000', 'regex:/^\d+(\.\d{1,2})?$/'];

        return [
            CashShiftSettings::POLICY_KEY => ['type' => 'select', 'rules' => ['required', 'in:'.implode(',', CashShiftSettings::POLICIES)], 'options' => CashShiftSettings::POLICIES,
                'default' => CashShiftSettings::POLICY_ATTACH, 'label' => 'cash_shift.setting.policy', 'hint' => 'cash_shift.setting_hint.policy', 'messages' => []],
            CashShiftSettings::NOTE_ABOVE_KEY => ['type' => 'money', 'rules' => $money, 'default' => CashShiftSettings::DEFAULT_NOTE_ABOVE,
                'label' => 'cash_shift.setting.note_above', 'hint' => 'cash_shift.setting_hint.note_above', 'messages' => []],
            CashShiftSettings::REVIEW_ABOVE_KEY => ['type' => 'money', 'rules' => [...$money, 'gte:values.cash.'.self::field(CashShiftSettings::NOTE_ABOVE_KEY)], 'default' => CashShiftSettings::DEFAULT_REVIEW_ABOVE,
                'label' => 'cash_shift.setting.review_above', 'hint' => 'cash_shift.setting_hint.review_above', 'messages' => ['gte' => 'cash_shift.setting_gte']],
            CashShiftSettings::MAX_OPEN_HOURS_KEY => ['type' => 'int', 'rules' => ['required', 'integer', 'min:1', 'max:72'], 'suffix' => 'hours', 'default' => CashShiftSettings::DEFAULT_MAX_OPEN_HOURS,
                'label' => 'cash_shift.setting.max_open_hours', 'hint' => 'cash_shift.setting_hint.max_open_hours', 'messages' => []],
        ];
    }

    /**
     * Settings > Operations clock: when the dashboard shows an order, a delivery, a pickup or cash as getting late (amber) and late (red).
     * Each pair is validated so the amber limit is lower than the red one; the words come from lang/*\/ops_live.php and the factory
     * defaults from {@see OpsClock::DEFAULTS}.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function opsClockSchema(): array
    {
        $schema = [];
        foreach (OpsClock::KINDS as $kind) {
            $unit = OpsClock::UNITS[$kind];
            $max = OpsClock::MAX[$unit];
            foreach (['amber', 'red'] as $level) {
                $key = OpsClock::settingKey($kind, $level);
                $field = self::field($key);
                $rules = ['required', 'integer', 'min:1', "max:{$max}"];
                $messages = [];
                if ($level === 'amber') {
                    $rules[] = 'lt:values.ops_clock.'.self::field(OpsClock::settingKey($kind, 'red'));
                    $messages['lt'] = 'ops_live.setting_lt';
                }
                $schema[$key] = ['type' => 'int', 'rules' => $rules, 'suffix' => $unit === 'minutes' ? 'min' : $unit, 'default' => OpsClock::defaultFor($kind, $level),
                    'label' => 'ops_live.setting.'.$field, 'hint' => 'ops_live.setting_hint.'.$field, 'messages' => $messages];
            }
        }

        return $schema;
    }

    /**
     * The driver rules that sit under the clock limits: when a parcel needs two people, how long a driver may take to accept, when a silent driver is set
     * off duty, how many drivers the system tries by itself, and when the customer is told the driver is close. Defaults from {@see DispatchSettings}.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function dispatchSchema(): array
    {
        $rows = [
            'dispatch.two_person_weight_kg' => [1, 500, DispatchSettings::TWO_PERSON_WEIGHT_KG, 'kg'],
            'dispatch.accept_timeout_min' => [0, 120, DispatchSettings::ACCEPT_TIMEOUT_MIN, 'min'],
            'dispatch.stale_duty_min' => [5, 240, DispatchSettings::STALE_DUTY_MIN, 'min'],
            'dispatch.max_auto_attempts' => [1, 10, DispatchSettings::MAX_AUTO_ATTEMPTS, null],
            'dispatch.nearby_eta_min' => [1, 60, DispatchSettings::NEARBY_ETA_MIN, 'min'],
            'dispatch.nearby_distance_m' => [100, 10000, DispatchSettings::NEARBY_DISTANCE_M, 'm'],
        ];
        $schema = [];
        foreach ($rows as $key => [$min, $max, $default, $suffix]) {
            $field = self::field($key);
            $schema[$key] = ['type' => 'int', 'rules' => ['required', 'integer', "min:{$min}", "max:{$max}"], 'suffix' => $suffix, 'default' => $default,
                'label' => 'ops_live.setting.'.$field, 'hint' => 'ops_live.setting_hint.'.$field, 'messages' => []];
        }

        return $schema;
    }

    /**
     * Settings > Printing: the paper of the picking list and the delivery note, and whether a paid order's delivery note shows prices
     * (docs/guides/PICKING_LIST_AND_DELIVERY_NOTE.md). The words come from lang/*\/print_docs.php; the options are labelled in org_admin.option.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function printingSchema(): array
    {
        $paper = fn (string $field) => ['type' => 'select', 'rules' => ['required', 'in:'.implode(',', PrintSettings::PAPERS)], 'options' => PrintSettings::PAPERS,
            'default' => 'a4', 'label' => 'print_docs.setting.'.$field, 'hint' => 'print_docs.setting_hint.'.$field, 'messages' => []];

        return [
            PrintSettings::PICKING_PAPER => $paper('picking_paper'),
            PrintSettings::NOTE_PAPER => $paper('note_paper'),
            PrintSettings::NOTE_PRICES => ['type' => 'bool', 'rules' => ['boolean'], 'default' => false,
                'label' => 'print_docs.setting.note_prices', 'hint' => 'print_docs.setting_hint.note_prices', 'messages' => []],
        ];
    }

    public function save(Settings $settings): void
    {
        abort_unless($this->canGlobally('settings.edit'), 403);
        $fields = self::schema()[$this->tab] ?? abort(404);
        $this->cleanIntroTexts($fields);
        $rules = [];
        $names = [];
        $messages = [];
        foreach ($fields as $key => $def) {
            $path = $def['type'] === 'secret' ? 'secrets.'.self::field($key) : "values.{$this->tab}.".self::field($key);
            $rules[$path] = $def['rules'];
            $names[$path] = __($def['label'] ?? 'org_admin.setting.'.self::field($key));
            foreach ($def['messages'] ?? [] as $rule => $line) {
                $messages[$path.'.'.$rule] = __($line);
            }
        }
        $this->validate($rules, $messages, $names);

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
            $current = $settings->get($key, $def['default'] ?? null);
            $same = $def['type'] === 'bool' ? (bool) $current === $new : (string) $current === (string) $new;
            if (! $same) {
                $settings->set($key, $new);
                $changed++;
            }
        }
        $this->toast($changed ? trans_choice('org_admin.settings_saved', $changed, ['count' => $changed]) : __('org_admin.settings_unchanged'));
    }

    /**
     * The intro texts are plain, one-line text: tags and extra white space go before the length is checked, so what the admin sees in
     * the field after saving is exactly what customers get. (A value that is not a string is left for the `string` rule to refuse.)
     *
     * @param  array<string, array<string, mixed>>  $fields
     */
    private function cleanIntroTexts(array $fields): void
    {
        foreach ($fields as $key => $def) {
            $f = self::field($key);
            if (empty($def['intro']) || ! is_string($this->values[$this->tab][$f] ?? null)) {
                continue;
            }
            $this->values[$this->tab][$f] = IntroSlides::clean($this->values[$this->tab][$f]);
        }
    }

    /**
     * "Reset to default" of one intro slide: its four texts are emptied (the apps then use their own words) and saved at once; the
     * other fields of the page, saved or not, are untouched. The toast carries an Undo that puts back exactly what the fields held.
     */
    public function resetIntroSlide(int $slide, Settings $settings): void
    {
        abort_unless($this->canGlobally('settings.edit'), 403);
        if ($slide < 1 || $slide > IntroSlides::SLIDES) {
            return;
        }
        $before = [];
        foreach (IntroSlides::PARTS as $part) {
            foreach (IntroSlides::LANGS as $lang) {
                $before[$part][$lang] = (string) ($this->values['general'][IntroSlides::field($slide, $part, $lang)] ?? '');
            }
        }
        $this->applyIntroSlide($slide, array_fill_keys(IntroSlides::PARTS, array_fill_keys(IntroSlides::LANGS, '')), $settings);
        if ($before === array_fill_keys(IntroSlides::PARTS, array_fill_keys(IntroSlides::LANGS, ''))) {
            $this->toast(__('intro_admin.already_default', ['slide' => $slide]));

            return;
        }
        $this->dispatch('toast', type: 'success', message: __('intro_admin.reset_done', ['slide' => $slide]),
            undoLabel: __('admin.undo'), undoEvent: 'intro-slide-undo', undoParams: ['slide' => $slide, 'texts' => $before]);
    }

    /**
     * Undo of a reset. The texts come back from the browser, so they are cleaned and checked like a normal save: anything that is not
     * plain text within the limits is ignored (that text stays empty, the app's own words).
     *
     * @param  array<mixed>  $texts  part => lang => text
     */
    #[On('intro-slide-undo')]
    public function undoIntroSlide(int $slide, array $texts, Settings $settings): void
    {
        abort_unless($this->canGlobally('settings.edit'), 403);
        if ($slide < 1 || $slide > IntroSlides::SLIDES) {
            return;
        }
        $restored = [];
        foreach (IntroSlides::PARTS as $part) {
            foreach (IntroSlides::LANGS as $lang) {
                $text = $texts[$part][$lang] ?? '';
                $restored[$part][$lang] = IntroSlides::served($text, $part) ?? '';
            }
        }
        $this->applyIntroSlide($slide, $restored, $settings);
        $this->toast(__('admin.saved'));
    }

    /**
     * Writes the four texts of one slide (an empty text clears the setting) into the form and, when they differ from what is stored,
     * into the settings: one audit entry per changed text, like any other setting.
     *
     * @param  array<string, array<string, string>>  $texts  part => lang => text
     */
    private function applyIntroSlide(int $slide, array $texts, Settings $settings): void
    {
        foreach (IntroSlides::PARTS as $part) {
            foreach (IntroSlides::LANGS as $lang) {
                $text = $texts[$part][$lang];
                $this->values['general'][IntroSlides::field($slide, $part, $lang)] = $text;
                $key = IntroSlides::key($slide, $part, $lang);
                $new = $text === '' ? null : $text;
                if ((string) $settings->get($key) !== (string) $new) {
                    $settings->set($key, $new);
                }
            }
        }
    }

    /** Fetches one real tile with the SAVED map settings and says whether the provider answered with an image. */
    public function testTiles(): void
    {
        abort_unless($this->canGlobally('settings.view'), 403);
        $problem = MapConfig::problem();
        if ($problem !== null) {
            $this->toast(__('org_admin.map_test.'.$problem), 'error');

            return;
        }
        try {
            $response = Http::timeout(5)->withUserAgent(config('app.name', 'Al Bahar').' admin tile check')->accept('image/*')->get(MapConfig::sampleTileUrl());
        } catch (ConnectionException) {
            $this->toast(__('org_admin.map_test.unreachable'), 'error');

            return;
        }
        if (! $response->successful()) {
            $this->toast(__('org_admin.map_test.refused', ['status' => $response->status()]), 'error');

            return;
        }
        $isImage = str_starts_with((string) $response->header('Content-Type'), 'image/');
        $this->toast($isImage ? __('org_admin.map_test.ok') : __('org_admin.map_test.not_image'), $isImage ? 'success' : 'error');
    }

    /**
     * What the "Intro screens" block draws: per slide, its number and the shipped title and body in both languages (the placeholders
     * and the preview's fallback), plus the limits.
     *
     * @return array{slides: list<array{n: int, defaults: array<string, array<string, string>>}>, max: array<string, int>}
     */
    private static function introView(): array
    {
        $slides = [];
        foreach (IntroSlides::slideNumbers() as $n) {
            $defaults = [];
            foreach (IntroSlides::PARTS as $part) {
                foreach (IntroSlides::LANGS as $lang) {
                    $defaults[$part][$lang] = IntroSlides::default($n, $part, $lang);
                }
            }
            $slides[] = ['n' => $n, 'defaults' => $defaults];
        }

        return ['slides' => $slides, 'max' => ['title' => IntroSlides::TITLE_MAX, 'body' => IntroSlides::BODY_MAX]];
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
            'intro' => $this->tab === 'general' ? self::introView() : null,
            'masked' => $masked,
            'canEdit' => $this->canGlobally('settings.edit'),
        ])->title(__('nav.settings'));
    }
}
