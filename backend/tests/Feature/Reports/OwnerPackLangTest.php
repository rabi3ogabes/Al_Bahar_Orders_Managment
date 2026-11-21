<?php

use App\Domain\Reports\Services\CustomerValue;
use App\Domain\Reports\Services\OfferReturn;
use App\Domain\Reports\Services\OwnerPackPresenter;

/**
 * Every word of the daily close, the owner pack, the customer value lists and the offer returns exists in English AND Arabic with the same
 * placeholders, and every key the code asks for exists.
 */
const OWNER_PACK_LANG_FILES = ['daily_close', 'owner_pack', 'customer_value', 'offer_return'];

function opFlatten(array $lines, string $prefix = ''): array
{
    $out = [];
    foreach ($lines as $key => $value) {
        is_array($value) ? $out += opFlatten($value, $prefix.$key.'.') : $out[$prefix.$key] = $value;
    }

    return $out;
}

it('has the same keys and placeholders in English and Arabic, and no empty text', function (string $file) {
    $en = opFlatten(require lang_path("en/{$file}.php"));
    $ar = opFlatten(require lang_path("ar/{$file}.php"));

    expect(array_keys($ar))->toEqualCanonicalizing(array_keys($en));
    foreach ($en as $key => $text) {
        preg_match_all('/:[a-z_]+/', $text, $e);
        preg_match_all('/:[a-z_]+/', $ar[$key], $a);
        $en_ph = array_values(array_unique($e[0]));
        $ar_ph = array_values(array_unique($a[0]));
        sort($en_ph);
        sort($ar_ph);
        expect($ar_ph)->toBe($en_ph, "placeholders of {$file}.{$key}");
        expect(trim($ar[$key]))->not->toBe('')->and(trim($text))->not->toBe('');
    }
})->with(OWNER_PACK_LANG_FILES);

it('has every key the screens, services and mails ask for', function () {
    $keys = [];
    foreach (OWNER_PACK_LANG_FILES as $f) {
        foreach (array_keys(opFlatten(require lang_path("en/{$f}.php"))) as $k) {
            $keys[$f.'.'.$k] = true;
        }
    }
    $files = [
        app_path('Livewire/Admin/Insights/DailyClosePage.php'), app_path('Livewire/Admin/Insights/OwnerPackPage.php'), app_path('Livewire/Admin/Sales/CustomerValuePage.php'),
        app_path('Livewire/Admin/Marketing/OfferReturnPage.php'), app_path('Domain/Reports/Services/DailyCloseService.php'), app_path('Domain/Reports/Services/OwnerPackSender.php'),
        app_path('Domain/Reports/Services/OwnerPackData.php'), app_path('Domain/Reports/Services/OwnerPackPresenter.php'), app_path('Support/AdminNavigation.php'),
        resource_path('views/livewire/admin/insights/daily-close.blade.php'), resource_path('views/livewire/admin/insights/owner-pack.blade.php'),
        resource_path('views/livewire/admin/sales/customer-value.blade.php'), resource_path('views/livewire/admin/marketing/offer-return.blade.php'),
        resource_path('views/documents/daily-close.blade.php'), resource_path('views/emails/owner-pack.blade.php'), resource_path('views/livewire/admin/sales/customer-show.blade.php'),
    ];
    $asked = [];
    foreach ($files as $file) {
        // A key is asked for by __(), trans_choice(), Lang::has() or a menu label; a key still being built ('...s_'.$section) is checked in the next test.
        preg_match_all("/(?:__|trans_choice|Lang::has)\\(\\s*['\"]((?:daily_close|owner_pack|customer_value|offer_return)\\.[A-Za-z0-9_.]*[A-Za-z0-9_])['\"](?!\\s*\\.)|'label' => '((?:daily_close|owner_pack|customer_value|offer_return)\\.[a-z_]+)'/", file_get_contents($file), $m);
        $m[1] = array_values(array_filter(array_merge($m[1], $m[2])));
        $asked = array_merge($asked, $m[1]);
    }
    $missing = array_values(array_filter(array_unique($asked), fn ($k) => ! isset($keys[$k]) && ! preg_grep('/^'.preg_quote($k, '/').'\./', array_keys($keys))));

    expect($missing)->toBe([]);
});

