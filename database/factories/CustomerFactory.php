<?php

namespace Database\Factories;

use App\Enums\CustomerType;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => fake()->numerify('08##########'),
            'email' => fake()->unique()->safeEmail(),
            'address' => fake()->address(),
            'password' => null,
            'remember_token' => null,
            'email_verified_at' => null,
            'google_id' => null,
            'profile_photo_path' => null,
            'is_active' => true,
            'customer_type' => CustomerType::Regular,
        ];
    }

    public function invoice(): static
    {
        return $this->state(['customer_type' => CustomerType::Invoice]);
    }

    public function registered(string $password = 'password123'): static
    {
        return $this->state([
            'password' => $password,
            'email_verified_at' => now(),
        ]);
    }

    public function unverified(): static
    {
        return $this->state([
            'email_verified_at' => null,
        ]);
    }
}
