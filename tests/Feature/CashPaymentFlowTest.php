<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\StockMutationSource;
use App\Enums\StockReservationStatus;
use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Models\Permission;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\StockMutation;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Filament\Support\Enums\Width;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('new payments leave Updated At empty until the payment changes', function (): void {
    $payment = Payment::factory()->create();
    $createdAt = $payment->created_at;

    expect($payment->updated_at)->toBeNull();

    $this->travel(1)->minute();
    $payment->update(['status' => PaymentStatus::Paid]);

    expect($payment->refresh()->updated_at)->not->toBeNull()
        ->and($payment->updated_at->greaterThan($createdAt))->toBeTrue();
});

test('unpaid cash fulfillment completes and consumes reserved stock', function (): void {
    $actor = User::factory()->create();
    $order = Order::factory()->create(['payment_method' => PaymentMethod::Cash, 'status' => OrderStatus::WaitingPayment]);
    $variant = ProductVariant::factory()->create(['stock_available' => 10, 'stock_reserved' => 0, 'stock_out' => 0]);
    $item = OrderItem::factory()->for($order)->create(['product_variant_id' => $variant->id, 'quantity' => 2]);
    StockMutation::reserveForOrder($variant, 2, $item->id, $actor->id);
    $order->initializeCashPayment($actor);
    $mutationCount = StockMutation::query()->count();

    foreach ([OrderStatus::Packing, OrderStatus::Shipping, OrderStatus::Done] as $status) {
        $order->advanceStatus($actor);
        expect($order->refresh()->status)->toBe($status)
            ->and($order->payment->status)->toBe(PaymentStatus::Unpaid);
    }

    expect($variant->refresh()->stock_reserved)->toBe('0.000')
        ->and($variant->stock_in_transit)->toBe('0.000')
        ->and($variant->stock_out)->toBe('2.000')
        ->and($variant->stock_available)->toBe('8.000')
        ->and($order->statusLogs()->count())->toBe(4)
        ->and(StockMutation::query()->count())->toBe($mutationCount + 1);
});

test('admin shipping moves reserved stock into transit and done moves it out', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($admin);
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    $order = Order::factory()->create([
        'status' => OrderStatus::Packing,
        'fulfillment_warehouse_id' => $warehouse->id,
    ]);
    $item = OrderItem::factory()->for($order)->create(['product_variant_id' => $variant->id, 'quantity' => 2]);
    StockMutation::reserveForOrder($variant, 2, $item->id, null, $warehouse);

    Livewire::test(ManageOrders::class)
        ->callTableAction('advanceStatus', $order)
        ->assertHasNoTableActionErrors();

    $stock = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_variant_id', $variant->id)->firstOrFail();
    expect($order->refresh()->status)->toBe(OrderStatus::Shipping)
        ->and($order->stock_reservation_status)->toBe(StockReservationStatus::Reserved)
        ->and($variant->refresh()->stock_available)->toBe('8.000')
        ->and($variant->stock_reserved)->toBe('0.000')
        ->and($variant->stock_in_transit)->toBe('2.000')
        ->and($variant->stock_out)->toBe('0.000')
        ->and($stock->stock_available)->toBe('8.000')
        ->and($stock->stock_reserved)->toBe('0.000')
        ->and($stock->stock_in_transit)->toBe('2.000')
        ->and($stock->total_stock)->toBe('10.000');

    $marker = StockMutation::query()->where('source', StockMutationSource::OrderShipped)->where('reference_id', $item->id)->sole();
    expect($marker->quantity_change)->toBe('0.000')
        ->and($marker->stock_before)->toBe('8.000')
        ->and($marker->stock_after)->toBe('8.000');

    $order->advanceStatus();

    expect($order->refresh()->status)->toBe(OrderStatus::Done)
        ->and($variant->refresh()->stock_in_transit)->toBe('0.000')
        ->and($variant->stock_out)->toBe('2.000')
        ->and($stock->refresh()->stock_in_transit)->toBe('0.000')
        ->and($stock->stock_out)->toBe('2.000');
});

test('pending reservation cannot be shipped', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($admin);
    $order = Order::factory()->create([
        'status' => OrderStatus::Packing,
        'stock_reservation_status' => StockReservationStatus::Pending,
    ]);

    Livewire::test(ManageOrders::class)
        ->callTableAction('advanceStatus', $order)
        ->assertNotified('Reserve this order\'s stock with Retry Reservation before shipping.');

    expect(fn () => $order->advanceStatus())->toThrow(ValidationException::class);
    expect($order->refresh()->status)->toBe(OrderStatus::Packing)
        ->and($order->statusLogs()->count())->toBe(0);
});

