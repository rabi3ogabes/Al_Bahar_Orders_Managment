<?php

namespace App\Support;

use App\Domain\Identity\Models\User;

/**
 * Where a staff member lands after signing in (or after choosing their first password): the dashboard home when their features include it,
 * otherwise the first page of their own menu. A branch employee ticked "Stock only" would otherwise land on a 403 page.
 */
final class StaffLanding
{
    public static function url(User $user): string
    {
        foreach (AdminNavigation::for($user) as $group) {
            foreach ($group['items'] as $item) {
                return route($item['route']);
            }
        }

        return route('admin.dashboard');
    }
}
