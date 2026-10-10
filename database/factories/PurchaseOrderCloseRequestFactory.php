<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderCloseRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PurchaseOrderCloseRequest> */
class PurchaseOrderCloseRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'approval_status' => 'pending',
            'approval_level' => 0,
        ];
    }
}
