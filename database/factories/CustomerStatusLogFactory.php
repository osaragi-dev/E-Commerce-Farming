<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\Customer;
use App\Models\CustomerStatusLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerStatusLog> */
class CustomerStatusLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'event' => 'restored',
            'from_status' => RecordStatus::Deleted,
            'to_status' => RecordStatus::Saved,
            'changed_by_user_id' => User::factory(),
            'created_at' => now(),
        ];
    }
}
