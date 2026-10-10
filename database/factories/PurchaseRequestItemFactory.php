<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseRequestItem>
 */
class PurchaseRequestItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'purchase_request_id' => PurchaseRequest::factory(),
            'item_id' => Item::factory(),
            'qty_order' => fake()->numberBetween(1, 20),
            'specification' => fake()->optional()->sentence(4),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
