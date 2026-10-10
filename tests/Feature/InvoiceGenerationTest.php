<?php

use App\Enums\CustomerType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RecordStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\SystemFreezeState;
use App\Models\User;
use App\Services\InvoiceGenerationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function invoiceGenerationActor(array $permissions = ['Create:Invoice', 'View:Invoice', 'Delete:Invoice', 'Verify:Payment']): User
{
    $actor = User::factory()->create();
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $actor->givePermissionTo($permissions);

    return $actor;
}

function invoiceGenerationOrder(Customer $customer, array $attributes = []): Order
{
    $order = Order::factory()->for($customer)->create([
        'payment_method' => PaymentMethod::Invoice,
        'status' => OrderStatus::ReadyToProcess,
        'subtotal' => '20000.20', 'shipping_cost' => '1000.10', 'total' => '21000.30',
        'created_at' => '2026-09-18 12:00:00',
        ...$attributes,
    ]);
    OrderItem::factory()->for($order)->create([
        'product_name' => 'Farm eggs', 'variant_name' => 'Grade A', 'unit' => 'box',
        'quantity' => 2, 'unit_price' => '10000.10', 'subtotal' => '20000.20',
    ]);
    Payment::factory()->for($order)->create([
        'method' => PaymentMethod::Invoice, 'status' => PaymentStatus::Unpaid,
        'amount' => '21000.30', 'created_by' => auth('web')->id(),
    ]);

    return $order;
}

function invoiceGenerationInput(Customer $customer, array $attributes = []): array
{
    return [
        'customer_id' => $customer->id, 'generation_method' => 'by_date',
        'invoice_date' => '2026-09-30', 'due_date' => '2026-10-30',
        'start_date' => '2026-09-01', 'end_date' => '2026-09-30',
        ...$attributes,
    ];
}

function generateTestInvoice(Customer $customer, array $attributes = []): Invoice
{
    $service = app(InvoiceGenerationService::class);
    $input = invoiceGenerationInput($customer, $attributes);

    return $service->generate($input, $service->fingerprint($service->preview($input)), (string) Str::uuid());
}

test('invoice generation aggregates exact amounts and preserves the reviewed document snapshot', function (): void {
    $this->actingAs(invoiceGenerationActor());
    $customer = Customer::factory()->invoice()->create(['name' => 'Invoice customer']);
    $first = invoiceGenerationOrder($customer);
    $second = invoiceGenerationOrder($customer, ['created_at' => '2026-09-19 12:00:00']);

    $invoice = generateTestInvoice($customer);

    expect($invoice->total)->toBe('42000.60');
    expect($invoice->document_payload)->toMatchArray([
        'customer_name' => 'Invoice customer', 'subtotal' => '40000.40',
        'shipping_cost' => '2000.20', 'total' => '42000.60',
        'period_start' => '2026-09-18', 'period_end' => '2026-09-19',
    ]);
    expect($invoice->document_payload['items'])->toHaveCount(2);
    expect($invoice->payments()->pluck('order_id')->all())->toEqualCanonicalizing([$first->id, $second->id]);
    $snapshot = $invoice->document_payload;
    $customer->update(['name' => 'Renamed customer']);
    $first->items()->first()->update(['product_name' => 'Renamed eggs']);
    config(['invoice.seller_name' => 'Changed seller']);
    expect($invoice->fresh()->document_payload)->toBe($snapshot);
});

test('invoice generation links only the latest payment attempt', function (): void {
    $this->actingAs(invoiceGenerationActor());
    $customer = Customer::factory()->invoice()->create();
    $order = invoiceGenerationOrder($customer);
    $previous = $order->payment;
    $previous->update(['status' => PaymentStatus::Void]);
    $latest = Payment::factory()->for($order)->create([
        'attempt_number' => 2, 'method' => PaymentMethod::Invoice,
        'status' => PaymentStatus::Unpaid, 'amount' => '21000.30',
    ]);
    $invoice = generateTestInvoice($customer);
    expect($invoice->payments()->get()->modelKeys())->toBe([$latest->id]);
    expect($previous->fresh()->invoice_id)->toBeNull();
});

