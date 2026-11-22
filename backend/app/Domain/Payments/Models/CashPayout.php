<?php

namespace App\Domain\Payments\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money a cashier paid out of the open drawer of a branch (see CashPayoutService). It lowers the shift's expected cash until it is voided.
 * Never deleted: a mistake is voided with a reason, so the Z-report and the audit trail keep the whole story.
 */
class CashPayout extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['voided_at' => 'immutable_datetime'];

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /** @return BelongsTo<CashShift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class, 'cash_shift_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