it('has the words of the keys that are built from a variable', function () {
    $need = [
        'daily_close' => [...array_map(fn ($s) => 's_'.$s, ['orders', 'sales', 'cash', 'problems', 'speed', 'stock']), ...array_map(fn ($s) => 'h_'.$s, ['orders', 'sales', 'cash', 'problems', 'speed', 'stock']),
            'errors.forbidden', 'errors.branch_unknown', 'errors.too_early', 'errors.note_too_long', 'errors.already_closed', 'errors.note_required', 'errors.not_closed', 'errors.reason_required', 'errors.bad_date', 'errors.too_old',
            ...array_map(fn ($s) => 'flag_'.$s, ['never_accepted', 'cash', 'open_variances'])],
        'owner_pack' => [...array_map(fn ($s) => 'status.'.$s, ['sent', 'partial', 'failed', 'sending', 'not_connected']), ...array_map(fn ($s) => 'error.'.$s, ['whatsapp_not_connected', 'no_recipients', 'mail_not_ready', 'pack_not_built', 'some_failed']),
            ...array_map(fn ($s) => 'ch_'.$s, ['email', 'whatsapp']), ...array_map(fn ($s) => 'metric.'.$s, ['sales', 'orders', 'average', 'on_time_rate', 'claims_rate']), ...array_map(fn ($s) => 'metric.'.$s.'_short', array_keys(OwnerPackPresenter::COLUMNS)),
            ...array_map(fn ($s) => 'attention.'.$s, ['unclosed_days', 'never_accepted', 'open_variances', 'cash_with_collectors', 'claims_waiting', 'stock_out', 'sales_down', 'late_deliveries']),
            ...array_map(fn ($s) => 'def.'.$s, array_keys(OwnerPackPresenter::COLUMNS))],
        'customer_value' => [...array_map(fn ($s) => 'sort_'.$s, CustomerValue::SORTS), ...array_map(fn ($s) => 'empty_'.$s, CustomerValue::TABS), ...array_map(fn ($s) => 'empty_'.$s.'_hint', CustomerValue::TABS),
            ...array_map(fn ($s) => 'q_'.$s, CustomerValue::TABS), ...array_map(fn ($s) => 'tile_'.$s, CustomerValue::TABS), ...array_map(fn ($s) => 'tile_'.$s.'_note', CustomerValue::TABS)],
        'offer_return' => [...array_map(fn ($s) => 'verdict_'.$s, ['strong', 'fair', 'weak', 'no_cost', 'not_enough']), ...array_map(fn ($s) => 'kind_'.$s, ['all', ...OfferReturn::KINDS]),
            ...array_map(fn ($s) => 'manage_'.$s, OfferReturn::KINDS)],
    ];
    foreach ($need as $file => $list) {
        $flat = opFlatten(require lang_path("en/{$file}.php"));
        foreach ($list as $key) {
            // The tiles are named after the tabs, except "one_time" and "new" which keep their own words.
            expect(array_key_exists($key, $flat))->toBeTrue("{$file}.{$key}");
        }
    }
    expect(true)->toBeTrue();
});

it('writes every attention line in both languages for every count a person can see', function () {
    foreach (['en', 'ar'] as $lang) {
        foreach (['unclosed_days', 'never_accepted', 'open_variances', 'claims_waiting', 'stock_out', 'sales_down', 'late_deliveries'] as $key) {
            foreach ([1, 2, 5, 12, 100] as $n) {
                $line = OwnerPackPresenter::sentence(['key' => $key, 'count' => $n, 'branches' => ['DOH']], $lang);
                expect($line)->not->toContain('owner_pack.')->not->toContain(':count')->not->toContain(':percent')->toContain('DOH');
            }
        }
        expect(OwnerPackPresenter::sentence(['key' => 'cash_with_collectors', 'count' => '340.00'], $lang))->toContain('340.00');
    }
});
