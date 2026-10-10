<?php

use App\Enums\PaymentStatus;
use App\Filament\Exports\PaymentExportService;
use App\Filament\Pages\SalesReports;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(function (): void {
    $this->actor = User::factory()->sso()->create();
    $this->actor->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($this->actor);
});

test('sales reports is available at its renamed URL', function (): void {
    $this->get('/admin/sales-reports')->assertOk()->assertSee('Sales Reports');
    expect(SalesReports::getUrl())->toEndWith('/admin/sales-reports');
    $this->get('/admin/payment-export')->assertNotFound();
});

test('payment export loads an inclusive date range with order items', function (): void {
    $first = Payment::factory()->create(['status' => PaymentStatus::Paid, 'created_at' => '2026-09-01 00:00:00']);
    $last = Payment::factory()->create(['status' => PaymentStatus::Paid, 'created_at' => '2026-09-30 23:59:59']);
    $outside = Payment::factory()->create(['status' => PaymentStatus::Paid, 'created_at' => '2026-10-01 00:00:00']);
    $before = Payment::factory()->create(['status' => PaymentStatus::Paid, 'created_at' => '2026-08-31 23:59:59']);
    OrderItem::factory()->count(2)->for($first->order)->create(['product_name' => 'Fresh eggs']);
    OrderItem::factory()->for($last->order)->create();
    OrderItem::factory()->for($outside->order)->create();
    OrderItem::factory()->for($before->order)->create();
    Livewire::test(SalesReports::class)
        ->assertDontSee('Fresh eggs')
        ->fillForm(['start' => '2026-09-01', 'end' => '2026-09-30'])
        ->call('loadPayments')->assertHasNoFormErrors()
        ->assertCountTableRecords(2)
        ->assertSee($first->payment_number)
        ->assertSee($last->payment_number)
        ->assertDontSee($before->payment_number)
        ->assertDontSee($outside->payment_number)
        ->assertSeeInOrder([$last->payment_number, 'Product', $first->payment_number, 'Product', 'Fresh eggs', 'Fresh eggs'])
        ->assertSee('Fresh eggs')
        ->call('exportExcel')->assertFileDownloaded('payments-2026-09-01-to-2026-09-30.xlsx');
});

test('payment export validates missing and reversed dates', function (): void {
    Livewire::test(SalesReports::class)->call('loadPayments')
        ->assertHasFormErrors(['start' => 'required', 'end' => 'required'])
        ->fillForm(['start' => '2026-09-30', 'end' => '2026-09-01'])
        ->call('loadPayments')->assertHasFormErrors(['end']);
});

test('payment report keeps transactions without items and does not split item groups across pages', function (): void {
    $payment = Payment::factory()->create(['status' => PaymentStatus::Paid, 'created_at' => '2026-09-30 12:00:00']);
    OrderItem::factory()->count(12)->for($payment->order)->create(['product_name' => 'Group item']);
    $empty = Payment::factory()->create(['status' => PaymentStatus::Paid, 'created_at' => '2026-09-29 12:00:00']);

    $component = Livewire::test(SalesReports::class)
        ->fillForm(['start' => '2026-09-01', 'end' => '2026-09-30'])
        ->call('loadPayments')
        ->assertSee($empty->payment_number)
        ->assertSeeHtml('data-row-type="Transaction"')
        ->assertSeeHtml('data-row-type="Item"');

    $document = new DOMDocument;
    @$document->loadHTML($component->html());
    $xpath = new DOMXPath($document);
    $itemTables = $xpath->query('//table[starts-with(@aria-label, "Order items for ")]');
    expect($itemTables->length)->toBe(2);
    expect($xpath->query('.//thead//th', $itemTables->item(0))->length)->toBe(6);
    expect($xpath->query('.//tr[@data-row-type="Item"]', $itemTables->item(0))->length)->toBe(12);
    expect($xpath->query('ancestor::article[@data-row-type="Transaction"]', $itemTables->item(0))->length)->toBe(1);
    expect($xpath->query('//article[@data-row-type="Transaction"]')->length)->toBe(2);
    $component->assertSee('No order items');

    $rows = iterator_to_array(app(PaymentExportService::class)->rows($payment->load('order.items', 'createdBy')));
    expect($rows)->toHaveCount(13);
    expect(array_column($rows, 0))->toBe(['Transaction', ...array_fill(0, 12, 'Item')]);
    expect(iterator_to_array(app(PaymentExportService::class)->rows($empty->load('order.items', 'createdBy'))))->toHaveCount(1);
});

