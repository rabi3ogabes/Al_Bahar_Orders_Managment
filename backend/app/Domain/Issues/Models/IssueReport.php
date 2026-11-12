<?php

namespace App\Domain\Issues\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Models\Payment;
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
