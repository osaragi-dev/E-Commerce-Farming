<?php

use App\Enums\CustomerType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ShippingMethod;
use App\Enums\StockReservationStatus;
use App\Livewire\Public\Checkout;
use App\Livewire\Public\OrderConfirmation;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Sector;
use App\Models\StockOpnameSession;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Notifications\StockMovementRecommendationForAdmins;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function checkoutSetup(int $stock = 10, int $price = 25000): array
{
    Warehouse::query()->where('code', 'WH-SCCR')->update(['is_pos' => true]);
    $sector = Sector::factory()->create(['is_active' => true]);
    $category = Category::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);
    $unit = Unit::factory()->create(['code' => 'BOX']);

    $product = Product::factory()->create(['category_id' => $category->id, 'is_active' => true]);

    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'unit_id' => $unit->id,
        'price' => $price,
        'is_active' => true,
        'stock_available' => $stock,
    ]);

    $customer = Customer::factory()->registered()->create();

    $cart = Cart::forCustomer($customer);
    $cartItem = $cart->addItem($variant, 2);

    return [$customer, $variant, $cartItem];
}

test('guest cannot access checkout', function (): void {
    $this->get(route('checkout'))->assertRedirect(route('login'));
});

test('checkout keeps the form and order summary in one centered column', function (string $origin): void {
    [$customer, $variant, $cartItem] = checkoutSetup();
    $this->actingAs($customer, 'customer');
    $query = $origin === 'buy-now'
        ? ['variant' => $variant->id, 'qty' => 1]
        : ['items' => $cartItem->id];

    Livewire::withQueryParams($query)
        ->test(Checkout::class)
        ->assertSee('class="wrap max-w-[640px]"', false)
        ->assertSeeInOrder(['Recipient Info', 'Recipient Name', 'Recipient Phone Number', 'Payment Method', 'Notes (optional)', 'Order Summary'])
        ->assertSee('class="cart-summary mt-5"', false)
        ->assertDontSee('commerce-layout')
        ->assertDontSee('commerce-summary');
})->with(['selected cart items' => 'cart', 'Buy Now' => 'buy-now']);

test('qris checkout keeps payment proof above notes and order summary', function (): void {
    [$customer, $variant] = checkoutSetup();
    $this->actingAs($customer, 'customer');

    Livewire::withQueryParams(['variant' => $variant->id, 'qty' => 1])
        ->test(Checkout::class)
        ->set('paymentMethod', 'qris')
        ->assertSeeInOrder(['Payment Method', 'Pay with QRIS', 'Upload Payment Proof (optional)', 'Notes (optional)', 'Order Summary'])
        ->assertSee('class="cart-summary mt-5"', false);
});

test('public checkout rejects Invoice even for invoice customers', function (): void {
    [$customer, $variant, $cartItem] = checkoutSetup();
    $customer->update(['customer_type' => CustomerType::Invoice]);
    $this->actingAs($customer, 'customer');

    Livewire::withQueryParams(['items' => $cartItem->id])->test(Checkout::class)
        ->set('recipientName', $customer->name)->set('recipientPhone', $customer->phone)
        ->set('shippingAddress', 'SCCR')->set('paymentMethod', 'invoice')
        ->call('placeOrder')->assertHasErrors(['paymentMethod']);

    expect(Order::count())->toBe(0)->and(Payment::count())->toBe(0)
        ->and($variant->refresh()->stock_reserved)->toBe('0.000');
});

test('checkout autofills customer contact details and accepts official locations', function (): void {
    [$customer, $variant, $cartItem] = checkoutSetup();
    $customer->update([
        'phone' => '08123456789',
        'address' => 'Agung Putra University',
    ]);

    $this->actingAs($customer, 'customer');

    Livewire::withQueryParams(['items' => $cartItem->id])
        ->test(Checkout::class)
        ->assertSet('recipientPhone', '08123456789')
        ->assertSet('shippingAddress', 'Agung Putra University');
});

test('checkout offers delivery and pickup shipping methods', function (): void {
    [$customer, $variant, $cartItem] = checkoutSetup();

    $this->actingAs($customer, 'customer')
        ->get(route('checkout', ['items' => $cartItem->id]))
        ->assertSee('Shipping Method')
        ->assertSee('value="delivery"', false)
        ->assertSee('value="pickup"', false)
        ->assertSee('value="Others"', false)
        ->assertSee('Select a delivery location');
});

