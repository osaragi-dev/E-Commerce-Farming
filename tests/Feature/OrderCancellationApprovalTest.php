<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\StockMutationSource;
use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Models\ApprovalSetting;
use App\Models\ApprovalSettingLevel;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Models\Permission;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\StockMutation;
use App\Models\StockOpnameSession;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\ApprovalWorkflow;
use App\Services\PaymentVoidWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actor = User::factory()->create();
    $this->actor->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($this->actor);
});

test('paid cancellation traverses approval levels and returns fulfilled or shipped stock exactly once', function (bool $completed): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_available' => 10, 'stock_reserved' => 0, 'stock_in_transit' => 0]);
    $stock = WarehouseStock::factory()->create(['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'stock_available' => 10]);
    $order = Order::factory()->create(['status' => OrderStatus::Packing, 'fulfillment_warehouse_id' => $warehouse->id]);
    $item = OrderItem::factory()->create(['order_id' => $order->id, 'product_variant_id' => $variant->id, 'quantity' => '1.250']);
    StockMutation::reserveForOrder($variant, '1.250', $item->id, $this->actor->id, $warehouse);
    $payment = Payment::factory()->for($order)->create(['status' => PaymentStatus::Paid]);
    $order->advanceStatus($this->actor);
    if ($completed) {
        $order->advanceStatus($this->actor);
    }
    $setting = ApprovalSetting::where('module_code', 'void_payment')->firstOrFail();
    ApprovalSettingLevel::factory()->count(2)->create(['approval_setting_id' => $setting->id]);

    Livewire::test(ManageOrders::class)->callTableAction('cancelOrder', $order, ['reason' => 'Goods returned'])
        ->assertHasNoTableActionErrors()->assertNotified('Cancellation requested. Awaiting void payment approval.');
    $request = $payment->voidRequests()->sole();
    expect($request->cancel_order)->toBeTrue();
    expect($order->fresh()->status)->toBe($completed ? OrderStatus::Done : OrderStatus::Shipping);
    expect($stock->fresh()->stock_in_transit)->toBe($completed ? '0.000' : '1.250');
    expect($stock->fresh()->stock_out)->toBe($completed ? '1.250' : '0.000');
    expect(fn () => $order->fresh()->advanceStatus($this->actor))->toThrow(RuntimeException::class);
    expect(fn () => $order->fresh()->cancel($this->actor, 'Again'))->toThrow(ValidationException::class);
    app(ApprovalWorkflow::class)->approve($request, $this->actor);
    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid);
    app(ApprovalWorkflow::class)->approve($request->fresh(), $this->actor);

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
    expect($order->fresh()->cancellation_reason)->toBe('Goods returned');
    expect($payment->fresh()->status)->toBe(PaymentStatus::Void);
    foreach ([$stock->fresh(), $variant->fresh()] as $balance) {
        expect($balance->stock_available)->toBe('10.000');
        expect($balance->stock_reserved)->toBe('0.000');
        expect($balance->stock_in_transit)->toBe('0.000');
        expect($balance->stock_out)->toBe('0.000');
    }
    expect(fn () => app(ApprovalWorkflow::class)->approve($request->fresh(), $this->actor))->toThrow(ValidationException::class);
    expect(fn () => $order->fresh()->cancel($this->actor, 'Again'))->toThrow(RuntimeException::class);
    expect(StockMutation::where('reference_id', $item->id)->where('source', StockMutationSource::CancelOrder)->count())->toBe(1);
})->with([false, true]);

test('cancellation handles payment states without changing historical attempts', function (?PaymentStatus $status, ?PaymentStatus $expected): void {
    $order = Order::factory()->create(['status' => OrderStatus::Packing]);
    $old = Payment::factory()->for($order)->create(['status' => PaymentStatus::Void, 'attempt_number' => 1]);
    $payment = $status ? Payment::factory()->for($order)->create(['status' => $status, 'attempt_number' => 2]) : null;
    $order->cancel($this->actor, 'Cancelled');
    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
    expect($old->fresh()->status)->toBe(PaymentStatus::Void);
    if ($payment) {
        expect($payment->fresh()->status)->toBe($expected);
        if ($status === PaymentStatus::Paid) {
            expect($payment->voidRequests()->sole()->approval_status)->toBe('approved');
            expect($payment->voidRequests()->sole()->logs()->count())->toBe(2);
        }
    }
})->with([
    [PaymentStatus::Unpaid, PaymentStatus::UnpaidCancel],
    [PaymentStatus::WaitingConfirm, PaymentStatus::Void],
    [PaymentStatus::Paid, PaymentStatus::Void],
    [PaymentStatus::Void, PaymentStatus::Void],
    [PaymentStatus::Rejected, PaymentStatus::Rejected],
    [PaymentStatus::UnpaidCancel, PaymentStatus::UnpaidCancel],
    [null, null],
]);

