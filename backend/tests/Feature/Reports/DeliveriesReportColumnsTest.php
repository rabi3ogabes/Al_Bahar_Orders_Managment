<?php

use App\Domain\Reports\Models\DataExport;
use App\Domain\Reports\Services\ReportService;
use App\Livewire\Admin\Insights\ReportIndex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/OwnerNumbersSupport.php';

// Reports > Deliveries counts deliveries CREATED in the period with the status they have NOW. Its columns say so, so nobody reads them as the scorecard's period numbers.

beforeEach(function () {
    $this->world = onWorld();
    $order = onOrder([[$this->world->a, '10.00', '0.00', 'failed']]);
    DB::table('deliveries')->insert(['id' => (string) Str::uuid(), 'order_fulfillment_id' => $order->fulfillments->first()->id, 'branch_id' => $this->world->a->id, 'mode' => 'internal',
        'status' => 'failed', 'tracking_number' => 'TRK9', 'failed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $this->today = now()->toDateString();
});

it('keeps every number and renames the columns to say "so far"', function () {
    $r = app(ReportService::class)->run($this->world->admin, 'deliveries', $this->today, $this->today);

    expect($r['columns'])->toBe(['executor', 'mode', 'deliveries', 'now_delivered', 'attempts', 'now_failed', 'avg_minutes_on_road_delivered'])
        ->and($r['rows'])->toHaveCount(1)->and((int) $r['rows'][0]['deliveries'])->toBe(1)->and((int) $r['rows'][0]['now_failed'])->toBe(1)->and((int) $r['rows'][0]['now_delivered'])->toBe(0);
});

it('shows the new headings and the one-line explanation under the table, in English and Arabic', function () {
    $this->actingAs($this->world->admin);

    Livewire::test(ReportIndex::class)->set('report', 'deliveries')
        ->assertSee('Deliveries created')->assertSee('Delivered so far')->assertSee('Failed or returned so far')->assertSee('Avg. minutes on road (delivered ones)')
        ->assertSee(__('owner_numbers.foot_deliveries'))->assertDontSee('>Failed<', false);
    app()->setLocale('ar');
    Livewire::test(ReportIndex::class)->set('report', 'deliveries')->assertSee('سُلّمت حتى الآن')->assertSee(__('owner_numbers.foot_deliveries'));
    expect(__('owner_numbers.foot_deliveries'))->not->toBe('owner_numbers.foot_deliveries');
});

it('writes the renamed columns into the CSV header', function () {
    $this->actingAs($this->world->admin);
    Livewire::test(ReportIndex::class)->set('report', 'deliveries')->call('export');

    $export = DataExport::firstOrFail();
    $csv = file_get_contents(Storage::disk($export->disk)->path($export->path));

    expect($csv)->toContain('now_delivered')->and($csv)->toContain('now_failed')->and($csv)->toContain('avg_minutes_on_road_delivered');
});