test('checkout selects the farm location for pickup', function (): void {
    [$customer, $variant, $cartItem] = checkoutSetup();
    $this->actingAs($customer, 'customer');

    Livewire::withQueryParams(['items' => $cartItem->id])
        ->test(Checkout::class)
        ->set('shippingMethod', ShippingMethod::Pickup->value)
        ->assertSet('shippingAddress', Checkout::PICKUP_LOCATION)
        ->assertDontSee('Select a pickup location')
        ->assertDontSee('value="Others"', false)
        ->assertSee('Pickup location: Agung Farm &amp; Garden', false)
        ->assertSee(Checkout::PICKUP_LOCATION_MAPS_ADDRESS)
        ->assertSeeHtml('href="'.e(Checkout::pickupLocationMapsUrl()).'"')
        ->assertSee('checkout-pickup-map-link')
        ->assertSeeHtml('target="_blank"')
        ->assertSeeHtml('rel="noopener noreferrer"')
        ->set('shippingMethod', ShippingMethod::Delivery->value)
        ->assertSet('shippingAddress', '')
        ->assertSee('Select a delivery location')
        ->assertDontSee(Checkout::PICKUP_LOCATION_MAPS_ADDRESS)
        ->assertDontSee('google.com/maps/search/', false);
});

test('checkout requires a manual address when Others is selected', function (): void {
    [$customer, $variant, $cartItem] = checkoutSetup();
    $this->actingAs($customer, 'customer');

    Livewire::withQueryParams(['items' => $cartItem->id])
        ->test(Checkout::class)
        ->set('recipientName', 'Budi')
        ->set('recipientPhone', '08123456789')
        ->set('shippingAddress', Checkout::OTHER_LOCATION)
        ->set('paymentMethod', 'cash')
        ->call('placeOrder')
        ->assertHasErrors(['shippingAddressOther']);
});

test('customer can place an order with a manual shipping address', function (string $shippingMethodValue, string $manualAddress): void {
    [$customer, $variant, $cartItem] = checkoutSetup();
    $this->actingAs($customer, 'customer');

    Livewire::withQueryParams(['items' => $cartItem->id])
        ->test(Checkout::class)
        ->set('recipientName', 'Budi')
        ->set('recipientPhone', '08123456789')
        ->set('shippingMethod', $shippingMethodValue)
        ->set('shippingAddress', Checkout::OTHER_LOCATION)
        ->set('shippingAddressOther', $manualAddress)
        ->set('paymentMethod', 'cash')
        ->call('placeOrder')
        ->assertHasNoErrors()
        ->assertRedirect();

    $order = Order::query()->firstOrFail();

    expect($order->shipping_method)->toBe(ShippingMethod::from($shippingMethodValue))
        ->and($order->shipping_address)->toBe($manualAddress);
})->with([
    'delivery' => [ShippingMethod::Delivery->value, 'Jl. Kebun Raya No. 10'],
    'pickup' => [ShippingMethod::Pickup->value, 'Pickup point near the east gate'],
]);

test('checkout prefers profile contact details and clears unsupported addresses', function (string $address, string $expectedAddress): void {
    [$customer, $variant, $cartItem] = checkoutSetup();
    $customer->update([
        'phone' => '08222222222',
        'address' => $address,
    ]);

    $this->actingAs($customer, 'customer');

    Livewire::withQueryParams(['items' => $cartItem->id])
        ->test(Checkout::class)
        ->assertSet('recipientPhone', '08222222222')
        ->assertSet('shippingAddress', $expectedAddress);
})->with([
    'official profile address' => ['SCCR', 'SCCR'],
    'unsupported profile address' => ['Outside delivery area', ''],
]);

test('checkout header retains the greeting and account dropdown actions', function (): void {
    [$customer, $variant, $cartItem] = checkoutSetup();

    $this->actingAs($customer, 'customer')->get(route('checkout', ['items' => $cartItem->id]))
        ->assertSee('Hi, '.$customer->name)
        ->assertSee('My Account')
        ->assertSee('My Orders')
        ->assertSee('Log Out')
        ->assertSee(route('account'))
        ->assertSee(route('orders.history'));
});

