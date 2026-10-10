<?php

use App\Enums\CustomerType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RecordStatus;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Livewire\Public\OrderConfirmation;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use App\Services\AdminOrderCreationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actor = User::factory()->create();
    $this->actor->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($this->actor);
});

function invoicePaymentOrderData(Customer $customer, ProductVariant $variant): array
{
    return [
        'customer_id' => $customer->id,
        'recipient_name' => $customer->name,
        'recipient_phone' => $customer->phone,
        'shipping_method' => 'delivery',
        'shipping_address' => 'SCCR',
        'shipping_cost' => '5000',
        'payment_method' => 'invoice',
        'items' => [['product_variant_id' => $variant->id, 'quantity' => 2]],
    ];
}

test('back office creates an unpaid invoice order ready for fulfillment', function (): void {
    $customer = Customer::factory()->invoice()->create();
    $variant = ProductVariant::factory()->create(['price' => 25000, 'stock_available' => 10, 'stock_reserved' => 0]);

    Livewire::test(CreateOrder::class)
        ->fillForm(invoicePaymentOrderData($customer, $variant))
        ->call('create')->assertHasNoFormErrors();

    $order = Order::sole();
    expect($order->status)->toBe(OrderStatus::ReadyToProcess)
        ->and($order->payment_method)->toBe(PaymentMethod::Invoice)
        ->and($order->total)->toBe('55000.00')
        ->and($order->payment->status)->toBe(PaymentStatus::Unpaid)
        ->and($order->payment->verified_at)->toBeNull()
        ->and($order->payment->proof_upload_deadline_at)->toBeNull()
        ->and($order->canStartPacking())->toBeTrue()
        ->and($variant->refresh()->stock_reserved)->toBe('2.000');
});

test('invoice payment rejects ineligible customers before reserving stock', function (array $attributes, bool $deleted): void {
    $customer = Customer::factory()->invoice()->create($attributes);
    if ($deleted) {
        $customer->delete();
    }
    $variant = ProductVariant::factory()->create(['stock_available' => 10, 'stock_reserved' => 0]);

    expect(fn () => app(AdminOrderCreationService::class)->create(invoicePaymentOrderData($customer, $variant)))
        ->toThrow(ValidationException::class);

    expect(Order::count())->toBe(0)
        ->and(Payment::count())->toBe(0)
        ->and($variant->refresh()->stock_reserved)->toBe('0.000');
})->with([
    'regular' => [['customer_type' => CustomerType::Regular], false],
    'inactive' => [['is_active' => false], false],
    'draft' => [['record_status' => RecordStatus::Draft], false],
    'deleted' => [[], true],
]);

test('invoice orders cannot be marked paid during creation', function (): void {
    $customer = Customer::factory()->invoice()->create();
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);

    expect(fn () => app(AdminOrderCreationService::class)->create(invoicePaymentOrderData($customer, $variant), confirmPayment: true))
        ->toThrow(ValidationException::class, 'Invoice orders must be created with an unpaid payment.');

    expect(Order::count())->toBe(0)->and(Payment::count())->toBe(0);
});

test('invoice enum input cannot bypass customer eligibility', function (): void {
    $customer = Customer::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    $data = invoicePaymentOrderData($customer, $variant);
    $data['payment_method'] = PaymentMethod::Invoice;

    expect(fn () => app(AdminOrderCreationService::class)->create($data))
        ->toThrow(ValidationException::class, 'Invoice payment requires an Invoice customer.');

    expect(Order::count())->toBe(0);
});

test('invoice payment can be confirmed without QRIS proof after fulfillment', function (OrderStatus $status): void {
    $order = Order::factory()->for(Customer::factory()->invoice())->create(['payment_method' => PaymentMethod::Invoice, 'status' => $status]);
    $payment = Payment::factory()->for($order)->create(['method' => PaymentMethod::Invoice, 'status' => PaymentStatus::Unpaid]);

    Livewire::test(ManagePayments::class)->callTableAction('verifyPayment', $payment)->assertHasNoTableActionErrors();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Paid)
        ->and($payment->verified_by)->toBe($this->actor->id)
        ->and($payment->proofs()->count())->toBe(0)
        ->and($order->refresh()->status)->toBe($status);
})->with([OrderStatus::ReadyToProcess, OrderStatus::Packing, OrderStatus::Shipping, OrderStatus::Done]);

