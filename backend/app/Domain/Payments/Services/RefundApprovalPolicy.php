<?php

namespace App\Domain\Payments\Services;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Enums\UserType;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Payments\Models\Refund;
use App\Domain\Settings\Services\Settings;
use Illuminate\Support\Facades\DB;

/** Dual control on refunds: who may approve a request, and when the person who asked may approve it themselves. */
class RefundApprovalPolicy
{
    public function __construct(private readonly AccessControl $access, private readonly Settings $settings) {}

    public function selfApprovalAllowed(): bool
    {
        return (bool) $this->settings->get('refunds.allow_self_approval', true);
    }

    /** Does any OTHER active team member hold the approval right on every branch this refund touches? */
    public function otherApproverExists(Refund $refund, User $except): bool
    {
        $branches = $refund->payment?->order?->branchIds() ?? [];
        $holders = DB::table('user_roles as ur')->join('role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('p.key', 'refunds.approve')->select('ur.user_id');
        $candidates = User::query()->where('type', UserType::Staff->value)->where('status', UserStatus::Active->value)->where('id', '!=', $except->id)
            ->where(fn ($q) => $q->where('is_super_admin', true)->orWhereIn('id', $holders))->orderBy('id')->limit(100)->get();

        return $candidates->contains(fn (User $u) => $branches === []
            ? $this->access->allows($u, 'refunds.approve')
            : collect($branches)->every(fn ($b) => $this->access->allows($u, 'refunds.approve', $b)));
    }

    /** @return 'other'|'self'|'blocked'|null  null = not the requester's own request */
    public function stateFor(Refund $refund, User $user): ?string
    {
        if ($refund->requested_by !== $user->id) {
            return null;
        }
        if ($this->otherApproverExists($refund, $user)) {
            return 'other';
        }

        return $this->selfApprovalAllowed() ? 'self' : 'blocked';
    }
}
