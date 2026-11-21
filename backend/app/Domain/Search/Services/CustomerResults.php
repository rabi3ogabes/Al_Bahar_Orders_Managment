<?php

namespace App\Domain\Search\Services;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Messaging\Services\CustomerScope;
use App\Domain\Search\Support\SearchGroup;
use App\Domain\Search\Support\SearchQuery;
use App\Livewire\Admin\Sales\CustomerIndex;
use App\Support\Phone;

/**
 * Customers for the global search: by name, e-mail or phone, for staff who may open the Customers page.
 *
 * The people are the ones the Customers list shows ({@see CustomerScope}: company-wide staff see everyone, branch staff the customers who
 * ordered from their branches) and phone and e-mail are masked unless the person may edit customers, exactly as in that list: the search
 * never shows more of a customer than the list does. One bounded query on the trigram indexes of users.name, email and phone.
 */
final class CustomerResults
{
    public function __construct(private readonly AccessControl $acl) {}

    public function find(User $user, SearchQuery $query): ?SearchGroup
    {
        if (! $this->acl->allows($user, 'customers.view') || ! $query->searchesTables()) {
            return null;
        }

        $rows = CustomerScope::for($user)
            ->select(['users.id', 'users.name', 'users.phone', 'users.email', 'users.status'])
            ->where(function ($w) use ($query) {
                $w->where('users.name', 'ilike', SearchQuery::contains($query->raw))->orWhere('users.email', 'ilike', SearchQuery::contains($query->raw));
                if ($query->phone !== null) {
                    $w->orWhere('users.phone', 'like', $query->phoneHasCountryCode ? SearchQuery::startsWith('+974'.$query->phone) : SearchQuery::contains($query->phone));
                }
            })
            ->orderBy('users.name')->orderBy('users.id')->limit(SearchGroup::LIMIT + 1)->get();
        if ($rows->isEmpty()) {
            return null;
        }

        $reveal = $this->acl->allows($user, 'customers.edit');
        $items = $rows->take(SearchGroup::LIMIT)->map(fn (User $c) => [
            'id' => (string) $c->id,
            'title' => $c->name,
            'subtitle' => implode(' · ', array_filter([
                SearchGroup::ltr($reveal ? $c->phone : Phone::mask($c->phone)),
                SearchGroup::ltr($reveal ? $c->email : CustomerIndex::maskEmail($c->email)),
            ])),
            'badge' => $c->status === UserStatus::Active ? null : __('status.'.$c->status->value),
            'meta' => null,
            'url' => route('admin.customers.index', ['view' => $c->id]),
            'icon' => 'user',
            'ltr' => false,
        ])->values()->all();

        return new SearchGroup('customers', __('search.group.customers'), $items, $rows->count() > SearchGroup::LIMIT
            ? ['label' => __('search.see_all.customers'), 'url' => route('admin.customers.index', ['search' => $query->raw])] : null);
    }
}
