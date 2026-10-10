<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Step 2 — Ready to Process introduces OrderStatus::ReadyToProcess as the
 * mandatory state between "payment settled" and "Admin started Picking".
 * Before this change, Payment::confirm() never touched Order::status, so
 * any order that was already paid before this migration runs is sitting in
 * WaitingPayment with a Paid payment — exactly the confusing
 * "Cash | Paid | Waiting Payment" state this feature removes.
 *
 * This migration:
 * - Does NOT alter any table structure (orders.status is a plain string
 *   column already, see 2026_08_30_090000_rename_payment_status_pending_to
 *   _waiting_confirm.php for the same style of data-only migration).
 * - Does NOT create, modify, or delete any Payment row — it only reads
 *   payments.status to decide which orders qualify.
 * - Only ever moves orders FROM 'waiting_payment' TO 'ready_to_process'.
 *   It never touches orders in any other status (Picking, Packing,
 *   Cancelled, etc.), so it cannot regress or skip any order forward
 *   incorrectly.
 * - Is idempotent: running it twice is safe, because after the first run
 *   the affected orders are no longer 'waiting_payment' and the WHERE
 *   clause will simply match nothing the second time.
 * - Writes one order_status_logs row per affected order, using the exact
 *   same shape Order::advanceStatus()/cancel()/markReadyToProcess() write,
 *   so this backfill is indistinguishable in the audit trail from a "real"
 *   transition (changed_by_user_id is left null — this is a system/
 *   migration-initiated change, not an Admin action, and the column is
 *   already nullable for that reason).
 */
return new class extends Migration
{
    public function up(): void
    {
        $affectedOrderIds = DB::table('orders')
            ->where('orders.status', 'waiting_payment')
            ->whereNull('orders.deleted_at')
            ->whereIn('orders.id', function ($query): void {
                $query->select('order_id')
                    ->from('payments')
                    ->where('status', 'paid')
                    ->whereNull('deleted_at');
            })
            ->pluck('orders.id');

        if ($affectedOrderIds->isEmpty()) {
            return;
        }

        $now = now();

        DB::table('orders')
            ->whereIn('id', $affectedOrderIds)
            ->update([
                'status' => 'ready_to_process',
                'updated_at' => $now,
            ]);

        $logs = $affectedOrderIds->map(fn (string $orderId): array => [
            'id' => (string) Str::orderedUuid(),
            'order_id' => $orderId,
            'from_status' => 'waiting_payment',
            'to_status' => 'ready_to_process',
            'changed_by_user_id' => null,
            'note' => 'Backfilled by migration: payment was already Paid before Ready to Process existed as an order status.',
            'created_at' => $now,
        ])->all();

        DB::table('order_status_logs')->insert($logs);
    }

    public function down(): void
    {
        // Intentionally not reversible: we cannot safely reconstruct which
        // status each of these orders would have been in had this
        // migration never run, and the "JANGAN menghapus data/payment
        // history existing" rule means the order_status_logs rows this
        // created should not be deleted either. No-op by design.
    }
};