test('buy now preserves the requested quantity on checkout', function (): void {
    [$customer, $variant, $cartItem] = checkoutSetup(stock: 10, price: 25000);
    $this->actingAs($customer, 'customer');

    Livewire::withQueryParams(['variant' => $variant->id, 'qty' => 2])
        ->test(Checkout::class)
        ->assertSet('buyNowQuantity', 2)
        ->assertSee('50.000');
});

test('buy now quantity is limited to POS stock', function (): void {
    [$customer, $variant] = checkoutSetup(stock: 10);
    $firstWarehouse = Warehouse::defaultFulfillment();
    $secondWarehouse = Warehouse::factory()->create();
    WarehouseStock::factory()->create(['warehouse_id' => $firstWarehouse->id, 'product_variant_id' => $variant->id, 'stock_available' => 3]);
    WarehouseStock::factory()->create(['warehouse_id' => $secondWarehouse->id, 'product_variant_id' => $variant->id, 'stock_available' => 7]);
    $this->actingAs($customer, 'customer');

    Livewire::withQueryParams(['variant' => $variant->id, 'qty' => 10])
        ->test(Checkout::class)
        ->assertSet('cartItems', fn ($items) => $items->sole()->quantity === '3.000')
        ->assertSet('subtotal', 75000.0);
});

test('buy now excludes stock held only in inactive warehouses', function (): void {
    [$customer, $variant] = checkoutSetup(stock: 10);
    $activeWarehouse = Warehouse::defaultFulfillment();
    $inactiveWarehouse = Warehouse::factory()->create(['is_active' => false]);
    WarehouseStock::factory()->create(['warehouse_id' => $activeWarehouse->id, 'product_variant_id' => $variant->id, 'stock_available' => 0]);
    WarehouseStock::factory()->create(['warehouse_id' => $inactiveWarehouse->id, 'product_variant_id' => $variant->id, 'stock_available' => 10]);
    $this->actingAs($customer, 'customer');

    Livewire::withQueryParams(['variant' => $variant->id, 'qty' => 1])
        ->test(Checkout::class)
        ->assertSet('cartItems', fn ($items) => $items->isEmpty());
});

test('customer can place an order from selected cart items', function (): void {
    $this->travelTo('2026-09-09 10:00:00');
    [$customer, $variant, $cartItem] = checkoutSetup(stock: 10, price: 25000);

    $this->actingAs($customer, 'customer');

    Livewire::withQueryParams(['items' => $cartItem->id])
        ->test(Checkout::class)
        ->set('recipientName', 'Budi')
        ->set('recipientPhone', '08123456789')
        ->set('shippingAddress', 'SCCR')
        ->set('paymentMethod', 'cash')
        ->call('placeOrder')
        ->assertRedirect();

    $this->assertDatabaseHas('orders', [
        'recipient_name' => 'Budi',
        'subtotal' => 50000,
        'payment_method' => 'cash',
    ]);

    $this->assertDatabaseHas('order_items', [
        'product_variant_id' => $variant->id,
        'quantity' => 2,
    ]);

    $order = Order::query()->firstOrFail();
    expect($order->order_number)->toBe('ORD-20260909-001')
        ->and($order->status)->toBe(OrderStatus::ReadyToProcess)
        ->and($order->shipping_method)->toBe(ShippingMethod::Delivery)
        ->and($order->created_by)->toBeNull()
        ->and($order->updated_by)->toBeNull()
        ->and($order->updated_at)->toBeNull()
        ->and($order->payment->status)->toBe(PaymentStatus::Unpaid)
        ->and($order->payment->amount)->toBe($order->total)
        ->and($order->payment()->count())->toBe(1)
        ->and($order->payment->proofs()->count())->toBe(0);

    $this->assertDatabaseMissing('cart_items', ['id' => $cartItem->id]);
});

