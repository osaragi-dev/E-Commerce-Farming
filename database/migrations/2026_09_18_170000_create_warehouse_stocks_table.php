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
        Schema::create('warehouse_stocks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignUuid('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedInteger('stock_available')->default(0);
            $table->unsignedInteger('stock_reserved')->default(0);
            $table->unsignedInteger('stock_out')->default(0);
            $table->timestamps();
            $table->unique(['warehouse_id', 'product_variant_id']);
        });

        $warehouseId = DB::table('warehouses')->where('code', 'WH-FARMING')->value('id');

        if ($warehouseId === null) {
            $warehouseId = (string) Str::uuid7();

            DB::table('warehouses')->insert([
                'id' => $warehouseId,
                'code' => 'WH-FARMING',
                'name' => 'Farming Warehouse',
                'is_active' => true,
                'record_status' => 'saved',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('product_variants')
            ->select(['id', 'stock_available', 'stock_reserved', 'stock_out'])
            ->orderBy('id')
            ->chunkById(500, function ($variants) use ($warehouseId): void {
                $rows = $variants->map(fn ($variant): array => [
                    'id' => (string) Str::uuid7(),
                    'warehouse_id' => $warehouseId,
                    'product_variant_id' => $variant->id,
                    'stock_available' => $variant->stock_available,
                    'stock_reserved' => $variant->stock_reserved,
                    'stock_out' => $variant->stock_out,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all();

                if ($rows !== []) {
                    DB::table('warehouse_stocks')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_stocks');
    }
};
