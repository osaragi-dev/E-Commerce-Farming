<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Filament\Pages\GenerateInvoice;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\InvoiceGenerationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;

function invoicePageActor(array $permissions = ['ViewAny:Invoice', 'Create:Invoice', 'View:Invoice', 'Export:Invoice']): User
{
    $actor = User::factory()->sso()->create();
    $actor->assignRole(Role::findOrCreate('invoice_operator', 'web'));
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $actor->givePermissionTo($permissions);

    return $actor;
}

function invoicePageInput(): array
{
    $customer = Customer::factory()->invoice()->create();
    $order = Order::factory()->for($customer)->create([
        'payment_method' => PaymentMethod::Invoice, 'status' => OrderStatus::ReadyToProcess,
        'subtotal' => 20000, 'shipping_cost' => 0, 'total' => 20000,
        'created_at' => '2026-09-18 12:00:00',
    ]);
    OrderItem::factory()->for($order)->create([
        'quantity' => 1, 'unit_price' => 20000, 'subtotal' => 20000,
    ]);
    Payment::factory()->for($order)->create([
        'method' => PaymentMethod::Invoice, 'status' => PaymentStatus::Unpaid, 'amount' => 20000,
    ]);

    return [
        'customer_id' => $customer->id, 'generation_method' => 'by_date',
        'invoice_date' => '2026-09-30', 'due_date' => '2026-10-30',
        'start_date' => '2026-09-01', 'end_date' => '2026-09-30',
        'selected_order_ids' => [$order->id],
    ];
}

function invoicePageDocument(): Invoice
{
    $service = app(InvoiceGenerationService::class);
    $input = invoicePageInput();

    return $service->generate($input, $service->fingerprint($service->preview($input)), (string) Str::uuid());
}

test('invoice history filters exact payment status and restores all invoices when cleared', function (PaymentStatus $status, PaymentStatus $otherStatus): void {
    $this->actingAs(invoicePageActor());
    $matchingInvoice = Invoice::factory()->create();
    Payment::factory()->count(2)->for($matchingInvoice)->create(['status' => $status]);
    Payment::factory()->for($matchingInvoice)->create(['status' => $otherStatus])->delete();
    $otherInvoice = Invoice::factory()->create();
    Payment::factory()->count(2)->for($otherInvoice)->create(['status' => $otherStatus]);
    $partialInvoice = Invoice::factory()->create();
    Payment::factory()->count(2)->for($partialInvoice)->sequence(
        ['status' => PaymentStatus::Paid], ['status' => PaymentStatus::Unpaid],
    )->create();
    $reviewInvoice = Invoice::factory()->create();
    Payment::factory()->count(2)->for($reviewInvoice)->sequence(
        ['status' => $status], ['status' => PaymentStatus::WaitingConfirm],
    )->create();
    $emptyInvoice = Invoice::factory()->create();
    $deletedInvoice = Invoice::factory()->create();
    Payment::factory()->for($deletedInvoice)->create(['status' => $status]);
    $deletedInvoice->delete();

    $page = Livewire::test(GenerateInvoice::class)
        ->filterTable('payment_status', $status->value);

    $page->assertCanSeeTableRecords([$matchingInvoice])
        ->assertCanNotSeeTableRecords([$otherInvoice, $partialInvoice, $reviewInvoice, $emptyInvoice, $deletedInvoice])
        ->assertCountTableRecords(1)
        ->filterTable('payment_status', null)
        ->assertCanSeeTableRecords([$matchingInvoice, $otherInvoice, $partialInvoice, $reviewInvoice, $emptyInvoice])
        ->assertCanNotSeeTableRecords([$deletedInvoice])
        ->assertCountTableRecords(5);
})->with([
    'paid' => [PaymentStatus::Paid, PaymentStatus::Unpaid],
    'unpaid' => [PaymentStatus::Unpaid, PaymentStatus::Paid],
]);

test('invoice page previews and generates reviewed orders with retry protection', function (string $method): void {
    $this->actingAs(invoicePageActor());
    $input = [...invoicePageInput(), 'generation_method' => $method];

    $page = Livewire::test(GenerateInvoice::class)->fillForm($input)
        ->call('preview')->assertHasNoFormErrors();

    expect($page->get('previewToken'))->not->toBeNull();
    $this->assertDatabaseCount('invoices', 0);
    $page->call('generateInvoice')->assertHasNoFormErrors();
    $invoice = Invoice::sole();
    expect($page->get('generatedInvoiceId'))->toBe($invoice->id);
    expect($invoice->payments()->count())->toBe(1);
    $page->call('generateInvoice')->assertHasNoFormErrors();
    $this->assertDatabaseCount('invoices', 1);
})->with(['by_date', 'by_selected_order']);

