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
use App\Models\Warehouse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('public');
    Warehouse::query()->where('code', 'WH-SCCR')->update(['is_pos' => true]);
    $this->actor = User::factory()->create();
    $this->actor->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($this->actor);
});

test('admin bank transfers support optional proof rejection reupload and confirmation', function (bool $withProof): void {
    $customer = Customer::factory()->create();
    $variant = ProductVariant::factory()->create(['price' => 25000, 'stock_available' => 10]);
    $data = [
        'customer_id' => $customer->id, 'recipient_name' => $customer->name, 'recipient_phone' => $customer->phone,
        'shipping_method' => 'delivery', 'shipping_address' => 'SCCR', 'shipping_cost' => '5.000',
        'payment_method' => 'bank_transfer', 'notes' => 'Bank transfer test',
        'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
    ];
    if ($withProof) {
        $data['payment_proof'] = UploadedFile::fake()->image('transfer.png');
    }

    Livewire::test(CreateOrder::class)->fillForm($data)->call('create')->assertHasNoFormErrors();

    $payment = Payment::sole();
    expect($payment->method)->toBe(PaymentMethod::BankTransfer);
    expect($payment->status)->toBe($withProof ? PaymentStatus::WaitingConfirm : PaymentStatus::Unpaid);
    expect($payment->amount)->toBe('30000.00');
    expect($payment->proof_upload_deadline_at)->toBeNull();
    expect($variant->refresh()->stock_reserved)->toBe('1.000');

    if (! $withProof) {
        expect(fn () => $payment->confirm($this->actor))->toThrow(RuntimeException::class);
        Livewire::test(ManagePayments::class)->callTableAction('verifyPayment', $payment)
            ->assertHasTableActionErrors(['payment_proof' => 'required']);
        $payment->submitProof('first.png', $this->actor);
    }
    $payment->reject($this->actor, 'Image is unreadable');
    Livewire::test(ManagePayments::class)->callTableAction('uploadPaymentProof', $payment->refresh(), [
        'payment_proof' => UploadedFile::fake()->image('replacement.png'),
    ])->assertHasNoTableActionErrors();
    Livewire::test(ManagePayments::class)->callTableAction('verifyPayment', $payment->refresh())
        ->assertHasNoTableActionErrors();
    expect($payment->refresh()->status)->toBe(PaymentStatus::Paid);
    expect($payment->proofs()->count())->toBe(2);
})->with([false, true]);

test('expired bank transfer reupload windows reject uploads and cancel the order', function (): void {
    $order = Order::factory()->create(['payment_method' => PaymentMethod::BankTransfer, 'status' => OrderStatus::ReadyToProcess]);
    $payment = Payment::factory()->for($order)->create(['method' => PaymentMethod::BankTransfer, 'status' => PaymentStatus::Unpaid]);
    $payment->submitProof('transfer.png', $this->actor);
    $payment->reject($this->actor, 'Unreadable proof');
    $payment->currentProof()->update(['resubmission_deadline_at' => now()->subMinute()]);

    expect(fn () => $payment->submitProof('late.png', $this->actor))->toThrow(RuntimeException::class);
    $this->artisan('payments:cancel-expired-proof-reuploads')->assertSuccessful();

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled);
});

test('bank transfer upload and confirmation require verification permission', function (): void {
    $order = Order::factory()->create(['payment_method' => PaymentMethod::BankTransfer, 'status' => OrderStatus::ReadyToProcess]);
    $payment = Payment::factory()->for($order)->create(['method' => PaymentMethod::BankTransfer, 'status' => PaymentStatus::Unpaid]);
    $viewer = User::factory()->create();
    foreach (['ViewAny:Payment', 'View:Payment'] as $permission) {
        $viewer->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($viewer);

    Livewire::test(ManagePayments::class)->assertTableActionHidden('verifyPayment', $payment)
        ->assertTableActionHidden('uploadPaymentProof', $payment);
    expect($payment->refresh()->status)->toBe(PaymentStatus::Unpaid);
});
