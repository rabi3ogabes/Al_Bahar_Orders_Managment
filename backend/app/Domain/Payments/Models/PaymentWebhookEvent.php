<?php

namespace App\Domain\Payments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymentWebhookEvent extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['payload' => 'array', 'signature_valid' => 'boolean', 'processed_at' => 'immutable_datetime'];
}
