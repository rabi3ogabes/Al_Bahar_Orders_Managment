<?php

namespace App\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;

/**
 * Where a staff member lands after signing in (or after choosing their first password): the dashboard home when the route lets them in
 * (dashboard.view or deliveries.view, as routes/web.php), otherwise the first page of their own menu. A branch employee who was given
 * only "See stock" would otherwise land on a 403 page.
 */
final class StaffLanding
{
    public static function url(User $user): string
    {
        $acl = app(AccessControl::class);
        // A wall display account has one page: the live board in its wall mode.
        if ($acl->isWallDisplay($user)) {
            return route('admin.operations.board', ['wall' => 1]);
        }
        if ($acl->allows($user, 'dashboard.view') || $acl->allows($user, 'deliveries.view')) {
            return route('admin.dashboard');
        }
        foreach (AdminNavigation::for($user) as $group) {
            foreach ($group['items'] as $item) {
                return route($item['route']);
            }
        }

        return route('admin.dashboard');
    }
}
