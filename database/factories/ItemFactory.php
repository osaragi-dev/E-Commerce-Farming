<?php

namespace Database\Factories;

use App\Enums\ItemType;
use App\Enums\RecordStatus;
use App\Models\Item;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Item> */
class ItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('ITM-#####'),
            'sku' => fake()->unique()->bothify('SKU-#####'),
            'name' => fake()->words(3, true),
            'item_type' => ItemType::RawMaterial,
            'unit_id' => Unit::factory(),
            'initial_stock' => 0,
            'min_stock' => 0,
            'avg_purchase_price' => 0,
            'purchasable' => true,
            'is_active' => true,
            'record_status' => RecordStatus::Saved,
        ];
    }
}
