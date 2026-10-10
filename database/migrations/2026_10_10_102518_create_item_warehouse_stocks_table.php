<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('item_warehouse_stocks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('item_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $table->decimal('stock_available', 15, 3)->default(0);
            $table->decimal('stock_reserved', 15, 3)->default(0);
            $table->decimal('stock_in_transit', 15, 3)->default(0);
            $table->decimal('stock_out', 15, 3)->default(0);
            $table->timestamps();
            $table->unique(['item_id', 'warehouse_id']);
        });

        foreach (DB::table('items')->whereNotNull('initial_warehouse_id')->get() as $item) {
            DB::table('item_warehouse_stocks')->insert([
                'id' => (string) Str::uuid(),
                'item_id' => $item->id,
                'warehouse_id' => $item->initial_warehouse_id,
                'stock_available' => $item->initial_stock,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_warehouse_stocks');
    }
};
