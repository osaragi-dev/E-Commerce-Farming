<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('merge migration converts legacy picking records into packing without self transitions', function (): void {
    $orderWithHistory = Order::factory()->create(['status' => OrderStatus::Packing]);
    $orderWithoutHistory = Order::factory()->create(['status' => OrderStatus::Packing]);
    $cancelledOrder = Order::factory()->create(['status' => OrderStatus::Cancelled]);

    DB::table('orders')
        ->whereIn('id', [$orderWithHistory->id, $orderWithoutHistory->id])
        ->update(['status' => 'picking']);

    DB::table('order_status_logs')->insert([
        [
            'id' => (string) Str::orderedUuid(),
            'order_id' => $orderWithHistory->id,
            'from_status' => 'ready_to_process',
            'to_status' => 'picking',
            'changed_by_user_id' => null,
            'note' => null,
            'created_at' => now()->subMinute(),
        ],
        [
            'id' => (string) Str::orderedUuid(),
            'order_id' => $orderWithHistory->id,
            'from_status' => 'picking',
            'to_status' => 'packing',
            'changed_by_user_id' => null,
            'note' => null,
            'created_at' => now(),
        ],
        [
            'id' => (string) Str::orderedUuid(),
            'order_id' => $cancelledOrder->id,
            'from_status' => 'picking',
            'to_status' => 'cancelled',
            'changed_by_user_id' => null,
            'note' => null,
            'created_at' => now(),
        ],
    ]);

    $migration = require database_path('migrations/2026_09_18_102854_merge_picking_into_packing_order_status.php');
    $migration->up();

    expect(DB::table('orders')->where('id', $orderWithHistory->id)->value('status'))->toBe('packing')
        ->and(DB::table('orders')->where('id', $orderWithoutHistory->id)->value('status'))->toBe('packing');

    $logs = DB::table('order_status_logs')
        ->whereIn('order_id', [$orderWithHistory->id, $orderWithoutHistory->id, $cancelledOrder->id])
        ->get();

    expect($logs->contains(fn (object $log): bool => $log->from_status === 'picking' || $log->to_status === 'picking'))->toBeFalse()
        ->and($logs->contains(fn (object $log): bool => $log->from_status === 'packing' && $log->to_status === 'packing'))->toBeFalse()
        ->and($logs->where('order_id', $orderWithHistory->id)->where('from_status', 'ready_to_process')->where('to_status', 'packing'))->toHaveCount(1)
        ->and($logs->where('order_id', $orderWithoutHistory->id)->where('from_status', 'ready_to_process')->where('to_status', 'packing'))->toHaveCount(1)
        ->and($logs->where('order_id', $cancelledOrder->id)->where('from_status', 'packing')->where('to_status', 'cancelled'))->toHaveCount(1);

    $logCount = DB::table('order_status_logs')->count();
    $migration->up();

    expect(DB::table('order_status_logs')->count())->toBe($logCount);
});
