<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderStatusLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrderStatusLog> */
class OrderStatusLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'from_status' => null,
            'to_status' => OrderStatus::WaitingPayment,
            'changed_by_user_id' => null,
            'note' => null,
            'created_at' => now(),
        ];
    }
}
