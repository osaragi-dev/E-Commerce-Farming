<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Vendor;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PurchaseOrder> */
class PurchaseOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'po_date' => now()->toDateString(),
            'expected_delivery_date' => now()->addDays(7)->toDateString(),
            'purchase_request_id' => PurchaseRequest::factory(),
            'warehouse_id' => Warehouse::factory(),
            'vendor_id' => Vendor::factory(),
            'payment_by' => 'holding',
            'payment_basis' => 'goods_receipt',
            'subtotal' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 0,
            'approval_status' => 'approved',
            'approval_level' => 0,
            'record_status' => RecordStatus::Saved,
        ];
    }
}
