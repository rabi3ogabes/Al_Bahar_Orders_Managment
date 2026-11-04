<?php

namespace App\Domain\Branches\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use HasFactory, HasTranslations, HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'pickup_enabled' => 'boolean',
        'delivery_enabled' => 'boolean',
        'cod_enabled' => 'boolean',
        'pay_at_pickup_enabled' => 'boolean',
        'is_fulfillment_hub' => 'boolean',
        'pay_at_pickup_tenders' => 'array',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function hours(): HasMany
    {
        return $this->hasMany(BranchHour::class)->orderBy('day_of_week');
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(BranchHoliday::class)->orderBy('starts_at');
    }

    public function settings(): HasMany
    {
        return $this->hasMany(BranchSetting::class);
    }

    public function employeeAssignments(): HasMany
    {
        return $this->hasMany(BranchEmployee::class);
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'branch_employees')->withPivot(['job_title', 'is_active', 'is_delivery_driver'])->withTimestamps();
    }

    public function deliveryZones(): BelongsToMany
    {
        return $this->belongsToMany(DeliveryZone::class, 'branch_delivery_zones')->using(BranchDeliveryZone::class)
            ->withPivot(['id', 'delivery_fee', 'free_delivery_threshold', 'min_order_amount', 'max_order_amount', 'oversized_surcharge',
                'cod_enabled', 'express_enabled', 'express_fee', 'estimated_minutes', 'priority', 'is_active'])->withTimestamps();
    }

    public function zoneConfigs(): HasMany
    {
        return $this->hasMany(BranchDeliveryZone::class);
    }

    public function slots(): HasMany
    {
        return $this->hasMany(DeliverySlot::class);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings->firstWhere('key', $key)?->value['v'] ?? $default;
    }
}
