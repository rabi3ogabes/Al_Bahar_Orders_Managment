<?php

namespace Database\Factories;

use App\Domain\Catalog\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Brand> */
class BrandFactory extends Factory
{
    protected $model = Brand::class;

    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(1, 99999);

        return ['slug' => "brand-{$n}", 'name_en' => "Maison {$n}", 'name_ar' => "ميزون {$n}", 'status' => 'active'];
    }
}
