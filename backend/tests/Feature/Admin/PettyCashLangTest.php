<?php

use App\Domain\Payments\Services\CashPayoutService;
use Illuminate\Support\Arr;

/** Every text of petty cash, the Z-report PDF and the assembly report exists in English and Arabic, with the same blanks to fill, and the Arabic is really Arabic. */
function ltDot(string $locale, string $file): array
{
    return Arr::dot(require lang_path("{$locale}/{$file}.php"));
}

function ltBlanks(string $text): array
{
    preg_match_all('/:[a-z_]+/', $text, $m);
    $found = array_values(array_unique($m[0]));
    sort($found);

    return $found;
}

it('has the same keys and blanks in English and Arabic, and Arabic in the Arabic file', function (string $file) {
    $en = ltDot('en', $file);
    $ar = ltDot('ar', $file);
    expect(array_keys($ar))->toEqualCanonicalizing(array_keys($en));
    foreach ($en as $key => $text) {
        expect(ltBlanks((string) $ar[$key]))->toBe(ltBlanks((string) $text), "{$file}.{$key}");
        if ($key !== 'pdf.file') {
            expect(preg_match('/\p{Arabic}/u', (string) $ar[$key]))->toBe(1, "{$file}.{$key} is not Arabic");
        }
    }
})->with(['petty_cash', 'assembly_sales', 'promotion_exclusions', 'wall_display']);

it('has the payout messages and the setting words in both languages', function () {
    $keys = [
        'errors' => ['cash_payout_amount_invalid', 'cash_payout_reason_invalid', 'cash_payout_note_required', 'cash_payout_exceeds_drawer', 'cash_payout_approver_required', 'cash_payout_approver_invalid',
            'cash_payout_shift_closed', 'cash_payout_duplicate', 'cash_payout_receipt_invalid', 'cash_payout_already_voided', 'cash_payout_void_reason_required', 'cash_payout_void_needs_approver'],
        'cash_shift' => ['setting.payout_approval', 'setting_hint.payout_approval'],
        'content_admin' => ['r_sales_assembly', 'rd_sales_assembly'],
        'checkout' => ['coupon_excluded'],
        'branch_access' => ['group_wall', 'wall_display', 'wall_display_hint', 'preset_wall_display', 'preset_wall_display_hint', 'error_wall_display_alone'],
    ];
    foreach ($keys as $file => $list) {
        $en = ltDot('en', $file);
        $ar = ltDot('ar', $file);
        foreach ($list as $key) {
            expect($en)->toHaveKey($key)->and($ar)->toHaveKey($key);
            expect(preg_match('/\p{Arabic}/u', (string) $ar[$key]))->toBe(1, "{$file}.{$key}");
            expect(ltBlanks((string) $ar[$key]))->toBe(ltBlanks((string) $en[$key]), "{$file}.{$key}");
        }
    }
});

it('names every payout reason in both languages', function () {
    foreach (CashPayoutService::REASONS as $reason) {
        expect(__('petty_cash.reasons.'.$reason, [], 'en'))->not->toBe('petty_cash.reasons.'.$reason)->and(preg_match('/\p{Arabic}/u', __('petty_cash.reasons.'.$reason, [], 'ar')))->toBe(1);
    }
});
