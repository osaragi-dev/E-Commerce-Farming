<?php

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\Role;
use App\Models\User;
use App\Notifications\NewOrderForAdmins;
use App\Notifications\OrderStatusChangedForCustomer;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
});

test('new orders notify active admin users', function (): void {
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));

    $customer = Customer::factory()->registered()->create();
    $order = Order::factory()->create(['customer_id' => $customer->id]);

    NewOrderForAdmins::send($order);

    Notification::assertSentTo($admin, NewOrderForAdmins::class);
    Notification::assertNotSentTo($customer, NewOrderForAdmins::class);
});

test('new order notifications link admins to the orders page', function (): void {
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    $order = Order::factory()->create();

    $notification = new NewOrderForAdmins($order);
    $data = $notification->toDatabase($admin);

    expect($data['actions'][0]['label'])->toBe('View order')
        ->and($data['actions'][0]['url'])->toBe(
            OrderResource::getUrl('index'),
        )
        ->and($data['actions'][0]['shouldMarkAsRead'])->toBeTrue();
});

test('order status changes notify the customer', function (): void {
    $customer = Customer::factory()->registered()->create();
    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'status' => OrderStatus::WaitingPayment,
    ]);

    OrderStatusLog::query()->create([
        'order_id' => $order->id,
        'from_status' => OrderStatus::WaitingPayment,
        'to_status' => OrderStatus::WaitingConfirmation,
        'created_at' => now(),
    ]);

    Notification::assertSentTo($customer, OrderStatusChangedForCustomer::class, function (OrderStatusChangedForCustomer $notification): bool {
        return $notification->to === OrderStatus::WaitingConfirmation;
    });
});
