<?php

namespace App\Domain\Orders\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['address_snapshot' => 'array', 'fee_breakdown' => 'array', 'tax_snapshot' => 'array', 'placed_at' => 'immutable_datetime', 'confirmed_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime', 'payment_due_at' => 'immutable_datetime', 'status' => 'App\Domain\Orders\Enums\OrderStatus', 'payment_status' => 'App\Domain\Payments\Enums\PaymentStatus', 'loyalty_points_redeemed' => 'integer'];


    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\Identity\Models\User::class);
    }

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function fulfillments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OrderFulfillment::class);
    }

    public function history(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    public function notes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OrderNote::class);
    }

    public function cancellations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OrderCancellation::class);
    }

    public function invoice(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(OrderInvoice::class);
    }

    public function payments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Domain\Payments\Models\Payment::class);
    }

    public function coupons(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Domain\Marketing\Models\OrderCoupon::class);
    }

    public function money(string $field): \App\Support\Money
    {
        return \App\Support\Money::of($this->getAttribute($field) ?? '0', $this->currency ?? 'QAR');
    }

    /** Branches involved in this order (via fulfillments) — used for branch-scoped authorization. */
    public function branchIds(): array
    {
        return ($this->relationLoaded('fulfillments') ? $this->fulfillments : $this->fulfillments()->get(['id', 'branch_id']))->pluck('branch_id')->unique()->values()->all();
    }
}
