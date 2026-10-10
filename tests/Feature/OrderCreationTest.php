<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RecordStatus;
use App\Enums\ShippingLocation;
use App\Enums\ShippingMethod;
use App\Enums\StockMutationSource;
use App\Enums\StockReservationStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\Schemas\OrderInfolist;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\StockMutation;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\AdminOrderCreationService;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    Warehouse::query()->where('code', 'WH-SCCR')->update(['is_pos' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $role = Role::findOrCreate('super_admin', 'web');
    $this->actor = User::factory()->create();
    $this->actor->assignRole($role);
    $this->actingAs($this->actor);
});

test('orders list hides new order for super admin and users with create permission', function (): void {
    $ordersUrl = OrderResource::getUrl('index');
    $createUrl = OrderResource::getUrl('create');

    $this->get($ordersUrl)
        ->assertSuccessful()
        ->assertDontSee('New Order')
        ->assertDontSee('href="'.$createUrl.'"', false);
    $this->get($createUrl)->assertSuccessful();

    $user = User::factory()->create();
    foreach (['ViewAny:Order', 'Create:Order'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
    }

    $this->actingAs($user)
        ->get($ordersUrl)
        ->assertSuccessful()
        ->assertDontSee('New Order')
        ->assertDontSee('href="'.$createUrl.'"', false);
    $this->get($createUrl)->assertSuccessful();

    $user->revokePermissionTo('Create:Order');
    $this->get($createUrl)->assertForbidden();
});

test('order item fields share one responsive row', function (): void {
    $schema = Livewire::test(CreateOrder::class)->instance()->getSchema('form');
    $repeater = $schema->getComponent('items');
    $grid = $repeater->getChildComponents()[0];
    $fields = $grid->getChildComponents();

    expect($grid->getAllColumns())->toBe([
        'default' => 1,
        'md' => 12,
    ])
        ->and(array_map(fn ($field): string => $field->getName(), $fields))
        ->toBe(['product_variant_id', 'quantity', 'line_subtotal'])
        ->and($fields[0]->getColumnSpan())->toBe(['default' => 1, 'md' => 6])
        ->and($fields[1]->getColumnSpan())->toBe(['default' => 1, 'md' => 2])
        ->and($fields[2]->getColumnSpan())->toBe(['default' => 1, 'md' => 4]);
});

test('product options only include current active products and variants', function (): void {
    $availableVariant = ProductVariant::factory()->create([
        'stock_available' => 10,
        'name' => 'Fresh Variant',
    ]);
    $availableVariant->product->update(['name' => 'Updated Product Name']);

    $deletedVariant = ProductVariant::factory()->create(['stock_available' => 10]);
    $deletedVariant->product->delete();

    $inactiveProductVariant = ProductVariant::factory()->create(['stock_available' => 10]);
    $inactiveProductVariant->product->update(['is_active' => false]);

    $draftProductVariant = ProductVariant::factory()->create(['stock_available' => 10]);
    $draftProductVariant->product->update(['record_status' => RecordStatus::Draft]);

    $inactiveVariant = ProductVariant::factory()->create([
        'stock_available' => 10,
        'is_active' => false,
    ]);

    $schema = Livewire::test(CreateOrder::class)->instance()->getSchema('form');
    $productSelect = $schema->getComponent('items')->getChildComponents()[0]->getChildComponents()[0];
    $options = $productSelect->getOptions();

    expect($options)->toHaveKey($availableVariant->id)
        ->and($options[$availableVariant->id])->toContain('Updated Product Name - Fresh Variant')
        ->and($options)->not->toHaveKey($deletedVariant->id)
        ->and($options)->not->toHaveKey($inactiveProductVariant->id)
        ->and($options)->not->toHaveKey($draftProductVariant->id)
        ->and($options)->not->toHaveKey($inactiveVariant->id);
});

test('order creation rejects a product that becomes unavailable before submit', function (): void {
    $customer = Customer::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    $variant->product->update(['is_active' => false]);

    Livewire::test(CreateOrder::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'recipient_name' => $customer->name,
            'recipient_phone' => $customer->phone,
            'shipping_method' => ShippingMethod::Delivery->value,
            'shipping_address' => ShippingLocation::Sccr->value,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
            'payment_method' => PaymentMethod::Cash->value,
            'shipping_cost' => 0,
        ])
        ->call('create')
        ->assertHasFormErrors(['items.0.product_variant_id']);

    expect(Order::query()->count())->toBe(0);
});

