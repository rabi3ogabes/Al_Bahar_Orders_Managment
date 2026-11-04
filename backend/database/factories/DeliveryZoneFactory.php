<?php

namespace Database\Factories;

use App\Domain\Branches\Models\DeliveryZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DeliveryZone> */
class DeliveryZoneFactory extends Factory
{
    protected $model = DeliveryZone::class;

    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(1, 99999);

        return ['code' => 'Z'.$n, 'name_en' => 'Zone '.$n, 'name_ar' => 'منطقة '.$n, 'zone_numbers' => (string) ($n % 98 + 1), 'is_active' => true];
    }
}
