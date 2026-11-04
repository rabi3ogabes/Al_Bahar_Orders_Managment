<?php

namespace Database\Factories;

use App\Domain\Branches\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Branch> */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(1, 99999);

        return [
            'code' => 'BR'.$n,
            'name_en' => 'Branch '.$n,
            'name_ar' => 'فرع '.$n,
            'phone' => '+97444'.fake()->numerify('######'),
            'email' => "branch{$n}@albahar.qa",
            'address_en' => fake()->streetAddress(),
            'address_ar' => 'الدوحة',
            'area' => 'Doha',
            'latitude' => fake()->randomFloat(7, 25.20, 25.40),
            'longitude' => fake()->randomFloat(7, 51.40, 51.60),
            'status' => 'active',
            'opening_status' => 'open',
            'pickup_enabled' => true,
            'delivery_enabled' => true,
            'cod_enabled' => true,
            'pay_at_pickup_enabled' => true,
            'pay_at_pickup_tenders' => ['cash', 'card_terminal'],
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => 'inactive']);
    }
}