test('cancellation is available through done but cannot be repeated', function (OrderStatus $status, bool $allowed): void {
    $order = Order::factory()->create(['status' => $status]);
    expect($order->canBeCancelled())->toBe($allowed);
    if ($allowed) {
        $order->cancel($this->actor, 'Cancelled');
        expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
    } else {
        expect(fn () => $order->cancel($this->actor, 'Cancelled'))->toThrow(RuntimeException::class);
    }
})->with([
    [OrderStatus::WaitingPayment, true], [OrderStatus::WaitingConfirmation, true],
    [OrderStatus::ReadyToProcess, true], [OrderStatus::Packing, true],
    [OrderStatus::Shipping, true], [OrderStatus::ReadyForPickup, true],
    [OrderStatus::Done, true], [OrderStatus::PickedUp, true], [OrderStatus::Cancelled, false],
]);

test('revision preserves the hold and rejection or withdrawal releases it', function (string $decision): void {
    $order = Order::factory()->create(['status' => OrderStatus::Shipping]);
    $payment = Payment::factory()->for($order)->create(['status' => PaymentStatus::Paid]);
    ApprovalSettingLevel::factory()->create(['approval_setting_id' => ApprovalSetting::where('module_code', 'void_payment')->value('id')]);
    $order->cancel($this->actor, 'Returned');
    $request = $payment->voidRequests()->sole();
    app(ApprovalWorkflow::class)->revise($request, $this->actor, 'Explain return');
    expect($order->fresh()->nextStatus())->toBeNull();
    app(PaymentVoidWorkflow::class)->resubmit($request->fresh(), $this->actor, 'Returned to warehouse');
    if ($decision === 'reject') {
        app(ApprovalWorkflow::class)->reject($request->fresh(), $this->actor, 'Not returned');
    } else {
        app(PaymentVoidWorkflow::class)->withdraw($request->fresh(), $this->actor);
    }
    expect($order->fresh()->nextStatus())->toBe(OrderStatus::Done);
    expect($order->fresh()->status)->toBe(OrderStatus::Shipping);
    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid);
})->with(['reject', 'withdraw']);

test('insufficient stock rolls back the final approval and cancellation', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::Packing]);
    $variant = ProductVariant::factory()->create(['stock_available' => 5, 'stock_reserved' => 0]);
    OrderItem::factory()->create(['order_id' => $order->id, 'product_variant_id' => $variant->id, 'quantity' => 2]);
    $payment = Payment::factory()->for($order)->create(['status' => PaymentStatus::Paid]);
    ApprovalSettingLevel::factory()->create(['approval_setting_id' => ApprovalSetting::where('module_code', 'void_payment')->value('id')]);
    $order->cancel($this->actor, 'Returned');
    $request = $payment->voidRequests()->sole();
    expect(fn () => app(ApprovalWorkflow::class)->approve($request, $this->actor))->toThrow(InvalidArgumentException::class);
    expect($request->fresh()->approval_status)->toBe('requested');
    expect($order->fresh()->status)->toBe(OrderStatus::Packing);
    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid);
    expect($variant->fresh()->stock_available)->toBe('5.000');
});

test('paid cancellation requires both order delete and payment void permissions', function (string $permission): void {
    $actor = User::factory()->create();
    $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    $order = Order::factory()->create(['status' => OrderStatus::Shipping]);
    Payment::factory()->for($order)->create(['status' => PaymentStatus::Paid]);
    expect(fn () => $order->cancel($actor, 'Returned'))->toThrow(AuthorizationException::class);
    expect($order->fresh()->status)->toBe(OrderStatus::Shipping);
})->with(['Delete:Order', 'Void:Payment', 'Update:Order']);

