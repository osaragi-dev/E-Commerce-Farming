<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ShippingMethod;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

test('payment details open on a dedicated page from the payments list', function (): void {
    $user = User::factory()->sso()->create();
    $user->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($user);
    $payment = Payment::factory()->create();
    $url = PaymentResource::getUrl('view', ['record' => $payment]);

    $this->get(PaymentResource::getUrl())->assertOk()->assertSee($url, false);
    $this->get($url)->assertOk()
        ->assertSee($payment->payment_number)
        ->assertSee($payment->order->order_number)
        ->assertSee('Payment Information')
        ->assertSee('Payment Proof')
        ->assertSee('Payment History')
        ->assertSee('Order Information');
});

test('payment detail page requires payment view permission', function (): void {
    $payment = Payment::factory()->create();
    $url = PaymentResource::getUrl('view', ['record' => $payment]);
    $this->get($url)->assertRedirect();

    $user = User::factory()->sso()->create();
    $user->assignRole(Role::findOrCreate('payment_reader', 'web'));
    Permission::findOrCreate('ViewAny:Payment', 'web');
    $user->givePermissionTo('ViewAny:Payment');
    $this->actingAs($user)->get($url)->assertForbidden();

    Permission::findOrCreate('View:Payment', 'web');
    $user->givePermissionTo('View:Payment');
    $this->get($url)->assertOk();
});

test('payment edit corrects unpaid details and keeps the order method in sync', function (): void {
    $user = User::factory()->sso()->create();
    $user->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($user);
    $payment = Payment::factory()->create(['status' => PaymentStatus::Unpaid, 'method' => PaymentMethod::Cash]);
    $payment->order->update(['payment_method' => PaymentMethod::Cash, 'status' => OrderStatus::ReadyToProcess]);

    Livewire::test(ViewOrder::class, ['record' => $payment->order_id])
        ->callAction('edit', data: ['method' => 'bank_transfer', 'amount' => '75.000,00', 'status' => 'unpaid'])
        ->assertHasNoActionErrors();

    expect($payment->fresh()->method)->toBe(PaymentMethod::BankTransfer)
        ->and($payment->fresh()->amount)->toBe($payment->amount)
        ->and($payment->fresh()->updated_by)->toBe($user->id)
        ->and($payment->order->fresh()->payment_method)->toBe(PaymentMethod::BankTransfer);
});

test('order edit cannot change cash payment status', function (): void {
    $user = User::factory()->sso()->create();
    $user->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($user);
    $payment = Payment::factory()->create(['status' => PaymentStatus::Unpaid, 'method' => PaymentMethod::Cash]);
    $payment->order->update(['payment_method' => PaymentMethod::Cash, 'status' => OrderStatus::ReadyToProcess]);

    Livewire::test(ViewOrder::class, ['record' => $payment->order_id])
        ->callAction('edit', data: ['status' => 'paid'])
        ->assertHasNoActionErrors();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Unpaid)
        ->and($payment->fresh()->verified_by)->toBeNull()
        ->and($payment->fresh()->verified_at)->toBeNull();
});

test('payment viewers cannot edit payments', function (): void {
    $user = User::factory()->sso()->create();
    $user->assignRole(Role::findOrCreate('payment_reader', 'web'));
    foreach (['ViewAny:Payment', 'View:Payment'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
    }
    $this->actingAs($user);
    $payment = Payment::factory()->create();
    Livewire::test(ViewPayment::class, ['record' => $payment->id])->assertActionDoesNotExist('edit');
});

test('order edit ignores payment amount and status changes for transfers', function (): void {
    $user = User::factory()->sso()->create();
    $user->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($user);
    $payment = Payment::factory()->create(['status' => PaymentStatus::Unpaid, 'method' => PaymentMethod::BankTransfer, 'amount' => 100000]);
    $payment->order->update(['payment_method' => PaymentMethod::BankTransfer, 'status' => OrderStatus::ReadyToProcess]);

    Livewire::test(ViewOrder::class, ['record' => $payment->order_id])
        ->callAction('edit', data: ['amount' => '75.000,00', 'status' => 'paid'])
        ->assertHasNoActionErrors();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Unpaid)
        ->and($payment->fresh()->amount)->toBe('100000.00');
});

test('payment editing permission does not grant confirmation permission', function (): void {
    $user = User::factory()->sso()->create();
    $user->assignRole(Role::findOrCreate('payment_editor', 'web'));
    foreach (['ViewAny:Order', 'View:Order', 'Update:Order', 'ViewAny:Payment', 'View:Payment', 'Update:Payment'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
    }
    $this->actingAs($user);
    $payment = Payment::factory()->create(['status' => PaymentStatus::Unpaid, 'method' => PaymentMethod::Cash]);
    $payment->order->update(['payment_method' => PaymentMethod::Cash, 'status' => OrderStatus::ReadyToProcess]);

    Livewire::test(ViewOrder::class, ['record' => $payment->order_id])
        ->callAction('edit', data: ['status' => 'paid'])->assertHasNoActionErrors();
    expect($payment->fresh()->status)->toBe(PaymentStatus::Unpaid);
});

test('order edit changes customer and recipient details without changing payment', function (): void {
    $user = User::factory()->sso()->create();
    $user->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($user);
    $payment = Payment::factory()->create();
    $customer = Customer::factory()->create();
    $before = $payment->fresh()->getAttributes();

    Livewire::test(ViewOrder::class, ['record' => $payment->order_id])
        ->callAction('edit', data: ['customer_id' => $customer->id, 'recipient_phone' => $customer->phone])->assertHasNoActionErrors();

    expect($payment->order->fresh()->customer_id)->toBe($customer->id)
        ->and($payment->order->fresh()->recipient_name)->toBe($customer->name)
        ->and($payment->order->fresh()->recipient_phone)->toBe($customer->phone)
        ->and($payment->fresh()->getAttributes())->toBe($before);
});

test('order edit updates shipping location and phone without changing payment', function (): void {
    $user = User::factory()->sso()->create();
    $user->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($user);
    $payment = Payment::factory()->create();
    $before = $payment->fresh()->getAttributes();

    Livewire::test(ViewOrder::class, ['record' => $payment->order_id])
        ->callAction('edit', data: [
            'shipping_method' => 'pickup',
            'shipping_address' => 'SCCR',
            'recipient_phone' => '081234567890',
        ])->assertHasNoActionErrors();

    expect($payment->order->fresh()->shipping_method)->toBe(ShippingMethod::Pickup)
        ->and($payment->order->fresh()->shipping_address)->toBe('SCCR')
        ->and($payment->order->fresh()->recipient_phone)->toBe('081234567890')
        ->and($payment->fresh()->getAttributes())->toBe($before);
});
