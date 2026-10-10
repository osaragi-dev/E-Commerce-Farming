<?php

namespace Database\Factories;

use App\Models\PaymentSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentSetting> */
class PaymentSettingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'proof_resubmission_hours' => 24,
            'updated_by' => null,
        ];
    }
}
