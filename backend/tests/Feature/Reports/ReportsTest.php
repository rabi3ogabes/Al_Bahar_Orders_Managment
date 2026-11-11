<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Reports\Models\DataExport;
use App\Domain\Reports\Services\ReportService;

it('runs every report without errors and respects branch scope', function () {
    $w = commerceWorld();
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 8]); // split across A and B
    quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->assertCreated();
    $today = now('Asia/Qatar')->toDateString();
    $admin = staffWithRole('admin');
    foreach (array_keys(ReportService::REPORTS) as $r) {
        $res = app(ReportService::class)->run($admin, $r, $today, $today);
        expect($res)->toHaveKeys(['columns', 'rows', 'summary']);
    }
    $all = app(ReportService::class)->run($admin, 'sales_by_branch', $today, $today);
    expect($all['rows'])->toHaveCount(2);

    $mgrA = staffWithRole('branch_manager', $w->a);
    $mine = app(ReportService::class)->run($mgrA, 'sales_by_branch', $today, $today);
    expect($mine['rows'])->toHaveCount(1)->and($mine['rows'][0]['branch'])->toBe($w->a->code);
    expect(app(ReportService::class)->run($mgrA, 'sales_by_branch', $today, $today, $w->b->id)['rows'])->toBe([]);
});

it('denies reports without permission and audits exports', function () {
    $w = commerceWorld();
    actingAsStaff(staffWithRole('pickup_employee', $w->a));
    $this->getJson('/api/v1/admin/reports/sales_summary?from=2026-01-01&to=2026-12-31')->assertForbidden();

    actingAsStaff(staffWithRole('accountant'));
    $this->postJson('/api/v1/admin/exports', ['report' => 'finance_collections', 'from' => '2026-01-01', 'to' => '2026-12-31'])->assertStatus(202);
    expect(AuditLog::where('action', 'report.exported')->exists())->toBeTrue()->and(DataExport::first()->status)->toBe('completed');
    $url = $this->getJson('/api/v1/admin/exports')->json('data.0.download_url');
    expect($url)->not->toBeNull();
});
