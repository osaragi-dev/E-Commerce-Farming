<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\ItemStockMutation;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItemStockMutation>
 */
class ItemStockMutationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'warehouse_id' => Warehouse::factory(),
            'source' => 'goods_receipt',
            'reference_type' => 'goods_receipt_item',
            'reference_id' => (string) fake()->uuid(),
            'stock_before' => 0,
            'stock_after' => 1,
            'quantity_change' => 1,
            'recorded_by' => User::factory(),
            'created_at' => now(),
        ];
    }
}
