<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentProof;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentProof> */
class PaymentProofFactory extends Factory
{
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'file_path' => 'payment-proofs/'.fake()->uuid().'.jpg',
            'uploaded_at' => now(),
            'status' => PaymentStatus::WaitingConfirm,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'rejection_reason' => null,
            'resubmission_deadline_at' => null,
            'is_current' => true,
        ];
    }
}
