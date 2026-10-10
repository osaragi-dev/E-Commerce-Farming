<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Step 2 — Waiting Confirmation introduces OrderStatus::WaitingConfirmation
 * as the mandatory state between "a Payment has been recorded" and "the
 * Payment has been confirmed / order is ready to process". Before this
 * migration, an order whose Payment was already 'waiting_confirm' (QRIS
 * checkout, or Cash after Record Payment) stayed at 'waiting_payment' —
 * this backfills existing data to match the new lifecycle.
 *
 * Mirrors 2026_09_02_100000_backfill_ready_to_process_order_status.php
 * exactly, same reasoning and same safety properties:
 * - Data-only: does not alter table structure.
 * - Does NOT create, modify, or delete any Payment row — only reads
 *   payments.status to decide which orders qualify.
 * - Only moves orders FROM 'waiting_payment' TO 'waiting_confirmation',
 *   and only when their Payment is 'waiting_confirm'. Never touches any
 *   other order status. Never touches orders whose Payment is already
 *   'paid' — those were already handled by the earlier ReadyToProcess
 *   backfill migration, and the two WHERE clauses are mutually exclusive
 *   (a Payment has exactly one status).
 * - Idempotent: safe to run more than once — after the first run the
 *   affected orders are no longer 'waiting_payment', so a second run
 *   matches nothing.
 * - Writes one order_status_logs row per affected order, in the exact
 *   same shape Order::markWaitingConfirmation() itself writes, so this
 *   backfill is indistinguishable in the audit trail from a "real"
 *   transition (changed_by_user_id left null — system/migration-initiated,
 *   not an Admin action).
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
                    ->where('status', 'waiting_confirm')
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
                'status' => 'waiting_confirmation',
                'updated_at' => $now,
            ]);

        $logs = $affectedOrderIds->map(fn (string $orderId): array => [
            'id' => (string) Str::orderedUuid(),
            'order_id' => $orderId,
            'from_status' => 'waiting_payment',
            'to_status' => 'waiting_confirmation',
            'changed_by_user_id' => null,
            'note' => 'Backfilled by migration: payment was already Waiting Confirm before Waiting Confirmation existed as an order status.',
            'created_at' => $now,
        ])->all();

        DB::table('order_status_logs')->insert($logs);
    }

    public function down(): void
    {
        // Intentionally not reversible — same reasoning as
        // 2026_09_02_100000_backfill_ready_to_process_order_status.php.
    }
};
