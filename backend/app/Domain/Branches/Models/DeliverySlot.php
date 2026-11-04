<?php

namespace App\Domain\Branches\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliverySlot extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean', 'day_of_week' => 'integer'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(DeliverySlotBooking::class);
    }
}