test('checkout assigns the default fulfillment warehouse and reserves its stock', function (): void {
    [$customer, $variant, $cartItem] = checkoutSetup(stock: 10);
    $this->actingAs($customer, 'customer');

    Livewire::withQueryParams(['items' => $cartItem->id])
        ->test(Checkout::class)
        ->set('recipientName', 'Budi')
        ->set('recipientPhone', '08123456789')
        ->set('shippingAddress', 'SCCR')
        ->set('paymentMethod', 'cash')
        ->call('placeOrder')
        ->assertRedirect();

    $order = Order::query()->firstOrFail();
    $warehouse = Warehouse::defaultFulfillment();
    $stock = WarehouseStock::query()
        ->where('warehouse_id', $warehouse?->id)
        ->where('product_variant_id', $variant->id)
        ->firstOrFail();

    expect($order->fulfillment_warehouse_id)->toBe($warehouse?->id)
        ->and($stock->stock_available)->toBe('8.000')
        ->and($stock->stock_reserved)->toBe('2.000');
});

test('checkout rejects stock held only outside the POS warehouse', function (): void {
    [$customer, $variant, $cartItem] = checkoutSetup(stock: 7);
    $pos = Warehouse::defaultFulfillment();
    $fallback = Warehouse::factory()->create(['code' => 'WH-FALLBACK-CHECKOUT']);

    WarehouseStock::factory()->create([
        'warehouse_id' => $pos->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 0,
    ]);
    WarehouseStock::factory()->create([
        'warehouse_id' => $fallback->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 7,
    ]);

    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    Notification::fake();

    $this->actingAs($customer, 'customer');

    Livewire::withQueryParams(['items' => $cartItem->id])
        ->test(Checkout::class)
        ->set('recipientName', 'Budi')
        ->set('recipientPhone', '08123456789')
        ->set('shippingAddress', 'SCCR')
        ->set('paymentMethod', 'cash')
        ->call('placeOrder')
        ->assertHasErrors(['itemIds']);

    $stock = WarehouseStock::query()
        ->where('warehouse_id', $fallback->id)
        ->where('product_variant_id', $variant->id)
        ->firstOrFail();

    expect(Order::query()->count())->toBe(0)
        ->and($stock->stock_available)->toBe('7.000')
        ->and($stock->stock_reserved)->toBe('0.000')
        ->and($variant->refresh()->stock_available)->toBe('7.000');

    Notification::assertNotSentTo($admin, StockMovementRecommendationForAdmins::class);
});

test('customer can place a pickup order at the farm location', function (): void {
    [$customer, $variant, $cartItem] = checkoutSetup();

    $this->actingAs($customer, 'customer');

    Livewire::withQueryParams(['items' => $cartItem->id])
        ->test(Checkout::class)
        ->set('recipientName', 'Budi')
        ->set('recipientPhone', '08123456789')
        ->set('shippingMethod', ShippingMethod::Pickup->value)
        ->set('shippingAddress', 'SCCR')
        ->set('paymentMethod', 'cash')
        ->call('placeOrder')
        ->assertHasNoErrors()
        ->assertRedirect();

    $order = Order::query()->firstOrFail();

    expect($order->shipping_method)->toBe(ShippingMethod::Pickup)
        ->and($order->shipping_address)->toBe(Checkout::PICKUP_LOCATION);
});

test('checkout fails when stock is no longer sufficient', function (): void {
    [$customer, $variant, $cartItem] = checkoutSetup(stock: 1, price: 25000);

    $this->actingAs($customer, 'customer');

    Livewire::withQueryParams(['items' => $cartItem->id])
        ->test(Checkout::class)
        ->set('recipientName', 'Budi')
        ->set('recipientPhone', '08123456789')
        ->set('shippingAddress', 'SCCR')
        ->set('paymentMethod', 'cash')
        ->call('placeOrder')
        ->assertHasErrors(['itemIds']);

    $this->assertDatabaseMissing('orders', ['recipient_name' => 'Budi']);
    $this->assertDatabaseHas('cart_items', ['id' => $cartItem->id]);
});

