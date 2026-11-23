<?php

namespace App\Livewire\Admin\Sales;

use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Services\CustomerFilter;
use App\Domain\Messaging\Services\CustomerMessaging;
use App\Domain\Messaging\Services\CustomerScope;
use App\Domain\Reports\Services\CustomerValue;
use App\Livewire\Admin\Concerns\WithAdminTable;
use App\Livewire\Admin\Sales\Concerns\HasRecordWindow;
use App\Livewire\Admin\Sales\Concerns\PeeksAtOrder;
use App\Support\BoundedPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Customer directory. Branch-scoped staff only see customers who ordered from their branches; personal data
 * (phone, email) is masked unless the viewer holds customers.edit. A row opens the customer in a side window (`?view=<customer id>`).
 *
 * Staff who may send messages (notifications.create) can also tick customers (this page, or "everyone matching the filters") and send them a
 * message from the bulk bar or a row. The filters live in CustomerFilter, the same class the send uses, so "select everyone matching" can never
 * disagree with the list. The window itself is the MessageComposer, which is told what to send to by an event and re-checks it on the server.
 */
class CustomerIndex extends Component
{
    use HasRecordWindow, PeeksAtOrder;
    use WithAdminTable {
        updated as private tableUpdated;
    }

    #[Locked]
    #[Url(as: 'view', except: '')]
    public string $viewId = '';

    #[Url(except: '')]
    public string $filterStatus = '';

    #[Url(except: '')]
    public string $filterConsent = '';

    /** '' | yes (an order left in the cart) | no */
    #[Url(except: '')]
    public string $filterCart = '';

    /** '' | never | 24h | 3d | 7d | 30d: NOT messaged within that time */
    #[Url(except: '')]
    public string $filterContact = '';

    /**
     * Ticked customers. Kept by the browser and sent along with the next request; only ever used to build the list handed to the composer,
     * which re-resolves it for the signed-in staff member.
     *
     * @var list<string>
     */
    public array $selected = [];

    /** "Select all N customers matching your filters": everyone the filters match, minus the ones un-ticked afterwards (`except`). */
    public bool $allMatching = false;

    /** @var list<string> */
    public array $except = [];

    /** How many customers the current filters match, and which ones are on this page (set on every render; the page's checkboxes read them). */
    #[Locked]
    public int $matching = 0;

    /** @var list<string> */
    #[Locked]
    public array $pageIds = [];

    public function mount(): void
    {
        abort_unless($this->can('customers.view'), 403);
        if ($this->viewId !== '') {
            $this->openView($this->viewId, quiet: true);
        }
    }

    protected function findViewable(string $id): ?Model
    {
        $customer = User::where('type', 'customer')->find($id);

        return $customer && CustomerShow::viewableBy(auth()->user(), $customer) ? $customer : null;
    }

    /** Customers visible to the viewer (global: all; branch-scoped: ordered from one of their branches). */
    public static function visibleTo(User $viewer): Builder
    {
        return CustomerScope::for($viewer);
    }

    public static function maskEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return $email;
        }
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).str_repeat('•', max(2, min(6, mb_strlen($local) - 1))).'@'.$domain;
    }

    /** The list's filters as the messaging classes know them (also what "select all matching" is sent with). @return array<string, string> */
    private function currentFilters(): array
    {
        return array_filter([
            'search' => trim($this->search), 'status' => $this->filterStatus, 'consent' => $this->filterConsent,
            'cart' => $this->filterCart, 'contact' => $this->filterContact,
        ], fn (string $v) => $v !== '');
    }

    /** Changing the search or any filter changes which customers "everyone" means, so the ticks start again. */
    public function updated($property): void
    {
        $this->tableUpdated($property);
        if ($property === 'search' || str_starts_with((string) $property, 'filter')) {
            $this->clearSelection();
        }
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->allMatching = false;
        $this->except = [];
    }

    private function canSendMessages(): bool
    {
        return app(CustomerMessaging::class)->canSend(auth()->user());
    }

    /** The bulk bar's "Send message": the ticked customers, or everyone matching the filters minus the un-ticked. */
    public function composeSelected(): void
    {
        abort_unless($this->canSendMessages(), 403);
        $ids = array_values(array_filter(array_map('strval', $this->selected), fn (string $id) => Str::isUuid($id)));
        $except = array_values(array_filter(array_map('strval', $this->except), fn (string $id) => Str::isUuid($id)));
        if ($this->allMatching ? $this->matching - count($except) < 1 : $ids === []) {
            $this->toast(__('messaging_customers.nothing_selected'), 'error');

            return;
        }
        $this->dispatch('compose-message', mode: $this->allMatching ? 'all' : 'ids', ids: $this->allMatching ? [] : $ids,
            filters: $this->allMatching ? $this->currentFilters() : [], except: $this->allMatching ? $except : [], source: 'selection');
    }

    /** A row's own "Send message" button. */
    public function composeFor(string $id): void
    {
        abort_unless($this->canSendMessages(), 403);
        if (! Str::isUuid($id) || ! $this->findViewable($id)) {
            $this->toast(__('drawer_sales.not_available'), 'error');

            return;
        }
        $this->dispatch('compose-message', mode: 'ids', ids: [$id], filters: [], except: [], source: 'row');
    }

    /** A send that came from the ticks is done: start again with nothing ticked. */
    #[On('messages-sent')]
    public function messagesSent(string $batch = '', string $source = ''): void
    {
        if ($source === 'selection') {
            $this->clearSelection();
        }
    }

    public function render()
    {
        $filters = app(CustomerFilter::class);
        $q = $filters->withLastContact($filters->apply(self::visibleTo(auth()->user()), $this->currentFilters()))
            ->with('loyaltyAccount:id,user_id,balance,tier')
            ->latest();
        $customers = BoundedPage::paginate($q, $this->perPage);
        // Orders and spend of the customers on this page, read through the viewer's own branches (customers.view): the customers' own branch rule, the dashboard's sales definition.
        $value = app(CustomerValue::class);
        /** @var Collection<int, User> $page */
        $page = $customers->getCollection();
        $lifetime = $value->lifetime($value->scopeFor(auth()->user()), $page->map(fn (User $c) => (string) $c->id)->all());
        $page->each(function (User $c) use ($lifetime) {
            $c->setAttribute('orders_count', $lifetime[$c->id]['orders'] ?? 0);
            $c->setAttribute('spend', $lifetime[$c->id]['spend'] ?? '0.00');
        });
        $this->matching = $customers->total();
        $this->pageIds = $page->map(fn (User $c) => (string) $c->id)->all();

        return view('livewire.admin.sales.customers', [
            'customers' => $customers,
            'reveal' => $this->can('customers.edit'),
            'canSend' => $this->canSendMessages(),
            'bulkMax' => app(CustomerMessaging::class)->limits()['bulk_max'],
        ])->title(__('nav.customers'));
    }
}
