<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrderItem> */
class OrderItemFactory extends Factory
{
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 5);
        $unitPrice = fake()->randomFloat(2, 5_000, 100_000);

        return [
            'order_id' => Order::factory(),
            'product_id' => null,
            'product_name' => fake()->words(2, true),
            'variant_name' => fake()->optional()->randomElement(['Grade A', 'Grade B', 'Standar']),
            'unit' => fake()->randomElement(['pack', 'box', 'ekor', 'botol', 'kg']),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'subtotal' => $quantity * $unitPrice,
        ];
    }
}
