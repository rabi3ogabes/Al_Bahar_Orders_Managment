<?php

/**
 * Every word the owner's numbers use exists in English and Arabic, with the same placeholders, and every key the code asks for exists.
 */
function onFlatten(array $lines, string $prefix = ''): array
{
    $out = [];
    foreach ($lines as $key => $value) {
        is_array($value) ? $out += onFlatten($value, $prefix.$key.'.') : $out[$prefix.$key] = $value;
    }

    return $out;
}

it('has the same keys and placeholders in English and Arabic', function () {
    $en = onFlatten(require lang_path('en/owner_numbers.php'));
    $ar = onFlatten(require lang_path('ar/owner_numbers.php'));

    expect(array_keys($ar))->toEqualCanonicalizing(array_keys($en));
    foreach ($en as $key => $text) {
        preg_match_all('/:[a-z_]+/', $text, $e);
        preg_match_all('/:[a-z_]+/', $ar[$key], $a);
        expect(array_unique($a[0]))->toEqualCanonicalizing(array_unique($e[0]), "placeholders of owner_numbers.{$key}");
        expect(trim($ar[$key]))->not->toBe('')->and(trim($text))->not->toBe('');
    }
});

it('has every owner_numbers key that the dashboard, reports, campaigns and audit screens ask for', function () {
    $keys = array_keys(onFlatten(require lang_path('en/owner_numbers.php')));
    $files = [app_path('Livewire/Admin/Dashboard.php'), app_path('Livewire/Admin/SystemHealthCard.php'), app_path('Livewire/Admin/Marketing/CampaignIndex.php'),
        app_path('Livewire/Admin/Insights/ReportIndex.php'), app_path('Livewire/Admin/Organization/AuditIndex.php'), resource_path('views/livewire/admin/dashboard.blade.php'),
        resource_path('views/livewire/admin/system-health-card.blade.php'), resource_path('views/livewire/admin/marketing/campaigns.blade.php'),
        resource_path('views/livewire/admin/insights/reports.blade.php'), resource_path('views/livewire/admin/organization/audit.blade.php')];
    $asked = [];
    foreach ($files as $file) {
        preg_match_all('/owner_numbers\\.([a-z_]+(?:\\.[a-z_:\\-]+)?)/', file_get_contents($file), $m);
        $asked = array_merge($asked, $m[1]);
    }
    $missing = array_values(array_filter(array_unique($asked), fn ($k) => ! in_array($k, $keys, true) && ! preg_grep('/^'.preg_quote($k, '/').'\./', $keys)
        && ! in_array($k, ['health_state', 'health_queue_state', 'task', 'col_', 'note_', 'campaign_note_'], true)));

    expect($missing)->toBe([]);
});
