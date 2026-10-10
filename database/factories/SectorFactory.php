<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\Sector;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Sector>
 */
class SectorFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        // Real business data only has 3 sectors (seeded via SectorSeeder), but
        // factories may be called many times across a test run, so a high
        // cardinality synthetic name keeps fake()->unique() from exhausting.
        $name = fake()->unique()->words(2, true).' Sector';

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('####'),
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 10),
            'record_status' => RecordStatus::Saved,
        ];
    }
}
