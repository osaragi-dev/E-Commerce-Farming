<?php

namespace Database\Factories;

use App\Models\DirectOrder;
use App\Models\DirectOrderItem;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DirectOrderItem>
 */
class DirectOrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'direct_order_id' => DirectOrder::factory(),
            'item_id' => Item::factory(),
            'quantity' => fake()->numberBetween(1, 20),
            'unit_price' => fake()->randomFloat(2, 1, 500000),
            'total_price' => fake()->randomFloat(2, 1, 500000),
            'notes' => fake()->optional()->sentence(),
            'sort_order' => 0,
        ];
    }
}