test('only the owning customer can view their order confirmation page', function (): void {
    [$customer, $variant, $cartItem] = checkoutSetup();

    $this->actingAs($customer, 'customer');

    Livewire::withQueryParams(['items' => $cartItem->id])
        ->test(Checkout::class)
        ->set('recipientName', 'Budi')
        ->set('recipientPhone', '08123456789')
        ->set('shippingAddress', 'SCCR')
        ->set('paymentMethod', 'cash')
        ->call('placeOrder');

    $order = Order::query()->first();

    $otherCustomer = Customer::factory()->registered()->create();

    $this->actingAs($otherCustomer, 'customer');

    Livewire::test(OrderConfirmation::class, ['order' => $order])
        ->assertForbidden();
});
test('buy now cash creates an unpaid payment without removing cart items', function (): void {
    [$customer, $variant, $cartItem] = checkoutSetup();
    $this->actingAs($customer, 'customer');

    Livewire::withQueryParams(['variant' => $variant->id, 'qty' => 1])
        ->test(Checkout::class)
        ->set('recipientName', 'Budi')
        ->set('recipientPhone', '08123456789')
        ->set('shippingAddress', 'SCCR')
        ->set('paymentMethod', 'cash')
        ->call('placeOrder')
        ->assertHasNoErrors()
        ->assertRedirect();

    $order = Order::query()->firstOrFail();
    expect($order->status)->toBe(OrderStatus::ReadyToProcess)
        ->and($order->payment->status)->toBe(PaymentStatus::Unpaid)
        ->and($order->payment->amount)->toBe($order->total)
        ->and($order->payment()->count())->toBe(1);
    $this->assertDatabaseHas('cart_items', ['id' => $cartItem->id]);
    $this->get(route('orders.show', $order))->assertSuccessful()->assertSee('Unpaid');
});

test('order timeline uses the same fulfillment stages for each payment method', function (string $method): void {
    $customer = Customer::factory()->registered()->create();
    $order = Order::factory()->for($customer)->create(['payment_method' => $method, 'status' => OrderStatus::ReadyToProcess]);

    $this->actingAs($customer, 'customer')->get(route('orders.show', $order))->assertSeeInOrder(['Waiting', 'Packing', 'Shipping', 'Done']);
})->with([
    'cash' => ['cash'],
    'qris' => ['qris'],
]);

test('public qris checkout may be placed without proof and stays waiting for payment', function (): void {
    $this->travelTo('2026-09-26 10:00:00');
    PaymentSetting::current()->update(['proof_resubmission_hours' => 24]);

    [$customer, $variant, $cartItem] = checkoutSetup();
    $this->actingAs($customer, 'customer');

    Livewire::withQueryParams(['items' => $cartItem->id])
        ->test(Checkout::class)
        ->set('recipientName', 'Budi')
        ->set('recipientPhone', '08123456789')
        ->set('shippingAddress', 'SCCR')
        ->set('paymentMethod', 'qris')
        ->call('placeOrder')
        ->assertHasNoErrors()
        ->assertRedirect();

    $order = Order::firstOrFail();

    expect($order->status)->toBe(OrderStatus::WaitingPayment)
        ->and($order->payment->status)->toBe(PaymentStatus::Unpaid)
        ->and($order->payment->proof_upload_deadline_at?->format('Y-m-d H:i'))
        ->toBe('2026-09-27 10:00')
        ->and($order->payment->proofs()->count())->toBe(0)
        ->and($order->canStartPacking())->toBeFalse()
        ->and($order->nextStatus())->toBeNull();
});

test('public checkout creates a pending reservation while the system is frozen', function (): void {
    [$customer, $variant, $cartItem] = checkoutSetup();
    $warehouse = Warehouse::defaultFulfillment();
    $session = StockOpnameSession::factory()->create(['warehouse_id' => $warehouse?->id]);
    $session->freeze();

    $this->actingAs($customer, 'customer');

    Livewire::withQueryParams(['items' => $cartItem->id])
        ->test(Checkout::class)
        ->set('recipientName', 'Budi')
        ->set('recipientPhone', '08123456789')
        ->set('shippingAddress', 'SCCR')
        ->set('paymentMethod', PaymentMethod::Cash->value)
        ->call('placeOrder')
        ->assertHasNoErrors()
        ->assertRedirect();

    $order = Order::query()->firstOrFail();

    expect($order->stock_reservation_status)->toBe(StockReservationStatus::Pending)
        ->and($order->status)->toBe(OrderStatus::ReadyToProcess)
        ->and($order->payment->status)->toBe(PaymentStatus::Unpaid)
        ->and($order->canStartPacking())->toBeFalse()
        ->and($variant->refresh()->stock_available)->toBe('10.000')
        ->and($variant->stock_reserved)->toBe('0.000')
        ->and(WarehouseStock::query()
            ->where('warehouse_id', $warehouse?->id)
            ->where('product_variant_id', $variant->id)
            ->exists())->toBeFalse();
});

