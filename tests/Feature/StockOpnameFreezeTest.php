<?php

use App\Enums\StockAdjustmentReason;
use App\Enums\StockAdjustmentType;
use App\Enums\StockMutationSource;
use App\Enums\StockOpnameStatus;
use App\Enums\StockReservationStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\ProductVariant;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\StockMutation;
use App\Models\StockOpname;
use App\Models\StockOpnameSession;
use App\Models\SystemFreezeState;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\OrderStockReservationService;
use App\Services\SystemFreezeGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function frozenSystem(): array
{
    $warehouse = Warehouse::factory()->create();
    $session = StockOpnameSession::factory()->create([
        'warehouse_id' => $warehouse->id,
    ]);
    $session->freeze();

    return [$warehouse, $session];
}

test('frozen system rejects stock transfers and manual adjustments', function (): void {
    [$warehouse] = frozenSystem();
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    WarehouseStock::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 10,
    ]);

    expect(fn () => StockMovement::transfer($warehouse, $destination, $variant, 1))
        ->toThrow(ValidationException::class)
        ->and(fn () => StockMutation::record(
            $variant,
            StockMutationSource::ManualAdjustment,
            1,
            warehouse: $warehouse,
        ))
        ->toThrow(ValidationException::class)
        ->and(fn () => StockAdjustment::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'type' => StockAdjustmentType::Tambah,
            'quantity' => 1,
            'reason' => StockAdjustmentReason::Lainnya,
        ]))
        ->toThrow(ValidationException::class);

    expect(WarehouseStock::query()->where('warehouse_id', $warehouse->id)->value('stock_available'))->toBe('10.000')
        ->and(StockMovement::query()->count())->toBe(0);
});

test('frozen system rejects order reservation release and fulfillment', function (): void {
    [$warehouse] = frozenSystem();
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    WarehouseStock::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 10,
        'stock_reserved' => 2,
    ]);
    $orderItemId = (string) str()->uuid();

    expect(fn () => StockMutation::reserveForOrder($variant, 1, $orderItemId, null, $warehouse))
        ->toThrow(ValidationException::class)
        ->and(fn () => StockMutation::releaseFromOrder($variant, 1, $orderItemId, null, $warehouse))
        ->toThrow(ValidationException::class)
        ->and(fn () => StockMutation::fulfillForOrder($variant, 1, $orderItemId, null, $warehouse))
        ->toThrow(ValidationException::class);
});

test('frozen system rejects direct product stock edits', function (): void {
    frozenSystem();
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);

    expect(fn () => $variant->update(['stock_available' => 9]))
        ->toThrow(ValidationException::class);

    expect($variant->refresh()->stock_available)->toBe('10.000');
});

test('frozen system allows multiple stock opname records to be created', function (): void {
    [, $frozenSession] = frozenSystem();
    $otherWarehouse = Warehouse::factory()->create();

    $secondSession = StockOpnameSession::factory()->create([
        'warehouse_id' => $otherWarehouse->id,
    ]);

    expect($secondSession->status)->toBe(StockOpnameStatus::Draft)
        ->and(StockOpnameSession::query()->count())->toBe(2)
        ->and(SystemFreezeState::current()->frozen_session_id)->toBe($frozenSession->id);
});

test('frozen system rejects order and payment writes', function (): void {
    $order = Order::factory()->create();
    $payment = Payment::factory()->create(['order_id' => $order->id]);
    frozenSystem();

    expect(fn () => $order->update(['notes' => 'Attempted during freeze']))
        ->toThrow(ValidationException::class)
        ->and(fn () => $payment->update(['rejection_reason' => 'Attempted during freeze']))
        ->toThrow(ValidationException::class);
});

test('public order bypass does not apply to admin sessions', function (): void {
    frozenSystem();
    $this->actingAs(User::factory()->create());

    expect(fn () => app(SystemFreezeGuard::class)->runPublicOrderCreation(
        fn (): Order => Order::factory()->create(),
    ))->toThrow(ValidationException::class);
});