test('shipping method controls the available shipping addresses', function (): void {
    $schema = Livewire::test(CreateOrder::class)->instance()->getSchema('form');
    $shippingMethod = $schema->getComponent('shipping_method');
    $field = $schema->getComponent('shipping_address');

    expect($shippingMethod->getOptions())->toBe([
        'delivery' => 'Delivery',
        'pickup' => 'Pickup',
    ])
        ->and($field->getOptions())->toBe([
            'SCCR' => 'SCCR',
            'Agung Putra University' => 'Agung Putra University',
            'Sains De Resto' => 'Sains De Resto',
            'Karenina Agung Resort' => 'Karenina Agung Resort',
            'Agung Farm & Garden' => 'Agung Farm & Garden',
            'Others' => 'Others',
        ]);

    $component = Livewire::test(CreateOrder::class)
        ->fillForm(['shipping_method' => ShippingMethod::Pickup->value])
        ->assertFormSet([
            'shipping_method' => ShippingMethod::Pickup,
            'shipping_address' => ShippingLocation::AgungFarmAndGarden->value,
        ])
        ->assertDontSee('Others')
        ->assertSee('Pickup location: Agung Farm &amp; Garden.', false);

    $shippingAddress = $component->instance()->getSchema('form')->getComponent('shipping_address');

    expect($shippingAddress->canSelectPlaceholder())->toBeFalse();
});

test('shipping method appears below the recipient fields', function (): void {
    $schema = Livewire::test(CreateOrder::class)->instance()->getSchema('form');
    $section = $schema->getComponents()[0];
    $components = $section->getChildComponents();

    expect($components[0]->getName())->toBe('customer_id')
        ->and($components[1])->toBeInstanceOf(Grid::class)
        ->and(array_map(fn ($field): ?string => $field->getName(), $components[1]->getChildComponents()))
        ->toBe(['recipient_name', 'recipient_phone'])
        ->and($components[2]->getName())->toBe('shipping_method')
        ->and($components[3]->getName())->toBe('shipping_address');
});

test('delivery shipping address keeps all fixed locations and an Others input', function (): void {
    $schema = Livewire::test(CreateOrder::class)->instance()->getSchema('form');
    $field = $schema->getComponent('shipping_address');

    expect($field->getOptions())->toBe([
        'SCCR' => 'SCCR',
        'Agung Putra University' => 'Agung Putra University',
        'Sains De Resto' => 'Sains De Resto',
        'Karenina Agung Resort' => 'Karenina Agung Resort',
        'Agung Farm & Garden' => 'Agung Farm & Garden',
        'Others' => 'Others',
    ]);

    Livewire::test(CreateOrder::class)
        ->fillForm(['shipping_address' => 'Others'])
        ->assertSee('Other Shipping Address');
});

test('Other shipping address requires manual input', function (): void {
    $customer = Customer::factory()->create();
    $variant = ProductVariant::factory()->create([
        'stock_available' => 10,
        'stock_reserved' => 0,
    ]);

    Livewire::test(CreateOrder::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'recipient_name' => $customer->name,
            'recipient_phone' => $customer->phone,
            'shipping_address' => 'Others',
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
            'payment_method' => PaymentMethod::Cash->value,
            'shipping_cost' => 0,
        ])
        ->call('create')
        ->assertHasFormErrors(['shipping_address_other' => 'required']);
});

