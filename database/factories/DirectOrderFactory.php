<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\DirectOrder;
use App\Models\Vendor;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DirectOrder> */
class DirectOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'do_date' => now()->toDateString(),
            'payment_by' => fake()->randomElement(['Holding', 'Farming']),
            'reason' => fake()->sentence(),
            'warehouse_id' => Warehouse::factory(),
            'vendor_id' => Vendor::factory(),
            'subtotal' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 0,
            'transaction_proof' => 'direct-order-proofs/proof.pdf',
            'record_status' => RecordStatus::Saved,
        ];
    }
}
