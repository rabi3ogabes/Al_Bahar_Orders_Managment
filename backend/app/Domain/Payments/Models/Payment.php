<?php

namespace App\Domain\Payments\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;
use App\Support\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'pending', 'captured_amount' => '0.00', 'refunded_amount' => '0.00', 'attempt' => 1, 'lock_version' => 0, 'currency' => 'QAR'];

    protected $casts = ['status' => 'App\Domain\Payments\Enums\PaymentStatus', 'metadata' => 'array', 'expires_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime', 'failed_at' => 'immutable_datetime'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function collections(): HasMany
    {
        return $this->hasMany(CashCollection::class);
    }

    public function refundable(): Money
    {
        return Money::of($this->captured_amount)->minus(Money::of($this->refunded_amount));
    }
}
