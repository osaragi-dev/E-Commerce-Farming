<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        // Real business data only has 5 units (seeded via UnitSeeder), but
        // factories may be called many times across a test run — every
        // Product now needs a unit_id, so a high-cardinality synthetic code
        // keeps fake()->unique() from exhausting.
        return [
            'code' => fake()->unique()->bothify('unit-????'),
            'name' => fake()->words(2, true).' unit',
            'is_active' => true,
            'record_status' => RecordStatus::Saved,
        ];
    }
}
