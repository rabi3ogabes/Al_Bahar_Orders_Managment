<?php

namespace App\Domain\Payments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymentReconciliationItem extends Model
{
    use HasUuids;

    protected $guarded = ['id'];
}
