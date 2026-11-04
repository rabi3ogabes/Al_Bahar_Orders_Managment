<?php

namespace Database\Factories;

use App\Domain\Inventory\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Supplier> */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        return ['code' => 'SUP'.fake()->unique()->numberBetween(1, 99999), 'name' => fake()->company(), 'status' => 'active', 'currency' => 'QAR'];
    }
}
