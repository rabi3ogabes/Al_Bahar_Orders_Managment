<?php

namespace App\Domain\Cms\Services;

use App\Domain\Settings\Services\Settings;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The customer app's bottom menu ("footer"): which of five looks it has and which destinations it shows, set from the
 * dashboard (Appearance > App footer). Stored as one plain array in the settings table (app.footer) and published inside
 * the public theme payload, so the apps pick it up with the colours. Everything is checked against fixed allow-lists.
 */
class AppFooterService
{
    public const SETTING = 'app.footer';

    public const DESIGNS = ['classic', 'floating', 'minimal', 'bubble', 'glass'];

    /** Where a footer item can lead. The app owns the route behind each key. */
    public const ITEMS = ['home', 'categories', 'auctions', 'cart', 'account', 'orders', 'wishlist', 'search', 'support', 'notifications'];

    /** Icon keys the app can draw. Each item has a default one (see DEFAULT_ICONS). */
    public const ICONS = ['home', 'grid', 'gavel', 'bag', 'person', 'receipt', 'heart', 'search', 'headset', 'bell', 'tag', 'star', 'store', 'gift'];

    public const DEFAULT_ICONS = [
        'home' => 'home', 'categories' => 'grid', 'auctions' => 'gavel', 'cart' => 'bag', 'account' => 'person',
        'orders' => 'receipt', 'wishlist' => 'heart', 'search' => 'search', 'support' => 'headset', 'notifications' => 'bell',
    ];

    public const MAX_ITEMS = 5;

    public const MIN_ENABLED = 3;

    public function __construct(private readonly Settings $settings, private readonly ThemeService $themes) {}

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        return [
            'design' => 'classic', 'show_labels' => true, 'cart_badge' => true, 'accent' => null,
            'items' => array_map(fn (string $k) => ['key' => $k, 'enabled' => true, 'icon' => null, 'label_en' => null, 'label_ar' => null], ['home', 'categories', 'auctions', 'cart', 'account']),
        ];
    }

    /** What the apps get: the saved footer, or the default when nothing valid is saved. Plain arrays only. @return array<string, mixed> */
    public function config(): array
    {
        $saved = $this->settings->get(self::SETTING);
        if (! is_array($saved)) {
            return $this->defaults();
        }
        try {
            return $this->validate($saved);
        } catch (ValidationException) {
            return $this->defaults();
        }
    }

    /**
     * Strict check of a footer config: allow-lists, at most 5 entries, at least 3 switched on, Home always there and on.
     * Returns the cleaned array (unknown fields dropped) or throws a ValidationException.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input): array
    {
        $validator = Validator::make($input, [
            'design' => ['required', Rule::in(self::DESIGNS)],
            'show_labels' => ['required', 'boolean'],
            'cart_badge' => ['required', 'boolean'],
            'accent' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'items' => ['required', 'array', 'min:'.self::MIN_ENABLED, 'max:'.self::MAX_ITEMS],
            'items.*' => ['array'],
            'items.*.key' => ['required', 'string', Rule::in(self::ITEMS), 'distinct'],
            'items.*.enabled' => ['required', 'boolean'],
            'items.*.icon' => ['nullable', 'string', Rule::in(self::ICONS)],
            'items.*.label_en' => ['nullable', 'string', 'max:20'],
            'items.*.label_ar' => ['nullable', 'string', 'max:20'],
        ]);
        $validator->after(function ($v) use ($input) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $items = array_values($input['items']);
            $enabled = array_filter($items, fn ($i) => filter_var($i['enabled'], FILTER_VALIDATE_BOOLEAN));
            if (count($enabled) < self::MIN_ENABLED) {
                $v->errors()->add('items', __('footer_admin.err_min_enabled', ['n' => self::MIN_ENABLED]));
            }
            if (! array_filter($enabled, fn ($i) => $i['key'] === 'home')) {
                $v->errors()->add('items', __('footer_admin.err_home'));
            }
        });
        $data = $validator->validate();

        $label = fn ($v) => ($t = trim(strip_tags((string) $v))) === '' ? null : $t;

        return [
            'design' => $data['design'],
            'show_labels' => (bool) $data['show_labels'],
            'cart_badge' => (bool) $data['cart_badge'],
            'accent' => ! empty($data['accent']) ? strtoupper($data['accent']) : null,
            'items' => array_map(fn (array $i) => [
                'key' => $i['key'], 'enabled' => (bool) $i['enabled'], 'icon' => $i['icon'] ?? null,
                'label_en' => $label($i['label_en'] ?? null), 'label_ar' => $label($i['label_ar'] ?? null),
            ], array_values($data['items'])),
        ];
    }

    /**
     * Validates and stores the footer, and clears the public theme cache so the apps see it at once.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function save(array $input): array
    {
        $clean = $this->validate($input);
        $this->settings->set(self::SETTING, $clean);
        $this->themes->forget();

        return $clean;
    }

    /** Puts the original five-tab footer back. @return array<string, mixed> */
    public function reset(): array
    {
        $this->settings->set(self::SETTING, $this->defaults());
        $this->themes->forget();

        return $this->defaults();
    }
}
