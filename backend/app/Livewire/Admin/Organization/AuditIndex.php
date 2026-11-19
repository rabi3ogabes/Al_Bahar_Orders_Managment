<?php

namespace App\Livewire\Admin\Organization;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
use App\Livewire\Admin\Concerns\WithAdminTable;
use App\Support\BusinessTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Read-only, filterable audit trail. Branch-level viewers only see entries for their branches.
 *
 * The log only ever grows, so this page never reads all of it: without dates it shows the last DEFAULT_DAYS days (a record's own trail, opened
 * from its page, is the exception: it is bounded by the record), and the number of matching entries is counted only up to COUNT_CAP, shown as
 * "1,000+" beyond that, instead of a count(*) over millions of rows on every page view and keystroke.
 */
class AuditIndex extends Component
{
    use WithAdminTable;

    /** The window shown when nobody picked dates. */
    public const DEFAULT_DAYS = 30;

    /** Entries are counted up to this many; more than this is shown as "1,000+" and reached by narrowing the dates or the search. */
    public const COUNT_CAP = 1000;

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
            ->when($this->effectiveFrom(), fn ($q, $from) => $q->where('created_at', '>=', BusinessTime::dateRange($from, $from)[0]))
            ->when($this->to && strtotime($this->to), fn ($q) => $q->where('created_at', '<=', BusinessTime::dateRange($this->to, $this->to)[1]))
            ->orderByDesc('id');
        $this->branchScope('audit.view')->apply($q->getQuery());

        return $q;
    }

    /** The first day shown: the one picked, else DEFAULT_DAYS before the last day picked (or before today), and none for one record's own trail. */
    private function effectiveFrom(): ?string
    {
        if ($this->from && strtotime($this->from)) {
            return $this->from;
        }
        if ($this->subject !== '' && str_contains($this->subject, ':')) {
            return null;
        }
        $end = $this->to && strtotime($this->to) ? BusinessTime::dateRange($this->to, $this->to)[0]->setTimezone(BusinessTime::zone()) : BusinessTime::now();

        return $end->subDays(self::DEFAULT_DAYS)->toDateString();
    }

    public function export(AuditLogger $audit)
    {
        abort_unless($this->can('audit.export'), 403);
        $rows = $this->query()->limit(10000)->get();
        $audit->log('audit.exported', 'AuditLog', [], [], ['rows' => $rows->count(), 'filters' => array_filter([
            'search' => $this->search, 'branch' => $this->branch, 'module' => $this->filterModule, 'actor' => $this->filterActor,
            'subject' => $this->subject, 'from' => $this->effectiveFrom(), 'to' => $this->to,
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

    /**
     * One page of the log and a count that stops at COUNT_CAP: the count reads at most COUNT_CAP + 1 matching rows instead of every one.
     *
     * @return array{0: LengthAwarePaginator<int, AuditLog>, 1: bool} the page, and whether more entries match than are counted
     */
    private function page(): array
    {
        $query = $this->query();
        $matching = DB::query()->fromSub((clone $query)->toBase()->reorder()->select('audit_logs.id')->limit(self::COUNT_CAP + 1), 'm')->count();
        $capped = $matching > self::COUNT_CAP;
        $total = min($matching, self::COUNT_CAP);
        $page = max(1, min($this->getPage(), (int) ceil(max(1, $total) / $this->perPage)));
        $items = $total === 0 ? collect() : (clone $query)->forPage($page, $this->perPage)->get();

        return [new LengthAwarePaginator($items, $total, $this->perPage, $page, ['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'page']), $capped];
    }

    public function render()
    {
        [$logs, $capped] = $this->page();
        $branchNames = Branch::whereIn('id', $logs->pluck('branch_id')->filter()->unique())->pluck('code', 'id');

        return view('livewire.admin.organization.audit', [
            'logs' => $logs,
            'branchNames' => $branchNames,
            'branches' => $this->scopedBranches('audit.view'),
            'modules' => ['auth', 'employee', 'role', 'zone', 'branch', 'catalog', 'inventory', 'transfer', 'stocktaking', 'purchasing', 'settings', 'audit', 'order', 'payment', 'refund'],
            'canExport' => $this->can('audit.export'),
            'capped' => $capped,
            'defaultWindow' => $this->effectiveFrom() !== null && ! $this->from,
            'hasFilters' => (bool) ($this->search || $this->branch || $this->filterModule || $this->filterActor || $this->subject || $this->from || $this->to),
        ])->title(__('nav.audit'));
    }
}
