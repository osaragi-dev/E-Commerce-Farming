<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $proofUploadHours = (int) (DB::table('payment_settings')->value('proof_resubmission_hours') ?: 24);
        $payments = DB::table('payments')
            ->where('method', 'qris')
            ->where('status', 'unpaid')
            ->whereNull('deleted_at')
            ->whereNull('proof_upload_deadline_at')
            ->whereNotExists(function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('payment_proofs')
                    ->whereColumn('payment_proofs.payment_id', 'payments.id')
                    ->where('payment_proofs.is_current', true);
            })
            ->get(['id', 'order_id', 'created_at']);

        if ($payments->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($payments, $proofUploadHours): void {
            foreach ($payments as $payment) {
                $deadline = Carbon::parse($payment->created_at)->addHours($proofUploadHours);

                DB::table('payments')
                    ->where('id', $payment->id)
                    ->update(['proof_upload_deadline_at' => $deadline]);

                $order = DB::table('orders')
                    ->where('id', $payment->order_id)
                    ->whereNull('deleted_at')
                    ->first(['id', 'status']);

                if ($order?->status !== 'ready_to_process') {
                    continue;
                }

                $now = now();

                DB::table('orders')
                    ->where('id', $order->id)
                    ->update([
                        'status' => 'waiting_payment',
                        'updated_at' => $now,
                        'updated_by' => null,
                    ]);

                DB::table('order_status_logs')->insert([
                    'id' => (string) Str::orderedUuid(),
                    'order_id' => $order->id,
                    'from_status' => 'ready_to_process',
                    'to_status' => 'waiting_payment',
                    'changed_by_user_id' => null,
                    'note' => 'Backfilled by migration: QRIS payment proof was not uploaded, so the order remains waiting for payment.',
                    'created_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Intentionally not reversible: legacy order status normalization
        // cannot be safely restored without reconstructing historical state.
    }
};
