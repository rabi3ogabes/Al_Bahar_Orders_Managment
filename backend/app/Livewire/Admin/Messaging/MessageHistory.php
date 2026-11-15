<?php

namespace App\Livewire\Admin\Messaging;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Messaging\Models\CustomerMessage;
use App\Domain\Messaging\Models\MessageBatch;
use App\Domain\Messaging\Services\CustomerMessaging;
use App\Domain\Messaging\Services\CustomerScope;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Livewire\Admin\Sales\Concerns\HasRecordWindow;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * "Customer messages": every send the team made from the customers pages, newest first, with a result bar, filters (what, how, who, when)
 * and a side window per send (`?view=<batch id>`) holding the texts as sent and one row per customer with the reason it was or was not delivered.
 *
 * Times: a send is placed on the clock by the moment it went out (started_at), else the moment it is due while it is still scheduled
 * (scheduled_at), else when it was created (a send cancelled before it started). The "when" chips and dates are business time (Asia/Qatar). The quick ranges have no upper end, so a send scheduled for later
 * still shows at the top and can be cancelled.
 * Who sees what: staff with company-wide notification rights see every send, the others only their own (CustomerMessaging::visibleBatches),
 * and a viewer whose customer access is limited to some branches never sees a customer outside it (names, rows, search).
 *
 * @property-read Model|null $viewed
 * @property-read MessageBatch|null $batch
 * @property-read array<string, int> $statusCounts
 * @property-read LengthAwarePaginator<int, CustomerMessage> $recipients
 * @property-read string|null $promotionName
 */
class MessageHistory extends Component
{
    use HasRecordWindow {
        openView as private openViewWindow;
        closeView as private closeViewWindow;
    }
    use InteractsWithAdmin, WithPagination;

    public const PER_PAGE = 20;

    public const RECIPIENTS_PER_PAGE = 50;

    public const WHEN_CHOICES = ['all', 'today', '7d', '30d', '90d', 'custom', 'older_1d', 'older_7d', 'older_30d', 'older_90d'];

    public const STATUS_CHOICES = ['scheduled', 'sending', 'done', 'cancelled', 'has_failed', 'has_skipped', 'has_delivered'];

    /** The clock time of a send, as SQL: see the class description. */
    private const TIME_SQL = "COALESCE(customer_message_batches.started_at, CASE WHEN customer_message_batches.status = 'scheduled' THEN customer_message_batches.scheduled_at END, customer_message_batches.created_at)";