test('generation retries return the same invoice and other tokens cannot invoice the same payments', function (): void {
    $this->actingAs(invoiceGenerationActor());
    $customer = Customer::factory()->invoice()->create();
    $order = invoiceGenerationOrder($customer);
    $service = app(InvoiceGenerationService::class);
    $input = invoiceGenerationInput($customer);
    $fingerprint = $service->fingerprint($service->preview($input));
    $token = (string) Str::uuid();

    $invoice = $service->generate($input, $fingerprint, $token);

    expect($service->generate($input, $fingerprint, $token)->id)->toBe($invoice->id);
    expect(fn () => $service->generate($input, $fingerprint, (string) Str::uuid()))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('invoices', 1);
    expect($order->payment->fresh()->invoice_id)->toBe($invoice->id);
});

test('generation rejects a stale preview without consuming a number or linking payments', function (): void {
    $this->actingAs(invoiceGenerationActor());
    $customer = Customer::factory()->invoice()->create();
    $order = invoiceGenerationOrder($customer);
    $service = app(InvoiceGenerationService::class);
    $input = invoiceGenerationInput($customer);
    $fingerprint = $service->fingerprint($service->preview($input));
    $customer->update(['name' => 'Changed after preview']);

    expect(fn () => $service->generate($input, $fingerprint, (string) Str::uuid()))
        ->toThrow(ValidationException::class, 'Invoice details have changed.');

    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('invoice_sequences', 0);
    expect($order->payment->invoice_id)->toBeNull();
});

test('invoice preview refuses customers that are not active saved invoice customers', function (array $attributes): void {
    $this->actingAs(invoiceGenerationActor());
    $customer = Customer::factory()->invoice()->create($attributes);
    invoiceGenerationOrder($customer);

    expect(fn () => app(InvoiceGenerationService::class)->preview(invoiceGenerationInput($customer)))
        ->toThrow(ValidationException::class, 'Select an active, saved Invoice customer.');
})->with([
    'regular' => [['customer_type' => CustomerType::Regular]],
    'inactive' => [['is_active' => false]],
    'draft' => [['record_status' => RecordStatus::Draft]],
]);

test('selected orders cannot belong to a different customer', function (): void {
    $this->actingAs(invoiceGenerationActor());
    $customer = Customer::factory()->invoice()->create();
    $other = Customer::factory()->invoice()->create();
    $order = invoiceGenerationOrder($other);

    expect(fn () => app(InvoiceGenerationService::class)->preview(invoiceGenerationInput($customer, [
        'generation_method' => 'by_selected_order', 'selected_order_ids' => [$order->id],
    ])))->toThrow(ValidationException::class, 'No eligible orders');
    $this->assertDatabaseCount('invoices', 0);
});

test('selected generation includes only reviewed orders even outside the date range', function (): void {
    $this->actingAs(invoiceGenerationActor());
    $customer = Customer::factory()->invoice()->create();
    $selected = invoiceGenerationOrder($customer, ['created_at' => '2026-08-15 12:00:00']);
    $unselected = invoiceGenerationOrder($customer);

    $invoice = generateTestInvoice($customer, [
        'generation_method' => 'by_selected_order', 'selected_order_ids' => [$selected->id],
    ]);

    expect($invoice->document_payload['order_numbers'])->toBe([$selected->order_number]);
    expect($selected->payment->invoice_id)->toBe($invoice->id);
    expect($unselected->payment->invoice_id)->toBeNull();
});

test('cancelled and non invoice orders cannot enter an invoice', function (array $attributes): void {
    $this->actingAs(invoiceGenerationActor());
    $customer = Customer::factory()->invoice()->create();
    invoiceGenerationOrder($customer, $attributes);

    expect(fn () => app(InvoiceGenerationService::class)->preview(invoiceGenerationInput($customer)))
        ->toThrow(ValidationException::class, 'No eligible orders');
})->with([
    'cancelled' => [['status' => OrderStatus::Cancelled]],
    'cash' => [['payment_method' => PaymentMethod::Cash]],
]);

