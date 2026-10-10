<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\StockMovementItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockMovementItem> */
class StockMovementItemFactory extends Factory
{
    protected $model = StockMovementItem::class;

    public function definition(): array
    {
        return [
            'stock_movement_id' => StockMovement::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'quantity' => fake()->numberBetween(1, 20),
            'quantity_shipped' => null,
            'quantity_received' => null,
            'unit' => 'pcs',
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