test('admin can create an order with a manual Other shipping address', function (): void {
    $customer = Customer::factory()->create();
    $variant = ProductVariant::factory()->create([
        'stock_available' => 10,
        'stock_reserved' => 0,
    ]);
    $manualAddress = 'Warehouse Gate, Jalan Merdeka 10';

    Livewire::test(CreateOrder::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'recipient_name' => $customer->name,
            'recipient_phone' => $customer->phone,
            'shipping_address' => 'Others',
            'shipping_address_other' => $manualAddress,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
            'payment_method' => PaymentMethod::Cash->value,
            'shipping_cost' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $order = Order::query()->where('customer_id', $customer->id)->firstOrFail();

    expect($order->shipping_address)->toBe($manualAddress);
});

test('admin can create a pickup order at the farm location', function (): void {
    $customer = Customer::factory()->create();
    $variant = ProductVariant::factory()->create([
        'stock_available' => 10,
        'stock_reserved' => 0,
    ]);

    Livewire::test(CreateOrder::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'recipient_name' => $customer->name,
            'recipient_phone' => $customer->phone,
            'shipping_method' => ShippingMethod::Pickup->value,
            'shipping_address' => ShippingLocation::AgungFarmAndGarden->value,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
            'payment_method' => PaymentMethod::Cash->value,
            'shipping_cost' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $order = Order::query()->where('customer_id', $customer->id)->firstOrFail();

    expect($order->shipping_method)->toBe(ShippingMethod::Pickup)
        ->and($order->shipping_address)->toBe(ShippingLocation::AgungFarmAndGarden->value);
});

test('pickup orders reject a manual Other shipping address', function (): void {
    $customer = Customer::factory()->create();
    $variant = ProductVariant::factory()->create([
        'stock_available' => 10,
        'stock_reserved' => 0,
    ]);
    $manualAddress = 'Pickup point near the east gate';

    Livewire::test(CreateOrder::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'recipient_name' => $customer->name,
            'recipient_phone' => $customer->phone,
            'shipping_method' => ShippingMethod::Pickup->value,
            'shipping_address' => 'Others',
            'shipping_address_other' => $manualAddress,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
            'payment_method' => PaymentMethod::Cash->value,
            'shipping_cost' => 0,
        ])
        ->call('create')
        ->assertHasFormErrors(['shipping_address']);

    expect(Order::query()->count())->toBe(0);
});

test('notes explain when payment proof is required', function (): void {
    $schema = Livewire::test(CreateOrder::class)->instance()->getSchema('form');
    $notes = $schema->getComponent('notes');

    expect($notes->isRequired())->toBeFalse()
        ->and($notes->getChildComponents('below_content')[0]->getContent())
        ->toBe('Required when payment proof has not been uploaded.');
});

test('admin can create an order and reserve variant stock', function (): void {
    $this->travelTo('2026-09-09 10:00:00');
    Order::factory()->create(['order_number' => 'ORD-20260909-004']);

    $customer = Customer::factory()->create();
    $unit = Unit::factory()->create(['code' => 'kg']);
    $variant = ProductVariant::factory()->create([
        'unit_id' => $unit->id,
        'stock_available' => 10,
        'stock_reserved' => 0,
    ]);

    Livewire::test(CreateOrder::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'recipient_name' => $customer->name,
            'recipient_phone' => $customer->phone,
            'shipping_address' => 'SCCR',
            'items' => [
                [
                    'product_variant_id' => $variant->id,
                    'quantity' => 3,
                ],
            ],
            'payment_method' => PaymentMethod::Cash->value,
            'notes' => 'Deliver to reception.',
            'shipping_cost' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $order = Order::query()->where('customer_id', $customer->id)->firstOrFail();
    $orderItem = OrderItem::query()->whereBelongsTo($order)->firstOrFail();
    expect($order->order_number)->toBe('ORD-20260909-005')
        ->and($order->status)->toBe(OrderStatus::ReadyToProcess)
        ->and($order->stock_reservation_status)->toBe(StockReservationStatus::Reserved)
        ->and($order->shipping_address)->toBe('SCCR')
        ->and($order->payment->status)->toBe(PaymentStatus::Unpaid)
        ->and($order->payment->amount)->toBe($order->total)
        ->and($order->payment()->count())->toBe(1);

    expect($variant->refresh()->stock_available)->toBe('7.000')
        ->and($variant->stock_reserved)->toBe('3.000')
        ->and($orderItem->unit)->toBe('kg')
        ->and($orderItem->product_variant_id)->toBe($variant->id)
        ->and(StockMutation::query()
            ->where('product_variant_id', $variant->id)
            ->where('source', StockMutationSource::Order)
            ->exists())->toBeTrue();
});

test('admin order creation rejects stock held only outside the POS warehouse', function (): void {
    $customer = Customer::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_available' => 5]);
    $pos = Warehouse::defaultFulfillment();
    $otherWarehouse = Warehouse::factory()->create();

    WarehouseStock::factory()->create([
        'warehouse_id' => $pos->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 0,
    ]);
    WarehouseStock::factory()->create([
        'warehouse_id' => $otherWarehouse->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 5,
    ]);

    expect(fn (): Order => app(AdminOrderCreationService::class)->create([
        'customer_id' => $customer->id,
        'recipient_name' => $customer->name,
        'recipient_phone' => $customer->phone,
        'shipping_address' => 'SCCR',
        'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
        'payment_method' => PaymentMethod::Cash->value,
        'shipping_cost' => 0,
    ]))->toThrow(ValidationException::class, 'The selected products are not available in the POS warehouse.');

    expect(Order::query()->count())->toBe(0)
        ->and($variant->refresh()->stock_available)->toBe('5.000');
});

test('admin order creation keeps update audit fields empty until a later change', function (): void {
    $this->travelTo('2026-09-09 10:00:00');
    $customer = Customer::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_available' => 10, 'stock_reserved' => 0]);

    Livewire::test(CreateOrder::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'recipient_name' => $customer->name,
            'recipient_phone' => $customer->phone,
            'shipping_address' => 'SCCR',
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
            'payment_method' => PaymentMethod::Cash->value,
            'notes' => 'Initial order note.',
            'shipping_cost' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $order = Order::query()->where('customer_id', $customer->id)->firstOrFail();
    $createdAt = $order->created_at;

    expect($order->created_by)->toBe($this->actor->id)
        ->and($order->updated_by)->toBeNull()
        ->and($order->updated_at)->toBeNull();

    $this->travelTo('2026-09-09 10:05:00');
    $order->update(['notes' => 'Updated order note.']);

    expect($order->refresh()->created_at->equalTo($createdAt))->toBeTrue()
        ->and($order->updated_by)->toBe($this->actor->id)
        ->and($order->updated_at->equalTo(now()))->toBeTrue();
});

test('order process actions use the destination status colors', function (): void {
    $readyToProcess = Order::factory()->create([
        'status' => OrderStatus::ReadyToProcess,
        'payment_method' => PaymentMethod::Cash,
    ]);
    Payment::factory()->for($readyToProcess)->create([
        'method' => PaymentMethod::Cash,
        'status' => PaymentStatus::Unpaid,
    ]);
    $packing = Order::factory()->create(['status' => OrderStatus::Packing]);
    $shipping = Order::factory()->create(['status' => OrderStatus::Shipping]);
    $cancelable = Order::factory()->create(['status' => OrderStatus::WaitingPayment]);

    Livewire::test(ManageOrders::class)
        ->assertTableActionHasColor('advanceStatus', OrderStatus::Packing->getColor(), $readyToProcess)
        ->assertTableActionHasColor('advanceStatus', OrderStatus::Shipping->getColor(), $packing)
        ->assertTableActionHasColor('advanceStatus', OrderStatus::Done->getColor(), $shipping)
        ->assertTableActionHasColor('cancelOrder', OrderStatus::Cancelled->getColor(), $cancelable);
});

test('reservation rejects quantities greater than available stock', function (): void {
    $variant = ProductVariant::factory()->create(['stock_available' => 2]);
    $orderItem = OrderItem::factory()->create(['product_variant_id' => $variant->id]);

    expect(fn () => StockMutation::reserveForOrder($variant, 3, $orderItem->id, $this->actor->id))
        ->toThrow(InvalidArgumentException::class);

    expect($variant->refresh()->stock_available)->toBe('2.000')
        ->and($variant->stock_reserved)->toBe('0.000');
});

test('cancelling an order releases its reservation atomically', function (): void {
    $this->travelTo('2026-09-09 10:00:00');
    $cancelledAt = now();

    $variant = ProductVariant::factory()->create(['stock_available' => 7]);
    $order = Order::factory()->create(['status' => OrderStatus::WaitingPayment]);
    $orderItem = OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $variant->product_id,
        'product_variant_id' => $variant->id,
        'quantity' => 3,
    ]);

    StockMutation::reserveForOrder($variant, 3, $orderItem->id, $this->actor->id);
    $order->cancel($this->actor, 'Customer request');
    $cancelledOrder = $order->refresh();

    expect($variant->refresh()->stock_available)->toBe('7.000')
        ->and($variant->stock_reserved)->toBe('0.000')
        ->and($cancelledOrder->status)->toBe(OrderStatus::Cancelled)
        ->and($cancelledOrder->cancelled_at?->equalTo($cancelledAt))->toBeTrue()
        ->and(StockMutation::query()
            ->where('reference_id', $orderItem->id)
            ->where('quantity_change', 3)
            ->exists())->toBeTrue();
});

test('admin orders show the cancellation time in the table and exports', function (): void {
    $this->travelTo('2026-09-09 10:00:00');
    $order = Order::factory()->create(['status' => OrderStatus::WaitingPayment]);
    $order->cancel($this->actor, 'Customer request');

    Livewire::test(ManageOrders::class)
        ->assertCanSeeTableRecords([$order])
        ->assertTableColumnVisible('cancelled_at')
        ->assertTableColumnStateSet('cancelled_at', $order->cancelled_at, $order);

    expect(OrderResource::exportDefinition()->columns)->toHaveKey('cancelled_at', 'Cancelled At');
});

test('admin orders show waiting confirmation as ready to process and filter it with ready orders', function (): void {
    $waitingConfirmationOrder = Order::factory()->create(['status' => OrderStatus::WaitingConfirmation]);
    $readyToProcessOrder = Order::factory()->create(['status' => OrderStatus::ReadyToProcess]);
    $waitingPaymentOrder = Order::factory()->create(['status' => OrderStatus::WaitingPayment]);

    $component = Livewire::test(ManageOrders::class);

    $component
        ->assertTableColumnFormattedStateSet('status', 'Ready to Process', $waitingConfirmationOrder)
        ->filterTable('status', OrderStatus::ReadyToProcess->value)
        ->assertCanSeeTableRecords([$waitingConfirmationOrder, $readyToProcessOrder])
        ->assertCanNotSeeTableRecords([$waitingPaymentOrder]);

    expect($component->instance()->getTable()->getFilter('status')->getOptions())
        ->not->toHaveKey(OrderStatus::WaitingConfirmation->value);
});

test('admin orders show the current shipping method label', function (): void {
    $deliveryOrder = Order::factory()->create(['shipping_method' => ShippingMethod::Delivery]);
    $pickupOrder = Order::factory()->create(['shipping_method' => ShippingMethod::Pickup]);

    Livewire::test(ManageOrders::class)
        ->assertTableColumnVisible('shipping_method')
        ->assertTableColumnFormattedStateSet('shipping_method', 'Delivery', $deliveryOrder)
        ->assertTableColumnFormattedStateSet('shipping_method', 'Pickup', $pickupOrder);
});

test('admin orders omit the fulfillment warehouse from list detail filter and exports', function (): void {
    $order = Order::factory()->create();
    $table = Livewire::test(ManageOrders::class)->instance()->getTable();
    $detail = Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()]);
    $export = OrderResource::exportDefinition();

    expect($table->getColumn('fulfillmentWarehouse.name'))->toBeNull()
        ->and($table->getFilter('fulfillment_warehouse_id'))->toBeNull()
        ->and($detail->instance()->getSchema('infolist')->getComponent('fulfillmentWarehouse.name'))->toBeNull()
        ->and($export->columns)->not->toHaveKey('fulfillmentWarehouse.name')
        ->and($export->relationships)->not->toContain('fulfillmentWarehouse');

    $detail->assertSuccessful()->assertDontSee('Fulfillment Warehouse');
});

