<?php

namespace App\Domain\Payments\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One shift of the cash drawer of a branch: opened with a counted float, closed with an end-of-day count (see CashShiftService).
 * The Z-report of a closed shift is numbered per branch (Z-1, Z-2 ...), and what it shows is frozen in `summary` when the shift is closed.
 */
class CashShift extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'opened_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'variance_resolved_at' => 'immutable_datetime',
        'opening_notes' => 'array', 'closing_notes' => 'array', 'summary' => 'array', 'z_number' => 'integer', 'print_count' => 'integer',
    ];

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function hasOpenVariance(): bool
    {
        return $this->variance_status === 'open';
    }

    /** "Z-14": the number of the Z-report, null while the shift is still open. */
    public function zLabel(): ?string
    {
        return $this->z_number === null ? null : 'Z-'.$this->z_number;
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /** @return BelongsTo<User, $this> */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'variance_resolved_by');
    }

    /** Hand-ins received in this shift. @return HasMany<CashHandover, $this> */
    public function handovers(): HasMany
    {
        return $this->hasMany(CashHandover::class, 'cash_shift_id');
    }

    /** Hand-ins that were banked in this shift. @return HasMany<CashHandover, $this> */
    public function deposits(): HasMany
    {
        return $this->hasMany(CashHandover::class, 'deposit_shift_id');
    }

    /** Cash refunds paid out of this shift's drawer. @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class, 'cash_shift_id');
    }

    /** Petty cash paid out of this shift's drawer (voided ones included). @return HasMany<CashPayout, $this> */
    public function payouts(): HasMany
    {
        return $this->hasMany(CashPayout::class, 'cash_shift_id');
    }
}
