<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WarehouseStock> */
class WarehouseStockFactory extends Factory
{
    public function definition(): array
    {
        return [
            'warehouse_id' => Warehouse::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'stock_available' => fake()->numberBetween(0, 100),
            'stock_reserved' => 0,
            'stock_out' => 0,
        ];
    }
}
