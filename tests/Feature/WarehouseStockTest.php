<?php

use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Database\QueryException;

test('warehouse stock keeps inventory separate for each warehouse', function (): void {
    $variant = ProductVariant::factory()->create(['stock_available' => 20]);
    $farming = Warehouse::factory()->create(['code' => 'WH-FARMING-TEST']);
    $sccr = Warehouse::factory()->create(['code' => 'WH-SCCR-TEST']);

    WarehouseStock::factory()->create([
        'warehouse_id' => $farming->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 20,
    ]);
    WarehouseStock::factory()->create([
        'warehouse_id' => $sccr->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 5,
    ]);

    expect($variant->warehouseStocks()->count())->toBe(2)
        ->and($farming->stocks()->whereBelongsTo($variant, 'productVariant')->value('stock_available'))->toBe('20.000')
        ->and($sccr->stocks()->whereBelongsTo($variant, 'productVariant')->value('stock_available'))->toBe('5.000');
});

test('one variant cannot have duplicate stock rows in the same warehouse', function (): void {
    $stock = WarehouseStock::factory()->create();

    expect(fn () => WarehouseStock::factory()->create([
        'warehouse_id' => $stock->warehouse_id,
        'product_variant_id' => $stock->product_variant_id,
    ]))->toThrow(QueryException::class);
});
