<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\StockOpname;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockOpname> */
class StockOpnameFactory extends Factory
{
    public function definition(): array
    {
        $systemStock = fake()->numberBetween(0, 100);
        $countedStock = fake()->numberBetween(0, 100);

        return [
            'warehouse_id' => Warehouse::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'system_stock' => $systemStock,
            'counted_stock' => $countedStock,
            'difference' => $countedStock - $systemStock,
            'note' => fake()->optional()->sentence(),
            'counted_by' => null,
        ];
    }
}
