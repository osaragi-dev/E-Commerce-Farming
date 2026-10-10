<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\Category;
use App\Models\Sector;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'sector_id' => Sector::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('####'),
            'is_featured' => false,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 10),
            'record_status' => RecordStatus::Saved,
        ];
    }
}