test('pending reservations reduce the stock available to later public checkouts during freeze', function (): void {
    [$customer, $variant, $cartItem] = checkoutSetup(stock: 2);
    $warehouse = Warehouse::defaultFulfillment();
    $session = StockOpnameSession::factory()->create(['warehouse_id' => $warehouse?->id]);
    $session->freeze();

    $this->actingAs($customer, 'customer');

    Livewire::withQueryParams(['items' => $cartItem->id])
        ->test(Checkout::class)
        ->set('recipientName', 'Budi')
        ->set('recipientPhone', '08123456789')
        ->set('shippingAddress', 'SCCR')
        ->set('paymentMethod', PaymentMethod::Cash->value)
        ->call('placeOrder')
        ->assertHasNoErrors();

    $secondCartItem = Cart::forCustomer($customer)->addItem($variant, 2);

    Livewire::withQueryParams(['items' => $secondCartItem->id])
        ->test(Checkout::class)
        ->set('recipientName', 'Budi')
        ->set('recipientPhone', '08123456789')
        ->set('shippingAddress', 'SCCR')
        ->set('paymentMethod', PaymentMethod::Cash->value)
        ->call('placeOrder')
        ->assertHasErrors(['itemIds']);

    expect(Order::query()->count())->toBe(1)
        ->and($variant->refresh()->stock_available)->toBe('2.000')
        ->and($variant->stock_reserved)->toBe('0.000');
});

test('checkout does not use another warehouse when POS stock is pending during a freeze', function (): void {
    [$customer, $variant, $cartItem] = checkoutSetup(stock: 4);
    $pos = Warehouse::defaultFulfillment();
    $fallback = Warehouse::factory()->create(['code' => 'WH-FALLBACK-FROZEN']);

    foreach ([$pos, $fallback] as $warehouse) {
        WarehouseStock::factory()->create([
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'stock_available' => 2,
        ]);
    }

    StockOpnameSession::factory()->create(['warehouse_id' => $pos->id])->freeze();
    $this->actingAs($customer, 'customer');

    $placeOrder = function (string $itemId): mixed {
        return Livewire::withQueryParams(['items' => $itemId])
            ->test(Checkout::class)
            ->set('recipientName', 'Budi')
            ->set('recipientPhone', '08123456789')
            ->set('shippingAddress', 'SCCR')
            ->set('paymentMethod', PaymentMethod::Cash->value)
            ->call('placeOrder');
    };

    $placeOrder($cartItem->id)->assertHasNoErrors();
    $placeOrder(Cart::forCustomer($customer)->addItem($variant, 2)->id)
        ->assertHasErrors(['itemIds']);

    expect(Order::query()->orderBy('id')->pluck('fulfillment_warehouse_id')->all())
        ->toBe([$pos->id]);
    expect(Order::query()->where('stock_reservation_status', StockReservationStatus::Pending->value)->count())->toBe(1);
});

test('unfreezing processes a pending public reservation synchronously', function (): void {
    [$customer, $variant, $cartItem] = checkoutSetup();
    $warehouse = Warehouse::defaultFulfillment();
    $session = StockOpnameSession::factory()->create(['warehouse_id' => $warehouse?->id]);
    $session->freeze();

    $this->actingAs($customer, 'customer');

    Livewire::withQueryParams(['items' => $cartItem->id])
        ->test(Checkout::class)
        ->set('recipientName', 'Budi')
        ->set('recipientPhone', '08123456789')
        ->set('shippingAddress', 'SCCR')
        ->set('paymentMethod', PaymentMethod::Cash->value)
        ->call('placeOrder')
        ->assertHasNoErrors();

    $order = Order::query()->firstOrFail();
    $admin = User::factory()->create();

    $session->unfreeze($admin->id);

    expect($order->refresh()->stock_reservation_status)->toBe(StockReservationStatus::Reserved)
        ->and($order->stock_reservation_error)->toBeNull()
        ->and($order->canStartPacking())->toBeTrue()
        ->and($variant->refresh()->stock_available)->toBe('8.000')
        ->and($variant->stock_reserved)->toBe('2.000')
        ->and(WarehouseStock::query()
            ->where('warehouse_id', $warehouse?->id)
            ->where('product_variant_id', $variant->id)
            ->value('stock_reserved'))->toBe('2.000');
});

