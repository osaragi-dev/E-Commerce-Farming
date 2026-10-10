<?php

use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Notifications\StockMovementRecommendationForAdmins;
use App\Services\OrderFulfillmentResolver;
use Illuminate\Support\Facades\Notification;

function fulfillmentLine(ProductVariant $variant, int $quantity): array
{
    return ['variant' => $variant, 'quantity' => $quantity];
}

test('fulfillment prefers the POS warehouse when it can fulfill the order', function (): void {
    $pos = Warehouse::factory()->create(['is_pos' => true, 'code' => 'WH-POS-TEST']);
    $fallback = Warehouse::factory()->create(['code' => 'WH-FALLBACK-TEST']);
    $variant = ProductVariant::factory()->create(['stock_available' => 25]);

    WarehouseStock::factory()->create([
        'warehouse_id' => $pos->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 5,
    ]);
    WarehouseStock::factory()->create([
        'warehouse_id' => $fallback->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 20,
    ]);

    expect(app(OrderFulfillmentResolver::class)->resolveForLines([
        fulfillmentLine($variant, 3),
    ])?->id)->toBe($pos->id);
});

test('fulfillment rejects stock held only in another warehouse', function (): void {
    $pos = Warehouse::factory()->create(['is_pos' => true, 'code' => 'WH-POS-TEST']);
    $fallback = Warehouse::factory()->create(['code' => 'WH-FALLBACK-TEST']);
    $variant = ProductVariant::factory()->create(['stock_available' => 7]);

    WarehouseStock::factory()->create([
        'warehouse_id' => $pos->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 2,
    ]);
    WarehouseStock::factory()->create([
        'warehouse_id' => $fallback->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 5,
    ]);

    expect(app(OrderFulfillmentResolver::class)->resolveForLines([
        fulfillmentLine($variant, 3),
    ]))->toBeNull();
});

test('fulfillment requires an active POS warehouse', function (): void {
    $otherWarehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_available' => 3]);

    WarehouseStock::factory()->create([
        'warehouse_id' => $otherWarehouse->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 3,
    ]);

    expect(app(OrderFulfillmentResolver::class)->resolveForLines([
        fulfillmentLine($variant, 1),
    ]))->toBeNull();
    expect($variant->availableForSale())->toBe('0.000');
});

test('fulfillment rejects an order that would need stock split across warehouses', function (): void {
    $pos = Warehouse::factory()->create(['is_pos' => true, 'code' => 'WH-POS-TEST']);
    $fallback = Warehouse::factory()->create(['code' => 'WH-FALLBACK-TEST']);
    $firstVariant = ProductVariant::factory()->create(['stock_available' => 5]);
    $secondVariant = ProductVariant::factory()->create(['stock_available' => 5]);

    WarehouseStock::factory()->create([
        'warehouse_id' => $pos->id,
        'product_variant_id' => $firstVariant->id,
        'stock_available' => 5,
    ]);
    WarehouseStock::factory()->create([
        'warehouse_id' => $fallback->id,
        'product_variant_id' => $secondVariant->id,
        'stock_available' => 5,
    ]);

    expect(app(OrderFulfillmentResolver::class)->resolveForLines([
        fulfillmentLine($firstVariant, 1),
        fulfillmentLine($secondVariant, 1),
    ]))->toBeNull();
});

test('public availability ignores other warehouses when POS stock is empty', function (): void {
    $pos = Warehouse::factory()->create(['is_pos' => true, 'code' => 'WH-POS-TEST']);
    $fallback = Warehouse::factory()->create(['code' => 'WH-FALLBACK-TEST']);
    $variant = ProductVariant::factory()->create(['stock_available' => 4]);

    WarehouseStock::factory()->create([
        'warehouse_id' => $pos->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 0,
    ]);
    WarehouseStock::factory()->create([
        'warehouse_id' => $fallback->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 4,
    ]);

    expect($variant->availableForSale())->toBe('0.000');
});

test('public availability is sold out when every eligible warehouse is empty', function (): void {
    $pos = Warehouse::factory()->create(['is_pos' => true, 'code' => 'WH-POS-TEST']);
    $fallback = Warehouse::factory()->create(['code' => 'WH-FALLBACK-TEST']);
    $variant = ProductVariant::factory()->create(['stock_available' => 0]);

    WarehouseStock::factory()->create([
        'warehouse_id' => $pos->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 0,
    ]);
    WarehouseStock::factory()->create([
        'warehouse_id' => $fallback->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 0,
    ]);

    expect($variant->availableForSale())->toBe('0.000');
});

test('fallback fulfillment notifies active admin users', function (): void {
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    $order = Order::factory()->create();
    $pos = Warehouse::factory()->create(['is_pos' => true, 'code' => 'WH-POS-TEST']);
    $fallback = Warehouse::factory()->create(['code' => 'WH-FALLBACK-TEST']);
    Notification::fake();

    StockMovementRecommendationForAdmins::send($order, $fallback, $pos);

    Notification::assertSentTo($admin, StockMovementRecommendationForAdmins::class, function (StockMovementRecommendationForAdmins $notification) use ($admin): bool {
        $data = $notification->toDatabase($admin);

        return $data['actions'][0]['label'] === 'Open Stock Movements'
            && $data['actions'][0]['url'] === StockMovementResource::getUrl('index');
    });
});
