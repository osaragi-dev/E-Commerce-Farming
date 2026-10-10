<?php

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Filament\Resources\Payments\PaymentResource;
use App\Livewire\Public\Checkout;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

test('customer-edited recipient details remain the order snapshot across admin surfaces', function (): void {
    $customerName = 'Account Customer';
    $customerPhone = '08111111111';
    $recipientName = 'Delivery Recipient';
    $recipientPhone = '08999999999';

    $customer = Customer::factory()->registered()->create([
        'name' => $customerName,
        'phone' => $customerPhone,
    ]);
    $variant = ProductVariant::factory()->create([
        'price' => 25000,
        'stock_available' => 10,
    ]);
    $cartItem = Cart::forCustomer($customer)->addItem($variant, 1);

    $this->actingAs($customer, 'customer');

    Livewire::withQueryParams(['items' => $cartItem->id])
        ->test(Checkout::class)
        ->set('recipientName', $recipientName)
        ->set('recipientPhone', $recipientPhone)
        ->set('shippingAddress', 'SCCR')
        ->set('paymentMethod', 'cash')
        ->call('placeOrder')
        ->assertRedirect();

    $order = Order::query()->firstOrFail();
    $payment = $order->payment()->firstOrFail();

    expect($order->recipient_name)->toBe($recipientName)
        ->and($order->recipient_phone)->toBe($recipientPhone)
        ->and($customer->refresh()->name)->toBe($customerName)
        ->and($customer->phone)->toBe($customerPhone);

    $administrator = User::factory()->sso()->create();
    $administrator->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($administrator, 'web');
    Livewire::test(ManageOrders::class)
        ->assertCanSeeTableRecords([$order])
        ->assertTableColumnStateSet('recipient_name', $recipientName, $order);

    $orderModal = Livewire::test(ManageOrders::class)
        ->mountTableAction('view', $order)
        ->assertActionMounted()
        ->getMountedActionModalHtml();

    expect($orderModal)
        ->toContain('Recipient Name', 'Recipient Phone Number', $recipientName, $recipientPhone)
        ->not->toContain($customerName, $customerPhone);

    Livewire::test(ManagePayments::class)
        ->assertCanSeeTableRecords([$payment])
        ->assertTableColumnStateSet('order.recipient_name', $recipientName, $payment);

    $paymentModal = Livewire::test(ManagePayments::class)
        ->mountTableAction('view', $payment)
        ->assertActionMounted()
        ->getMountedActionModalHtml();

    expect($paymentModal)
        ->toContain('Recipient Name', 'Recipient Phone Number', $recipientName, $recipientPhone)
        ->not->toContain($customerName, $customerPhone);

    expect(OrderResource::exportDefinition()->columns)
        ->toHaveKey('recipient_name', 'Customer')
        ->toHaveKey('recipient_phone', 'Recipient Phone Number')
        ->not->toHaveKey('customer.name');

    expect(PaymentResource::exportDefinition()->columns)
        ->toHaveKey('order.recipient_name', 'Customer')
        ->toHaveKey('order.recipient_phone', 'Recipient Phone Number')
        ->not->toHaveKey('order.customer.name');

    $this->get(route('admin.orders.receipt', $order))
        ->assertSuccessful()
        ->assertSee($recipientName)
        ->assertSee($recipientPhone)
        ->assertDontSee($customerName);
});
