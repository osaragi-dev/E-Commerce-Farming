<?php

namespace Database\Factories;

use App\Models\CompanyGallery;
use App\Models\CompanyProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyGallery>
 */
class CompanyGalleryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'company_profile_id' => CompanyProfile::current()->getKey(),
            'image' => 'company-profile/'.fake()->uuid().'.jpg',
            'description' => null,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
