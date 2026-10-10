<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('stock_wastes')
            ->whereNotNull('posted_at')
            ->where('waste_status', 'saved')
            ->select(['warehouse_id', 'product_variant_id'])
            ->selectRaw('SUM(quantity) as waste_quantity')
            ->groupBy('warehouse_id', 'product_variant_id')
            ->orderBy('warehouse_id')
            ->orderBy('product_variant_id')
            ->get()
            ->each(function (object $waste): void {
                DB::table('product_variants')
                    ->where('id', $waste->product_variant_id)
                    ->increment('stock_out', $waste->waste_quantity);

                DB::table('warehouse_stocks')
                    ->where('warehouse_id', $waste->warehouse_id)
                    ->where('product_variant_id', $waste->product_variant_id)
                    ->increment('stock_out', $waste->waste_quantity);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('The waste out-bucket backfill is irreversible; use a forward migration to correct stock.');
    }
};
