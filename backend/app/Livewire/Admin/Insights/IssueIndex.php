<?php

namespace App\Livewire\Admin\Insights;

use App\Domain\Issues\Models\IssueReport;
use App\Domain\Issues\Services\IssueService;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Issue log: record payment problems and bugs in the platform or any app, track them to resolution. */
class IssueIndex extends Component
{
    use InteractsWithAdmin, WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: 'active')]
    public string $tab = 'active'; // active | closed | all

    #[Url(except: '')]
    public string $kind = '';

    #[Url(except: '')]
    public string $app = '';

    #[Url(except: '')]
    public string $severity = '';

    public bool $showForm = false;

    public array $form = [];

    #[Locked]
    public ?string $detailId = null;

    public string $resolution = '';

    public function mount(): void
    {
        abort_unless($this->can('orders.view'), 403);
        $this->blank();
        if (request()->query('order')) {
            $this->form['order_number'] = (string) request()->query('order');
            $this->form['kind'] = 'payment';
            $this->showForm = true;
        }
    }

    private function blank(): void
    {
        $this->form = ['kind' => 'payment', 'app' => 'dashboard', 'severity' => 'medium', 'title' => '', 'details' => '', 'order_number' => '', 'payment_reference' => ''];
    }

    public function updated(string $prop): void
    {
        if (in_array($prop, ['search', 'tab', 'kind', 'app', 'severity'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->blank();
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function save(IssueService $issues): void
    {
        abort_unless($this->can('orders.view'), 403);
        $d = $this->validate([
            'form.kind' => 'required|in:'.implode(',', IssueReport::KINDS), 'form.app' => 'required|in:'.implode(',', IssueReport::APPS),
            'form.severity' => 'required|in:'.implode(',', IssueReport::SEVERITIES), 'form.title' => 'required|string|min:5|max:191',
            'form.details' => 'nullable|string|max:5000', 'form.order_number' => 'nullable|string|max:24', 'form.payment_reference' => 'nullable|string|max:128',
        ], [], ['form.title' => __('issue_admin.title_field'), 'form.details' => __('issue_admin.details')])['form'];
        $issue = $this->attempt(fn () => $issues->report(auth()->user(), $d));
        if ($issue) {
            $this->showForm = false;
            $this->toast(__('issue_admin.saved', ['ref' => $issue->reference]));
        }
    }

    public function open(string $id): void
    {
        $this->detailId = IssueReport::findOrFail($id)->id;
        $this->resolution = (string) IssueReport::find($id)->resolution;
    }

    public function closeDetail(): void
    {
        $this->detailId = null;
    }

    public function setStatus(string $status, IssueService $issues): void
    {
        abort_unless($this->can('orders.edit'), 403);
        $issue = IssueReport::findOrFail($this->detailId);
        $this->attempt(fn () => $issues->setStatus($issue, $status, auth()->user(), trim($this->resolution) ?: null), __('issue_admin.updated'));
    }

    public function render()
    {
        $s = trim($this->search);
        $q = IssueReport::query()->with(['order:id,number', 'payment:id,provider_reference', 'reporter:id,name'])
            ->when($this->tab === 'active', fn ($q) => $q->whereIn('status', ['open', 'investigating']))
            ->when($this->tab === 'closed', fn ($q) => $q->whereIn('status', ['resolved', 'wont_fix']))
            ->when($this->kind, fn ($q, $v) => $q->where('kind', $v))->when($this->app, fn ($q, $v) => $q->where('app', $v))->when($this->severity, fn ($q, $v) => $q->where('severity', $v))
            ->when($s, fn ($q) => $q->where(fn ($w) => $w->where('title', 'ilike', "%{$s}%")->orWhere('reference', 'ilike', "%{$s}%")->orWhereHas('order', fn ($o) => $o->where('number', 'ilike', "%{$s}%"))))
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")->latest();

        return view('livewire.admin.insights.issues', [
            'issues' => $q->paginate(20),
            'counts' => ['active' => IssueReport::whereIn('status', ['open', 'investigating'])->count(), 'critical' => IssueReport::whereIn('status', ['open', 'investigating'])->whereIn('severity', ['high', 'critical'])->count()],
            'detail' => $this->detailId ? IssueReport::with(['order:id,number', 'payment:id,provider_reference,status', 'reporter:id,name', 'resolver:id,name'])->find($this->detailId) : null,
            'canEdit' => $this->can('orders.edit'),
        ])->title(__('issue_admin.title'));
    }
}
