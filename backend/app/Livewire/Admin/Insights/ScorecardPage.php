<?php

namespace App\Livewire\Admin\Insights;

use App\Domain\Identity\Services\AccessControl;
use App\Domain\Reports\Services\ScorecardCsv;
use App\Domain\Reports\Services\ScorecardPeriod;
use App\Domain\Reports\Services\ScorecardService;
use App\Domain\Reports\Services\ScorecardSettings;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Support\BusinessTime;
use App\Support\DomainException;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Insights > Scorecard. The question of the page is "How are the branches doing?" (and, on the second tab, the drivers): each branch's speed, promises kept,
 * failures and cash over a period, set beside the period of the same length before it. Every figure says how many cases it is built from and, below the
 * minimum sample, says "not enough data yet". Nothing on the page ranks people: the drivers are listed alphabetically unless the viewer sorts a column.
 *
 * Who sees what: reports.view, limited to the branches that right covers (a branch filter can only narrow it); a branch manager therefore sees their own
 * branches and the drivers of those branches, and the work and cash of a driver that belong to those branches only. Drivers and ordinary staff do not
 * hold reports.view: no menu entry, and the route answers 403. CSV needs reports.export and is audited. Every figure and rule is in
 * {@see ScorecardService}; this class only asks and shows. Everything the browser sends (tab, period, branch, driver, sort) is checked again here.
 */
class ScorecardPage extends Component
{
    use InteractsWithAdmin;

    public const RANGES = ['7', '30', '90', 'custom'];

    public const SORTS = ['name', 'delivered', 'on_time', 'trip', 'failed', 'jobs', 'cash', 'variance'];

    #[Url(except: 'branches')]
    public string $tab = 'branches';