test('order creation form explains automatic fulfillment warehouse selection', function (): void {
    $field = Livewire::test(CreateOrder::class)
        ->instance()
        ->getSchema('form')
        ->getComponent('fulfillment_warehouse_info');

    expect($field->getLabel())->toBe('Fulfillment Warehouse');
});

test('admin orders can be searched by payment method', function (): void {
    $cashOrder = Order::factory()->create(['payment_method' => PaymentMethod::Cash]);
    $qrisOrder = Order::factory()->create(['payment_method' => PaymentMethod::Qris]);

    Livewire::test(ManageOrders::class)
        ->searchTable(PaymentMethod::Qris->value)
        ->assertCanSeeTableRecords([$qrisOrder])
        ->assertCanNotSeeTableRecords([$cashOrder]);
});

test('admin orders expose stock reservation status and filter pending orders', function (): void {
    $pendingOrder = Order::factory()->create([
        'stock_reservation_status' => StockReservationStatus::Pending,
        'stock_reservation_error' => 'Insufficient stock for retry.',
    ]);
    $reservedOrder = Order::factory()->create([
        'stock_reservation_status' => StockReservationStatus::Reserved,
    ]);

    $component = Livewire::test(ManageOrders::class)
        ->assertTableColumnFormattedStateSet('stock_reservation_status', 'Pending', $pendingOrder)
        ->assertTableActionVisible('retryReservation', $pendingOrder)
        ->filterTable('stock_reservation_status', StockReservationStatus::Pending->value)
        ->assertCanSeeTableRecords([$pendingOrder])
        ->assertCanNotSeeTableRecords([$reservedOrder]);

    expect($component->instance()->getTable()->getFilter('stock_reservation_status')->getOptions())
        ->toBe([
            StockReservationStatus::Pending->value => StockReservationStatus::Pending->getLabel(),
            StockReservationStatus::Reserved->value => StockReservationStatus::Reserved->getLabel(),
            StockReservationStatus::Released->value => StockReservationStatus::Released->getLabel(),
        ])
        ->and(OrderResource::exportDefinition()->columns)
        ->toHaveKey('stock_reservation_status', 'Stock Reservation')
        ->toHaveKey('stock_reservation_error', 'Reservation Error');
});

