<?php

namespace App\Livewire\Admin\Sales;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Services\CustomerMessaging;
use App\Domain\Reports\Services\CustomerValue;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Concerns\WithAdminTable;
use App\Livewire\Admin\Sales\Concerns\HasRecordWindow;
use App\Livewire\Admin\Sales\Concerns\PeeksAtOrder;
use App\Support\BusinessTime;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sales > Customer value. The question of the page is "Who should we win back?": how many customers have gone quiet and what they were worth, then the
 * list, most valuable first. Tabs for the other ways to look (top customers, repeat buyers, one-time buyers, new this month), search, sort, a branch filter
 * and a CSV for people who may export customers.
 *
 * The figures come from {@see CustomerValue}: what a customer was charged at the viewer's branches only (a branch manager never reads what was spent
 * elsewhere), phone and e-mail masked unless the viewer may edit customers (the same rule as the customers list). A row opens the existing customer window,
 * where "Send message" is; nothing is sent from here.
 */
class CustomerValuePage extends Component
{
    use HasRecordWindow, PeeksAtOrder;
    use WithAdminTable;

    #[Url(except: 'quiet')]
    public string $tab = 'quiet';

    #[Locked]
    #[Url(as: 'view', except: '')]
    public string $viewId = '';

    public bool $showSettings = false;

    public string $quietDays = '';

    public function mount(CustomerValue $value): void
    {
        abort_unless($this->can('customers.view'), 403);
        if (! in_array($this->tab, CustomerValue::TABS, true)) {
            $this->tab = 'quiet';
        }
        $this->quietDays = (string) $value->quietDays();
        $value->ensureFresh();
        if ($this->viewId !== '') {
            $this->openView($this->viewId, quiet: true);
        }
    }

    protected function findViewable(string $id): ?Model
    {
        $customer = User::where('type', 'customer')->find($id);

        return $customer && CustomerShow::viewableBy(auth()->user(), $customer) ? $customer : null;
    }

    public function updatedTab(): void
    {
        if (! in_array($this->tab, CustomerValue::TABS, true)) {
            $this->tab = 'quiet';
        }
        $this->sort = '';
        $this->resetPage();
    }

    /** @return array{tab: string, sort: string, search: string, branch: string} */
    private function filters(): array
    {
        return ['tab' => $this->tab, 'sort' => $this->sort, 'search' => trim($this->search), 'branch' => Str::isUuid($this->branch) ? $this->branch : ''];
    }

    /** "Refresh now": one pass over the orders, at most once every two minutes whoever presses it. */
    public function refreshNow(CustomerValue $value): void
    {
        if (RateLimiter::tooManyAttempts('customer-value-refresh', 1)) {
            $this->toast(__('customer_value.refresh_wait'), 'error');

            return;
        }
        RateLimiter::hit('customer-value-refresh', 120);
        $value->refresh();
        $this->toast(__('customer_value.refreshed'));
    }

    public function saveSettings(Settings $settings): void
    {
        abort_unless($this->canGlobally('settings.edit'), 403);
        $data = $this->validate(['quietDays' => 'required|integer|min:'.CustomerValue::QUIET_MIN.'|max:'.CustomerValue::QUIET_MAX], [], ['quietDays' => __('customer_value.quiet_days_label')]);
        $settings->set('customer_value.gone_quiet_days', (int) $data['quietDays']);
        $this->showSettings = false;
        $this->resetPage();
        $this->toast(__('admin.saved'));
    }

    /** The list as a CSV (at most {@see CustomerValue::EXPORT_CAP} rows), for people who may export customers. Masked like the page; the export is audited. */
    public function export(CustomerValue $value, AuditLogger $audit): StreamedResponse
    {
        abort_unless($this->can('customers.export'), 403);
        $reveal = $this->can('customers.edit');
        $filters = $this->filters();
        $rows = $value->query(auth()->user(), $filters)->limit(CustomerValue::EXPORT_CAP)->get()->map(fn ($u) => $value->row($u));
        $audit->log('customer_value.exported', 'CustomerValue', [], ['tab' => $filters['tab'], 'search' => $filters['search'] !== '', 'branch' => $filters['branch'] ?: null, 'rows' => $rows->count(), 'masked' => ! $reveal]);

        $safe = fn (?string $v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) === 1 ? "'".$v : $v; // a spreadsheet must never run a customer's name as a formula
        $header = [__('customer_value.col_customer'), __('sales_admin.contact'), __('customer_value.col_orders'), __('customer_value.col_spend'), __('customer_value.col_basket'),
            __('customer_value.col_first'), __('customer_value.col_last'), __('customer_value.col_days_quiet'), __('customer_value.col_gap')];

        return response()->streamDownload(function () use ($rows, $header, $reveal, $safe) {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF");
            fputcsv($h, $header);
            foreach ($rows as $r) {
                fputcsv($h, [$safe($r->name), $reveal ? trim(($r->phone ?? '').' '.($r->email ?? '')) : trim((Phone::mask($r->phone) ?? '').' '.(CustomerIndex::maskEmail($r->email) ?? '')), $r->orders, $r->spend, $r->basket,
                    BusinessTime::display($r->first_order_at, 'Y-m-d'), BusinessTime::display($r->last_order_at, 'Y-m-d'), $r->days_quiet, $r->usual_gap_days ?? '']);
            }
            fclose($h);
        }, 'customer-value-'.$filters['tab'].'-'.BusinessTime::now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function render(CustomerValue $value)
    {
        abort_unless($this->can('customers.view'), 403);
        $filters = $this->filters();
        $branchId = $filters['branch'] !== '' ? $filters['branch'] : null;
        $rows = $value->paginate(auth()->user(), $filters, $this->perPage);
        $summary = $value->summary(auth()->user(), $branchId);
        $this->shownIds = array_values(array_map(fn (object $r) => (string) $r->id, $rows->items()));

        return view('livewire.admin.sales.customer-value', [
            'rows' => $rows,
            'summary' => $summary,
            'quietDays' => $value->quietDays(),
            'refreshedAt' => $value->refreshedAt(),
            'branches' => $this->scopedBranches('customers.view'),
            'reveal' => $this->can('customers.edit'),
            'canExport' => $this->can('customers.export'),
            'canSettings' => $this->canGlobally('settings.edit'),
            'canMessage' => app(CustomerMessaging::class)->canSend(auth()->user()),
            'sorts' => CustomerValue::SORTS,
        ])->title(__('customer_value.title'));
    }

    /** @var list<string> the customers on this page (the hero's "start with" button opens the first) */
    #[Locked]
    public array $shownIds = [];

    /** The hero's one action: open the most valuable customer of the list, in the customer window where the message is sent. */
    public function startWithFirst(): void
    {
        $first = $this->shownIds[0] ?? null;
        if ($first !== null) {
            $this->openView($first);
        }
    }
}
