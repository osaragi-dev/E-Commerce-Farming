<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $affectedOrders = DB::table('orders')
                ->where('status', 'picking')
                ->get(['id', 'updated_at']);

            DB::table('order_status_logs')
                ->where('from_status', 'picking')
                ->where('to_status', 'packing')
                ->whereIn('order_id', function ($query): void {
                    $query->select('order_id')
                        ->from('order_status_logs')
                        ->where('to_status', 'picking');
                })
                ->delete();

            DB::table('order_status_logs')
                ->where('from_status', 'picking')
                ->where('to_status', 'picking')
                ->delete();

            DB::table('order_status_logs')
                ->where('from_status', 'picking')
                ->where('to_status', 'packing')
                ->update(['from_status' => 'ready_to_process']);

            DB::table('orders')
                ->where('status', 'picking')
                ->update([
                    'status' => 'packing',
                    'updated_at' => now(),
                ]);

            DB::table('order_status_logs')
                ->where('to_status', 'picking')
                ->update(['to_status' => 'packing']);

            DB::table('order_status_logs')
                ->where('from_status', 'picking')
                ->update(['from_status' => 'packing']);

            if ($affectedOrders->isEmpty()) {
                return;
            }

            $ordersWithPackingLog = DB::table('order_status_logs')
                ->whereIn('order_id', $affectedOrders->pluck('id'))
                ->where('to_status', 'packing')
                ->pluck('order_id')
                ->flip();

            $missingLogs = $affectedOrders
                ->reject(fn (object $order): bool => $ordersWithPackingLog->has($order->id))
                ->map(fn (object $order): array => [
                    'id' => (string) Str::orderedUuid(),
                    'order_id' => $order->id,
                    'from_status' => 'ready_to_process',
                    'to_status' => 'packing',
                    'changed_by_user_id' => null,
                    'note' => 'Backfilled by migration: Picking was merged into Packing.',
                    'created_at' => $order->updated_at ?? now(),
                ])
                ->values()
                ->all();

            if ($missingLogs !== []) {
                DB::table('order_status_logs')->insert($missingLogs);
            }
        });
    }

    public function down(): void
    {
        // The merged lifecycle cannot be reconstructed safely.
    }
};
