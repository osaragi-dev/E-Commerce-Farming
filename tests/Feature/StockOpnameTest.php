<?php

use App\Enums\StockMutationSource;
use App\Models\ProductVariant;
use App\Models\StockMutation;
use App\Models\StockOpname;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Validation\ValidationException;

test('stock opname adjusts warehouse stock and records the difference', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_available' => 20]);
    WarehouseStock::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 12,
    ]);

    $opname = StockOpname::record($warehouse, $variant, 9, 'Physical count');

    expect($opname->system_stock)->toBe('12.000')
        ->and($opname->counted_stock)->toBe('9.000')
        ->and($opname->difference)->toBe('-3.000')
        ->and(WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_variant_id', $variant->id)
            ->value('stock_available'))->toBe('9.000')
        ->and($variant->refresh()->stock_available)->toBe('17.000')
        ->and(StockMutation::query()
            ->where('reference_id', $opname->id)
            ->where('source', StockMutationSource::Stocktake)
            ->value('quantity_change'))->toBe('-3.000');
});

test('stock opname rejects negative physical counts', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();

    expect(fn () => StockOpname::record($warehouse, $variant, -1))
        ->toThrow(ValidationException::class);
});