test('shipping rolls back all item changes if a later item lacks reserved stock', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::Packing]);
    $first = ProductVariant::factory()->create(['stock_available' => 5]);
    $second = ProductVariant::factory()->create(['stock_available' => 5]);
    $firstItem = OrderItem::factory()->for($order)->create(['product_variant_id' => $first->id, 'quantity' => 2]);
    OrderItem::factory()->for($order)->create(['product_variant_id' => $second->id, 'quantity' => 2]);
    StockMutation::reserveForOrder($first, 2, $firstItem->id, null);

    expect(fn () => $order->advanceStatus())->toThrow(InvalidArgumentException::class);

    expect($order->refresh()->status)->toBe(OrderStatus::Packing)
        ->and($first->refresh()->stock_reserved)->toBe('2.000')
        ->and($first->stock_in_transit)->toBe('0.000')
        ->and(StockMutation::query()->where('source', StockMutationSource::OrderShipped)->count())->toBe(0)
        ->and($order->statusLogs()->count())->toBe(0);
});

test('stale shipping request cannot move reserved stock twice', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::Packing]);
    $variant = ProductVariant::factory()->create(['stock_available' => 5]);
    $item = OrderItem::factory()->for($order)->create(['product_variant_id' => $variant->id, 'quantity' => 2]);
    StockMutation::reserveForOrder($variant, 2, $item->id, null);
    $stale = Order::query()->findOrFail($order->id);

    $order->advanceStatus();

    expect(fn () => $stale->advanceStatus())->toThrow(RuntimeException::class)
        ->and($variant->refresh()->stock_reserved)->toBe('0.000')
        ->and($variant->stock_in_transit)->toBe('2.000')
        ->and(StockMutation::query()->where('source', StockMutationSource::OrderShipped)->count())->toBe(1)
        ->and($order->statusLogs()->count())->toBe(1);
});

test('a shipping order from before the stock change completes from reserved stock', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_available' => 5]);
    $order = Order::factory()->create([
        'status' => OrderStatus::Shipping,
        'fulfillment_warehouse_id' => $warehouse->id,
    ]);
    $item = OrderItem::factory()->for($order)->create(['product_variant_id' => $variant->id, 'quantity' => 2]);
    StockMutation::reserveForOrder($variant, 2, $item->id, null, $warehouse);

    $order->advanceStatus();

    $stock = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_variant_id', $variant->id)->firstOrFail();
    expect($order->refresh()->status)->toBe(OrderStatus::Done)
        ->and($variant->refresh()->stock_reserved)->toBe('0.000')
        ->and($variant->stock_in_transit)->toBe('0.000')
        ->and($variant->stock_out)->toBe('2.000')
        ->and($stock->stock_reserved)->toBe('0.000')
        ->and($stock->stock_in_transit)->toBe('0.000')
        ->and($stock->stock_out)->toBe('2.000');
});

test('stale order completion cannot consume reserved stock twice without fulfillment mutations', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::Shipping]);
    $variant = ProductVariant::factory()->create(['stock_available' => 26, 'stock_reserved' => 4, 'stock_out' => 0]);
    OrderItem::factory()->for($order)->create(['product_variant_id' => $variant->id, 'quantity' => 2]);
    $stale = Order::findOrFail($order->id);

    $order->advanceStatus();

    expect(fn () => $stale->advanceStatus())->toThrow(RuntimeException::class)
        ->and($variant->refresh()->stock_available)->toBe('26.000')
        ->and($variant->stock_reserved)->toBe('2.000')
        ->and($variant->stock_out)->toBe('2.000')
        ->and($order->statusLogs()->count())->toBe(1)
        ->and(StockMutation::query()->where('source', StockMutationSource::OrderFulfilled)->exists())->toBeFalse();
});

test('cash confirmation preserves the current fulfillment stage and rejects stale retries', function (OrderStatus $status): void {
    $actor = User::factory()->create();
    $order = Order::factory()->create(['payment_method' => PaymentMethod::Cash, 'status' => $status]);
    $payment = Payment::factory()->for($order)->create(['method' => PaymentMethod::Cash, 'status' => PaymentStatus::Unpaid]);
    $stale = Payment::findOrFail($payment->id);

    $payment->confirm($actor);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Paid)
        ->and($payment->verified_by)->toBe($actor->id)
        ->and($payment->verified_at)->not->toBeNull()
        ->and($order->refresh()->status)->toBe($status)
        ->and($order->statusLogs()->count())->toBe(0);
    expect(fn () => $stale->confirm($actor))->toThrow(RuntimeException::class);
})->with([OrderStatus::ReadyToProcess, OrderStatus::Packing, OrderStatus::Shipping, OrderStatus::Done]);

