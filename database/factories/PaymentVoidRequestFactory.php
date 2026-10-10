<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentVoidRequestFactory extends Factory
{
    public function definition(): array
    {
        return ['payment_id' => Payment::factory(), 'requested_by' => User::factory(), 'reason' => fake()->sentence(), 'approval_status' => 'requested', 'approval_level' => 1];
    }
}
