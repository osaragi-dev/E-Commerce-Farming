<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ShippingMethod;
use App\Livewire\Public\OrderConfirmation;
use App\Livewire\Public\OrderHistory;
use App\Models\Cart as CartModel;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ProductVariant;
use Livewire\Livewire;

function makeOrderForUser(Customer $customer, OrderStatus $status): Order
{
    return Order::query()->create([
        'order_number' => 'FRM-TEST-'.uniqid(),
        'customer_id' => $customer->id,
        'status' => $status,
        'shipping_method' => ShippingMethod::Delivery,
        'recipient_name' => $customer->name,
        'recipient_phone' => '08123456789',
        'shipping_address' => 'Jl. Contoh',
        'shipping_cost' => 15000,
        'subtotal' => 50000,
        'total' => 65000,
    ]);
}

test('guest cannot access order history', function (): void {
    $this->get(route('orders.history'))->assertRedirect(route('login'));
});

test('customer only sees their own orders', function (): void {
    $user = Customer::factory()->registered()->create();
    $otherUser = Customer::factory()->registered()->create();

    $myOrder = makeOrderForUser($user, OrderStatus::WaitingPayment);
    $otherOrder = makeOrderForUser($otherUser, OrderStatus::WaitingPayment);

    $this->actingAs($user, 'customer');

    $this->get(route('orders.history'))
        ->assertOk()
        ->assertSee($myOrder->order_number)
        ->assertDontSee($otherOrder->order_number);
});

test('status tab filters orders correctly', function (): void {
    $user = Customer::factory()->registered()->create();

    $waitingOrders = collect([
        OrderStatus::WaitingPayment,
        OrderStatus::WaitingConfirmation,
        OrderStatus::ReadyToProcess,
    ])->map(fn (OrderStatus $status): Order => makeOrderForUser($user, $status));
    $packingOrder = makeOrderForUser($user, OrderStatus::Packing);
    $doneOrder = makeOrderForUser($user, OrderStatus::Done);

    $this->actingAs($user, 'customer');

    $response = $this->get(route('orders.history', ['status' => 'waiting']))
        ->assertOk();

    foreach ($waitingOrders as $waitingOrder) {
        $response->assertSee($waitingOrder->order_number);
    }

    $response->assertDontSee($doneOrder->order_number);

    $this->get(route('orders.history', ['status' => 'done']))
        ->assertOk()
        ->assertSee($doneOrder->order_number)
        ->assertDontSee($waitingOrders->first()->order_number);

    foreach (['waiting_confirmation', 'processing'] as $legacyTab) {
        $this->get(route('orders.history', ['status' => $legacyTab]))
            ->assertOk()
            ->assertSee($waitingOrders->last()->order_number)
            ->assertDontSee($doneOrder->order_number);
    }

    $this->get(route('orders.history', ['status' => 'picking']))
        ->assertOk()
        ->assertSee($packingOrder->order_number)
        ->assertDontSee($waitingOrders->last()->order_number)
        ->assertDontSee($doneOrder->order_number);
});

test('order history uses customer-facing status tabs and badges', function (): void {
    $user = Customer::factory()->registered()->create();

    $waitingOrder = makeOrderForUser($user, OrderStatus::ReadyToProcess);
    Payment::factory()->for($waitingOrder)->create([
        'method' => PaymentMethod::Cash,
        'status' => PaymentStatus::Unpaid,
    ]);

    $this->actingAs($user, 'customer')
        ->get(route('orders.history'))
        ->assertSeeInOrder(['All', 'Waiting', 'Packing', 'Shipping', 'Done', 'Cancelled'])
        ->assertSee($waitingOrder->order_number)
        ->assertSee('Waiting')
        ->assertDontSee('Waiting - Pending Payment')
        ->assertDontSee('Waiting Payment')
        ->assertDontSee('Waiting Confirmation')
        ->assertDontSee('Processing')
        ->assertDontSee('Picking');
});

test('rejected payments show an alert beside the order status badge', function (): void {
    $user = Customer::factory()->registered()->create();
    $order = makeOrderForUser($user, OrderStatus::WaitingPayment);
    Payment::factory()->for($order)->create([
        'status' => PaymentStatus::Rejected,
        'rejection_reason' => 'Payment proof is not readable.',
    ]);

    $this->actingAs($user, 'customer')
        ->get(route('orders.history'))
        ->assertSee($order->order_number)
        ->assertSee('Waiting')
        ->assertSee('Rejected')
        ->assertSee('Payment status: Rejected');
});