test('cancelling an unpaid cash order marks its payment unpaid-cancel and releases stock', function (): void {
    $actor = User::factory()->create();
    $actor->givePermissionTo(Permission::findOrCreate('Delete:Order', 'web'));
    $order = Order::factory()->create(['payment_method' => PaymentMethod::Cash, 'status' => OrderStatus::WaitingPayment]);
    $variant = ProductVariant::factory()->create(['stock_available' => 10, 'stock_reserved' => 0]);
    $item = OrderItem::factory()->for($order)->create(['product_variant_id' => $variant->id, 'quantity' => 2]);
    StockMutation::reserveForOrder($variant, 2, $item->id, $actor->id);
    $order->initializeCashPayment($actor);
    $order->cancel($actor);

    expect($order->payment->status)->toBe(PaymentStatus::UnpaidCancel)
        ->and($order->payment->canBeConfirmed())->toBeFalse()
        ->and($variant->refresh()->stock_available)->toBe('10.000')
        ->and($variant->stock_reserved)->toBe('0.000');
    $cancellation = StockMutation::query()
        ->where('reference_id', $item->id)
        ->where('source', StockMutationSource::CancelOrder)
        ->sole();
    expect($cancellation->stock_before)->toBe('8.000')
        ->and($cancellation->quantity_change)->toBe('2.000')
        ->and($cancellation->stock_after)->toBe('10.000')
        ->and($cancellation->source->getLabel())->toBe('Cancel Order');
    expect(fn () => $order->payment->confirm($actor))->toThrow(RuntimeException::class);
});

test('unpaid qris stays waiting for payment until proof is uploaded', function (): void {
    $order = Order::factory()->create(['payment_method' => PaymentMethod::Qris, 'status' => OrderStatus::WaitingPayment]);
    $payment = Payment::factory()->for($order)->create([
        'method' => PaymentMethod::Qris,
        'status' => PaymentStatus::Unpaid,
        'proof_upload_deadline_at' => now()->addDay(),
    ]);

    expect($order->canStartPacking())->toBeFalse()
        ->and($order->nextStatus())->toBeNull()
        ->and($payment->canBeConfirmed())->toBeFalse();
});

