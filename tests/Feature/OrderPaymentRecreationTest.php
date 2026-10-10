<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Models\PaymentVoidRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemFreezeState;
use App\Models\User;
use App\Services\OrderPaymentRecreationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actor = User::factory()->create();
    $this->actor->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($this->actor);
    $this->order = Order::factory()->create(['status' => OrderStatus::ReadyToProcess, 'payment_method' => PaymentMethod::Qris, 'total' => 55000]);
    $this->previous = Payment::factory()->for($this->order)->create(['status' => PaymentStatus::Void, 'method' => PaymentMethod::Qris]);
});

test('orders table creates a replacement payment and preserves void history', function (PaymentMethod $method): void {
    $proof = PaymentProof::factory()->for($this->previous)->create(['status' => PaymentStatus::Paid, 'reviewed_by' => $this->actor->id, 'reviewed_at' => now()]);
    $request = PaymentVoidRequest::factory()->for($this->previous)->create(['approval_status' => 'approved', 'requested_by' => $this->actor->id]);
    $proofAttributes = $proof->fresh()->getAttributes();
    $requestAttributes = $request->fresh()->getAttributes();
    $previousAttributes = $this->previous->fresh()->getAttributes();
    $stockStatus = $this->order->stock_reservation_status;
    Livewire::test(ManageOrders::class)
        ->assertTableActionVisible('createPayment', $this->order)
        ->callTableAction('createPayment', $this->order, ['method' => $method->value, 'expected_payment_id' => $this->previous->id])
        ->assertHasNoTableActionErrors();
    $order = $this->order->fresh('payment');
    expect($order->payment->status)->toBe(PaymentStatus::Unpaid)
        ->and($order->payment->method)->toBe($method)
        ->and($order->payment->amount)->toBe('55000.00')
        ->and($order->payment->attempt_number)->toBe(2)
        ->and($order->payment->created_by)->toBe($this->actor->id)
        ->and($order->payment->payment_number)->not->toBe($this->previous->payment_number)
        ->and($order->payment->proof_upload_deadline_at)->toBeNull()
        ->and($order->payment->verified_at)->toBeNull()
        ->and($order->payment->proofs()->count())->toBe(0)
        ->and($order->payment_method)->toBe($method)
        ->and($order->status)->toBe(OrderStatus::ReadyToProcess)
        ->and($order->stock_reservation_status)->toBe($stockStatus)
        ->and($order->payments()->count())->toBe(2);
    expect($this->previous->fresh()->getAttributes())->toBe($previousAttributes);
    expect($proof->fresh()->getAttributes())->toBe($proofAttributes);
    expect($request->fresh()->getAttributes())->toBe($requestAttributes);
    Livewire::test(ManageOrders::class)->assertTableActionHidden('createPayment', $order);
})->with([PaymentMethod::Cash, PaymentMethod::Qris, PaymentMethod::BankTransfer]);

test('latest attempt controls lazy eager and filtered order relationships', function (): void {
    $service = app(OrderPaymentRecreationService::class);
    $second = $service->recreate($this->order, PaymentMethod::Cash, $this->actor, $this->previous->id);
    $second->void($this->actor);
    $third = $service->recreate($this->order, PaymentMethod::BankTransfer, $this->actor, $second->id);
    expect($this->order->fresh()->payment->id)->toBe($third->id)
        ->and($this->order->fresh('payment')->payment->id)->toBe($third->id)
        ->and(Order::whereKey($this->order)->whereHas('payment', fn ($query) => $query->where('status', PaymentStatus::Void))->exists())->toBeFalse();
    Livewire::test(ManageOrders::class)->filterTable('payment_status', PaymentStatus::Void->value)->assertCanNotSeeTableRecords([$this->order]);
    expect($this->previous->fresh()->canSubmitProof())->toBeFalse();
    expect(fn () => $this->previous->submitProof('old.png', $this->actor))->toThrow(RuntimeException::class);
});

test('stale submissions cannot create another payment', function (): void {
    $service = app(OrderPaymentRecreationService::class);
    $service->recreate($this->order, PaymentMethod::Cash, $this->actor, $this->previous->id);
    expect(fn () => $service->recreate($this->order, PaymentMethod::Qris, $this->actor, $this->previous->id))->toThrow(ValidationException::class);
    expect($this->order->payments()->count())->toBe(2);
});

