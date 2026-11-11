<?php

namespace App\Livewire\Admin\Organization;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
use App\Livewire\Admin\Concerns\WithAdminTable;
use App\Support\BusinessTime;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Read-only, filterable audit trail. Branch-level viewers only see entries for their branches. */
class AuditIndex extends Component
{
    use WithAdminTable;

    #[Url(except: '')]
    public string $filterModule = '';

    #[Url(except: '')]
    public string $filterActor = '';

    #[Url(except: '')]
    public string $subject = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public ?int $expanded = null;

    public function mount(): void
    {
        abort_unless($this->can('audit.view'), 403);
    }

    public function toggle(int $id): void
    {
        $this->expanded = $this->expanded === $id ? null : $id;
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'branch', 'filterModule', 'filterActor', 'subject', 'from', 'to');
        $this->resetPage();
    }

    /** @return Builder<AuditLog> */
    private function query(): Builder
    {
        $q = AuditLog::query()->with('actor:id,name,email,type')
            ->when($this->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('action', 'ilike', "%{$s}%")->orWhere('auditable_id', $s)->orWhere('request_id', $s)->orWhere('ip', $s)))
            ->when($this->filterModule, fn ($q, $m) => $q->where('action', 'like', addcslashes($m, '%_').'.%'))
            ->when($this->filterActor, fn ($q, $a) => $q->whereHas('actor', fn ($u) => $u->where('name', 'ilike', "%{$a}%")->orWhere('email', 'ilike', "%{$a}%")))
            ->when($this->subject && str_contains($this->subject, ':'), function ($q) {
                [$type, $id] = explode(':', $this->subject, 2);
                $q->where('auditable_type', $type)->where('auditable_id', $id);
            })
            ->when($this->from && strtotime($this->from), fn ($q) => $q->where('created_at', '>=', BusinessTime::dateRange($this->from, $this->from)[0]))
            ->when($this->to && strtotime($this->to), fn ($q) => $q->where('created_at', '<=', BusinessTime::dateRange($this->to, $this->to)[1]))
            ->orderByDesc('id');
        $this->branchScope('audit.view')->apply($q->getQuery());

        return $q;
    }

    public function export(AuditLogger $audit)
    {
        abort_unless($this->can('audit.export'), 403);
        $rows = $this->query()->limit(10000)->get();
        $audit->log('audit.exported', 'AuditLog', [], [], ['rows' => $rows->count(), 'filters' => array_filter([
            'search' => $this->search, 'branch' => $this->branch, 'module' => $this->filterModule, 'actor' => $this->filterActor,
            'subject' => $this->subject, 'from' => $this->from, 'to' => $this->to,
        ])]);
        $name = 'audit-log-'.BusinessTime::now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel renders Arabic correctly
            fputcsv($out, ['id', 'created_at_qatar', 'actor', 'actor_email', 'action', 'subject_type', 'subject_id', 'branch_id', 'old_values', 'new_values', 'metadata', 'ip', 'request_id']);
            foreach ($rows as $r) {
                fputcsv($out, [$r->id, BusinessTime::local($r->created_at)?->format('Y-m-d H:i:s'), $r->actor?->name ?? 'system', $r->actor?->email, $r->action,
                    $r->auditable_type, $r->auditable_id, $r->branch_id, $r->old_values ? json_encode($r->old_values, JSON_UNESCAPED_UNICODE) : '',
                    $r->new_values ? json_encode($r->new_values, JSON_UNESCAPED_UNICODE) : '', $r->metadata ? json_encode($r->metadata, JSON_UNESCAPED_UNICODE) : '',
                    $r->ip, $r->request_id]);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function render()
    {
        $logs = $this->query()->paginate($this->perPage);
        $branchNames = Branch::whereIn('id', $logs->pluck('branch_id')->filter()->unique())->pluck('code', 'id');

        return view('livewire.admin.organization.audit', [
            'logs' => $logs,
            'branchNames' => $branchNames,
            'branches' => $this->scopedBranches('audit.view'),
            'modules' => ['auth', 'employee', 'role', 'zone', 'branch', 'catalog', 'inventory', 'transfer', 'stocktaking', 'purchasing', 'settings', 'audit', 'order', 'payment', 'refund'],
            'canExport' => $this->can('audit.export'),
            'hasFilters' => (bool) ($this->search || $this->branch || $this->filterModule || $this->filterActor || $this->subject || $this->from || $this->to),
        ])->title(__('nav.audit'));
    }
}
