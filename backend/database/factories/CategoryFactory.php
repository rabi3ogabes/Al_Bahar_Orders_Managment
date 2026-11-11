<?php

namespace Database\Factories;

use App\Domain\Catalog\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Category> */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        return ['slug' => 'cat-'.fake()->unique()->numberBetween(1, 999999), 'status' => 'active', 'path' => '/', 'depth' => 0];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Category $c) {
            $c->translations()->create(['locale' => 'en', 'name' => 'Living Room '.$c->slug]);
            $c->translations()->create(['locale' => 'ar', 'name' => 'غرفة المعيشة '.$c->slug]);
        });
    }
}
