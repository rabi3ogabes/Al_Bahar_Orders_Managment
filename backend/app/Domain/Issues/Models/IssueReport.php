<?php

namespace App\Domain\Issues\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\BranchScope;
use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/** A recorded problem: a payment issue or a bug in the platform or any of the apps. */
class IssueReport extends Model
{
    use HasUuids;

    public const KINDS = ['payment', 'bug', 'delivery', 'other'];

    public const APPS = ['customer', 'driver', 'staff', 'dashboard', 'storefront', 'server'];

    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    public const STATUSES = ['open', 'investigating', 'resolved', 'wont_fix'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'open', 'severity' => 'medium'];

    protected $casts = ['resolved_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $i) {
            $i->reference ??= 'ISS-'.str_pad((string) DB::selectOne("SELECT nextval('issue_reports_ref_seq') AS n")->n, 6, '0', STR_PAD_LEFT);
        });
    }

    /**
     * The issues a person may read: every issue for someone with access to every branch; for a branch-limited member the ones about an order (or one of its
     * payments) that has a part at one of their branches, and the ones they reported themselves. An issue with no order (a bug in an app) is company-wide
     * and stays hidden from them. Always apply this to every read of the log (list, counts, window, search, status change).
     *
     * @param  Builder<IssueReport>  $query
     * @return Builder<IssueReport>
     */
    public function scopeVisibleTo(Builder $query, BranchScope $scope, string $userId): Builder
    {
        if ($scope->all) {
            return $query;
        }

        return $query->where(function (Builder $w) use ($scope, $userId) {
            $w->where('reported_by', $userId);
            if (! $scope->isEmpty()) {
                $orders = DB::table('order_fulfillments')->whereIn('branch_id', $scope->branchIds)->select('order_id');
                $w->orWhereIn('order_id', $orders)->orWhereIn('payment_id', DB::table('payments')->whereIn('order_id', $orders)->select('id'));
            }
        });
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isClosed(): bool
    {
        return in_array($this->status, ['resolved', 'wont_fix'], true);
    }
}