test('order detail does not expose payment method change controls', function (): void {
    $user = Customer::factory()->registered()->create();
    $order = makeOrderForUser($user, OrderStatus::WaitingPayment);
    $order->forceFill(['payment_method' => PaymentMethod::Cash])->save();
    Payment::factory()->for($order)->create([
        'method' => PaymentMethod::Cash,
        'status' => PaymentStatus::Unpaid,
    ]);

    $this->actingAs($user, 'customer')
        ->get(route('orders.show', $order))
        ->assertSuccessful()
        ->assertDontSee('Change Payment Method')
        ->assertDontSee('Update Payment Method');
});

test('order detail does not show the invoice button', function (): void {
    $user = Customer::factory()->registered()->create();
    $order = makeOrderForUser($user, OrderStatus::WaitingPayment);

    $this->actingAs($user, 'customer')
        ->get(route('orders.show', $order))
        ->assertSuccessful()
        ->assertDontSee('View Invoice');
});

test('order detail stacks products shipping payment and actions in reading order', function (): void {
    $customer = Customer::factory()->registered()->create();
    $order = makeOrderForUser($customer, OrderStatus::WaitingPayment);
    $order->forceFill(['payment_method' => PaymentMethod::Cash])->save();
    OrderItem::factory()->for($order)->create();
    Payment::factory()->for($order)->create([
        'method' => PaymentMethod::Cash,
        'status' => PaymentStatus::Unpaid,
    ]);

    $this->actingAs($customer, 'customer')
        ->get(route('orders.show', $order))
        ->assertSuccessful()
        ->assertSeeHtml('class="wrap order-detail-shell"')
        ->assertSeeInOrder(['Order progress', 'Products', 'Delivery & Pickup', 'Payment Method', 'Cancel Order', 'Continue Shopping']);
});

test('order detail shows method-specific shipping information', function (ShippingMethod $shippingMethod, string $locationLabel, string $address): void {
    $user = Customer::factory()->registered()->create();
    $order = makeOrderForUser($user, OrderStatus::WaitingPayment);
    $order->forceFill([
        'shipping_method' => $shippingMethod,
        'shipping_address' => $address,
    ])->save();

    $this->actingAs($user, 'customer')
        ->get(route('orders.show', $order))
        ->assertSuccessful()
        ->assertSee('Shipping Method')
        ->assertSee($shippingMethod->getLabel())
        ->assertSee($locationLabel)
        ->assertSee($address);
})->with([
    'delivery' => [ShippingMethod::Delivery, 'Delivery Address', 'SCCR'],
    'pickup' => [ShippingMethod::Pickup, 'Pickup Location', 'Agung Farm & Garden'],
]);

test('customer can cancel a waiting order from the order detail page', function (): void {
    $user = Customer::factory()->registered()->create();
    $order = makeOrderForUser($user, OrderStatus::WaitingPayment);

    $this->actingAs($user, 'customer');

    Livewire::test(OrderConfirmation::class, ['order' => $order])
        ->call('cancelOrder')
        ->assertRedirect(route('orders.history'));

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled);
});

test('buy again selects only items restored from the selected order', function (): void {
    $user = Customer::factory()->registered()->create();
    $order = makeOrderForUser($user, OrderStatus::Done);
    $variant = ProductVariant::factory()->create(['stock_available' => 10, 'is_active' => true]);
    OrderItem::factory()->for($order)->create([
        'product_id' => $variant->product_id,
        'product_variant_id' => $variant->id,
        'quantity' => 2,
    ]);

    $cart = CartModel::forCustomer($user);
    $otherVariant = ProductVariant::factory()->create(['stock_available' => 10, 'is_active' => true]);
    $otherCartItem = $cart->addItem($otherVariant, 1);

    $this->actingAs($user, 'customer');

    Livewire::test(OrderHistory::class)
        ->call('buyAgain', $order->id)
        ->assertRedirect(route('cart', [
            'selected' => CartModel::forCustomer($user)->items()->where('product_variant_id', $variant->id)->value('id'),
        ]));

    expect($otherCartItem->refresh()->quantity)->toBe('1.000');
});
