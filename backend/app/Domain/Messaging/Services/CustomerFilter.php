<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The customers list's filters, in one place so "select everyone matching" in a bulk send resolves to exactly the customers the list shows.
 * Keys: search, status, consent (yes|no), cart (yes|no: an open cart with something in it), contact (never|24h|3d|7d|30d: NOT contacted within that time).
 */
class CustomerFilter
{
    public const CONTACT = ['never' => null, '24h' => 24, '3d' => 72, '7d' => 168, '30d' => 720];

    /**
     * @param  Builder<User>  $q
     * @param  array<string, mixed>  $f
     * @return Builder<User>
     */
    public function apply(Builder $q, array $f): Builder
    {
        $search = trim((string) ($f['search'] ?? ''));
        $status = (string) ($f['status'] ?? '');
        $consent = (string) ($f['consent'] ?? '');
        $cart = (string) ($f['cart'] ?? '');
        $contact = (string) ($f['contact'] ?? '');

        $q->when($search !== '', function ($q) use ($search) {
            $digits = preg_replace('/\D+/', '', $search);
            $q->where(fn ($w) => $w->where('name', 'ilike', "%{$search}%")->orWhere('email', 'ilike', "%{$search}%")
                ->when(strlen((string) $digits) >= 4, fn ($x) => $x->orWhere('phone', 'like', "%{$digits}%")));
        })
            ->when(in_array($status, ['active', 'suspended', 'deactivated'], true), fn ($q) => $q->where('status', $status))
            ->when($consent === 'yes', fn ($q) => $q->whereNotNull('marketing_consent_at'))
            ->when($consent === 'no', fn ($q) => $q->whereNull('marketing_consent_at'))
            ->when($cart === 'yes', fn ($q) => $q->whereExists($this->openCart()))
            ->when($cart === 'no', fn ($q) => $q->whereNotExists($this->openCart()));
        if (array_key_exists($contact, self::CONTACT)) {
            $hours = self::CONTACT[$contact];
            $q->whereRaw($hours === null
                ? ContactHistory::LAST_CONTACT_SQL.' IS NULL'
                : '('.ContactHistory::LAST_CONTACT_SQL.' IS NULL OR '.ContactHistory::LAST_CONTACT_SQL.' < ?)', $hours === null ? [] : [now()->subHours($hours)]);
        }

        return $q;
    }

    /** Adds `last_contact_at` to the rows (the list shows "last message 3 days ago"). */
    public function withLastContact(Builder $q): Builder
    {
        return $q->addSelect('users.*')->selectRaw(ContactHistory::LAST_CONTACT_SQL.' AS last_contact_at');
    }

    private function openCart(): \Closure
    {
        return fn ($sub) => $sub->selectRaw('1')->from('carts')->whereColumn('carts.user_id', 'users.id')->where('carts.status', 'active')
            ->whereExists(fn ($i) => $i->selectRaw('1')->from('cart_items')->whereColumn('cart_items.cart_id', 'carts.id'));
    }
}
