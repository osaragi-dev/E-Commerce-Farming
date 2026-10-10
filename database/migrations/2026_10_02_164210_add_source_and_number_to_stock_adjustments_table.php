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
        Schema::table('stock_adjustments', function (Blueprint $table): void {
            $table->string('source')->default('direct_adjustment')->index();
            $table->string('adjustment_number')->nullable()->index();
        });

        DB::table('stock_adjustments')
            ->whereIn('id', DB::table('stock_opname_items')->select('stock_adjustment_id')->whereNotNull('stock_adjustment_id'))
            ->update(['source' => 'stock_opname']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table): void {
            $table->dropColumn(['source', 'adjustment_number']);
        });
    }
};
