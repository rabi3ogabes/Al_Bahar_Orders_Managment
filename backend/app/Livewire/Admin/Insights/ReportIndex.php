<?php

namespace App\Livewire\Admin\Insights;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Reports\Jobs\GenerateExportJob;
use App\Domain\Reports\Models\DataExport;
use App\Domain\Reports\Services\ReportService;
use App\Livewire\Admin\Concerns\WithAdminTable;
use App\Support\BusinessTime;
use App\Support\DomainException;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Report picker (only reports the viewer may run), date/branch filters, table + chart, and CSV export via the queue. */
class ReportIndex extends Component
{
    use WithAdminTable;

    public const GROUPS = [
        'sales' => ['sales_summary', 'sales_by_branch', 'sales_by_product', 'sales_by_category', 'sales_by_brand', 'sales_by_payment_method', 'sales_by_fulfillment', 'order_status'],
        'inventory' => ['inventory_levels', 'inventory_valuation', 'stock_movements', 'fast_slow_movers', 'transfers', 'damaged_lost'],
        'operations' => ['customers', 'deliveries', 'pickups', 'auctions'],
        'finance' => ['finance_collections', 'finance_refunds', 'finance_outstanding', 'sadad_reconciliation'],
    ];

    /** Which column to chart (label column => value column) for grouped reports. */
    public const CHARTS = [
        'sales_by_branch' => ['branch', 'net'], 'sales_by_category' => ['category', 'net'], 'sales_by_brand' => ['brand', 'net'], 'sales_by_payment_method' => ['payment_method', 'net'],
        'sales_by_fulfillment' => ['fulfillment_type', 'net'], 'order_status' => ['status', 'orders'], 'inventory_valuation' => ['branch', 'retail_value'],
        'deliveries' => ['executor', 'deliveries'], 'pickups' => ['branch', 'pickups'], 'finance_collections' => ['branch', 'collected'], 'sales_by_product' => ['sku', 'net'],
    ];

    #[Url(except: '')]
    public string $report = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function mount(): void
    {
        abort_unless($this->can('reports.view'), 403);
        $available = $this->available();
        if (! in_array($this->report, array_merge(...array_values($available)), true)) {
            $this->report = collect($available)->flatten()->first() ?? '';
        }
        $this->from = $this->from ?: BusinessTime::now()->subDays(29)->toDateString();
        $this->to = $this->to ?: BusinessTime::now()->toDateString();
    }

    /** @return array<string, list<string>> */
    private function available(): array
    {
        $out = [];
        foreach (self::GROUPS as $g => $keys) {
            $ok = array_values(array_filter($keys, fn ($k) => $this->can(ReportService::REPORTS[$k])));
            if ($ok) {
                $out[$g] = $ok;
            }
        }

        return $out ?: ['sales' => []];
    }

    private function range(): array
    {
        $from = strtotime($this->from) ? $this->from : BusinessTime::now()->toDateString();
        $to = strtotime($this->to) && $this->to >= $from ? $this->to : $from;

        return [$from, $to];
    }

    public function preset(string $p): void
    {
        $now = BusinessTime::now();
        [$this->from, $this->to] = match ($p) {
            'today' => [$now->toDateString(), $now->toDateString()],
            '7d' => [$now->subDays(6)->toDateString(), $now->toDateString()],
            'mtd' => [$now->startOfMonth()->toDateString(), $now->toDateString()],
            default => [$now->subDays(29)->toDateString(), $now->toDateString()],
        };
    }

    public function export(AuditLogger $audit): void
    {
        abort_unless($this->can('reports.export') && isset(ReportService::REPORTS[$this->report]) && $this->can(ReportService::REPORTS[$this->report]), 403);
        [$from, $to] = $this->range();
        $filters = array_filter(['from' => $from, 'to' => $to, 'branch_id' => $this->branch ?: null]);
        $export = DataExport::create(['user_id' => auth()->id(), 'type' => $this->report, 'filters' => $filters, 'status' => 'queued']);
        $audit->log('report.exported', $export, [], ['report' => $this->report, 'filters' => $filters]);
        GenerateExportJob::dispatch($export->id);
        $this->toast(__('content_admin.export_queued'));
    }

    public function render(ReportService $reports)
    {
        [$from, $to] = $this->range();
        $result = null;
        $error = null;
        if ($this->report) {
            try {
                $result = $reports->run(auth()->user(), $this->report, $from, $to, $this->branch ?: null);
            } catch (DomainException $e) {
                $error = $e->getMessage();
            }
        }
        $chart = null;
        if ($result && isset(self::CHARTS[$this->report]) && count($result['rows']) > 0) {
            [$label, $value] = self::CHARTS[$this->report];
            $rows = array_slice($result['rows'], 0, 12);
            $chart = ['labels' => array_map(fn ($r) => (string) ($r[$label] ?? '—'), $rows), 'values' => array_map(fn ($r) => (float) ($r[$value] ?? 0), $rows), 'metric' => $value];
        }
        $perm = ReportService::REPORTS[$this->report] ?? 'reports.view';

        return view('livewire.admin.insights.reports', [
            'groups' => $this->available(),
            'result' => $result,
            'error' => $error,
            'chart' => $chart,
            'branches' => $this->scopedBranches($perm),
            'canExport' => $this->can('reports.export'),
        ])->title(__('nav.reports'));
    }
}
