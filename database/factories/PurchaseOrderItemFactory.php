<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequestItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PurchaseOrderItem> */
class PurchaseOrderItemFactory extends Factory
{
    public function definition(): array
    {
        $quantity = fake()->randomFloat(3, 1, 20);

        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'purchase_request_item_id' => PurchaseRequestItem::factory(),
            'item_id' => Item::factory(),
            'qty_requested' => $quantity,
            'qty_order' => $quantity,
            'unit_code' => 'unit',
            'unit_price' => 1000,
            'total_price' => $quantity * 1000,
            'sort_order' => 0,
        ];
    }
}
