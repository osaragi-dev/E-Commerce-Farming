<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('orders')
            ->where('status', 'cancelled')
            ->update([
                'stock_reservation_status' => 'released',
                'stock_reservation_error' => null,
            ]);

        DB::table('orders')
            ->where('status', '!=', 'cancelled')
            ->whereNull('stock_reservation_status')
            ->update(['stock_reservation_status' => 'reserved']);
    }

    public function down(): void
    {
        // Historical reservation state cannot be reconstructed safely.
    }
};