test('date selection includes both day boundaries and excludes orders outside the period', function (): void {
    $this->actingAs(invoiceGenerationActor());
    $customer = Customer::factory()->invoice()->create();
    $first = invoiceGenerationOrder($customer, ['created_at' => '2026-09-18 00:00:00']);
    $last = invoiceGenerationOrder($customer, ['created_at' => '2026-09-18 23:59:59']);
    invoiceGenerationOrder($customer, ['created_at' => '2026-09-17 23:59:59']);
    invoiceGenerationOrder($customer, ['created_at' => '2026-09-19 00:00:00']);

    $payload = app(InvoiceGenerationService::class)->preview(invoiceGenerationInput($customer, [
        'start_date' => '2026-09-18', 'end_date' => '2026-09-18',
    ]));

    expect($payload['order_numbers'])->toEqualCanonicalizing([$first->order_number, $last->order_number]);
});

test('invoice preview rejects invalid date ordering', function (array $attributes): void {
    $this->actingAs(invoiceGenerationActor());
    $customer = Customer::factory()->invoice()->create();
    invoiceGenerationOrder($customer);

    expect(fn () => app(InvoiceGenerationService::class)->preview(invoiceGenerationInput($customer, $attributes)))
        ->toThrow(ValidationException::class);
})->with([
    'due before issue' => [['due_date' => '2026-09-29']],
    'period reversed' => [['start_date' => '2026-09-30', 'end_date' => '2026-09-01']],
]);

test('invoice preview excludes payments that are no longer unpaid invoice receivables', function (PaymentStatus $status): void {
    $this->actingAs(invoiceGenerationActor());
    $customer = Customer::factory()->invoice()->create();
    $order = invoiceGenerationOrder($customer);
    $order->payment->update(['status' => $status]);

    expect(fn () => app(InvoiceGenerationService::class)->preview(invoiceGenerationInput($customer)))
        ->toThrow(ValidationException::class, 'No eligible orders');
})->with([PaymentStatus::Paid, PaymentStatus::Void, PaymentStatus::UnpaidCancel, PaymentStatus::WaitingConfirm, PaymentStatus::Rejected]);

test('invoice preview rejects inconsistent line and payment totals', function (): void {
    $this->actingAs(invoiceGenerationActor());
    $customer = Customer::factory()->invoice()->create();
    $order = invoiceGenerationOrder($customer);
    $order->payment->update(['amount' => '21000.31']);

    expect(fn () => app(InvoiceGenerationService::class)->preview(invoiceGenerationInput($customer)))
        ->toThrow(ValidationException::class, 'An order total does not match');
});

test('deleting an unpaid invoice releases its payments but never reuses its annual number', function (): void {
    $this->actingAs(invoiceGenerationActor());
    $customer = Customer::factory()->invoice()->create();
    $order = invoiceGenerationOrder($customer);
    $service = app(InvoiceGenerationService::class);
    $invoice = generateTestInvoice($customer);

    $service->delete($invoice);

    $this->assertSoftDeleted($invoice);
    expect($order->payment->fresh()->invoice_id)->toBeNull();
    $replacement = generateTestInvoice($customer);
    expect($replacement->invoice_number)->toBe('0002/FARM/IX/2026');
    $service->delete($replacement);
    $nextYear = generateTestInvoice($customer, ['invoice_date' => '2027-01-01', 'due_date' => '2027-01-31']);
    expect($nextYear->invoice_number)->toBe('0001/FARM/I/2027');
});

