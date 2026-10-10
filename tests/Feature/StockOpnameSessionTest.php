<?php

use App\Enums\StockOpnameStatus;
use App\Models\ProductVariant;
use App\Models\StockOpnameItem;
use App\Models\StockOpnameLog;
use App\Models\StockOpnameSession;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('stock opname draft has no number and starts as draft', function (): void {
    $session = StockOpnameSession::factory()->create();

    expect($session->opname_number)->toBeNull()
        ->and($session->status)->toBe(StockOpnameStatus::Draft)
        ->and($session->warehouse)->toBeInstanceOf(Warehouse::class)
        ->and(StockOpnameLog::query()->where('stock_opname_session_id', $session->id)->count())->toBe(1);
});

test('stock opname session owns countable items', function (): void {
    $session = StockOpnameSession::factory()->create();
    $variant = ProductVariant::factory()->create();
    $item = StockOpnameItem::factory()->create([
        'stock_opname_session_id' => $session->id,
        'product_variant_id' => $variant->id,
        'system_qty' => 25,
        'physical_qty' => 23,
        'variance_qty' => -2,
    ]);

    expect($session->fresh()->items)->toHaveCount(1)
        ->and($item->session->is($session))->toBeTrue()
        ->and($item->variance_qty)->toBe('-2.000');
});
