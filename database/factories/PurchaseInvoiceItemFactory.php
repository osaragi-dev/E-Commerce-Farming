<?php

namespace Database\Factories;

use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\PurchaseOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PurchaseInvoiceItem> */
class PurchaseInvoiceItemFactory extends Factory
{
    public function definition(): array
    {
        $quantity = fake()->randomFloat(3, 1, 20);
        $price = fake()->randomFloat(2, 100, 10000);

        return [
            'purchase_invoice_id' => PurchaseInvoice::factory(),
            'purchase_order_item_id' => PurchaseOrderItem::factory(),
            'invoice_quantity' => $quantity,
            'final_unit_price' => $price,
            'line_total' => $quantity * $price,
        ];
    }
}
