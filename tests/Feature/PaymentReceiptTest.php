<?php

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\Action;
use Livewire\Livewire;

function paymentReceiptViewer(bool $canView = true): User
{
    $user = User::factory()->sso()->create();
    $user->assignRole(Role::findOrCreate('receipt_viewer', 'web'));
    $permissions = $canView ? ['ViewAny:Payment', 'View:Payment'] : ['ViewAny:Payment'];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $user->givePermissionTo($permissions);

    return $user;
}

test('payment receipt requires authentication', function (): void {
    $payment = Payment::factory()->create(['status' => PaymentStatus::Paid]);
    $this->get(route('admin.payments.receipt', $payment))->assertRedirect();
});

test('payment receipt requires view permission in both table and route', function (): void {
    $this->actingAs(paymentReceiptViewer(false));
    $payment = Payment::factory()->create(['status' => PaymentStatus::Paid]);
    Livewire::test(ManagePayments::class)->assertTableActionHidden('printReceipt', $payment);
    $this->get(route('admin.payments.receipt', $payment))->assertForbidden();
});

test('unpaid or voided payments cannot print a paid receipt', function (PaymentStatus $status): void {
    $this->actingAs(paymentReceiptViewer());
    $payment = Payment::factory()->create(['status' => $status]);
    Livewire::test(ManagePayments::class)->assertTableActionHidden('printReceipt', $payment);
    $this->get(route('admin.payments.receipt', $payment))->assertForbidden();
})->with([
    PaymentStatus::Unpaid, PaymentStatus::WaitingConfirm, PaymentStatus::Rejected,
    PaymentStatus::UnpaidCancel, PaymentStatus::Void,
]);

test('paid payment prints from the payments table without opening a new tab', function (): void {
    $this->actingAs(paymentReceiptViewer());
    $payment = Payment::factory()->create(['status' => PaymentStatus::Paid]);
    Livewire::test(ManagePayments::class)
        ->assertTableActionVisible('printReceipt', $payment)
        ->assertTableActionExists('printReceipt', fn (Action $action): bool => $action->getUrl() === null
            && str_contains($action->getCustomAlpineClickHandler(), 'farming-print-receipt'), $payment);
});

test('embedded browser receipt contains no print controls or auto print module', function (): void {
    $this->actingAs(paymentReceiptViewer());
    $payment = Payment::factory()->create(['status' => PaymentStatus::Paid]);
    $this->get(route('admin.payments.receipt', [$payment, 'embedded' => 1]))->assertOk()
        ->assertSee($payment->payment_number)
        ->assertDontSee('data-print-receipt', false)
        ->assertDontSee('payment-receipt.js', false);
});

test('paid receipt shows transaction snapshots and permits reprints without changing payment', function (PaymentMethod $method): void {
    $cashier = paymentReceiptViewer();
    $this->actingAs($cashier);
    $order = Order::factory()->create([
        'recipient_name' => 'Receipt Customer', 'subtotal' => 50000, 'shipping_cost' => 5000, 'total' => 55000,
    ]);
    $name = 'Fresh organic garden vegetables with an especially long product name <script>alert(1)</script>';
    OrderItem::factory()->for($order)->create([
        'product_name' => 'Hidden parent product', 'variant_name' => $name,
        'quantity' => 2, 'unit' => 'box', 'unit_price' => 25000, 'subtotal' => 50000,
    ]);
    $payment = Payment::factory()->for($order)->create([
        'status' => PaymentStatus::Paid, 'method' => $method, 'amount' => 55000,
        'verified_by' => $cashier->id, 'verified_at' => '2026-09-30 10:15:00',
    ]);
    $originalPayment = $payment->fresh()->getAttributes();
    $originalOrder = $order->fresh()->getAttributes();

    foreach (range(1, 2) as $attempt) {
        $this->get(route('admin.payments.receipt', $payment))->assertOk()
            ->assertSee([$order->order_number, $payment->payment_number, 'Receipt Customer', $cashier->name,
                $method->getLabel(), 'Paid', '30/09/2026 10:15', 'WIB', $name,
                '2 box', 'Rp 25.000', 'Rp 50.000', 'Rp 5.000', 'Rp 55.000'])
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('Hidden parent product');
    }
    expect($payment->fresh()->getAttributes())->toBe($originalPayment);
    expect($order->fresh()->getAttributes())->toBe($originalOrder);
})->with([PaymentMethod::Cash, PaymentMethod::Qris]);

test('receipt falls back to customer name when recipient is empty', function (?string $recipient): void {
    $this->actingAs(paymentReceiptViewer());
    $customer = Customer::factory()->create(['name' => 'Registered Customer']);
    $order = Order::factory()->for($customer)->create(['recipient_name' => $recipient]);
    $payment = Payment::factory()->for($order)->create(['status' => PaymentStatus::Paid]);
    $this->get(route('admin.payments.receipt', $payment))->assertOk()->assertSee('Registered Customer');
})->with([null, '']);

test('receipt omits the cashier row when no verifier is recorded', function (): void {
    $this->actingAs(paymentReceiptViewer());
    $payment = Payment::factory()->create(['status' => PaymentStatus::Paid, 'verified_by' => null]);

    $this->get(route('admin.payments.receipt', $payment))
        ->assertSee($payment->payment_number)->assertDontSee('<dt>Cashier</dt>', false);
});
