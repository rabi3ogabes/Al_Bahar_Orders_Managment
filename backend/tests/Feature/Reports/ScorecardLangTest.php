<?php

use App\Domain\Fulfillment\Models\DeliveryDecline;
use App\Domain\Reports\Services\ScorecardCauses;
use App\Livewire\Admin\Sales\DeliveryIndex;

/**
 * Every word of the scorecard exists in English AND Arabic with the same placeholders, every key the code asks for exists, and every reason code a delivery,
 * an order or a job can be given has a word for it.
 */
function scFlatten(array $lines, string $prefix = ''): array
{
    $out = [];
    foreach ($lines as $key => $value) {
        is_array($value) ? $out += scFlatten($value, $prefix.$key.'.') : $out[$prefix.$key] = $value;
    }

    return $out;
}

it('has the same keys and placeholders in English and Arabic, and no empty text', function () {
    $en = scFlatten(require lang_path('en/scorecard.php'));
    $ar = scFlatten(require lang_path('ar/scorecard.php'));

    expect(array_keys($ar))->toEqualCanonicalizing(array_keys($en));
    foreach ($en as $key => $text) {
        preg_match_all('/:[a-z_]+/', $text, $e);
        preg_match_all('/:[a-z_]+/', $ar[$key], $a);
        $enPlaceholders = array_values(array_unique($e[0]));
        $arPlaceholders = array_values(array_unique($a[0]));
        sort($enPlaceholders);
        sort($arPlaceholders);
        expect($arPlaceholders)->toBe($enPlaceholders, "placeholders of scorecard.{$key}");
        expect(trim($ar[$key]))->not->toBe('')->and(trim($text))->not->toBe('');
    }
});

it('has every scorecard key the page, the services and the menu ask for', function () {
    $keys = array_keys(scFlatten(require lang_path('en/scorecard.php')));
    $files = [app_path('Livewire/Admin/Insights/ScorecardPage.php'), app_path('Domain/Reports/Services/ScorecardService.php'), app_path('Domain/Reports/Services/ScorecardPeriod.php'),
        app_path('Domain/Reports/Services/ScorecardFormat.php'), app_path('Domain/Reports/Services/ScorecardCsv.php'), app_path('Support/AdminNavigation.php'),
        resource_path('views/livewire/admin/insights/scorecard.blade.php'), resource_path('views/components/admin/score-metric.blade.php')];
    $asked = [];
    foreach ($files as $file) {
        preg_match_all("/(?:__|trans_choice)\\(\\s*'scorecard\\.([A-Za-z0-9_.]*[A-Za-z0-9_])'(?!\\s*\\.)|'label' => 'scorecard\\.([a-z_]+)'/", file_get_contents($file), $m);
        $asked = array_merge($asked, array_filter(array_merge($m[1], $m[2])));
    }
    // Keys built from a variable at runtime ('scorecard.last_'.$r, 'scorecard.'.$label ...) are checked by the two tests below and by the page tests.
    expect(array_values(array_diff(array_unique($asked), $keys)))->toBe([]);
});

it('has a word, in both languages, for every reason a delivery fails, an order is cancelled, a job is given back or a claim is opened', function () {
    foreach (['en', 'ar'] as $locale) {
        $scorecard = scFlatten(require lang_path("{$locale}/scorecard.php"));
        $sales = scFlatten(require lang_path("{$locale}/sales_admin.php"));
        $returns = scFlatten(require lang_path("{$locale}/returns.php"));
        foreach (ScorecardCauses::FAIL_REASONS as $code) {
            expect($sales)->toHaveKey('fail_'.$code);
        }
        foreach (ScorecardCauses::CANCEL_REASONS as $code) {
            expect($scorecard)->toHaveKey('cancel.'.$code);
        }
        foreach (ScorecardCauses::DECLINE_REASONS as $code) {
            expect($scorecard)->toHaveKey('decline.'.$code);
        }
        foreach (array_keys($returns) as $key) {
            if (str_starts_with($key, 'type.')) {
                expect($scorecard)->toHaveKey('claim.'.substr($key, 5));
            }
        }
        foreach (['too_long', 'bad_dates', 'from_after_to', 'in_future'] as $code) {
            expect($scorecard)->toHaveKey('errors.'.$code);
        }
        foreach (['on_time', 'late', 'no_promise', 'failed', 'declined', 'timeout', 'off_duty', 'held', 'balanced', 'difference'] as $code) {
            expect($scorecard)->toHaveKey('ledger.'.$code);
        }
    }
});

it('has the cash, claim and decline reasons the data really holds', function () {
    foreach (['too_far', 'vehicle', 'shift_ending', 'other'] as $code) {
        expect(DeliveryDecline::DRIVER_REASONS)->toContain($code);
    }
    foreach (['customer_request', 'out_of_stock', 'payment_issue', 'fraud_suspected', 'duplicate_order', 'damaged', 'other', 'changed_mind', 'ordered_by_mistake', 'found_better_price', 'delivery_too_long'] as $code) {
        expect(ScorecardCauses::CANCEL_REASONS)->toContain($code);
    }
    expect(DeliveryIndex::FAIL_REASONS)->toEqualCanonicalizing(ScorecardCauses::FAIL_REASONS);
});
