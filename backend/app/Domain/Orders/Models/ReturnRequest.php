<?php

namespace App\Domain\Orders\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Models\User;
use App\Domain\Payments\Models\Refund;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A return or damage claim: requested → (under_review) → approved | rejected → received → refunded → closed, or cancelled by the customer
 * before it is decided. See docs/guides/RETURNS_AND_SUBSTITUTIONS.md.
 */
class ReturnRequest extends Model
{
    use HasUuids;

    /** What the customer reports. `not_as_described` is what older apps send for a quality problem. */
    public const TYPES = ['damaged', 'wrong_item', 'missing_item', 'quality', 'changed_mind', 'other'];

    public const LEGACY_TYPES = ['not_as_described'];

    /** Claims that cannot be judged without a picture of the problem. */
    public const EVIDENCE_REQUIRED = ['damaged', 'wrong_item'];

    /** Claims about the condition of the goods: the staff app and the Needs-attention feed treat them as urgent, and the goods are never restocked by default. */
    public const DEFECT_TYPES = ['damaged', 'wrong_item', 'missing_item', 'quality', 'not_as_described'];

    /** At most this many photos on one claim. */
    public const MAX_PHOTOS = 5;

    /** Statuses in which the customer may still add photos or a note, and cancel. */
    public const EDITABLE = ['requested', 'under_review'];

    /** Statuses that wait on the team (nobody has decided yet). */
    public const UNDECIDED = ['requested', 'under_review'];

    /** Statuses that hold their units: no second claim may ask for the same pieces. */
    public const HOLDING = ['requested', 'under_review', 'approved'];

    protected $guarded = ['id'];

    protected $casts = [
        'items' => 'array', 'decided_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime', 'review_started_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Refund, $this> */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    /** @return HasMany<ReturnRequestPhoto, $this> */
    public function photos(): HasMany
    {
        return $this->hasMany(ReturnRequestPhoto::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return HasMany<ReturnRequestEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ReturnRequestEvent::class)->orderBy('created_at')->orderBy('id');
    }

    public function needsEvidence(): bool
    {
        return in_array($this->reason_code, self::EVIDENCE_REQUIRED, true);
    }

    public function isDefect(): bool
    {
        return in_array($this->reason_code, self::DEFECT_TYPES, true);
    }

    /** True while the claim's units are spoken for (see scopeHolding). */
    public function holdsUnits(): bool
    {
        return in_array($this->status, self::HOLDING, true) || (in_array($this->status, ['refunded', 'closed'], true) && $this->return_method === 'none');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE, true);
    }

    /**
     * Claims whose units are still spoken for: the ones that are open, and the ones refunded without the goods coming back (`return_method` none),
     * whose units were never added to `quantity_returned`. A claim whose goods came back has already counted them there.
     *
     * @param  Builder<ReturnRequest>  $query
     * @return Builder<ReturnRequest>
     */
    public function scopeHolding(Builder $query): Builder
    {
        return $query->where(fn (Builder $w) => $w->whereIn('status', self::HOLDING)
            ->orWhere(fn (Builder $x) => $x->whereIn('status', ['refunded', 'closed'])->where('return_method', 'none')));
    }
}
