<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table): void {
            $table->unsignedInteger('fulfillment_priority')->default(100)->after('is_pos')->index();
        });

        DB::table('warehouses')
            ->where('code', 'WH-SCCR')
            ->update(['fulfillment_priority' => 10]);
        DB::table('warehouses')
            ->where('code', 'WH-FARMING')
            ->update(['fulfillment_priority' => 20]);

        $index = 'warehouses_one_active_pos_unique';

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX {$index} ON warehouses (is_pos) WHERE is_pos = true AND is_active = true AND record_status = 'saved' AND deleted_at IS NULL");
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement("CREATE UNIQUE INDEX {$index} ON warehouses (is_pos) WHERE is_pos = 1 AND is_active = 1 AND record_status = 'saved' AND deleted_at IS NULL");
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS warehouses_one_active_pos_unique');

        Schema::table('warehouses', function (Blueprint $table): void {
            $table->dropColumn('fulfillment_priority');
        });
    }
};
