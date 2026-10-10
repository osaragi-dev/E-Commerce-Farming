<?php

namespace Database\Factories;

use App\Enums\StockOpnameStatus;
use App\Models\StockOpnameSession;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockOpnameSession> */
class StockOpnameSessionFactory extends Factory
{
    protected $model = StockOpnameSession::class;

    public function definition(): array
    {
        return [
            'warehouse_id' => Warehouse::factory(),
            'period' => fake()->optional()->monthName(),
            'opname_date' => now()->toDateString(),
            'status' => StockOpnameStatus::Draft,
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