test('stock opname can record its counted variance while the system is frozen', function (): void {
    [$warehouse] = frozenSystem();
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    WarehouseStock::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 10,
    ]);

    $opname = StockOpname::record($warehouse, $variant, 12);

    expect($opname->difference)->toBe('2.000')
        ->and(WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_variant_id', $variant->id)
            ->value('stock_available'))->toBe('12.000')
        ->and(StockMutation::query()
            ->where('reference_id', $opname->id)
            ->first()?->source)->toBe(StockMutationSource::Stocktake);
});

test('freezing is global and cannot be duplicated', function (): void {
    $warehouse = Warehouse::factory()->create();
    $otherWarehouse = Warehouse::factory()->create();
    $session = StockOpnameSession::factory()->create(['warehouse_id' => $warehouse->id]);
    $otherSession = StockOpnameSession::factory()->create(['warehouse_id' => $otherWarehouse->id]);

    $session->freeze();

    expect($session->refresh()->status)->toBe(StockOpnameStatus::Frozen)
        ->and(SystemFreezeState::current()->is_frozen)->toBeTrue()
        ->and(fn () => $otherSession->freeze())->toThrow(ValidationException::class)
        ->and(StockOpnameSession::factory()->create([
            'warehouse_id' => $otherWarehouse->id,
        ])->status)->toBe(StockOpnameStatus::Draft);
});

test('unfreezing returns the stock opname to counting', function (): void {
    [, $session] = frozenSystem();

    $session->unfreeze();

    expect($session->refresh()->status)->toBe(StockOpnameStatus::Counting)
        ->and(app(SystemFreezeGuard::class)->isFrozen())->toBeFalse();
});

test('unfreezing keeps a reservation pending when stock is insufficient and retry can process it later', function (): void {
    $warehouse = Warehouse::defaultFulfillment();
    $variant = ProductVariant::factory()->create(['stock_available' => 1, 'stock_reserved' => 0]);
    $order = Order::factory()->create([
        'fulfillment_warehouse_id' => $warehouse?->id,
        'stock_reservation_status' => StockReservationStatus::Pending,
    ]);
    $item = OrderItem::factory()->for($order)->create([
        'product_id' => $variant->product_id,
        'product_variant_id' => $variant->id,
        'quantity' => 2,
    ]);
    $session = StockOpnameSession::factory()->create(['warehouse_id' => $warehouse?->id]);
    $session->freeze();
    $admin = User::factory()->create();

    $session->unfreeze($admin->id);

    expect($order->refresh()->stock_reservation_status)->toBe(StockReservationStatus::Pending)
        ->and($order->stock_reservation_error)->toContain('Insufficient stock')
        ->and($variant->refresh()->stock_available)->toBe('1.000')
        ->and($variant->stock_reserved)->toBe('0.000');

    $variant->update(['stock_available' => 2]);
    $result = app(OrderStockReservationService::class)->reservePending($order, $admin->id);

    expect($result['reserved'])->toBeTrue()
        ->and($order->refresh()->stock_reservation_status)->toBe(StockReservationStatus::Reserved)
        ->and($variant->refresh()->stock_available)->toBe('0.000')
        ->and($variant->stock_reserved)->toBe('2.000')
        ->and($item->refresh()->quantity)->toBe('2.000');
});

test('cancelling a pending reservation does not restore stock', function (): void {
    $warehouse = Warehouse::defaultFulfillment();
    $variant = ProductVariant::factory()->create(['stock_available' => 5, 'stock_reserved' => 0]);
    $order = Order::factory()->create([
        'fulfillment_warehouse_id' => $warehouse?->id,
        'stock_reservation_status' => StockReservationStatus::Pending,
    ]);
    $item = OrderItem::factory()->for($order)->create([
        'product_id' => $variant->product_id,
        'product_variant_id' => $variant->id,
        'quantity' => 2,
    ]);
    $actor = User::factory()->create();

    $actor->givePermissionTo(Permission::findOrCreate('Delete:Order', 'web'));
    $order->cancel($actor, 'Customer request');

    expect($order->refresh()->stock_reservation_status)->toBe(StockReservationStatus::Released)
        ->and($variant->refresh()->stock_available)->toBe('5.000')
        ->and($variant->stock_reserved)->toBe('0.000')
        ->and(StockMutation::query()
            ->where('reference_id', $item->id)
            ->where('source', StockMutationSource::CancelOrder)
            ->exists())->toBeFalse();
});
