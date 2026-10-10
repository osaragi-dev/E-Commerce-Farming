<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\ShippingMethod;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        $shippingMethod = ShippingMethod::Delivery;
        $shippingCost = fake()->randomFloat(2, 5_000, 25_000);
        $subtotal = fake()->randomFloat(2, 20_000, 500_000);

        return [
            'order_number' => 'ORD-'.now()->format('Ymd').'-'.Str::padLeft((string) fake()->unique()->numberBetween(1, 999), 3, '0'),
            'customer_id' => Customer::factory(),
            'status' => OrderStatus::WaitingPayment,
            'shipping_method' => $shippingMethod,
            'recipient_name' => fake()->name(),
            'recipient_phone' => fake()->numerify('08##########'),
            'shipping_address' => fake()->address(),
            'shipping_cost' => $shippingCost,
            'tracking_number' => null,
            'subtotal' => $subtotal,
            'total' => $subtotal + $shippingCost,
            'cancelled_by' => null,
            'cancellation_reason' => null,
            'cancelled_at' => null,
            'notes' => null,
        ];
    }

    public function delivery(): static
    {
        return $this->state(fn (): array => [
            'shipping_method' => ShippingMethod::Delivery,
            'shipping_address' => fake()->address(),
        ]);
    }
}
