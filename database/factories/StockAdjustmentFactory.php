<?php

namespace Database\Factories;

use App\Enums\StockAdjustmentReason;
use App\Enums\StockAdjustmentType;
use App\Models\ProductVariant;
use App\Models\StockAdjustment;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockAdjustment>
 */
class StockAdjustmentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'warehouse_id' => Warehouse::query()->where('code', 'WH-FARMING')->value('id'),
            'product_variant_id' => ProductVariant::factory(),
            'type' => fake()->randomElement(StockAdjustmentType::cases()),
            'quantity' => fake()->numberBetween(1, 20),
            'reason' => fake()->randomElement(StockAdjustmentReason::cases()),
            'note' => fake()->optional()->sentence(),
        ];
    }
}