test('admin can retry a pending stock reservation from the orders table', function (): void {
    $warehouse = Warehouse::defaultFulfillment();
    $variant = ProductVariant::factory()->create(['stock_available' => 2, 'stock_reserved' => 0]);
    $order = Order::factory()->create([
        'fulfillment_warehouse_id' => $warehouse?->id,
        'stock_reservation_status' => StockReservationStatus::Pending,
    ]);
    OrderItem::factory()->for($order)->create([
        'product_id' => $variant->product_id,
        'product_variant_id' => $variant->id,
        'quantity' => 2,
    ]);

    Livewire::test(ManageOrders::class)
        ->callTableAction('retryReservation', $order)
        ->assertHasNoTableActionErrors();

    expect($order->refresh()->stock_reservation_status)->toBe(StockReservationStatus::Reserved)
        ->and($variant->refresh()->stock_available)->toBe('0.000')
        ->and($variant->stock_reserved)->toBe('2.000');
});

test('admin orders can filter and sort by payment status', function (): void {
    $paidOrder = Order::factory()->create();
    Payment::factory()->for($paidOrder)->create(['status' => PaymentStatus::Paid]);

    $waitingOrder = Order::factory()->create();
    Payment::factory()->for($waitingOrder)->create(['status' => PaymentStatus::WaitingConfirm]);

    Livewire::test(ManageOrders::class)
        ->sortTable('payment.status')
        ->filterTable('payment_status', PaymentStatus::Paid->value)
        ->assertCanSeeTableRecords([$paidOrder])
        ->assertCanNotSeeTableRecords([$waitingOrder]);
});