test('inactive approval settings do not delay order cancellation', function (): void {
    $setting = ApprovalSetting::where('module_code', 'void_payment')->firstOrFail();
    ApprovalSettingLevel::factory()->create(['approval_setting_id' => $setting->id]);
    $setting->update(['is_active' => false]);
    $order = Order::factory()->create(['status' => OrderStatus::Shipping]);
    $payment = Payment::factory()->for($order)->create(['status' => PaymentStatus::Paid]);
    $order->cancel($this->actor, 'Returned');
    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
    expect($payment->voidRequests()->sole()->approval_status)->toBe('approved');
});

test('a role with delete and payment void permissions can cancel a paid order without update permission', function (): void {
    $actor = User::factory()->create();
    $role = Role::findOrCreate('order_canceller', 'web');
    foreach (['ViewAny:Order', 'Delete:Order', 'Void:Payment'] as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $actor->assignRole($role);
    $this->actingAs($actor);
    ApprovalSetting::where('module_code', 'void_payment')->firstOrFail()->update(['is_active' => false]);
    $order = Order::factory()->create(['status' => OrderStatus::Shipping]);
    $payment = Payment::factory()->for($order)->create(['status' => PaymentStatus::Paid]);

    Livewire::test(ManageOrders::class)
        ->callTableAction('cancelOrder', $order, ['reason' => 'Goods returned'])
        ->assertHasNoTableActionErrors();

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Void);
});

test('frozen system prevents requesting and completing order cancellation', function (bool $requested): void {
    $order = Order::factory()->create(['status' => OrderStatus::Shipping]);
    $payment = Payment::factory()->for($order)->create(['status' => PaymentStatus::Paid]);
    ApprovalSettingLevel::factory()->create(['approval_setting_id' => ApprovalSetting::where('module_code', 'void_payment')->value('id')]);
    if ($requested) {
        $order->cancel($this->actor, 'Returned');
    }
    $warehouse = Warehouse::factory()->create();
    StockOpnameSession::factory()->create(['warehouse_id' => $warehouse->id])->freeze();
    expect(fn () => $requested
        ? app(ApprovalWorkflow::class)->approve($payment->voidRequests()->sole(), $this->actor)
        : $order->cancel($this->actor, 'Returned'))->toThrow(ValidationException::class);
    expect($order->fresh()->status)->toBe(OrderStatus::Shipping);
    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid);
})->with([true, false]);

test('invoice linked payments prevent cancellation through stale orders', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::Shipping]);
    $payment = Payment::factory()->for($order)->create(['status' => PaymentStatus::Paid]);
    $order->load('payment');
    $payment->update(['invoice_id' => Invoice::factory()->create()->id]);
    expect(fn () => $order->cancel($this->actor, 'Returned'))->toThrow(RuntimeException::class, 'Delete the unpaid invoice');
    expect($order->fresh()->status)->toBe(OrderStatus::Shipping);
});

test('customers cannot cancel paid or shipped orders', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::Shipping]);
    expect($order->canBeCancelledByCustomer())->toBeFalse();
    expect(fn () => $order->cancel(null, 'Returned'))->toThrow(ValidationException::class);
    $order->update(['status' => OrderStatus::ReadyToProcess]);
    Payment::factory()->for($order)->create(['status' => PaymentStatus::Paid]);
    expect($order->fresh()->canBeCancelledByCustomer())->toBeFalse();
    expect(fn () => $order->cancel(null, 'Returned'))->toThrow(ValidationException::class);
});

test('expired rejected proofs do not automatically cancel fulfillment already in progress', function (OrderStatus $status): void {
    $order = Order::factory()->create(['status' => $status]);
    $payment = Payment::factory()->for($order)->create(['status' => PaymentStatus::Rejected]);
    PaymentProof::factory()->create([
        'payment_id' => $payment->id, 'status' => PaymentStatus::Rejected,
        'is_current' => true, 'resubmission_deadline_at' => now()->subHour(),
    ]);
    expect($payment->cancelIfResubmissionExpired())->toBeFalse();
    expect($order->fresh()->status)->toBe($status);
})->with([OrderStatus::Packing, OrderStatus::Shipping, OrderStatus::ReadyForPickup]);
