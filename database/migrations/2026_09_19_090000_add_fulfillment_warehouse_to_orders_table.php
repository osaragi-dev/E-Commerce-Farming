<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $sccrWarehouseId = DB::table('warehouses')->where('code', 'WH-SCCR')->value('id');

        if ($sccrWarehouseId === null) {
            $sccrWarehouseId = (string) Str::uuid7();

            DB::table('warehouses')->insert([
                'id' => $sccrWarehouseId,
                'code' => 'WH-SCCR',
                'name' => 'SCCR Warehouse',
                'is_active' => true,
                'record_status' => 'saved',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignUuid('fulfillment_warehouse_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('warehouses')
                ->nullOnDelete();
        });

        DB::table('orders')->whereNull('fulfillment_warehouse_id')->update([
            'fulfillment_warehouse_id' => $sccrWarehouseId,
        ]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fulfillment_warehouse_id');
        });
    }
};