test('invoice payment confirmation still requires verification permission', function (): void {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(Permission::findOrCreate('ViewAny:Payment', 'web'));
    $this->actingAs($viewer);
    $order = Order::factory()->create(['payment_method' => PaymentMethod::Invoice, 'status' => OrderStatus::ReadyToProcess]);
    $payment = Payment::factory()->for($order)->create(['method' => PaymentMethod::Invoice, 'status' => PaymentStatus::Unpaid]);

    Livewire::test(ManagePayments::class)->assertTableActionHidden('verifyPayment', $payment);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Unpaid);
});

test('issued invoice prevents cancellation and voiding even through stale records', function (): void {
    $customer = Customer::factory()->invoice()->create();
    $variant = ProductVariant::factory()->create(['price' => 25000, 'stock_available' => 10, 'stock_reserved' => 0]);
    $order = app(AdminOrderCreationService::class)->create(invoicePaymentOrderData($customer, $variant));
    $payment = $order->payment;
    $invoice = Invoice::factory()->create([
        'generation_token' => (string) Str::uuid7(),
        'invoice_number' => 'TEST/001',
        'sequence_year' => 2026,
        'sequence' => 1,
        'customer_id' => $customer->id,
        'customer_name' => $customer->name,
        'invoice_date' => '2026-09-30',
        'due_date' => '2026-10-07',
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'total' => '55000.00',
        'document_payload' => [],
    ]);
    Payment::whereKey($payment->id)->update(['invoice_id' => $invoice->id]);

    expect(fn () => $order->cancel($this->actor))->toThrow(RuntimeException::class, 'Delete the unpaid invoice')
        ->and(fn () => $payment->void($this->actor))->toThrow(RuntimeException::class, 'Delete the unpaid invoice');

    expect($order->refresh()->status)->toBe(OrderStatus::ReadyToProcess)
        ->and($order->canBeCancelled())->toBeFalse()
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Unpaid)
        ->and($payment->canBeVoided())->toBeFalse()
        ->and($payment->invoice->id)->toBe($invoice->id)
        ->and($variant->refresh()->stock_reserved)->toBe('2.000');
});

test('customers cannot alter a settled invoice through payment proof uploads', function (): void {
    Storage::fake('public');
    $customer = Customer::factory()->invoice()->registered()->create();
    $invoice = Invoice::factory()->for($customer)->create();
    $order = Order::factory()->for($customer)->create(['payment_method' => PaymentMethod::Invoice, 'status' => OrderStatus::Done]);
    $payment = Payment::factory()->for($order)->create([
        'invoice_id' => $invoice->id,
        'method' => PaymentMethod::Invoice,
        'status' => PaymentStatus::Paid,
        'verified_by' => $this->actor->id,
        'verified_at' => now(),
    ]);
    $this->actingAs($customer, 'customer');

    Livewire::test(OrderConfirmation::class, ['order' => $order])
        ->set('proofFile', UploadedFile::fake()->image('forged.png'))
        ->call('submitProof')->assertForbidden();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Paid)
        ->and($payment->canSubmitProof())->toBeFalse()
        ->and($payment->proofs()->count())->toBe(0)
        ->and($invoice->fresh()->paymentStatusLabel())->toBe('Paid')
        ->and(Storage::disk('public')->allFiles('payment-proofs'))->toBe([]);
});

test('payment proof mutation rechecks the persisted method and settled status', function (PaymentMethod $method): void {
    $order = Order::factory()->create(['payment_method' => $method, 'status' => OrderStatus::Done]);
    $payment = Payment::factory()->for($order)->create(['method' => $method, 'status' => PaymentStatus::Unpaid]);
    Payment::whereKey($payment->id)->update(['status' => PaymentStatus::Paid]);

    expect(fn () => $payment->submitProof('forged.png'))
        ->toThrow(RuntimeException::class, 'This payment cannot accept a payment proof.');

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($payment->proofs()->count())->toBe(0);
})->with([PaymentMethod::Invoice, PaymentMethod::Cash, PaymentMethod::Qris]);
