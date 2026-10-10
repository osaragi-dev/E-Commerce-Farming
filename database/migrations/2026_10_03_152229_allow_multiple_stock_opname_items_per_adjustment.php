<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_opname_items', function (Blueprint $table): void {
            $table->dropUnique(['stock_adjustment_id']);
        });
    }

    public function down(): void
    {
        $hasMultipleItems = DB::table('stock_opname_items')
            ->select('stock_adjustment_id')
            ->whereNotNull('stock_adjustment_id')
            ->groupBy('stock_adjustment_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasMultipleItems) {
            throw new RuntimeException('Cannot restore one-to-one stock opname adjustment links while adjustments contain multiple items.');
        }

        Schema::table('stock_opname_items', function (Blueprint $table): void {
            $table->unique('stock_adjustment_id');
        });
    }
};
