<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'payment_number' => Payment::generatePaymentNumber(),
            'method' => fake()->randomElement([
                PaymentMethod::Cash,
                PaymentMethod::Qris,
            ]),
            'amount' => fake()->randomFloat(2, 20_000, 500_000),
            'status' => PaymentStatus::WaitingConfirm,
            'verified_by' => null,
            'verified_at' => null,
            'rejection_reason' => null,
        ];
    }
}