test('public qris checkout with proof waits for confirmation', function (): void {
    Storage::fake('public');
    [$customer, $variant, $cartItem] = checkoutSetup();
    $this->actingAs($customer, 'customer');
    $checkout = Livewire::withQueryParams(['items' => $cartItem->id])
        ->test(Checkout::class)
        ->set('recipientName', 'Budi')
        ->set('recipientPhone', '08123456789')
        ->set('shippingAddress', 'SCCR')
        ->set('paymentMethod', 'qris');

    $checkout->set('proofFile', UploadedFile::fake()->image('qris.png'))
        ->call('placeOrder')->assertHasNoErrors()->assertRedirect();

    $order = Order::firstOrFail();
    $proof = $order->payment->currentProof();

    expect($order->status)->toBe(OrderStatus::WaitingConfirmation)
        ->and($order->payment->status)->toBe(PaymentStatus::WaitingConfirm)
        ->and($order->canStartPacking())->toBeTrue()
        ->and($proof)->not->toBeNull()
        ->and($proof->file_path)->toBe('payment-proofs/'.$order->order_number.'.png');

    Storage::disk('public')->assertExists($proof->file_path);
});

test('customer can upload an initial qris proof from order details', function (): void {
    Storage::fake('public');

    $customer = Customer::factory()->registered()->create();
    $order = Order::factory()->for($customer)->create([
        'payment_method' => PaymentMethod::Qris,
        'status' => OrderStatus::WaitingPayment,
    ]);
    $payment = Payment::factory()->for($order)->create([
        'method' => PaymentMethod::Qris,
        'status' => PaymentStatus::Unpaid,
        'proof_upload_deadline_at' => now()->addDay(),
    ]);

    $this->actingAs($customer, 'customer');

    Livewire::test(OrderConfirmation::class, ['order' => $order])
        ->assertSee('Payment Proof Required')
        ->assertSee('Upload Payment Proof')
        ->assertSee('payment-proof-upload')
        ->set('proofFile', UploadedFile::fake()->image('initial-proof.png'))
        ->call('submitProof')
        ->assertRedirect(route('orders.history'));

    expect($payment->refresh()->status)->toBe(PaymentStatus::WaitingConfirm)
        ->and($payment->proof_upload_deadline_at)->toBeNull()
        ->and($payment->currentProof())->not->toBeNull()
        ->and($order->refresh()->status)->toBe(OrderStatus::WaitingConfirmation);

    Storage::disk('public')->assertExists('payment-proofs/'.$order->order_number.'.png');
});

test('expired initial qris proof deadline cancels the order and releases stock', function (): void {
    $this->travelTo('2026-09-26 10:00:00');
    PaymentSetting::current()->update(['proof_resubmission_hours' => 24]);

    [$customer, $variant, $cartItem] = checkoutSetup(stock: 10);
    $this->actingAs($customer, 'customer');

    Livewire::withQueryParams(['items' => $cartItem->id])
        ->test(Checkout::class)
        ->set('recipientName', 'Budi')
        ->set('recipientPhone', '08123456789')
        ->set('shippingAddress', 'SCCR')
        ->set('paymentMethod', 'qris')
        ->call('placeOrder')
        ->assertRedirect();

    $order = Order::firstOrFail();

    $this->travelTo('2026-09-27 11:00:00');

    $this->artisan('payments:cancel-expired-proof-reuploads')
        ->expectsOutput('Cancelled 1 expired payment proof order(s).')
        ->assertSuccessful();

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->payment->status)->toBe(PaymentStatus::UnpaidCancel)
        ->and($variant->refresh()->stock_available)->toBe('10.000')
        ->and($variant->stock_reserved)->toBe('0.000');
});

