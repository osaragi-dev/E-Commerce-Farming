<?php

namespace Database\Factories;

use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GoodsReceipt> */
class GoodsReceiptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'receipt_number' => 'GRN-'.now()->format('Ymd').'-'.fake()->unique()->numerify('########'),
            'received_at' => now()->toDateString(),
            'status' => 'approved',
        ];
    }
}
