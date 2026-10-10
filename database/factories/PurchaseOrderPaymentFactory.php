<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PurchaseOrderPayment> */
class PurchaseOrderPaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'payment_basis' => 'goods_receipt',
            'accepted_amount' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_payable' => 0,
            'payment_amount' => 0,
            'remaining_amount' => 0,
            'payment_date' => now()->toDateString(),
        ];
    }
}