    public const RECIPIENT_STATUSES = ['sent', 'delivered', 'failed', 'skipped', 'queued', 'cancelled'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $filterKind = '';

    #[Url(except: '')]
    public string $filterChannel = '';

    #[Url(except: '')]
    public string $filterStatus = '';

    #[Url(except: '')]
    public string $filterStaff = '';

    /** One of WHEN_CHOICES. The default shows the last 30 days. */
    #[Url(except: '30d')]
    public string $when = '30d';

    /** Custom range, business-time dates (Y-m-d), both ends included. */
    #[Url(as: 'from', except: '')]
    public string $dateFrom = '';

    #[Url(as: 'to', except: '')]
    public string $dateTo = '';

    #[Locked]
    #[Url(as: 'view', except: '')]
    public string $viewId = '';

    /** Filters of the customer table inside the window. */
    public string $recipientStatus = '';

    public string $recipientSearch = '';

    /** The decision waiting for a "yes": retry | cancel. */
    #[Locked]
    public string $confirming = '';

    public bool $showConfirm = false;

    /** @var array<string, string> batch id => customer name, for single sends the viewer may see (filled while rendering) */
    protected array $singleNames = [];

    public function mount(): void
    {
        abort_unless($this->can('notifications.view'), 403);
        if ($this->viewId !== '') {
            $this->openView($this->viewId, quiet: true);
        }
    }

    /* ───────────────────────── filters ───────────────────────── */

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'filterKind', 'filterChannel', 'filterStatus', 'filterStaff', 'when', 'dateFrom', 'dateTo'], true)) {
            $this->resetPage();
        }
        if (in_array($property, ['recipientStatus', 'recipientSearch'], true)) {
            $this->resetPage('rp');
        }
    }

    /** A quick "when" chip. Choosing "pick dates" starts from the last week so the two pickers are never empty. */
    public function setWhen(string $when): void
    {
        if (! in_array($when, self::WHEN_CHOICES, true)) {
            return;
        }
        $this->when = $when;
        if ($when === 'custom' && ($this->parseDate($this->dateFrom) === null && $this->parseDate($this->dateTo) === null)) {
            $this->dateTo = BusinessTime::now()->format('Y-m-d');
            $this->dateFrom = BusinessTime::now()->subDays(6)->format('Y-m-d');
        }
        $this->resetPage();
    }

    /** Everything back to "show me all of it": no search, no filters, any time. */
    public function clearFilters(): void
    {
        $this->reset('search', 'filterKind', 'filterChannel', 'filterStatus', 'filterStaff', 'dateFrom', 'dateTo');
        $this->when = 'all';
        $this->resetPage();
    }

    /** The "waiting" tile: jump to the sends that have not gone out yet, whenever they are due. */
    public function showWaiting(): void
    {
        $this->reset('search', 'filterKind', 'filterChannel', 'filterStaff', 'dateFrom', 'dateTo');
        $this->filterStatus = 'scheduled';
        $this->when = 'all';
        $this->resetPage();
    }

    public function showFailed(): void
    {
        $this->reset('search', 'filterKind', 'filterChannel', 'filterStaff');
        $this->filterStatus = 'has_failed';
        $this->resetPage();
    }

    /* ───────────────────────── the window ───────────────────────── */

    protected function findViewable(string $id): ?Model
    {
        return $this->messaging()->visibleBatches(auth()->user())->with('creator:id,name')->find($id);
    }

    public function openView(string $id, bool $quiet = false): void
    {
        $this->recipientStatus = '';
        $this->recipientSearch = '';
        $this->resetPage('rp');
        $this->showConfirm = false;
        $this->confirming = '';
        $this->openViewWindow($id, $quiet);
    }

    public function closeView(): void
    {
        $this->closeViewWindow();
        $this->showConfirm = false;
        $this->resetPage('rp');
    }

    public function setRecipientStatus(string $status): void
    {
        if ($status === '' || in_array($status, self::RECIPIENT_STATUSES, true)) {
            $this->recipientStatus = $status;
            $this->resetPage('rp');
        }
    }

    /** What was written in a send, for the window. @return MessageBatch|null */
    #[Computed]
    public function batch(): ?MessageBatch
    {
        $viewed = $this->viewed;

        return $viewed instanceof MessageBatch ? $viewed : null;
    }

    /** How many message rows of the open send are in each state (one grouped query on the batch's own index). @return array<string, int> */
    #[Computed]
    public function statusCounts(): array
    {
        $batch = $this->batch;
        if (! $batch) {
            return [];
        }

        return $this->messagesOf($batch)->toBase()->select(['customer_messages.status as s', DB::raw('count(*) as n')])->groupBy('customer_messages.status')->pluck('n', 's')
            ->map(fn ($n) => (int) $n)->all();
    }

    /** The open send's customers, 50 to a page, in name order. @return LengthAwarePaginator<int, CustomerMessage> */
    #[Computed]
    public function recipients(): LengthAwarePaginator
    {
        $batch = $this->batch;
        if (! $batch) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, self::RECIPIENTS_PER_PAGE, 1);
        }
        $q = $this->messagesOf($batch)->join('users', 'users.id', '=', 'customer_messages.user_id')
            ->select('customer_messages.*', 'users.name as customer_name', 'users.phone as customer_phone')
            ->orderBy('users.name')->orderBy('customer_messages.channel')->orderBy('customer_messages.id');
        $s = trim($this->recipientSearch);
        $q->when(in_array($this->recipientStatus, self::RECIPIENT_STATUSES, true), fn ($w) => $w->where('customer_messages.status', $this->recipientStatus))
            ->when($s !== '', function ($w) use ($s) {
                $digits = preg_replace('/\D+/', '', $s) ?? '';
                $w->where(fn ($x) => $x->where('users.name', 'ilike', '%'.$this->like($s).'%')
                    ->when(strlen($digits) >= 4, fn ($p) => $p->orWhere('users.phone', 'like', '%'.$digits.'%')));
            });

        return $q->paginate(self::RECIPIENTS_PER_PAGE, pageName: 'rp');
    }

    /** The offer behind the coupon the send attached, for the summary (the name, or null when it was removed since). */
    #[Computed]
    public function promotionName(): ?string
    {
        $coupon = $this->batch?->content['coupon'] ?? [];
        if (! ($coupon['enabled'] ?? false) || ! ($coupon['promotion_id'] ?? null)) {
            return null;
        }
        $promotion = Promotion::query()->whereKey((string) $coupon['promotion_id'])->first();

        return $promotion ? ($promotion->{'name_'.app()->getLocale()} ?? $promotion->name_en) : null;
    }

    /** The message rows of one send that the viewer may see. @return Builder<CustomerMessage> */
    private function messagesOf(MessageBatch $batch): Builder
    {
        $q = CustomerMessage::query()->where('customer_messages.batch_id', $batch->id);
        $this->limitToVisibleCustomers($q, 'customer_messages.user_id');

        return $q;
    }

    public function askRetry(): void
    {
        $batch = $this->actionable();
        if ($batch && $batch->failed_count > 0 && in_array($batch->status, ['done', 'sending'], true)) {
            $this->confirming = 'retry';
            $this->showConfirm = true;
        }
    }

    public function askCancel(): void
    {
        $batch = $this->actionable();
        if ($batch && in_array($batch->status, ['scheduled', 'sending'], true)) {
            $this->confirming = 'cancel';
            $this->showConfirm = true;
        }
    }

    /** The "yes" of the retry / cancel decision. Both go through CustomerMessaging, which checks rights and the send's state again. */
    public function confirm(): void
    {
        $batch = $this->actionable();
        $asked = $this->showConfirm;
        $this->showConfirm = false;
        if (! $batch || ! $asked) {
            return;
        }
        $svc = $this->messaging();
        if ($this->confirming === 'retry') {
            $n = $this->attempt(fn () => $svc->retryFailed(auth()->user(), $batch));
            if ($n !== null) {
                $this->toast(trans_choice('messaging_history.retry_done', (int) $n, ['count' => number_format((int) $n)]), $n > 0 ? 'success' : 'error');
            }
        } elseif ($this->confirming === 'cancel') {
            $this->attempt(fn () => $svc->cancel(auth()->user(), $batch), __('messaging_history.cancel_done'));
        }
        unset($this->viewed, $this->batch, $this->statusCounts, $this->recipients);
    }

    /** The open send, only when the viewer may change it. */
    private function actionable(): ?MessageBatch
    {
        abort_unless($this->messaging()->canSend(auth()->user()), 403);

        return $this->showView ? $this->batch : null;
    }

    /* ───────────────────────── the list ───────────────────────── */

    /** The visible sends with every filter but the dates. @return Builder<MessageBatch> */
    private function visible(): Builder
    {
        return $this->messaging()->visibleBatches(auth()->user());
    }

    /** The same clock time for a loaded send. */
    public function clockTime(MessageBatch $b): CarbonImmutable
    {
        return $b->started_at ?? ($b->status === 'scheduled' ? $b->scheduled_at : null) ?? CarbonImmutable::instance($b->created_at);
    }

    /** @param Builder<MessageBatch> $q @return Builder<MessageBatch> */
    private function inPeriod(Builder $q): Builder
    {
        [$from, $to] = $this->bounds();
        $time = self::TIME_SQL;

        return $q->when($from, fn ($w) => $w->whereRaw($time.' >= ?', [$from->toIso8601String()]))
            ->when($to, fn ($w) => $w->whereRaw($time.' <= ?', [$to->toIso8601String()]));
    }

    /** @return Builder<MessageBatch> */
    private function filtered(): Builder
    {
        $q = $this->inPeriod($this->visible());
        $kind = in_array($this->filterKind, MessageBatch::KINDS, true) ? $this->filterKind : null;
        $channel = in_array($this->filterChannel, MessageBatch::CHANNELS, true) ? $this->filterChannel : null;
        $staff = $this->canFilterByStaff() && Str::isUuid($this->filterStaff) ? $this->filterStaff : null;
        $q->when($kind, fn ($w) => $w->where('customer_message_batches.kind', $kind))
            ->when($channel, fn ($w) => $w->whereJsonContains('customer_message_batches.channels', $channel))
            ->when($staff, fn ($w) => $w->where('customer_message_batches.created_by', $staff))
            ->when(in_array($this->filterStatus, self::STATUS_CHOICES, true), fn ($w) => match ($this->filterStatus) {
                'has_failed' => $w->where('customer_message_batches.failed_count', '>', 0),
                'has_skipped' => $w->where('customer_message_batches.skipped_count', '>', 0),
                'has_delivered' => $w->where('customer_message_batches.delivered_count', '>', 0),
                default => $w->where('customer_message_batches.status', $this->filterStatus),
            });
        $s = trim($this->search);
        if (mb_strlen($s) >= 2) {
            $like = '%'.$this->like($s).'%';
            $customers = $this->matchingCustomerIds($s);
            $q->where(fn ($w) => $w->where('customer_message_batches.template_name', 'ilike', $like)
                ->orWhereIn('customer_message_batches.created_by', User::query()->where('name', 'ilike', $like)->where('type', 'staff')->select('id'))
                ->when($customers !== [], fn ($x) => $x->orWhereIn('customer_message_batches.id', CustomerMessage::query()->whereIn('user_id', $customers)->select('batch_id'))));
        }

        return $q;
    }

    /** Customers (that the viewer may see) whose name, e-mail or phone contains the search text; capped, so a very short search stays cheap. @return list<string> */
    private function matchingCustomerIds(string $s): array
    {
        $digits = preg_replace('/\D+/', '', $s) ?? '';
        $like = '%'.$this->like($s).'%';
        $q = CustomerScope::for(auth()->user())
            ->where(fn ($w) => $w->where('users.name', 'ilike', $like)->orWhere('users.email', 'ilike', $like)
                ->when(strlen($digits) >= 4, fn ($x) => $x->orWhere('users.phone', 'like', '%'.$digits.'%')));

        return $q->limit(500)->pluck('users.id')->map(fn ($id) => (string) $id)->all();
    }

    /** Escapes the LIKE wildcards of what staff typed. */
    private function like(string $s): string
    {
        return addcslashes($s, '\\%_');
    }

    /** @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} the range in UTC; a missing end is open */
    private function bounds(): array
    {
        $today = BusinessTime::now()->startOfDay();

        return match ($this->when) {
            'today' => [$today->utc(), null],
            '7d' => [$today->subDays(6)->utc(), null],
            '30d' => [$today->subDays(29)->utc(), null],
            '90d' => [$today->subDays(89)->utc(), null],
            'older_1d' => [null, CarbonImmutable::now()->subDay()],
            'older_7d' => [null, CarbonImmutable::now()->subDays(7)],
            'older_30d' => [null, CarbonImmutable::now()->subDays(30)],
            'older_90d' => [null, CarbonImmutable::now()->subDays(90)],
            'custom' => $this->customBounds(),
            default => [null, null],
        };
    }

    /** @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} */
    private function customBounds(): array
    {
        $from = $this->parseDate($this->dateFrom);
        $to = $this->parseDate($this->dateTo);
        if ($from && $to && $from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from?->startOfDay()->utc(), $to?->endOfDay()->utc()];
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            $d = CarbonImmutable::createFromFormat('!Y-m-d', $value, BusinessTime::zone());
        } catch (Throwable) {
            return null;
        }

        return $d && $d->format('Y-m-d') === $value ? $d : null;
    }

    /** The sentence under the "when" chips, so nobody has to guess what the list covers. */
    public function periodSummary(): string
    {
        [$from, $to] = $this->bounds();
        $fmt = fn (?CarbonImmutable $d) => BusinessTime::display($d, 'd M Y');
        if ($from && $to) {
            return __('messaging_history.period_between', ['from' => $fmt($from), 'to' => $fmt($to)]);
        }
        if ($from) {
            return __('messaging_history.period_since', ['from' => $fmt($from)]);
        }
        if ($to) {
            return __('messaging_history.period_before', ['to' => BusinessTime::display($to, 'd M Y, H:i')]);
        }

        return __('messaging_history.period_all');
    }

    public function canFilterByStaff(): bool
    {
        return app(AccessControl::class)->allowsGlobally(auth()->user(), 'notifications.view');
    }

    /** Whether the viewer sees every customer; when not, customers are limited to those of their branches. */
    private function sees(): bool
    {
        return app(AccessControl::class)->scope(auth()->user(), 'customers.view')->all;
    }

    /** @param Builder<covariant Model> $q */
    private function limitToVisibleCustomers(Builder $q, string $column): void
    {
        if (! $this->sees()) {
            $q->whereIn($column, CustomerScope::for(auth()->user())->select('users.id'));
        }
    }

    /** @return Builder<User> staff who sent something, for the "sent by" filter */
    private function senders(): Builder
    {
        return User::query()->whereIn('id', MessageBatch::query()->whereNotNull('created_by')->select('created_by')->distinct())->orderBy('name');
    }

    /** Who a send went to: the customer's name for a single send, otherwise "24 customers". $lookup reads the name for a send that is not on the current page. */
    public function audienceLabel(MessageBatch $b, bool $lookup = false): string
    {
        if ($b->source === 'single') {
            if ($lookup && ! isset($this->singleNames[$b->id])) {
                $this->singleNames += $this->singleCustomerNames([$b->id]);
            }

            return $this->singleNames[$b->id] ?? trans_choice('messaging_history.customers', 1, ['count' => 1]);
        }
        $n = (int) $b->recipients_count;
        $text = trans_choice('messaging_history.customers', $n, ['count' => number_format($n)]);

        return ($b->audience['mode'] ?? '') === 'filters' ? $text.' · '.__('messaging_history.audience_filters') : $text;
    }

    /** A message written from scratch: its name already says "custom message", so the kind badge would only repeat it. */
    public function isPlainCustom(MessageBatch $b): bool
    {
        return $b->kind === 'custom' && $this->isCustomName($b);
    }

    private function isCustomName(MessageBatch $b): bool
    {
        return in_array($b->template_name, [__('messaging_admin.custom_template', [], 'en'), __('messaging_admin.custom_template', [], 'ar')], true);
    }

    /** Whether the batch's template was deleted since (a message written from scratch never had one, which is not the same). */
    public function templateRemoved(MessageBatch $b): bool
    {
        return $b->kind !== 'custom' && $b->template_id === null && ! $this->isCustomName($b);
    }

    /** @param LengthAwarePaginator<int, MessageBatch> $batches */
    private function loadSingleNames(LengthAwarePaginator $batches): void
    {
        $this->singleNames = $this->singleCustomerNames(collect($batches->items())->where('source', 'single')->pluck('id')->map(fn ($id) => (string) $id)->all());
    }

    /**
     * The customer of each single send (one query), leaving out customers the viewer may not see.
     *
     * @param  list<string>  $batchIds
     * @return array<string, string> batch id => name
     */
    private function singleCustomerNames(array $batchIds): array
    {
        if ($batchIds === []) {
            return [];
        }
        $q = DB::table('customer_messages as cm')->join('users as u', 'u.id', '=', 'cm.user_id')->whereIn('cm.batch_id', $batchIds)
            ->selectRaw('distinct on (cm.batch_id) cm.batch_id as batch_id, u.name as name')->orderBy('cm.batch_id');
        if (! $this->sees()) {
            $q->whereIn('u.id', CustomerScope::for(auth()->user())->select('users.id'));
        }

        return $q->pluck('name', 'batch_id')->map(fn ($n) => (string) $n)->all();
    }

    /**
     * The figures for the chosen period (never more than the filters on dates and visibility): grouped queries on the sends, plus one on
     * their WhatsApp messages, the only ones a delivery report can come back for.
     *
     * @return array{sends: int, sent: int, delivered_pct: ?int, wa_sent: int, wa_delivered: int, failed: int, skipped: int, waiting: int, waiting_sends: int}
     */
    private function tiles(): array
    {
        $period = $this->inPeriod($this->visible());
        $sum = (clone $period)->toBase()->selectRaw('count(*) as sends, coalesce(sum(sent_count), 0) as sent, coalesce(sum(failed_count), 0) as failed, coalesce(sum(skipped_count), 0) as skipped')->first();
        $wa = CustomerMessage::query()->where('channel', 'whatsapp')->whereIn('status', ['sent', 'delivered'])->whereIn('batch_id', (clone $period)->select('id'))
            ->toBase()->selectRaw("count(*) as sent, count(*) filter (where status = 'delivered') as delivered")->first();
        $open = $this->visible()->whereIn('status', ['scheduled', 'sending']);
        $waiting = CustomerMessage::query()->where('status', 'queued')->whereIn('batch_id', (clone $open)->select('id'))->count();
        $waSent = (int) ($wa->sent ?? 0);
        $waDelivered = (int) ($wa->delivered ?? 0);

        return [
            'sends' => (int) ($sum->sends ?? 0), 'sent' => (int) ($sum->sent ?? 0), 'failed' => (int) ($sum->failed ?? 0), 'skipped' => (int) ($sum->skipped ?? 0),
            'wa_sent' => $waSent, 'wa_delivered' => $waDelivered, 'delivered_pct' => $waSent > 0 ? (int) round($waDelivered / $waSent * 100) : null,
            'waiting' => $waiting, 'waiting_sends' => (clone $open)->count(),
        ];
    }

    private function messaging(): CustomerMessaging
    {
        return app(CustomerMessaging::class);
    }

    public function render()
    {
        $viewer = auth()->user();
        $batches = null;
        $tiles = null;
        $senders = collect();
        $hasAny = true;
        $failed = false;
        try {
            $batches = $this->filtered()->with('creator:id,name')
                ->orderByRaw(self::TIME_SQL.' DESC')
                ->orderByDesc('customer_message_batches.id')->paginate(self::PER_PAGE);
            $this->loadSingleNames($batches);
            $tiles = $this->tiles();
            $hasAny = $batches->total() > 0 || $this->visible()->exists();
            $senders = $this->canFilterByStaff() ? $this->senders()->get(['id', 'name']) : collect();
        } catch (Throwable $e) {
            report($e);
            $failed = true;
        }
        $live = $batches !== null && (collect($batches->items())->contains(fn (MessageBatch $b) => $b->status === 'sending'
            || ($b->status === 'scheduled' && $b->scheduled_at && $b->scheduled_at->lte(now()->addMinutes(2))))
            || ($this->showView && $this->batch && $this->batch->status === 'sending'));

        return view('livewire.admin.messaging.message-history', [
            'batches' => $batches,
            'failed' => $failed,
            'tiles' => $tiles,
            'hasAny' => $hasAny,
            'live' => $live,
            'canSend' => $this->messaging()->canSend($viewer),
            'senders' => $senders,
            'reveal' => $this->can('customers.edit'),
            'canOpenCustomer' => $this->can('customers.view'),
            'filtersActive' => $this->search !== '' || $this->filterKind !== '' || $this->filterChannel !== '' || $this->filterStatus !== '' || $this->filterStaff !== '' || $this->when !== '30d',
        ])->title(__('nav.customer_messages'));
    }
}
