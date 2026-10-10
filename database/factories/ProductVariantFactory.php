<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'unit_id' => Unit::factory(),
            'name' => fake()->randomElement(['Standar', 'Grade A', 'Grade B', 'Size A', 'Size B']),
            'price' => fake()->randomFloat(2, 5_000, 100_000),
            'initial_stock' => fake()->numberBetween(0, 100),
            'stock_available' => fake()->numberBetween(0, 100),
            'stock_reserved' => 0,
            'stock_out' => 0,
            'low_stock_threshold' => 10,
            'is_active' => true,
            'record_status' => RecordStatus::Saved,
        ];
    }
}