test('payment export requires payment export and order access permissions', function (): void {
    $user = User::factory()->create();
    foreach (['ViewAny:Payment', 'Export:Payment', 'ViewAny:Order'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $user->givePermissionTo('ViewAny:Payment', 'ViewAny:Order');
    $this->actingAs($user);
    expect(SalesReports::canAccess())->toBeFalse();
    Livewire::test(SalesReports::class)->assertForbidden();
    $user->givePermissionTo('Export:Payment');
    expect(SalesReports::canAccess())->toBeTrue();
    $user->revokePermissionTo('ViewAny:Order');
    expect(SalesReports::canAccess())->toBeFalse();
});

test('payment report and workbook include only paid transactions with their item rows', function (): void {
    $payment = Payment::factory()->create(['status' => PaymentStatus::Paid, 'amount' => 25000, 'created_at' => '2026-09-15 12:00:00']);
    OrderItem::factory()->count(2)->for($payment->order)->sequence(
        ['product_name' => '=SUM(1,1)', 'quantity' => 1, 'unit_price' => 12500, 'subtotal' => 12500],
        ['product_name' => 'Fresh eggs', 'quantity' => 2, 'unit_price' => 6250, 'subtotal' => 12500],
    )->create();
    $excluded = collect(PaymentStatus::cases())->reject(fn (PaymentStatus $status): bool => $status === PaymentStatus::Paid)
        ->map(function (PaymentStatus $status): Payment {
            $record = Payment::factory()->create(['status' => $status, 'created_at' => '2026-09-15 12:00:00']);
            OrderItem::factory()->for($record->order)->create(['product_name' => 'Excluded '.$status->value]);

            return $record;
        });
    $component = Livewire::test(SalesReports::class)
        ->fillForm(['start' => '2026-09-01', 'end' => '2026-09-30'])
        ->call('loadPayments')
        ->assertCountTableRecords(1)
        ->assertSee($payment->payment_number);
    foreach ($excluded as $record) {
        $component->assertDontSee($record->payment_number)->assertDontSee('Excluded '.$record->status->value);
    }
    $response = $component->instance()->exportExcel();
    ob_start();
    $response->sendContent();
    $content = ob_get_clean();
    $path = tempnam(sys_get_temp_dir(), 'payment-export');
    file_put_contents($path, $content);
    $reader = new Reader;
    try {
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $sheets[$sheet->getName()] = [];
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }
        expect(array_keys($sheets))->toBe(['Payments']);
        expect($sheets['Payments'])->toHaveCount(4);
        $transaction = $sheets['Payments'][1];
        expect($transaction[0])->toBe('Transaction');
        expect($transaction[1])->toBe($payment->order->order_number);
        expect($transaction[5])->toBe(2);
        expect($transaction[11])->toEqual(25000);
        expect($transaction[12])->toBe($payment->payment_number);
        expect(array_slice($transaction, 14))->each->toBe('');
        foreach (array_slice($sheets['Payments'], 2) as $row) {
            expect($row[0])->toBe('Item');
            expect($row[1])->toBe($payment->order->order_number);
            expect(array_slice($row, 3, 11))->each->toBe('');
            expect($row[19])->toEqual(12500);
        }
        expect($sheets['Payments'][2][14])->toBe("'=SUM(1,1)");
        expect($sheets['Payments'][3][14])->toBe('Fresh eggs');
        expect($sheets['Payments'][3][18])->toBe(2);
        expect($sheets['Payments'][3][17])->toEqual(6250);
        expect(json_encode($sheets))->not->toContain($payment->id, ...$excluded->pluck('payment_number')->all());
        expect(json_encode($sheets))->not->toContain('Excluded');
    } finally {
        $reader->close();
        unlink($path);
    }
});
