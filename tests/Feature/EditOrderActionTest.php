<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $user = User::factory()->sso()->create();
    $user->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($user);
});

test('order edit selects invoice for the selected invoice customer without a phone', function (bool $changeCustomer): void {
    $customer = Customer::factory()->invoice()->create();
    $order = Order::factory()->for($changeCustomer ? Customer::factory()->create() : $customer)->create([
        'payment_method' => PaymentMethod::Qris,
        'status' => OrderStatus::WaitingPayment,
    ]);
    $payment = Payment::factory()->for($order)->create([
        'method' => PaymentMethod::Qris,
        'status' => PaymentStatus::Unpaid,
        'proof_upload_deadline_at' => now()->addDay(),
    ]);

    Livewire::test(ManageOrders::class)
        ->callTableAction('edit', $order, [
            'customer_id' => $customer->id,
            'method' => 'invoice',
            'recipient_phone' => null,
        ])
        ->assertHasNoTableActionErrors();

    expect($order->refresh()->payment_method)->toBe(PaymentMethod::Invoice)
        ->and($order->customer_id)->toBe($customer->id)
        ->and($order->recipient_phone)->toBeNull()
        ->and($order->status)->toBe(OrderStatus::ReadyToProcess);
    expect($payment->refresh()->method)->toBe(PaymentMethod::Invoice)
        ->and($payment->status)->toBe(PaymentStatus::Unpaid)
        ->and($payment->proof_upload_deadline_at)->toBeNull()
        ->and($payment->invoice_id)->toBeNull();
})->with([false, true]);

test('order edit rejects invoice for a regular customer', function (): void {
    $order = Order::factory()->for(Customer::factory())->create(['payment_method' => PaymentMethod::Cash]);
    $payment = Payment::factory()->for($order)->create(['method' => PaymentMethod::Cash, 'status' => PaymentStatus::Unpaid]);

    Livewire::test(ManageOrders::class)
        ->callTableAction('edit', $order, ['method' => 'invoice'])
        ->assertHasTableActionErrors(['method']);

    expect($order->refresh()->payment_method)->toBe(PaymentMethod::Cash);
    expect($payment->refresh()->method)->toBe(PaymentMethod::Cash);
});

test('cancelled orders hide edit even for super administrators', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::Cancelled]);

    Livewire::test(ManageOrders::class)->assertTableActionHidden('edit', $order);
});

test('an open edit form cannot change an order cancelled after it was opened', function (): void {
    $order = Order::factory()->for(Customer::factory())->create(['status' => OrderStatus::WaitingPayment, 'recipient_phone' => '081234567890']);
    $page = Livewire::test(ManageOrders::class)->mountTableAction('edit', $order)
        ->setTableActionData(['recipient_phone' => '089999999999']);

    $order->cancel(auth()->user(), 'Customer cancelled');
    $page->callMountedTableAction();

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->fresh()->recipient_phone)->toBe('081234567890');
});