    #[Url(except: '30')]
    public string $range = '30';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: '')]
    public string $branch = '';

    #[Url(except: 'name')]
    public string $sort = 'name';

    #[Url(except: 'asc')]
    public string $dir = 'asc';

    /** The driver whose window is open; mirrors the window so reload, sharing and Back work. */
    #[Locked]
    #[Url(as: 'driver', except: '')]
    public string $driverId = '';

    /** The branch whose window is open. */
    #[Locked]
    #[Url(as: 'card', except: '')]
    public string $branchCardId = '';

    public bool $showDriver = false;

    public bool $showBranch = false;

    public bool $showSettings = false;

    public string $minSample = '';

    /** Said under the period when what was asked for could not be used. */
    public ?string $notice = null;

    public function mount(ScorecardSettings $settings): void
    {
        abort_unless($this->can('reports.view'), 403);
        $this->normalise();
        $this->minSample = (string) $settings->minSample();
        if ($this->driverId !== '' && $this->tab === 'drivers') {
            $this->showDriver = true;
        } else {
            $this->driverId = '';
        }
        if ($this->branchCardId !== '' && $this->tab === 'branches') {
            $this->showBranch = true;
        } else {
            $this->branchCardId = '';
        }
    }

    /** Whatever came in the address, made safe: an unknown tab, range, sort or branch falls back to the default. */
    private function normalise(): void
    {
        $this->tab = in_array($this->tab, ScorecardService::TABS, true) ? $this->tab : 'branches';
        $this->range = in_array($this->range, self::RANGES, true) ? $this->range : '30';
        $this->sort = in_array($this->sort, self::SORTS, true) ? $this->sort : 'name';
        $this->dir = $this->dir === 'desc' ? 'desc' : 'asc';
        $this->branch = Str::isUuid($this->branch) ? $this->branch : '';
        if ($this->range === 'custom' && ($this->from === '' || $this->to === '')) {
            $last = ScorecardPeriod::last(30);
            $this->from = $this->from !== '' ? $this->from : $last->from;
            $this->to = $this->to !== '' ? $this->to : $last->to;
        }
    }

    public function setRange(string $range): void
    {
        $this->range = in_array($range, self::RANGES, true) ? $range : '30';
        $this->normalise();
        $this->closeWindows();
    }

    public function updatedTab(): void
    {
        $this->normalise();
        $this->closeWindows();
    }

    public function updatedBranch(): void
    {
        $this->normalise();
        $this->closeWindows();
    }

    public function updatedFrom(): void
    {
        $this->closeWindows();
    }

    public function updatedTo(): void
    {
        $this->closeWindows();
    }

    public function sortBy(string $column): void
    {
        if (! in_array($column, self::SORTS, true)) {
            return;
        }
        $this->dir = $this->sort === $column && $this->dir === 'asc' ? 'desc' : 'asc';
        $this->sort = $column;
    }

    /** Opens a driver, but only one the viewer's own branches show (a made-up id, or a driver of someone else's branch, opens nothing). */
    public function openDriver(string $driverId): void
    {
        if (! app(ScorecardService::class)->mayOpenDriver(auth()->user(), $driverId, $this->period(), $this->branchFilter())) {
            $this->closeDriver();
            $this->toast(__('scorecard.not_available'), 'error');

            return;
        }
        $this->driverId = $driverId;
        $this->showDriver = true;
    }

    public function openBranch(string $branchId): void
    {
        $known = Str::isUuid($branchId) && app(ScorecardService::class)->branchesFor(auth()->user(), $this->branchFilter())->contains('id', $branchId);
        if (! $known) {
            $this->closeBranch();
            $this->toast(__('scorecard.not_available'), 'error');

            return;
        }
        $this->branchCardId = $branchId;
        $this->showBranch = true;
    }

    /** Every way of closing a side window (✕, Esc, scrim, Back) clears it from the address. */
    public function updatedShowDriver(bool $open): void
    {
        if (! $open) {
            $this->closeDriver();
        }
    }

    public function updatedShowBranch(bool $open): void
    {
        if (! $open) {
            $this->closeBranch();
        }
    }

    public function closeDriver(): void
    {
        $this->showDriver = false;
        $this->driverId = '';
    }

    public function closeBranch(): void
    {
        $this->showBranch = false;
        $this->branchCardId = '';
    }

    private function closeWindows(): void
    {
        $this->closeDriver();
        $this->closeBranch();
    }

    public function refresh(ScorecardService $service): void
    {
        $service->forget(auth()->user(), $this->period(), $this->branchFilter());
        $this->toast(__('scorecard.refreshed'));
    }

    public function saveSettings(ScorecardSettings $settings): void
    {
        abort_unless($this->canGlobally('settings.edit'), 403);
        $data = $this->validate(['minSample' => 'required|integer|min:'.ScorecardSettings::MIN.'|max:'.ScorecardSettings::MAX], [], ['minSample' => __('scorecard.min_label')]);
        $settings->setMinSample((int) $data['minSample']);
        $this->showSettings = false;
        $this->toast(__('admin.saved'));
    }

    /** The tab as a CSV, for people who may export reports at the branches shown. Audited. */
    public function export(ScorecardService $service): StreamedResponse
    {
        abort_unless($this->can('reports.export'), 403);
        try {
            $result = $service->export(auth()->user(), $this->tab, $this->period(), $this->branchFilter());
        } catch (DomainException $e) {
            abort($e->status, $e->getMessage());
        }
        $csv = ScorecardCsv::rows($result['tab'], $result['data'], app()->getLocale());
        $name = 'scorecard-'.$result['tab'].'-'.$result['period']['from'].'-to-'.$result['period']['to'].'.csv';

        return response()->streamDownload(function () use ($csv) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $csv['header']);
            foreach ($csv['rows'] as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** The period the address asks for; a range that cannot be used falls back to the last 30 days and says why. */
    private function period(): ScorecardPeriod
    {
        $this->notice = null;
        if ($this->range === 'custom') {
            try {
                return ScorecardPeriod::between($this->from, $this->to);
            } catch (DomainException $e) {
                $this->notice = __('scorecard.fell_back', ['why' => $e->getMessage()]);

                return ScorecardPeriod::last(30);
            }
        }

        return ScorecardPeriod::last((int) $this->range);
    }

    private function branchFilter(): ?string
    {
        return $this->branch !== '' ? $this->branch : null;
    }

    /**
     * The drivers in the order asked for: by name unless a column is chosen. A figure that is not shown (too few cases) sorts last whichever way it goes.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sorted(array $rows): array
    {
        if ($this->sort === 'name') {
            return $this->dir === 'desc' ? array_reverse($rows) : $rows;
        }
        $key = fn (array $d): float|int|null => match ($this->sort) {
            'delivered' => $d['delivered']['value'],
            'on_time' => $d['on_time']['value'],
            'trip' => $d['trip']['median']['value'],
            'failed' => $d['failed']['rate']['value'],
            'jobs' => $d['jobs']['declined'] + $d['jobs']['timeouts'],
            'cash' => (float) $d['cash']['amount'],
            default => (float) $d['cash']['net'],
        };
        $withValue = array_values(array_filter($rows, fn (array $d) => $key($d) !== null));
        $without = array_values(array_filter($rows, fn (array $d) => $key($d) === null));
        usort($withValue, fn (array $a, array $b) => ($this->dir === 'desc' ? -1 : 1) * ($key($a) <=> $key($b)) ?: [mb_strtolower($a['name']), $a['id']] <=> [mb_strtolower($b['name']), $b['id']]);

        return array_merge($withValue, $without);
    }

    public function render(ScorecardService $service, ScorecardSettings $settings)
    {
        abort_unless($this->can('reports.view'), 403);
        $this->normalise();
        $period = $this->period();
        $user = auth()->user();
        $branchOptions = $service->branchesFor($user);
        if ($this->branch !== '' && ! $branchOptions->contains('id', $this->branch)) {
            $this->branch = ''; // a branch the viewer cannot see (a hand-edited address): back to all of theirs
        }
        $data = null;
        $error = false;
        if ($branchOptions->isNotEmpty()) {
            try {
                $data = $this->tab === 'drivers' ? $service->drivers($user, $period, $this->branchFilter()) : $service->branches($user, $period, $this->branchFilter());
            } catch (Throwable $e) {
                report($e);
                $error = true;
            }
        }
        if ($data !== null && $this->tab === 'drivers') {
            $data['rows'] = $this->sorted($data['rows']);
        }
        $branchCard = $data !== null && $this->tab === 'branches' && $this->branchCardId !== '' ? collect($data['rows'])->firstWhere('id', $this->branchCardId) : null;
        if ($this->branchCardId !== '' && $branchCard === null) {
            $this->closeBranch();
        }
        $driverDetail = null;
        if ($this->driverId !== '' && $this->tab === 'drivers' && ! $error) {
            $driverDetail = $service->driver($user, $this->driverId, $period, $this->branchFilter());
            if ($driverDetail === null) {
                $this->closeDriver();
            }
        }
        $all = app(AccessControl::class)->scope($user, 'reports.view')->all;

        return view('livewire.admin.insights.scorecard', [
            'data' => $data,
            'error' => $error,
            'period' => $data['period'] ?? $this->describe($period),
            'branchOptions' => $branchOptions,
            'branchCard' => $branchCard,
            'driverDetail' => $driverDetail,
            'canExport' => $this->can('reports.export'),
            'canSettings' => $this->canGlobally('settings.edit'),
            'canAll' => $all,
            'today' => BusinessTime::now()->toDateString(),
            'minAllowed' => ScorecardSettings::MIN,
            'maxAllowed' => ScorecardSettings::MAX,
            'minDefault' => ScorecardSettings::DEFAULT_MIN_SAMPLE,
        ])->title(__('scorecard.title'));
    }

    /** @return array{from: string, to: string, days: int, previous_from: string, previous_to: string, preset: ?int, includes_today: bool} */
    private function describe(ScorecardPeriod $period): array
    {
        $before = $period->previous();

        return ['from' => $period->from, 'to' => $period->to, 'days' => $period->days(), 'previous_from' => $before->from, 'previous_to' => $before->to,
            'preset' => $period->preset(), 'includes_today' => $period->includesToday()];
    }
}
