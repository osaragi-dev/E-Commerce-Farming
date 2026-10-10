<?php

use App\Enums\OrderStatus;
use App\Enums\ShippingMethod;
use App\Models\CompanyProfile;
use App\Models\Customer;
use App\Models\Order;

function makeInvoiceOrder(Customer $customer): Order
{
    return Order::query()->create([
        'order_number' => 'FRM-TEST-'.uniqid(),
        'customer_id' => $customer->id,
        'status' => OrderStatus::WaitingPayment,
        'shipping_method' => ShippingMethod::Delivery,
        'recipient_name' => $customer->name,
        'recipient_phone' => '08123456789',
        'shipping_address' => 'Jl. Contoh',
        'shipping_cost' => 15000,
        'subtotal' => 50000,
        'total' => 65000,
    ]);
}

test('guest cannot access invoice', function (): void {
    $order = makeInvoiceOrder(Customer::factory()->registered()->create());

    $this->get(route('orders.invoice', $order))->assertRedirect(route('login'));
});

test('customer can view their own invoice', function (): void {
    $customer = Customer::factory()->registered()->create();
    $order = makeInvoiceOrder($customer);

    $this->actingAs($customer, 'customer');

    $this->get(route('orders.invoice', $order))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('invoice view displays method-specific shipping information', function (ShippingMethod $shippingMethod, string $locationLabel, string $address): void {
    $customer = Customer::factory()->registered()->create();
    $order = makeInvoiceOrder($customer);
    $order->forceFill([
        'shipping_method' => $shippingMethod,
        'shipping_address' => $address,
    ])->save();

    $html = view('pdf.invoice', [
        'order' => $order->load(['items', 'payment', 'customer']),
        'company' => CompanyProfile::current(),
    ])->render();

    expect($html)
        ->toContain('Shipping Method', $shippingMethod->getLabel(), $locationLabel, e($address));
})->with([
    'delivery' => [ShippingMethod::Delivery, 'Delivery Address', 'SCCR'],
    'pickup' => [ShippingMethod::Pickup, 'Pickup Location', 'Agung Farm & Garden'],
]);

test('other customer cannot view someone elses invoice', function (): void {
    $owner = Customer::factory()->registered()->create();
    $order = makeInvoiceOrder($owner);

    $otherCustomer = Customer::factory()->registered()->create();

    $this->actingAs($otherCustomer, 'customer');

    $this->get(route('orders.invoice', $order))->assertForbidden();
});
