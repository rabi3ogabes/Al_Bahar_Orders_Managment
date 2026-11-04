<?php

namespace App\Domain\Orders\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Models\OrderCoupon;
use App\Domain\Payments\Models\Payment;
use App\Support\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['address_snapshot' => 'array', 'fee_breakdown' => 'array', 'tax_snapshot' => 'array', 'placed_at' => 'immutable_datetime', 'confirmed_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime', 'payment_due_at' => 'immutable_datetime', 'status' => 'App\Domain\Orders\Enums\OrderStatus', 'payment_status' => 'App\Domain\Payments\Enums\PaymentStatus', 'loyalty_points_redeemed' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function fulfillments(): HasMany
    {
        return $this->hasMany(OrderFulfillment::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(OrderNote::class);
    }

    public function cancellations(): HasMany
    {
        return $this->hasMany(OrderCancellation::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(OrderInvoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(OrderCoupon::class);
    }

    public function money(string $field): Money
    {
        return Money::of($this->getAttribute($field) ?? '0', $this->currency ?? 'QAR');
    }

    /** Branches involved in this order (via fulfillments) — used for branch-scoped authorization. */
    public function branchIds(): array
    {
        return ($this->relationLoaded('fulfillments') ? $this->fulfillments : $this->fulfillments()->get(['id', 'branch_id']))->pluck('branch_id')->unique()->values()->all();
    }
}
