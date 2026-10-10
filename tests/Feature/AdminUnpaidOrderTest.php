<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('public');
    $role = Role::findOrCreate('super_admin', 'web');
    $this->actor = User::factory()->create();
    $this->actor->assignRole($role);
    $this->actingAs($this->actor);
});

function adminUnpaidOrderData(string $method, ?string $notes = 'Deliver after 5 PM.'): array
{
    $customer = Customer::factory()->create();
    $variant = ProductVariant::factory()->create(['price' => 25000, 'stock_available' => 10, 'stock_reserved' => 0]);

    return [
        'customer_id' => $customer->id,
        'recipient_name' => $customer->name,
        'recipient_phone' => $customer->phone,
        'shipping_address' => 'SCCR',
        'items' => [['product_variant_id' => $variant->id, 'quantity' => 2]],
        'payment_method' => $method,
        'shipping_cost' => 5000,
        'notes' => $notes,
    ];
}

test('admin orders allow missing notes', function (string $method): void {
    Livewire::test(CreateOrder::class)
        ->fillForm(adminUnpaidOrderData($method, null))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Order::firstOrFail()->notes)->toBeNull();
})->with([
    'cash' => 'cash',
    'qris' => 'qris',
]);

test('qris payment proof makes notes optional', function (): void {
    $data = adminUnpaidOrderData('qris', null);
    $data['payment_proof'] = UploadedFile::fake()->image('proof.png');

    Livewire::test(CreateOrder::class)
        ->fillForm($data)
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Order::firstOrFail()->notes)->toBeNull();
});

test('admin sets payment status from optional qris proof', function (string $method, bool $withProof): void {
    $data = adminUnpaidOrderData($method);
    if ($withProof) {
        $data['payment_proof'] = UploadedFile::fake()->image('proof.png');
    }

    Livewire::test(CreateOrder::class)->fillForm($data)->call('create')->assertHasNoFormErrors();

    $order = Order::firstOrFail();
    $hasQrisProof = $method === PaymentMethod::Qris->value && $withProof;

    $expectedOrderStatus = $hasQrisProof
        ? OrderStatus::WaitingConfirmation
        : OrderStatus::ReadyToProcess;

    expect($order->status)->toBe($expectedOrderStatus)
        ->and($order->notes)->toBe('Deliver after 5 PM.')
        ->and($order->payment->status)->toBe($hasQrisProof ? PaymentStatus::WaitingConfirm : PaymentStatus::Unpaid)
        ->and($order->payment->amount)->toBe('55000.00')
        ->and($order->payment->method->value)->toBe($method)
        ->and($order->payment()->count())->toBe(1)
        ->and($order->payment->proofs()->count())->toBe($withProof ? 1 : 0)
        ->and($order->payment->proof_upload_deadline_at)->toBeNull();
    if ($withProof) {
        $proof = $order->payment->currentProof();

        expect($proof->status)->toBe(PaymentStatus::WaitingConfirm)
            ->and($proof->file_path)->toBe('payment-proofs/'.$order->order_number.'.png');
        Storage::disk('public')->assertExists($proof->file_path);
    }
})->with(['cash' => ['cash', false], 'qris no proof' => ['qris', false], 'qris with proof' => ['qris', true]]);

test('qris unpaid proof waits for confirmation before payment is confirmed', function (OrderStatus $status): void {
    $order = Order::factory()->create(['payment_method' => PaymentMethod::Qris, 'status' => $status]);
    $payment = Payment::factory()->for($order)->create(['method' => PaymentMethod::Qris, 'status' => PaymentStatus::Unpaid]);

    expect(fn () => $payment->confirm($this->actor))->toThrow(RuntimeException::class);
    $payment->submitProof('proof.png', $this->actor);
    expect($payment->refresh()->status)->toBe(PaymentStatus::WaitingConfirm)
        ->and($order->refresh()->status)->toBe($status);

    $payment->confirm($this->actor);
    expect($payment->refresh()->status)->toBe(PaymentStatus::Paid)
        ->and($payment->currentProof()->status)->toBe(PaymentStatus::Paid)
        ->and($payment->verified_by)->toBe($this->actor->id)
        ->and($order->refresh()->status)->toBe($status);
    expect(fn () => $payment->confirm($this->actor))->toThrow(RuntimeException::class);
})->with([OrderStatus::ReadyToProcess, OrderStatus::Packing, OrderStatus::Shipping, OrderStatus::Done]);

