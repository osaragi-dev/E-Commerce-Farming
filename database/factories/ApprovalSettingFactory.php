<?php

namespace Database\Factories;

use App\Enums\ApprovalModule;
use App\Models\ApprovalSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ApprovalSetting> */
class ApprovalSettingFactory extends Factory
{
    protected $model = ApprovalSetting::class;

    public function definition(): array
    {
        return [
            'module_code' => ApprovalModule::StockMovement->value,
            'is_active' => true,
        ];
    }
}
