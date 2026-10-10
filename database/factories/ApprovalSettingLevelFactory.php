<?php

namespace Database\Factories;

use App\Models\ApprovalSettingLevel;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ApprovalSettingLevel> */
class ApprovalSettingLevelFactory extends Factory
{
    protected $model = ApprovalSettingLevel::class;

    public function definition(): array
    {
        return [
            'approval_setting_id' => null,
            'role_id' => fn (): string => Role::query()->create([
                'name' => fake()->unique()->jobTitle(),
                'code' => fake()->unique()->slug(),
                'guard_name' => 'web',
            ])->id,
        ];
    }
}
