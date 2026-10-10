<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('cancelled_at')->nullable()->after('cancellation_reason');
        });

        DB::table('orders')
            ->where('status', 'cancelled')
            ->whereNull('cancelled_at')
            ->orderBy('id')
            ->get(['id'])
            ->each(function (object $order): void {
                $cancelledAt = DB::table('order_status_logs')
                    ->where('order_id', $order->id)
                    ->where('to_status', 'cancelled')
                    ->oldest('created_at')
                    ->value('created_at');

                if ($cancelledAt !== null) {
                    DB::table('orders')
                        ->where('id', $order->id)
                        ->update(['cancelled_at' => $cancelledAt]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('cancelled_at');
        });
    }
};
