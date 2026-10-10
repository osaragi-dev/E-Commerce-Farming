<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Warehouse> */
class WarehouseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('WH-###'),
            'name' => fake()->unique()->words(3, true),
            'address' => fake()->optional()->address(),
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
            'is_pos' => false,
            'record_status' => RecordStatus::Saved,
        ];
    }
}
