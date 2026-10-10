<?php

use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Notifications\StockLevelAlertForAdmins;
use Illuminate\Support\Facades\Notification;

/** @return array{0: WarehouseStock, 1: User} */
function stockAlertFixture(int $available = 11): array
{
    $warehouse = Warehouse::factory()->create([
        'code' => 'WH-POS-ALERT',
        'is_pos' => true,
    ]);
    $variant = ProductVariant::factory()->create([
        'stock_available' => $available,
        'low_stock_threshold' => 10,
    ]);
    $stock = WarehouseStock::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'stock_available' => $available,
    ]);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));

    return [$stock, $admin];
}

test('POS stock reaching or falling below minimum sends a low stock alert', function (int $available): void {
    [$stock, $admin] = stockAlertFixture();
    Notification::fake();

    $stock->update(['stock_available' => $available]);

    Notification::assertSentTo($admin, StockLevelAlertForAdmins::class, function (StockLevelAlertForAdmins $notification) use ($admin, $stock): bool {
        $data = $notification->toDatabase($admin);

        return ! $notification->outOfStock
            && $data['title'] === 'Low stock alert'
            && $data['color'] === 'warning'
            && str_contains($data['body'], $stock->productVariant->name)
            && $data['actions'][0]['label'] === 'Open Stocks';
    });
})->with([10, 5]);

test('POS stock reaching zero sends an out of stock alert', function (): void {
    [$stock, $admin] = stockAlertFixture();
    Notification::fake();

    $stock->update(['stock_available' => 0]);

    Notification::assertSentTo($admin, StockLevelAlertForAdmins::class, function (StockLevelAlertForAdmins $notification) use ($admin): bool {
        $data = $notification->toDatabase($admin);

        return $notification->outOfStock
            && $data['title'] === 'Out of stock alert'
            && $data['color'] === 'danger';
    });
});

test('restocking to the minimum sends a low stock alert after stock was empty', function (): void {
    [$stock, $admin] = stockAlertFixture(0);
    Notification::fake();

    $stock->update(['stock_available' => 10]);

    Notification::assertSentTo($admin, StockLevelAlertForAdmins::class, function (StockLevelAlertForAdmins $notification): bool {
        return ! $notification->outOfStock;
    });
});

test('existing low stock rows each create one notification when synchronized', function (): void {
    $warehouse = Warehouse::factory()->create([
        'code' => 'WH-POS-ALERT',
        'is_pos' => true,
    ]);
    $firstVariant = ProductVariant::factory()->create([
        'stock_available' => 5,
        'low_stock_threshold' => 10,
    ]);
    $secondVariant = ProductVariant::factory()->create([
        'stock_available' => 2,
        'low_stock_threshold' => 10,
    ]);
    WarehouseStock::withoutEvents(function () use ($warehouse, $firstVariant, $secondVariant): void {
        WarehouseStock::factory()->create([
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $firstVariant->id,
            'stock_available' => 5,
        ]);
        WarehouseStock::factory()->create([
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $secondVariant->id,
            'stock_available' => 2,
        ]);
    });
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    Notification::fake();

    WarehouseStock::query()->whereBelongsTo($warehouse)->get();
    WarehouseStock::query()->whereBelongsTo($warehouse)->get();

    Notification::assertSentToTimes($admin, StockLevelAlertForAdmins::class, 2);
});

test('non POS warehouse stock does not send a stock level alert', function (): void {
    $warehouse = Warehouse::factory()->create(['is_pos' => false]);
    $variant = ProductVariant::factory()->create([
        'stock_available' => 11,
        'low_stock_threshold' => 10,
    ]);
    $stock = WarehouseStock::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 11,
    ]);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    Notification::fake();

    $stock->update(['stock_available' => 10]);

    Notification::assertNotSentTo($admin, StockLevelAlertForAdmins::class);
});

test('stock level alerts are sent only once while stock remains low', function (): void {
    [$stock, $admin] = stockAlertFixture();
    Notification::fake();

    $stock->update(['stock_available' => 10]);
    $stock->update(['stock_available' => 9]);

    Notification::assertSentToTimes($admin, StockLevelAlertForAdmins::class, 1);
});

test('stock level alerts can be sent again after stock recovers', function (): void {
    [$stock, $admin] = stockAlertFixture();
    Notification::fake();

    $stock->update(['stock_available' => 10]);
    $stock->update(['stock_available' => 15]);
    $stock->update(['stock_available' => 9]);

    Notification::assertSentToTimes($admin, StockLevelAlertForAdmins::class, 2);
});

test('inactive admins and customer users do not receive stock level alerts', function (): void {
    [$stock, $admin] = stockAlertFixture();
    $inactiveAdmin = User::factory()->create(['is_active' => false]);
    $customer = User::factory()->create(['is_active' => true]);
    $customer->assignRole(Role::findOrCreate('customer', 'web'));
    Notification::fake();

    $stock->update(['stock_available' => 10]);

    Notification::assertSentTo($admin, StockLevelAlertForAdmins::class);
    Notification::assertNotSentTo($inactiveAdmin, StockLevelAlertForAdmins::class);
    Notification::assertNotSentTo($customer, StockLevelAlertForAdmins::class);
});

test('stock level alerts are persisted as database notifications', function (): void {
    [$stock, $admin] = stockAlertFixture();

    $stock->update(['stock_available' => 10]);

    expect($admin->unreadNotifications()
        ->where('type', StockLevelAlertForAdmins::class)
        ->count())->toBe(1);
});