test('cancelled orders and changed payment states are rejected', function (bool $cancelled): void {
    if ($cancelled) {
        $this->order->update(['status' => OrderStatus::Cancelled]);
    } else {
        $this->previous->update(['status' => PaymentStatus::Paid]);
    }
    Livewire::test(ManageOrders::class)->assertTableActionHidden('createPayment', $this->order);
    expect(fn () => app(OrderPaymentRecreationService::class)->recreate($this->order, PaymentMethod::Cash, $this->actor, $this->previous->id))->toThrow(ValidationException::class);
    expect($this->order->payments()->count())->toBe(1);
})->with([true, false]);

test('frozen system rejects payment recreation', function (): void {
    SystemFreezeState::activate(actorId: $this->actor->id);
    Livewire::test(ManageOrders::class)->assertTableActionDisabled('createPayment', $this->order);
    expect(fn () => app(OrderPaymentRecreationService::class)->recreate($this->order, PaymentMethod::Cash, $this->actor, $this->previous->id))->toThrow(ValidationException::class);
    expect($this->order->payments()->count())->toBe(1);
});

test('replacement payment supports proof submission and confirmation', function (): void {
    $new = app(OrderPaymentRecreationService::class)->recreate($this->order, PaymentMethod::Qris, $this->actor, $this->previous->id);
    $new->submitProof('new.png', $this->actor);
    $new->confirm($this->actor);
    expect($this->order->fresh('payment')->payment->status)->toBe(PaymentStatus::Paid)
        ->and($this->previous->fresh()->status)->toBe(PaymentStatus::Void);
});

test('both order update and payment creation permissions are required', function (string $missing): void {
    $actor = User::factory()->create();
    foreach (array_diff(['ViewAny:Order', 'Update:Order', 'Create:Payment'], [$missing]) as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($actor);
    Livewire::test(ManageOrders::class)->assertTableActionHidden('createPayment', $this->order);
    expect(fn () => app(OrderPaymentRecreationService::class)->recreate($this->order, PaymentMethod::Cash, $actor, $this->previous->id))->toThrow(AuthorizationException::class);
    expect($this->order->payments()->count())->toBe(1);
})->with(['Update:Order', 'Create:Payment']);

test('invoice linked void payments cannot be replaced', function (): void {
    $this->previous->update(['invoice_id' => Invoice::factory()->create()->id]);
    Livewire::test(ManageOrders::class)->assertTableActionHidden('createPayment', $this->order);
    expect(fn () => app(OrderPaymentRecreationService::class)->recreate($this->order, PaymentMethod::Cash, $this->actor, $this->previous->id))->toThrow(ValidationException::class);
    expect($this->order->payments()->count())->toBe(1);
});

test('invoice is not an allowed replacement method', function (): void {
    expect(fn () => app(OrderPaymentRecreationService::class)->recreate($this->order, PaymentMethod::Invoice, $this->actor, $this->previous->id))->toThrow(ValidationException::class);
    expect($this->order->payments()->count())->toBe(1);
});

test('later fulfillment statuses are preserved when replacing payment', function (OrderStatus $status): void {
    $this->order->update(['status' => $status]);
    app(OrderPaymentRecreationService::class)->recreate($this->order, PaymentMethod::Cash, $this->actor, $this->previous->id);
    expect($this->order->fresh()->status)->toBe($status);
})->with([OrderStatus::Packing, OrderStatus::Shipping, OrderStatus::Done]);

test('payment attempt migration preserves existing payments on rollback and upgrade', function (): void {
    $migration = require database_path('migrations/2026_10_03_143712_add_attempt_number_to_payments_table.php');
    $number = $this->previous->payment_number;
    $migration->down();
    $migration->up();
    expect($this->previous->fresh()->attempt_number)->toBe(1)
        ->and($this->previous->fresh()->payment_number)->toBe($number)
        ->and($this->order->fresh('payment')->payment->id)->toBe($this->previous->id);
});

test('migration rollback refuses to discard payment history', function (): void {
    app(OrderPaymentRecreationService::class)->recreate($this->order, PaymentMethod::Cash, $this->actor, $this->previous->id);
    $migration = require database_path('migrations/2026_10_03_143712_add_attempt_number_to_payments_table.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Cannot roll back payment attempts');
    expect($this->order->payments()->count())->toBe(2);
});
