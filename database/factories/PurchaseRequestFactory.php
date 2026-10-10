<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\PurchaseRequest;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PurchaseRequest> */
class PurchaseRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'pr_date' => now()->toDateString(),
            'warehouse_id' => Warehouse::factory(),
            'required_date' => now()->addDays(7)->toDateString(),
            'record_status' => RecordStatus::Saved,
        ];
    }
}
