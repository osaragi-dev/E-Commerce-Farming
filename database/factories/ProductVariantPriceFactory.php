<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\ProductVariantPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariantPrice>
 */
class ProductVariantPriceFactory extends Factory
{
    protected $model = ProductVariantPrice::class;

    public function definition(): array
    {
        return [
            'product_variant_id' => ProductVariant::factory(),
            'price' => fake()->randomFloat(2, 5_000, 100_000),
            'effective_from' => today(),
            'effective_until' => today()->addDays(7),
        ];
    }
}
