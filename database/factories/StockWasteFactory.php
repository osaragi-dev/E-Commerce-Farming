<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Enums\StockWasteApprovalStatus;
use App\Enums\StockWasteSource;
use App\Enums\StockWasteStatus;
use App\Enums\StockWasteType;
use App\Models\ProductVariant;
use App\Models\StockWaste;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockWaste> */
class StockWasteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'warehouse_id' => Warehouse::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'pic_user_id' => User::factory(),
            'waste_date' => today(),
            'source' => StockWasteSource::Item,
            'waste_status' => StockWasteStatus::Saved,
            'quantity' => '1.000',
            'waste_type' => fake()->randomElement(StockWasteType::cases()),
            'reason' => fake()->optional()->sentence(),
            'record_status' => RecordStatus::Saved,
            'approval_status' => StockWasteApprovalStatus::Approved,
            'approval_level' => 0,
        ];
    }
}
