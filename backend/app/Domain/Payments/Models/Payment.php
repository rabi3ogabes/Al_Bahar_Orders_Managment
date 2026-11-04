<?php

namespace App\Domain\Payments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'pending', 'captured_amount' => '0.00', 'refunded_amount' => '0.00', 'attempt' => 1, 'lock_version' => 0, 'currency' => 'QAR'];

    protected $casts = ['status' => 'App\Domain\Payments\Enums\PaymentStatus', 'metadata' => 'array', 'expires_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime', 'failed_at' => 'immutable_datetime'];


    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\Orders\Models\Order::class);
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\Identity\Models\User::class);
    }

    public function transactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function refunds(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function collections(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CashCollection::class);
    }

    public function refundable(): \App\Support\Money
    {
        return \App\Support\Money::of($this->captured_amount)->minus(\App\Support\Money::of($this->refunded_amount));
    }
}
