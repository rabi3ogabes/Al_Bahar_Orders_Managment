<?php

namespace App\Livewire\Admin\Insights;

use App\Domain\Reports\Services\DailyCloseService;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Insights > Daily close. The question of the page is "How did yesterday go?": the sales of the day in one big number, which branches closed their
 * day, and for each branch the figures behind it in a side window, where a manager signs the day off with a note.
 *
 * Who sees what: reports.view, limited to the branches that right covers (a branch filter can only narrow it). Closing needs, at that branch, the
 * right to see its reports AND to approve its orders; re-opening a closed day needs the company-wide right to change settings. The figures and the
 * rules are in {@see DailyCloseService}; this class only asks and shows. Everything the browser sends (the date, the branch, the note) is checked again here.
 */
class DailyClosePage extends Component
{
    use InteractsWithAdmin;

    #[Url(except: '')]
    public string $date = '';

    #[Url(except: '')]
    public string $branch = '';

    /** The branch whose day is open in the side window; mirrors the window so reload, sharing and Back work. */
    #[Locked]
    #[Url(as: 'review', except: '')]
    public string $reviewId = '';

    public bool $showReview = false;

    public string $note = '';

    public string $reopenReason = '';

    public bool $showReopen = false;

    public bool $showSettings = false;

    /** @var array{grace_minutes: string, fallback_time: string} */
    public array $settingsForm = ['grace_minutes' => '', 'fallback_time' => ''];

    public function mount(DailyCloseService $closes): void
    {
        abort_unless($this->can('reports.view'), 403);
        $today = BusinessTime::now()->toDateString();
        $this->date = DailyCloseService::isDate($this->date) && $this->date <= $today ? $this->date : BusinessTime::now()->subDay()->toDateString();
        if ($this->date < $closes->earliestDate()) {
            $this->date = $closes->earliestDate();
        }
        $this->settingsForm = ['grace_minutes' => (string) $closes->graceMinutes(), 'fallback_time' => $closes->fallbackTime()];
        if ($this->reviewId !== '') {
            $this->openReview($this->reviewId, quiet: true);
        }
    }

    public function updatedDate(DailyCloseService $closes): void
    {
        $today = BusinessTime::now()->toDateString();
        if (! DailyCloseService::isDate($this->date) || $this->date > $today) {
            $this->date = BusinessTime::now()->subDay()->toDateString();
        } elseif ($this->date < $closes->earliestDate()) {
            $this->date = $closes->earliestDate();
        }
        $this->closeReview();
    }

    /** The two quick choices next to the date: today and yesterday. */
    public function pick(string $which): void
    {
        $this->date = $which === 'today' ? BusinessTime::now()->toDateString() : BusinessTime::now()->subDay()->toDateString();
        $this->closeReview();
    }

    public function updatedBranch(): void
    {
        $this->closeReview();
    }

    /** Opens the day of one branch, but only a branch the viewer's board shows (a made-up id, or a branch of someone else, opens nothing). */
    public function openReview(string $branchId, bool $quiet = false): void
    {
        $known = Str::isUuid($branchId) && collect($this->board())->contains(fn (array $b) => $b['branch']->id === $branchId);
        if (! $known) {
            $this->reviewId = '';
            $this->showReview = false;
            if (! $quiet) {
                $this->toast(__('daily_close.not_available'), 'error');
            }

            return;
        }
        $this->reviewId = $branchId;
        $this->note = '';
        $this->resetValidation();
        $this->showReview = true;
    }

    /** Every way of closing the side window (✕, Esc, scrim, Back) clears the branch from the address. */
    public function updatedShowReview(bool $open): void
    {
        if (! $open) {
            $this->closeReview();
        }
    }

    public function closeReview(): void
    {
        $this->showReview = false;
        $this->reviewId = '';
        $this->showReopen = false;
    }

    public function closeDay(DailyCloseService $closes): void
    {
        $this->validate(['note' => 'nullable|string|max:1000'], [], ['note' => __('daily_close.note')]);
        $done = $this->attempt(fn () => $closes->close(auth()->user(), $this->reviewId, $this->date, $this->note), __('daily_close.closed_toast'));
        if ($done !== null) {
            $this->note = '';
        }
    }

    public function askReopen(): void
    {
        abort_unless($this->canGlobally('settings.edit'), 403);
        $this->reopenReason = '';
        $this->resetValidation();
        $this->showReopen = true;
    }

    public function reopenDay(DailyCloseService $closes): void
    {
        $this->validate(['reopenReason' => 'required|string|min:3|max:500'], [], ['reopenReason' => __('admin.reason')]);
        if ($this->attempt(fn () => $closes->reopen(auth()->user(), $this->reviewId, $this->date, $this->reopenReason), __('daily_close.reopened_toast')) !== null) {
            $this->showReopen = false;
        }
    }

    public function saveSettings(Settings $settings): void
    {
        abort_unless($this->canGlobally('settings.edit'), 403);
        $data = $this->validate([
            'settingsForm.grace_minutes' => 'required|integer|min:0|max:240',
            'settingsForm.fallback_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        ], [], ['settingsForm.grace_minutes' => __('daily_close.grace_label'), 'settingsForm.fallback_time' => __('daily_close.fallback_label')])['settingsForm'];
        $settings->set('daily_close.grace_minutes', (int) $data['grace_minutes']);
        $settings->set('daily_close.fallback_time', (string) $data['fallback_time']);
        $this->showSettings = false;
        $this->toast(__('admin.saved'));
    }

    /** @return list<array<string, mixed>> */
    private function board(): array
    {
        return app(DailyCloseService::class)->board(auth()->user(), $this->date, $this->branch !== '' && Str::isUuid($this->branch) ? $this->branch : null);
    }

    public function render(DailyCloseService $closes)
    {
        abort_unless($this->can('reports.view'), 403);
        $board = $this->board();
        $reviewed = $this->reviewId !== '' ? collect($board)->first(fn (array $b) => $b['branch']->id === $this->reviewId) : null;
        if ($this->reviewId !== '' && $reviewed === null) {
            $this->reviewId = '';
            $this->showReview = false;
        }
        $sales = array_reduce($board, fn (Money $c, array $b) => $c->plus(Money::of((string) $b['numbers']['sales']['total'])), Money::zero());
        $orders = DailyCloseService::ordersOf($board, $this->date);

        return view('livewire.admin.insights.daily-close', [
            'board' => $board,
            'reviewed' => $reviewed,
            'sales' => $sales,
            'orders' => $orders,
            'tally' => DailyCloseService::tally($board),
            'branchOptions' => $closes->branchesFor(auth()->user()),
            'canSettings' => $this->canGlobally('settings.edit'),
            'earliest' => $closes->earliestDate(),
            'today' => BusinessTime::now()->toDateString(),
            'graceMinutes' => $closes->graceMinutes(),
            'fallbackTime' => $closes->fallbackTime(),
        ])->title(__('daily_close.title'));
    }
}
