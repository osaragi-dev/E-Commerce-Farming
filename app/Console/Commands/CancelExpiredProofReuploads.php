<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('payments:cancel-expired-proof-reuploads')]
#[Description('Cancel orders whose payment proof upload window has expired')]
class CancelExpiredProofReuploads extends Command
{
    public function handle(): int
    {
        $cancelled = 0;

        Payment::query()
            ->where('status', PaymentStatus::Rejected)
            ->whereHas('proofs', function ($query): void {
                $query
                    ->where('is_current', true)
                    ->where('status', PaymentStatus::Rejected)
                    ->whereNotNull('resubmission_deadline_at')
                    ->where('resubmission_deadline_at', '<=', now());
            })
            ->cursor()
            ->each(function (Payment $payment) use (&$cancelled): void {
                if ($payment->cancelIfResubmissionExpired()) {
                    $cancelled++;
                }
            });

        Payment::query()
            ->whereIn('method', array_filter(PaymentMethod::cases(), fn (PaymentMethod $method): bool => $method->requiresProof()))
            ->where('status', PaymentStatus::Unpaid)
            ->whereNotNull('proof_upload_deadline_at')
            ->where('proof_upload_deadline_at', '<=', now())
            ->whereHas('order', function ($query): void {
                $query->where('status', OrderStatus::WaitingPayment);
            })
            ->cursor()
            ->each(function (Payment $payment) use (&$cancelled): void {
                if ($payment->cancelIfInitialProofExpired()) {
                    $cancelled++;
                }
            });

        $this->info("Cancelled {$cancelled} expired payment proof order(s).");

        return self::SUCCESS;
    }
}