test('date range preview shows a visible error when all customer orders have been invoiced', function (): void {
    $this->actingAs(invoicePageActor());
    $input = invoicePageInput();
    $service = app(InvoiceGenerationService::class);
    $service->generate($input, $service->fingerprint($service->preview($input)), (string) Str::uuid());

    Livewire::test(GenerateInvoice::class)->fillForm($input)
        ->call('preview')->assertHasErrors(['data.customer_id'])
        ->assertSee('No eligible orders: this customer has no unpaid, uninvoiced orders in the selected date range.')
        ->assertSet('previewToken', null);
});

test('selected orders rejects orders belonging to another customer', function (): void {
    $this->actingAs(invoicePageActor());
    $input = invoicePageInput();
    $other = invoicePageInput();

    Livewire::test(GenerateInvoice::class)->fillForm([
        ...$input, 'generation_method' => 'by_selected_order', 'selected_order_ids' => $other['selected_order_ids'],
    ])->call('preview')->assertHasErrors()->assertSet('previewToken', null);
});

test('invoice page refuses generation without a preview', function (): void {
    $this->actingAs(invoicePageActor());

    Livewire::test(GenerateInvoice::class)->fillForm(invoicePageInput())
        ->call('generateInvoice')->assertHasErrors(['data.customer_id']);

    $this->assertDatabaseCount('invoices', 0);
});

test('expired previews cannot generate an invoice', function (): void {
    $this->actingAs(invoicePageActor());
    $page = Livewire::test(GenerateInvoice::class)->fillForm(invoicePageInput())
        ->call('preview')->assertHasNoFormErrors();
    $this->travel(11)->minutes();

    $page->call('generateInvoice')->assertHasErrors(['data.customer_id']);

    $this->assertDatabaseCount('invoices', 0);
});

test('changing invoice form data invalidates the reviewed preview', function (): void {
    $this->actingAs(invoicePageActor());
    $page = Livewire::test(GenerateInvoice::class)->fillForm(invoicePageInput())
        ->call('preview')->assertHasNoFormErrors();
    $token = $page->get('previewToken');

    $page->set('data.due_date', '2026-11-01');

    expect($page->get('previewToken'))->toBeNull();
    expect(Cache::has('invoice-preview:'.$token))->toBeFalse();
    $page->call('generateInvoice')->assertHasErrors(['data.customer_id']);
    $this->assertDatabaseCount('invoices', 0);
});

test('invoice page requires list permission', function (): void {
    $this->actingAs(invoicePageActor(['Create:Invoice']));

    Livewire::test(GenerateInvoice::class)->assertForbidden();
});

test('invoice downloads require authentication', function (): void {
    $invoice = Invoice::factory()->create();

    $this->get(route('admin.invoices.download', [$invoice, 'pdf']))->assertRedirect();
});

test('invoice view permission does not allow exporting documents', function (): void {
    $this->actingAs(invoicePageActor(['View:Invoice']));
    $invoice = Invoice::factory()->create();

    $this->get(route('admin.invoices.download', [$invoice, 'pdf']))->assertForbidden();
});

test('invoice viewing and previews enforce their own permissions', function (): void {
    $this->actingAs(invoicePageActor([]));
    $invoice = Invoice::factory()->create();

    $this->get(route('admin.invoices.show', $invoice))->assertForbidden();
    $this->get(route('admin.invoices.preview', (string) Str::uuid()))->assertForbidden();
});

test('deleted invoices and unsupported document formats are not downloadable', function (): void {
    $this->actingAs(invoicePageActor());
    $invoice = Invoice::factory()->create();
    $this->get(route('admin.invoices.download', [$invoice, 'html']))->assertNotFound();
    $invoice->delete();

    $this->get(route('admin.invoices.show', $invoice))->assertNotFound();
    $this->get(route('admin.invoices.download', [$invoice, 'pdf']))->assertNotFound();
});

test('issued invoice pdf and word downloads return valid document content', function (): void {
    $this->actingAs(invoicePageActor());
    $invoice = invoicePageDocument();

    $pdf = $this->get(route('admin.invoices.download', [$invoice, 'pdf']))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($pdf->getContent())->toStartWith('%PDF-');
    $word = $this->get(route('admin.invoices.download', [$invoice, 'docx']))
        ->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    $path = $word->baseResponse->getFile()->getPathname();
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    expect($xml)->toContain($invoice->invoice_number, htmlspecialchars($invoice->customer_name, ENT_QUOTES | ENT_XML1, 'UTF-8'));
    unlink($path);
});

test('preview pdf is only visible to the user who reviewed it', function (): void {
    $this->actingAs(invoicePageActor());
    $page = Livewire::test(GenerateInvoice::class)->fillForm(invoicePageInput())
        ->call('preview')->assertHasNoFormErrors();
    $url = route('admin.invoices.preview', $page->get('previewToken'));
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs(invoicePageActor());

    $this->get($url)->assertNotFound();
});
