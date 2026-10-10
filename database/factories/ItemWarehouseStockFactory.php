<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\ItemWarehouseStock;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItemWarehouseStock>
 */
class ItemWarehouseStockFactory extends Factory
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
            'stock_available' => 0,
            'stock_reserved' => 0,
            'stock_in_transit' => 0,
            'stock_out' => 0,
        ];
    }
}