test('customer re-uploaded payment proof uses the order number as filename', function (): void {
    Storage::fake('public');

    $customer = Customer::factory()->registered()->create();
    $order = Order::factory()->for($customer)->create([
        'payment_method' => PaymentMethod::Qris,
        'status' => OrderStatus::WaitingConfirmation,
    ]);
    $payment = Payment::factory()->for($order)->create([
        'method' => PaymentMethod::Qris,
        'status' => PaymentStatus::WaitingConfirm,
    ]);
    $oldProof = PaymentProof::factory()->for($payment)->create();
    $payment->reject(User::factory()->create(), 'Proof is not readable.');

    $this->actingAs($customer, 'customer');

    Livewire::test(OrderConfirmation::class, ['order' => $order])
        ->set('proofFile', UploadedFile::fake()->image('replacement.png'))
        ->call('submitProof')
        ->assertRedirect(route('orders.history'));

    $expectedPath = 'payment-proofs/'.$order->order_number.'.png';

    expect($payment->refresh()->status)->toBe(PaymentStatus::WaitingConfirm)
        ->and($payment->currentProof()->file_path)->toBe($expectedPath)
        ->and($payment->proofs()->count())->toBe(2)
        ->and($oldProof->refresh()->is_current)->toBeFalse();

    Storage::disk('public')->assertExists($expectedPath);
});

test('customer sees the payment proof resubmission deadline', function (): void {
    $this->travelTo('2026-09-17 10:00:00');

    $customer = Customer::factory()->registered()->create();
    $order = Order::factory()->for($customer)->create([
        'payment_method' => PaymentMethod::Qris,
        'status' => OrderStatus::WaitingPayment,
    ]);
    $payment = Payment::factory()->for($order)->create([
        'method' => PaymentMethod::Qris,
        'status' => PaymentStatus::Rejected,
        'rejection_reason' => 'Invalid payment proof',
    ]);
    PaymentProof::factory()->for($payment)->create([
        'status' => PaymentStatus::Rejected,
        'resubmission_deadline_at' => now()->addDay(),
    ]);

    $this->actingAs($customer, 'customer')
        ->get(route('orders.show', $order))
        ->assertSuccessful()
        ->assertSeeHtml('role="alert"')
        ->assertSeeHtml('class="payment-rejection-alert')
        ->assertSeeHtml('href="#payment-proof-upload"')
        ->assertSeeInOrder(['Payment Rejected', 'Upload a new payment proof before', 'Products', 'Delivery & Pickup', 'Payment Method', 'Upload Replacement Payment Proof', 'Continue Shopping'])
        ->assertSeeHtml('id="payment-proof-upload"')
        ->assertSee('Invalid payment proof')
        ->assertSee('Upload a new payment proof before')
        ->assertSee('18-09-2026 10:00');
});

test('customer cannot upload a proof after the resubmission deadline', function (): void {
    Storage::fake('public');

    $customer = Customer::factory()->registered()->create();
    $order = Order::factory()->for($customer)->create([
        'payment_method' => PaymentMethod::Qris,
        'status' => OrderStatus::WaitingPayment,
    ]);
    $payment = Payment::factory()->for($order)->create([
        'method' => PaymentMethod::Qris,
        'status' => PaymentStatus::Rejected,
        'rejection_reason' => 'Invalid payment proof',
    ]);
    PaymentProof::factory()->for($payment)->create([
        'status' => PaymentStatus::Rejected,
        'resubmission_deadline_at' => now()->subMinute(),
    ]);

    $this->actingAs($customer, 'customer')
        ->get(route('orders.show', $order))
        ->assertSuccessful()
        ->assertSee('Payment Rejected')
        ->assertSee('The payment proof upload window has expired. This order has been cancelled.')
        ->assertDontSee('href="#payment-proof-upload"', false);

    Livewire::test(OrderConfirmation::class, ['order' => $order])
        ->set('proofFile', UploadedFile::fake()->image('late-replacement.png'))
        ->call('submitProof')
        ->assertRedirect(route('orders.history'));

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Rejected)
        ->and($payment->proofs()->count())->toBe(1);

    Storage::disk('public')->assertMissing('payment-proofs/'.$order->order_number.'.png');
});
