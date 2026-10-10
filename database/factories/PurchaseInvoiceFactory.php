<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PurchaseInvoice> */
class PurchaseInvoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'vendor_id' => Vendor::factory(),
            'invoice_date' => now()->toDateString(),
            'subtotal' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'grand_total' => 0,
            'record_status' => RecordStatus::Saved,
        ];
    }
}
