<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = [
        'product_variants' => ['initial_stock', 'stock_available', 'stock_reserved', 'stock_in_transit', 'stock_out', 'low_stock_threshold'],
        'warehouse_stocks' => ['stock_available', 'stock_reserved', 'stock_in_transit', 'stock_out'],
        'order_items' => ['quantity'],
        'cart_items' => ['quantity'],
        'stock_adjustments' => ['quantity'],
        'stock_mutations' => ['stock_before', 'stock_after', 'quantity_change'],
        'stock_movements' => ['quantity'],
        'stock_movement_items' => ['quantity', 'quantity_shipped', 'quantity_received'],
        'stock_opnames' => ['system_stock', 'counted_stock', 'difference'],
        'stock_opname_items' => ['system_qty', 'physical_qty', 'variance_qty'],
    ];

    public function up(): void
    {
        foreach (['product_variants', 'order_items'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->decimal('price_quantity', 18, 3)->default(1);
            });
        }

        foreach (self::COLUMNS as $name => $columns) {
            Schema::table($name, function (Blueprint $table) use ($name, $columns): void {
                foreach ($columns as $column) {
                    $definition = $table->decimal($column, 18, 3);
                    if (in_array($name, ['product_variants', 'warehouse_stocks'], true)) {
                        $definition->default(0);
                    }
                    if (($name === 'stock_movements')
                        || ($name === 'stock_movement_items' && $column !== 'quantity')
                        || ($name === 'stock_opname_items' && $column !== 'system_qty')) {
                        $definition->nullable();
                    }
                    $definition->change();
                }
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Decimal quantities cannot safely be converted back to integers. Use a forward migration.');
    }
};
