<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\ProductVariant;
use App\Models\Repack;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Repack> */
class RepackFactory extends Factory
{
    public function definition(): array
    {
        return [
            'warehouse_id' => Warehouse::factory(),
            'from_product_variant_id' => ProductVariant::factory()->state(['initial_stock' => 0, 'stock_available' => 0]),
            'to_product_variant_id' => ProductVariant::factory()->state(['initial_stock' => 0, 'stock_available' => 0]),
            'from_quantity' => '1.000',
            'to_quantity' => '1000.000',
            'notes' => null,
            'record_status' => RecordStatus::Draft,
        ];
    }
}