test('admin orders use the stock mutation date range filter configuration', function (): void {
    $table = Livewire::test(ManageOrders::class)->instance()->getTable();
    $filter = $table->getFilter('created_at');

    expect($filter)->not->toBeNull()
        ->and($filter->getLabel())->toBe('Date')
        ->and($filter->getDisplayFormat())->toBe('d/m/Y')
        ->and($filter->getStartPlaceholder())->toBe('dd/mm/yyyy')
        ->and($filter->getEndPlaceholder())->toBe('dd/mm/yyyy')
        ->and($filter->getFirstDayOfWeek())->toBe(1)
        ->and($filter->shouldDisplayDualCalendar())->toBeFalse()
        ->and($filter->isInline())->toBeTrue()
        ->and($filter->getLocale())->toBe('en')
        ->and($filter->getTimezone())->toBe('Asia/Jakarta')
        ->and($table->getFiltersFormWidth())->toBe(Width::Large);
});

test('order detail shows status history in columns and omits payment note', function (): void {
    $livewire = Livewire::test(CreateOrder::class)->instance();
    $schema = OrderInfolist::configure(Schema::make($livewire));
    $statusLogs = $schema->getComponent('statusLogs');
    $status = collect($statusLogs->getChildComponents())->first(fn ($component): bool => $component->getName() === 'to_status');
    $note = collect($statusLogs->getChildComponents())->first(fn ($component): bool => $component->getName() === 'note');

    expect($statusLogs->isTable())->toBeTrue()
        ->and(collect($statusLogs->getTableColumns())->map(fn ($column): string => $column->getLabel())->all())
        ->toBe(['Status', 'Changed By', 'Changed At', 'Notes'])
        ->and(array_map(fn ($column): ?string => $column->getWidth(), $statusLogs->getTableColumns()))
        ->toBe(['24%', '22%', '22%', '32%'])
        ->and($statusLogs->getTableColumns()[0]->getAlignment()->value)
        ->toBe('center')
        ->and($status)->not->toBeNull()
        ->and($status->canWrap())->toBeTrue()
        ->and($note)->not->toBeNull()
        ->and($note->canWrap())->toBeTrue()
        ->and($schema->getComponent('payment_note'))->toBeNull();
});

test('admin cannot cancel an order without a cancellation reason', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::WaitingPayment]);

    Livewire::test(ManageOrders::class)
        ->callTableAction('cancelOrder', $order)
        ->assertHasTableActionErrors(['reason' => 'required']);

    expect($order->refresh()->status)->toBe(OrderStatus::WaitingPayment);
});

test('admin can cancel an order with a cancellation reason', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::WaitingPayment]);

    Livewire::test(ManageOrders::class)
        ->callTableAction('cancelOrder', $order, ['reason' => 'Customer request'])
        ->assertHasNoTableActionErrors();

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->cancellation_reason)->toBe('Customer request');
});