test('payment confirmation uses the verify permission and shows unpaid records', function (): void {
    $actor = User::factory()->create();
    foreach (['ViewAny:Payment', 'Verify:Payment'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $actor->givePermissionTo('ViewAny:Payment');
    $this->actingAs($actor);
    $order = Order::factory()->create(['payment_method' => PaymentMethod::Cash, 'status' => OrderStatus::Done]);
    $payment = Payment::factory()->for($order)->create(['method' => PaymentMethod::Cash, 'status' => PaymentStatus::Unpaid]);

    expect(PaymentResource::getNavigationBadge())->toBe('1');
    expect($actor->can('verify', $payment))->toBeFalse();
    Livewire::test(ManagePayments::class)
        ->assertCanSeeTableRecords([$payment])
        ->assertTableActionHidden('verifyPayment', $payment);
    $actor->givePermissionTo('Verify:Payment');
    Livewire::test(ManagePayments::class)
        ->assertTableActionVisible('verifyPayment', $payment)
        ->callTableAction('verifyPayment', $payment);
    expect($payment->refresh()->status)->toBe(PaymentStatus::Paid)
        ->and(PaymentResource::getNavigationBadge())->toBe('0');
});

test('only paid payments show the void action in the payments table', function (): void {
    $actor = User::factory()->create();
    foreach (['ViewAny:Payment', 'Void:Payment'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $actor->givePermissionTo(['ViewAny:Payment', 'Void:Payment']);
    $this->actingAs($actor);

    $order = Order::factory()->create([
        'payment_method' => PaymentMethod::Cash,
        'status' => OrderStatus::ReadyToProcess,
    ]);
    $payment = Payment::factory()->for($order)->create([
        'method' => PaymentMethod::Cash,
        'status' => PaymentStatus::Unpaid,
        'created_by' => $actor->id,
    ]);

    Livewire::test(ManagePayments::class)
        ->assertTableActionHidden('voidPayment', $payment);

    $paidOrder = Order::factory()->create([
        'payment_method' => PaymentMethod::Cash,
        'status' => OrderStatus::ReadyToProcess,
    ]);
    $paidPayment = Payment::factory()->for($paidOrder)->create([
        'method' => PaymentMethod::Cash,
        'status' => PaymentStatus::Paid,
    ]);

    Livewire::test(ManagePayments::class)
        ->assertTableActionVisible('voidPayment', $paidPayment)
        ->assertTableActionHasColor('voidPayment', 'danger', $paidPayment)
        ->callTableAction('voidPayment', $paidPayment, ['reason' => 'Duplicate payment'])
        ->assertHasNoTableActionErrors();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Unpaid)
        ->and($paidPayment->refresh()->status)->toBe(PaymentStatus::Void)
        ->and(PaymentStatus::Void->getColor())->toBe('danger');
});

test('admin payments can be searched by payment method', function (): void {
    $actor = User::factory()->create();
    Permission::findOrCreate('ViewAny:Payment', 'web');
    $actor->givePermissionTo('ViewAny:Payment');
    $this->actingAs($actor);

    $cashPayment = Payment::factory()->create([
        'method' => PaymentMethod::Cash,
        'status' => PaymentStatus::Paid,
        'verified_at' => now(),
    ]);
    $qrisPayment = Payment::factory()->create(['method' => PaymentMethod::Qris]);

    $component = Livewire::test(ManagePayments::class)
        ->assertTableColumnDoesNotExist('submitted_at')
        ->assertTableColumnExists('created_at')
        ->assertTableColumnVisible('verified_at')
        ->assertTableColumnStateSet('verified_at', $cashPayment->verified_at, $cashPayment);

    expect($component->instance()->getTable()->getColumn('method')->getLabel())
        ->toBe('Payment Method')
        ->and($component->instance()->getTable()->getColumn('verified_at')->getLabel())
        ->toBe('Verified At');

    $component
        ->searchTable(PaymentMethod::Qris->value)
        ->assertCanSeeTableRecords([$qrisPayment])
        ->assertCanNotSeeTableRecords([$cashPayment]);
});

test('admin payments use the stock mutation date range filter configuration', function (): void {
    $actor = User::factory()->create();
    Permission::findOrCreate('ViewAny:Payment', 'web');
    $actor->givePermissionTo('ViewAny:Payment');
    $this->actingAs($actor);

    $table = Livewire::test(ManagePayments::class)->instance()->getTable();
    $filter = $table->getFilter('created_at');

    expect($filter)->not->toBeNull()
        ->and($filter->getLabel())->toBe('Date')
        ->and($filter->getDisplayFormat())->toBe('d/m/Y')
        ->and($filter->getStartPlaceholder())->toBe('dd/mm/yyyy')
        ->and($filter->getEndPlaceholder())->toBe('dd/mm/yyyy')
        ->and($filter->getFirstDayOfWeek())->toBe(1)
        ->and($filter->shouldDisplayDualCalendar())->toBeFalse()
        ->and($filter->isInline())->toBeTrue()
        ->and($filter->getLocale())->toBe('en')
        ->and($filter->getTimezone())->toBe('Asia/Jakarta')
        ->and($table->getFiltersFormWidth())->toBe(Width::Large);
});

test('payment view shows created at instead of submitted at', function (): void {
    $actor = User::factory()->create();
    foreach (['ViewAny:Payment', 'View:Payment'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $actor->givePermissionTo(['ViewAny:Payment', 'View:Payment']);
    $this->actingAs($actor);

    $payment = Payment::factory()->create();

    $modal = Livewire::test(ManagePayments::class)
        ->mountTableAction('view', $payment)
        ->assertActionMounted()
        ->getMountedActionModalHtml();

    expect($modal)
        ->toContain('Created At')
        ->not->toContain('Submitted At');
});

test('qris confirmation reuses an existing proof without showing an upload', function (PaymentStatus $status): void {
    $actor = User::factory()->create();
    foreach (['ViewAny:Payment', 'Verify:Payment'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $actor->givePermissionTo(['ViewAny:Payment', 'Verify:Payment']);
    $this->actingAs($actor);
    $order = Order::factory()->create(['payment_method' => PaymentMethod::Qris, 'status' => OrderStatus::ReadyToProcess]);
    $payment = Payment::factory()->for($order)->create(['method' => PaymentMethod::Qris, 'status' => $status]);
    $proof = PaymentProof::factory()->for($payment)->create();

    Livewire::test(ManagePayments::class)
        ->mountTableAction('verifyPayment', $payment)
        ->assertSchemaComponentVisible('current_payment_proof')
        ->assertFormFieldHidden('payment_proof')
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Paid)
        ->and($payment->proofs()->count())->toBe(1)
        ->and($payment->currentProof()->id)->toBe($proof->id)
        ->and($proof->refresh()->status)->toBe(PaymentStatus::Paid);
})->with([PaymentStatus::Unpaid, PaymentStatus::WaitingConfirm]);

test('qris confirmation still requires an upload when no current proof exists', function (): void {
    $actor = User::factory()->create();
    foreach (['ViewAny:Payment', 'Verify:Payment'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $actor->givePermissionTo(['ViewAny:Payment', 'Verify:Payment']);
    $this->actingAs($actor);
    $order = Order::factory()->create(['payment_method' => PaymentMethod::Qris, 'status' => OrderStatus::ReadyToProcess]);
    $payment = Payment::factory()->for($order)->create(['method' => PaymentMethod::Qris, 'status' => PaymentStatus::Unpaid]);
    PaymentProof::factory()->for($payment)->create(['is_current' => false]);

    Livewire::test(ManagePayments::class)
        ->mountTableAction('verifyPayment', $payment)
        ->assertFormFieldVisible('payment_proof')
        ->assertSchemaComponentHidden('current_payment_proof')
        ->callMountedTableAction()
        ->assertHasTableActionErrors(['payment_proof' => 'required']);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Unpaid);
});

test('cash order actions allow packing without recording payment', function (): void {
    $actor = User::factory()->create();
    foreach (['ViewAny:Order', 'AdvanceStatus:Order'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $actor->givePermissionTo(['ViewAny:Order', 'AdvanceStatus:Order']);
    $this->actingAs($actor);
    $order = Order::factory()->create(['payment_method' => PaymentMethod::Cash, 'status' => OrderStatus::WaitingPayment]);
    $order->initializeCashPayment($actor);

    Livewire::test(ManageOrders::class)
        ->assertCanSeeTableRecords([$order])
        ->assertTableActionDoesNotExist('recordPayment')
        ->callTableAction('advanceStatus', $order);

    expect($order->refresh()->status)->toBe(OrderStatus::Packing)
        ->and($order->payment->status)->toBe(PaymentStatus::Unpaid);
});

test('qris review keeps its dedicated permissions', function (): void {
    $actor = User::factory()->create();
    $order = Order::factory()->create();
    $payment = Payment::factory()->for($order)->create();
    $this->actingAs($actor);

    foreach (['Reject:Payment', 'Void:Payment'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    expect($actor->can('reject', $payment))->toBeFalse()
        ->and($actor->can('void', $payment))->toBeFalse();
    $actor->givePermissionTo(['Reject:Payment', 'Void:Payment']);
    expect($actor->can('reject', $payment))->toBeTrue()
        ->and($actor->can('void', $payment))->toBeTrue();
});

test('retired record payment action stays absent for cash and rejected qris orders', function (): void {
    $actor = User::factory()->create();
    foreach (['ViewAny:Order', 'RecordPayment:Order'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $actor->givePermissionTo(['ViewAny:Order', 'RecordPayment:Order']);
    $this->actingAs($actor);

    $cashOrder = Order::factory()->create(['payment_method' => PaymentMethod::Cash, 'status' => OrderStatus::WaitingPayment]);
    $cashOrder->initializeCashPayment($actor);

    $qrisOrder = Order::factory()->create(['payment_method' => PaymentMethod::Qris, 'status' => OrderStatus::WaitingConfirmation]);
    $payment = Payment::factory()->for($qrisOrder)->create([
        'method' => PaymentMethod::Qris,
        'status' => PaymentStatus::WaitingConfirm,
    ]);
    PaymentProof::factory()->for($payment)->create();
    $payment->reject($actor, 'Proof is not readable.');

    expect($qrisOrder->refresh()->status)->toBe(OrderStatus::WaitingPayment)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Rejected)
        ->and($qrisOrder->payment->is($payment))->toBeTrue();

    Livewire::test(ManageOrders::class)
        ->assertCanSeeTableRecords([$cashOrder, $qrisOrder])
        ->assertTableActionDoesNotExist('recordPayment');

    expect(Permission::query()->where('name', 'RecordPayment:Order')->exists())->toBeTrue()
        ->and(array_key_exists('RecordPayment:Order', config('filament-shield.custom_permissions')))->toBeFalse();

    $replacement = $payment->submitProof('payment-proofs/replacement.jpg', $actor);

    expect($replacement->is_current)->toBeTrue()
        ->and($payment->refresh()->status)->toBe(PaymentStatus::WaitingConfirm)
        ->and($qrisOrder->refresh()->status)->toBe(OrderStatus::WaitingConfirmation)
        ->and($qrisOrder->payment->is($payment))->toBeTrue()
        ->and($payment->proofs()->count())->toBe(2);
});