test('qris confirmation modal requires proof and accepts an upload', function (): void {
    $order = Order::factory()->create(['payment_method' => PaymentMethod::Qris, 'status' => OrderStatus::Shipping]);
    $payment = Payment::factory()->for($order)->create(['method' => PaymentMethod::Qris, 'status' => PaymentStatus::Unpaid]);

    Livewire::test(ManagePayments::class)
        ->assertTableActionVisible('verifyPayment', $payment)
        ->callTableAction('verifyPayment', $payment)
        ->assertHasTableActionErrors(['payment_proof']);
    expect($payment->refresh()->status)->toBe(PaymentStatus::Unpaid);

    Livewire::test(ManagePayments::class)
        ->callTableAction('verifyPayment', $payment, ['payment_proof' => UploadedFile::fake()->image('receipt.png')])
        ->assertHasNoTableActionErrors();
    expect($payment->refresh()->status)->toBe(PaymentStatus::Paid)
        ->and($order->refresh()->status)->toBe(OrderStatus::Shipping);
    Storage::disk('public')->assertExists($payment->currentProof()->file_path);
});

test('qris confirmation preserves existing proof even when replacement data is supplied', function (bool $replace): void {
    $order = Order::factory()->create(['payment_method' => PaymentMethod::Qris, 'status' => OrderStatus::Done]);
    $payment = Payment::factory()->for($order)->create(['method' => PaymentMethod::Qris, 'status' => PaymentStatus::Unpaid]);
    $oldProof = $payment->submitProof('original.png', $this->actor);
    $data = $replace ? ['payment_proof' => UploadedFile::fake()->image('replacement.png')] : [];

    Livewire::test(ManagePayments::class)->callTableAction('verifyPayment', $payment, $data)->assertHasNoTableActionErrors();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Paid)
        ->and($payment->proofs()->count())->toBe(1)
        ->and($oldProof->refresh()->is_current)->toBeTrue()
        ->and($payment->currentProof()->id)->toBe($oldProof->id)
        ->and($payment->currentProof()->status)->toBe(PaymentStatus::Paid)
        ->and($order->refresh()->status)->toBe(OrderStatus::Done);
})->with(['reuse' => false, 'replace' => true]);

test('admin qris unpaid order can be processed or cancelled with correct stock', function (bool $cancel): void {
    $data = adminUnpaidOrderData('qris');
    Livewire::test(CreateOrder::class)->fillForm($data)->call('create')->assertHasNoFormErrors();
    $order = Order::firstOrFail();
    $variant = ProductVariant::findOrFail($data['items'][0]['product_variant_id']);

    if ($cancel) {
        $order->cancel($this->actor);
        expect($order->payment->status)->toBe(PaymentStatus::UnpaidCancel)
            ->and($order->payment->canStartConfirmation())->toBeFalse()
            ->and($variant->refresh()->stock_available)->toBe('10.000')
            ->and($variant->stock_reserved)->toBe('0.000');
    } else {
        expect($order->refresh()->status)->toBe(OrderStatus::ReadyToProcess)
            ->and($order->canStartPacking())->toBeTrue()
            ->and($order->nextStatus())->toBe(OrderStatus::Packing)
            ->and($order->payment->status)->toBe(PaymentStatus::Unpaid)
            ->and($order->payment->proof_upload_deadline_at)->toBeNull()
            ->and($variant->refresh()->stock_available)->toBe('8.000')
            ->and($variant->stock_reserved)->toBe('2.000')
            ->and($variant->stock_out)->toBe('0.000');

        $order->advanceStatus($this->actor);

        expect($order->refresh()->status)->toBe(OrderStatus::Packing);
    }
})->with(['complete' => false, 'cancel' => true]);

test('qris unpaid confirmation is hidden without verification permission', function (): void {
    $user = User::factory()->create();
    Permission::findOrCreate('ViewAny:Payment', 'web');
    $user->givePermissionTo('ViewAny:Payment');
    $this->actingAs($user);
    $order = Order::factory()->create(['payment_method' => PaymentMethod::Qris, 'status' => OrderStatus::ReadyToProcess]);
    $payment = Payment::factory()->for($order)->create(['method' => PaymentMethod::Qris, 'status' => PaymentStatus::Unpaid]);

    Livewire::test(ManagePayments::class)->assertTableActionHidden('verifyPayment', $payment);
    expect($payment->refresh()->status)->toBe(PaymentStatus::Unpaid);
});