test('grouped settlement and reversal update every payment without regressing fulfillment', function (): void {
    $actor = invoiceGenerationActor();
    $this->actingAs($actor);
    $customer = Customer::factory()->invoice()->create();
    $first = invoiceGenerationOrder($customer);
    $last = invoiceGenerationOrder($customer, ['status' => OrderStatus::Done]);
    $invoice = generateTestInvoice($customer);
    $service = app(InvoiceGenerationService::class);

    $service->setPaid($invoice, true);

    expect($invoice->fresh()->paymentStatusLabel())->toBe('Paid');
    foreach ([$first, $last] as $order) {
        expect($order->payment->fresh()->verified_by)->toBe($actor->id);
        expect($order->payment->fresh()->verified_at)->not->toBeNull();
    }
    expect(fn () => $service->delete($invoice))->toThrow(ValidationException::class, 'Only a fully unpaid invoice');
    $this->assertNotSoftDeleted($invoice);
    $service->setPaid($invoice, false);
    expect($invoice->fresh()->paymentStatusLabel())->toBe('Unpaid');
    foreach ([$first, $last] as $order) {
        expect($order->payment->fresh()->verified_by)->toBeNull();
        expect($order->payment->fresh()->verified_at)->toBeNull();
    }
    expect($last->fresh()->status)->toBe(OrderStatus::Done);
});

test('invalid linked payments prevent all grouped settlement changes', function (): void {
    $this->actingAs(invoiceGenerationActor());
    $customer = Customer::factory()->invoice()->create();
    $first = invoiceGenerationOrder($customer);
    $last = invoiceGenerationOrder($customer);
    $invoice = generateTestInvoice($customer);
    $last->payment->update(['status' => PaymentStatus::Void]);

    expect(fn () => app(InvoiceGenerationService::class)->setPaid($invoice, true))
        ->toThrow(ValidationException::class, 'Invoice payments have changed.');

    expect($first->payment->fresh()->status)->toBe(PaymentStatus::Unpaid);
});

test('invoice generation requires create permission', function (): void {
    $this->actingAs(invoiceGenerationActor([]));
    $customer = Customer::factory()->invoice()->create();

    expect(fn () => app(InvoiceGenerationService::class)->preview(invoiceGenerationInput($customer)))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(InvoiceGenerationService::class)->generate(invoiceGenerationInput($customer), '', (string) Str::uuid()))
        ->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('invoices', 0);
});

test('view permission alone cannot settle or delete invoices', function (): void {
    $this->actingAs(invoiceGenerationActor());
    $customer = Customer::factory()->invoice()->create();
    invoiceGenerationOrder($customer);
    $invoice = generateTestInvoice($customer);
    $this->actingAs(invoiceGenerationActor(['View:Invoice']));
    $service = app(InvoiceGenerationService::class);

    expect(fn () => $service->setPaid($invoice, true))->toThrow(AuthorizationException::class);
    expect(fn () => $service->delete($invoice))->toThrow(AuthorizationException::class);

    expect($invoice->fresh()->paymentStatusLabel())->toBe('Unpaid');
    $this->assertNotSoftDeleted($invoice);
});

test('system freeze blocks invoice generation settlement and deletion', function (): void {
    $this->actingAs(invoiceGenerationActor());
    $customer = Customer::factory()->invoice()->create();
    invoiceGenerationOrder($customer);
    $invoice = generateTestInvoice($customer);
    $service = app(InvoiceGenerationService::class);
    SystemFreezeState::activate();

    expect(fn () => $service->generate(invoiceGenerationInput($customer), '', (string) Str::uuid()))
        ->toThrow(ValidationException::class, 'The system is temporarily frozen');
    expect(fn () => $service->setPaid($invoice, true))->toThrow(ValidationException::class, 'The system is temporarily frozen');
    expect(fn () => $service->delete($invoice))->toThrow(ValidationException::class, 'The system is temporarily frozen');

    $this->assertDatabaseCount('invoices', 1);
    expect($invoice->fresh()->paymentStatusLabel())->toBe('Unpaid');
    $this->assertNotSoftDeleted($invoice);
});
