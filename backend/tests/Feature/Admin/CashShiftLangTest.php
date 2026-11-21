<?php

use Illuminate\Support\Arr;

/** Every text of the cash shift feature exists in English and Arabic, with the same blanks to fill, and the Arabic is really Arabic. */
function langDot(string $locale, string $file): array
{
    return Arr::dot(require lang_path("{$locale}/{$file}.php"));
}

function placeholders(string $text): array
{
    preg_match_all('/:[a-z_]+/', $text, $m);
    $found = array_values(array_unique($m[0]));
    sort($found);

    return $found;
}

it('has the same keys and the same placeholders in English and Arabic', function (string $file) {
    $en = langDot('en', $file);
    $ar = langDot('ar', $file);
    expect(array_keys($ar))->toEqualCanonicalizing(array_keys($en));
    foreach ($en as $key => $text) {
        expect(placeholders((string) $ar[$key]))->toBe(placeholders((string) $text), "{$file}.{$key}");
        expect(preg_match('/\p{Arabic}/u', (string) $ar[$key]))->toBe(1, "{$file}.{$key} is not Arabic");
    }
})->with(['cash_shift']);

it('has the cash shift messages, attention lines and settings words in both languages', function () {
    $keys = [
        'errors' => ['cash_shift_count_invalid', 'cash_shift_already_open', 'cash_shift_already_closed', 'cash_shift_required', 'cash_shift_not_closed'],
        'ops_live' => ['cat.cash_shift.label', 'cat.cash_shift.missing', 'action.close_shift', 'detail.shift_open', 'detail.shift_missing'],
        'org_admin' => ['settings_tab.cash', 'settings_tab_hint.cash', 'option.attach', 'option.block'],
    ];
    foreach ($keys as $file => $list) {
        $en = langDot('en', $file);
        $ar = langDot('ar', $file);
        foreach ($list as $key) {
            expect($en)->toHaveKey($key)->and($ar)->toHaveKey($key);
            expect(preg_match('/\p{Arabic}/u', (string) $ar[$key]))->toBe(1, "{$file}.{$key}");
            expect(placeholders((string) $ar[$key]))->toBe(placeholders((string) $en[$key]), "{$file}.{$key}");
        }
    }
});
