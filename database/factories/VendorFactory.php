<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Vendor> */
class VendorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'vendor_code' => fake()->unique()->bothify('VND-####'),
            'name' => fake()->company(),
            'contact_person' => fake()->name(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->companyEmail(),
            'address' => fake()->address(),
            'lead_time_days' => 0,
            'notes' => fake()->optional()->sentence(),
            'is_active' => true,
            'record_status' => RecordStatus::Saved,
        ];
    }
}
