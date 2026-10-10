<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\StockMutationSource;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Filament\Resources\Stocks\Pages\ListStocks;
use App\Livewire\Public\Checkout;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\StockMutation;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

test('public and admin orders keep warehouse stock accurate through completion', function (string $origin): void {
    Warehouse::query()->where('code', 'WH-SCCR')->update(['is_pos' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));

    $warehouse = Warehouse::defaultFulfillment();
    $variant = ProductVariant::factory()->create([
        'stock_available' => 10,
        'stock_reserved' => 0,
        'stock_out' => 0,
        'low_stock_threshold' => 8,
    ]);
    $stock = WarehouseStock::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 10,
    ]);
    $customer = Customer::factory()->registered()->create();

    if ($origin === 'public') {
        $cartItem = Cart::forCustomer($customer)->addItem($variant, 2);
        $this->actingAs($customer, 'customer');

        Livewire::withQueryParams(['items' => $cartItem->id])
            ->test(Checkout::class)
            ->set('recipientName', $customer->name)
            ->set('recipientPhone', $customer->phone)
            ->set('shippingAddress', 'SCCR')
            ->set('paymentMethod', PaymentMethod::Cash->value)
            ->call('placeOrder')
            ->assertRedirect();
    } else {
        $this->actingAs($admin, 'web');

        Livewire::test(CreateOrder::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'recipient_name' => $customer->name,
                'recipient_phone' => $customer->phone,
                'shipping_address' => 'SCCR',
                'items' => [['product_variant_id' => $variant->id, 'quantity' => 2]],
                'payment_method' => PaymentMethod::Cash->value,
                'shipping_cost' => 0,
            ])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    $order = Order::query()->whereBelongsTo($customer)->sole();
    $this->actingAs($admin, 'web');

    expect($order->status)->toBe(OrderStatus::ReadyToProcess)
        ->and($order->fulfillment_warehouse_id)->toBe($warehouse->id)
        ->and($order->items()->sole()->quantity)->toBe('2.000');

    $assertStock = static function (int $reserved, int $inTransit, int $out) use ($warehouse, $stock, $variant): void {
        expect($stock->refresh()->stock_available)->toBe('8.000')
            ->and($stock->stock_reserved)->toBe($reserved.'.000')
            ->and($stock->stock_in_transit)->toBe($inTransit.'.000')
            ->and($stock->stock_out)->toBe($out.'.000')
            ->and($variant->refresh()->stock_available)->toBe('8.000')
            ->and($variant->stock_reserved)->toBe($reserved.'.000')
            ->and($variant->stock_in_transit)->toBe($inTransit.'.000')
            ->and($variant->stock_out)->toBe($out.'.000');

        Livewire::test(ListStocks::class, ['warehouse' => $warehouse->id])
            ->assertTableColumnStateSet('stock_available', 8, $stock)
            ->assertTableColumnStateSet('stock_reserved', $reserved, $stock)
            ->assertTableColumnStateSet('stock_in_transit', $inTransit, $stock)
            ->assertTableColumnStateSet('stock_out', $out, $stock)
            ->assertTableColumnStateSet('stockStatus', 'Low Stock', $stock);
    };

    $assertStock(2, 0, 0);

    foreach ([OrderStatus::Packing, OrderStatus::Shipping, OrderStatus::Done] as $stage) {
        Livewire::test(ManageOrders::class)
            ->callTableAction('advanceStatus', $order)
            ->assertHasNoTableActionErrors();

        expect($order->refresh()->status)->toBe($stage);
        $assertStock(
            $stage === OrderStatus::Packing ? 2 : 0,
            $stage === OrderStatus::Shipping ? 2 : 0,
            $stage === OrderStatus::Done ? 2 : 0,
        );
    }

    expect(StockMutation::query()
        ->where('warehouse_id', $warehouse->id)
        ->where('product_variant_id', $variant->id)
        ->where('source', StockMutationSource::Order)
        ->count())->toBe(1);

    Livewire::test(ManageOrders::class)->assertTableActionHidden('advanceStatus', $order);
})->with(['public', 'admin']);
