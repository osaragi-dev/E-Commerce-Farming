<?php

namespace Database\Factories;

use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'generation_token' => (string) Str::uuid7(),
            'invoice_number' => fake()->unique()->numerify('######').'/FARM/IX/2026',
            'sequence_year' => 2026,
            'sequence' => fake()->unique()->numberBetween(1, 999999),
            'customer_id' => Customer::factory()->state(['customer_type' => CustomerType::Invoice]),
            'customer_name' => fake()->company(),
            'invoice_date' => '2026-09-30',
            'due_date' => '2026-10-03',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'total' => '100000.00',
            'document_payload' => [],
        ];
    }
}
