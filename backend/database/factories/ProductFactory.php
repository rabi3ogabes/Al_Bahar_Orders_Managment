<?php

namespace Database\Factories;

use App\Domain\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Creates an ACTIVE product with EN/AR translations and one default variant (sku = product sku).
 * Use ->withVariants(n) for a variable product.
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'sku' => 'SKU-'.Str::upper(Str::random(8)),
            'status' => 'active',
            'price' => '1000.00',
            'currency' => 'QAR',
            'available_in_all_branches' => true,
            'published_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Product $p) {
            if (! $p->translations()->exists()) {
                $n = fake()->unique()->numberBetween(1, 999999);
                $p->translations()->create(['locale' => 'en', 'name' => "Oslo Sofa {$n}", 'slug' => "oslo-sofa-{$n}", 'short_description' => 'Soft linen three-seater']);
                $p->translations()->create(['locale' => 'ar', 'name' => "كنبة أوسلو {$n}", 'slug' => "كنبة-أوسلو-{$n}", 'short_description' => 'كنبة ثلاثية من الكتان']);
            }
            if (! $p->variants()->exists()) {
                $p->variants()->create(['sku' => $p->sku, 'is_default' => true, 'status' => 'active']);
            }
        });
    }

    public function draft(): static
    {
        return $this->state(['status' => 'draft']);
    }

    public function selectedBranchesOnly(): static
    {
        return $this->state(['available_in_all_branches' => false]);
    }

    public function withVariants(int $count): static
    {
        return $this->state(['has_variants' => true])->afterCreating(function (Product $p) use ($count) {
            $p->variants()->delete();
            foreach (range(1, $count) as $i) {
                $p->variants()->create(['sku' => $p->sku.'-V'.$i, 'is_default' => $i === 1, 'status' => 'active', 'sort_order' => $i]);
            }
        });
    }
}
