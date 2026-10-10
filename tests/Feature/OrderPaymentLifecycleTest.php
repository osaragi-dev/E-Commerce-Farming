<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ShippingMethod;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Filament\Resources\Orders\Schemas\OrderForm;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Filament\Resources\Payments\Schemas\PaymentInfolist;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Models\PaymentSetting;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Contracts\TranslatableContentDriver;
use Livewire\Component as LivewireComponent;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $role = Role::findOrCreate('super_admin', 'web');
    $this->actor = User::factory()->create();
    $this->actor->assignRole($role);
    $this->actingAs($this->actor);
});

test('orders navigation badge excludes done and cancelled orders', function (): void {
    $activeOrder = Order::factory()->create(['status' => OrderStatus::ReadyToProcess]);
    Order::factory()->create(['status' => OrderStatus::Done]);
    Order::factory()->create(['status' => OrderStatus::Cancelled]);

    expect(OrderResource::getNavigationBadge())->toBe('1');

    $activeOrder->update(['status' => OrderStatus::Done]);

    expect(OrderResource::getNavigationBadge())->toBe('0');
});

test('a new payment starts waiting confirm', function (): void {
    $payment = Payment::factory()->create();

    expect($payment->status)->toBe(PaymentStatus::WaitingConfirm);
});

test('payment numbers use the PAY-YYYYMMDD-xxx format', function (): void {
    expect(Payment::generatePaymentNumber())->toMatch('/^PAY-\d{8}-\d{3}$/');

    $payment = Payment::factory()->create();

    expect($payment->payment_number)->toMatch('/^PAY-\d{8}-\d{3}$/');
});

test('qris payment schema exposes the company qr and customer proof fields', function (): void {
    $livewire = new class extends LivewireComponent implements HasSchemas
    {
        public function makeFilamentTranslatableContentDriver(): ?TranslatableContentDriver
        {
            return null;
        }

        public function getOldSchemaState(string $statePath): mixed
        {
            return null;
        }

        public function getSchemaComponent(string $key, bool $withHidden = false, array $skipComponentsChildContainersWhileSearching = []): Component|Action|ActionGroup|null
        {
            return null;
        }

        public function getSchema(string $name): ?Schema
        {
            return null;
        }

        public function currentlyValidatingSchema(?Schema $schema): void {}

        public function getDefaultTestingSchemaName(): ?string
        {
            return null;
        }
    };

    $paymentSchema = PaymentInfolist::configure(Schema::make($livewire));
    $orderFormSchema = OrderForm::configure(Schema::make($livewire));

    $paymentComponents = collect($paymentSchema->getFlatComponents(withActions: false, withHidden: true, withAbsoluteKeys: true));
    $orderComponents = collect($orderFormSchema->getFlatComponents(withActions: false, withHidden: true, withAbsoluteKeys: true));

    expect($paymentComponents->contains(fn ($component) => $component->getStatePath() === 'company_qris'))
        ->toBeTrue()
        ->and($paymentComponents->contains(fn ($component) => $component->getStatePath() === 'qris_info'))->toBeTrue()
        ->and($paymentComponents->contains(fn ($component) => $component->getStatePath() === 'proof_image'))->toBeTrue()
        ->and($paymentComponents->contains(fn ($component) => $component->getStatePath() === 'proof_filename'))->toBeTrue()
        ->and($orderComponents->contains(fn ($component) => $component->getStatePath() === 'payment_method'))->toBeTrue();
});

// ==========================================================================
// Coverage A/B — Checkout-level lifecycle results (model-level equivalent;
// the full Livewire Checkout flow itself is covered separately in
// CheckoutTest.php — these are direct, focused checks on Order/Payment
// state, not a duplicate of that Livewire test).
// ==========================================================================

test('coverage A: a cash order with no payment yet stays at waiting payment', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::WaitingPayment,
        'payment_method' => PaymentMethod::Cash,
    ]);

    expect($order->payment)->toBeNull()
        ->and($order->paymentStageLabel())->toBe('Not Recorded')
        ->and($order->status)->toBe(OrderStatus::WaitingPayment);
});

test('coverage B: a qris order moves to waiting confirmation once payment and proof exist', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::WaitingPayment,
        'payment_method' => PaymentMethod::Qris,
    ]);

    $payment = Payment::factory()->for($order)->create(['method' => PaymentMethod::Qris]);
    $payment->submitProof('payment-proofs/checkout-proof.jpg');
    $order->markWaitingConfirmation($this->actor);

    expect($payment->currentProof())->not->toBeNull()
        ->and($payment->status)->toBe(PaymentStatus::WaitingConfirm)
        ->and($order->refresh()->status)->toBe(OrderStatus::WaitingConfirmation);
});

// ==========================================================================
// Coverage C — Cash payment initialization
// ==========================================================================

test('coverage C: creating a cash order records an unpaid payment and makes it ready to process', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::WaitingPayment,
        'payment_method' => PaymentMethod::Cash,
    ]);

    expect($order->payment)->toBeNull();

    $order->initializeCashPayment($this->actor);

    expect($order->refresh()->status)->toBe(OrderStatus::ReadyToProcess)
        ->and($order->payment->method)->toBe(PaymentMethod::Cash)
        ->and($order->payment->amount)->toBe($order->total)
        ->and($order->payment->status)->toBe(PaymentStatus::Unpaid);
});

test('admin confirms payment and it becomes lunas', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::WaitingConfirmation]);
    $payment = Payment::factory()->for($order)->create();
    PaymentProof::factory()->for($payment)->create();

    $payment->confirm($this->actor);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Paid)
        ->and($payment->verified_by)->toBe($this->actor->id)
        ->and($payment->verified_at)->not->toBeNull();
});

// ==========================================================================
// Coverage D — Confirm Payment: WaitingConfirmation -> ReadyToProcess
// ==========================================================================

test('coverage D: confirming a payment moves the order to ready to process automatically', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::WaitingConfirmation]);
    $payment = Payment::factory()->for($order)->create();
    PaymentProof::factory()->for($payment)->create();

    $payment->confirm($this->actor);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Paid)
        ->and($order->refresh()->status)->toBe(OrderStatus::ReadyToProcess)
        ->and($order->statusLogs()->count())->toBe(1)
        ->and($order->statusLogs()->first()->from_status)->toBe(OrderStatus::WaitingConfirmation)
        ->and($order->statusLogs()->first()->to_status)->toBe(OrderStatus::ReadyToProcess);
});

test('an order becomes ready to process after confirm, and can then be moved to packing manually', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::WaitingConfirmation]);
    $payment = Payment::factory()->for($order)->create();
    PaymentProof::factory()->for($payment)->create();

    $payment->confirm($this->actor);

    expect($order->refresh()->status)->toBe(OrderStatus::ReadyToProcess)
        ->and($order->canStartPacking())->toBeTrue()
        ->and($order->nextStatus())->toBe(OrderStatus::Packing);

    $order->advanceStatus($this->actor);

    expect($order->refresh()->status)->toBe(OrderStatus::Packing)
        ->and($order->statusLogs()->first()->to_status)->toBe(OrderStatus::Packing);
});

test('waiting payment order cannot move directly to packing even if the payment is already paid', function (): void {
    // Simulates stale/pre-existing data (or a bypass of confirm()): a
    // Payment that is Paid while the Order is still WaitingPayment (never
    // even reached WaitingConfirmation). Under the STRICT rule, Packing is
    // only ever reachable from ReadyToProcess — this combination must NOT
    // be packing-eligible.
    $order = Order::factory()->create(['status' => OrderStatus::WaitingPayment]);
    Payment::factory()->for($order)->create(['status' => PaymentStatus::Paid]);

    expect($order->canStartPacking())->toBeFalse()
        ->and($order->nextStatus())->toBeNull();

    expect(fn () => $order->advanceStatus($this->actor))
        ->toThrow(RuntimeException::class);

    expect($order->refresh()->status)->toBe(OrderStatus::WaitingPayment);
});

test('stale waiting confirmation order with a paid payment cannot move directly to packing', function (): void {
    // A paid payment should normally move the order to ReadyToProcess. Keep
    // this guard for stale data that still has WaitingConfirmation.
    $order = Order::factory()->create(['status' => OrderStatus::WaitingConfirmation]);
    Payment::factory()->for($order)->create(['status' => PaymentStatus::Paid]);

    expect($order->canStartPacking())->toBeFalse()
        ->and($order->nextStatus())->toBeNull();

    expect(fn () => $order->advanceStatus($this->actor))
        ->toThrow(RuntimeException::class);

    expect($order->refresh()->status)->toBe(OrderStatus::WaitingConfirmation);
});

// ==========================================================================
// Coverage E — Reject Payment: WaitingConfirmation -> WaitingPayment
// ==========================================================================

test('admin rejects payment and it becomes ditolak', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::WaitingConfirmation]);
    $payment = Payment::factory()->for($order)->create();
    PaymentProof::factory()->for($payment)->create();

    $payment->reject($this->actor, 'Nominal payment tidak sesuai');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Rejected)
        ->and($payment->rejection_reason)->toBe('Nominal payment tidak sesuai');
});

test('rejecting a payment sets the configured proof resubmission deadline', function (): void {
    $this->travelTo('2026-09-17 10:00:00');
    PaymentSetting::current()->update(['proof_resubmission_hours' => 48]);

    $order = Order::factory()->create(['status' => OrderStatus::WaitingConfirmation]);
    $payment = Payment::factory()->for($order)->create([
        'method' => PaymentMethod::Qris,
    ]);
    $proof = PaymentProof::factory()->for($payment)->create();

    $payment->reject($this->actor, 'Proof is not readable.');

    expect($proof->refresh()->resubmission_deadline_at?->format('Y-m-d H:i'))
        ->toBe('2026-09-19 10:00')
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Rejected)
        ->and($order->refresh()->status)->toBe(OrderStatus::WaitingPayment);
});

test('a new rejection resets the proof resubmission deadline', function (): void {
    $this->travelTo('2026-09-17 10:00:00');
    PaymentSetting::current()->update(['proof_resubmission_hours' => 24]);

    $order = Order::factory()->create(['status' => OrderStatus::WaitingConfirmation]);
    $payment = Payment::factory()->for($order)->create([
        'method' => PaymentMethod::Qris,
    ]);
    $oldProof = PaymentProof::factory()->for($payment)->create();

    $payment->reject($this->actor, 'First proof is not readable.');
    $firstDeadline = $oldProof->refresh()->resubmission_deadline_at;

    $this->travelTo('2026-09-17 12:00:00');
    $newProof = $payment->refresh()->submitProof('payment-proofs/replacement.jpg', $this->actor);
    $payment->reject($this->actor, 'Second proof is not readable.');

    expect($oldProof->refresh()->resubmission_deadline_at)->toEqual($firstDeadline)
        ->and($oldProof->is_current)->toBeFalse()
        ->and($newProof->refresh()->resubmission_deadline_at?->format('Y-m-d H:i'))
        ->toBe('2026-09-18 12:00');
});

test('coverage E: rejecting a payment moves the order back to waiting payment, never cancels or advances it', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::WaitingConfirmation]);
    $payment = Payment::factory()->for($order)->create();
    PaymentProof::factory()->for($payment)->create();

    $payment->reject($this->actor, 'Bukti pembayaran tidak jelas');

    expect($order->refresh()->status)->toBe(OrderStatus::WaitingPayment)
        ->and($order->statusLogs()->first()->from_status)->toBe(OrderStatus::WaitingConfirmation)
        ->and($order->statusLogs()->first()->to_status)->toBe(OrderStatus::WaitingPayment);
});

test('reject requires a rejection reason', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::WaitingConfirmation]);
    $payment = Payment::factory()->for($order)->create();
    PaymentProof::factory()->for($payment)->create();

    expect(fn () => $payment->reject($this->actor, ''))
        ->toThrow(InvalidArgumentException::class);

    expect($order->refresh()->status)->toBe(OrderStatus::WaitingConfirmation);
});

// ==========================================================================
// Coverage F — Re-submit QRIS proof: WaitingPayment -> WaitingConfirmation
// ==========================================================================

test('coverage F: rejected payment can be resubmitted, order returns to waiting confirmation, proof history is preserved', function (): void {
    // Payment::factory() auto-creates its own Order (default status
    // WaitingPayment — see OrderFactory), which is exactly the correct
    // precondition here: after a Reject, the order sits at WaitingPayment
    // (see coverage E above) until the customer uploads a new proof.
    $payment = Payment::factory()->create(['method' => PaymentMethod::Qris, 'status' => PaymentStatus::Rejected, 'rejection_reason' => 'Bukti tidak jelas']);
    $oldProof = PaymentProof::factory()->for($payment)->create(['status' => PaymentStatus::Rejected, 'is_current' => true]);

    expect($payment->order->status)->toBe(OrderStatus::WaitingPayment);

    $newProof = $payment->submitProof('payment-proofs/new-proof.jpg', $this->actor);

    expect($payment->refresh()->status)->toBe(PaymentStatus::WaitingConfirm)
        ->and($payment->rejection_reason)->toBeNull()
        ->and($newProof->is_current)->toBeTrue()
        ->and($oldProof->refresh()->is_current)->toBeFalse()
        ->and($payment->order->refresh()->status)->toBe(OrderStatus::WaitingConfirmation);

    // History is preserved, not deleted.
    expect($payment->proofs()->count())->toBe(2);
});

test('an unpaid qris proof moves the waiting order to waiting confirmation', function (): void {
    $order = Order::factory()->create([
        'payment_method' => PaymentMethod::Qris,
        'status' => OrderStatus::WaitingPayment,
    ]);
    $payment = Payment::factory()->for($order)->create([
        'method' => PaymentMethod::Qris,
        'status' => PaymentStatus::Unpaid,
        'proof_upload_deadline_at' => now()->addDay(),
    ]);

    $payment->submitProof('payment-proofs/initial-proof.jpg', $this->actor);

    expect($payment->refresh()->status)->toBe(PaymentStatus::WaitingConfirm)
        ->and($payment->proof_upload_deadline_at)->toBeNull()
        ->and($payment->currentProof())->not->toBeNull()
        ->and($order->refresh()->status)->toBe(OrderStatus::WaitingConfirmation);
});

test('expired rejected proof command cancels the order idempotently', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::WaitingPayment]);
    $payment = Payment::factory()->for($order)->create([
        'method' => PaymentMethod::Qris,
        'status' => PaymentStatus::Rejected,
    ]);
    PaymentProof::factory()->for($payment)->create([
        'status' => PaymentStatus::Rejected,
        'resubmission_deadline_at' => now()->subMinute(),
    ]);

    $this->artisan('payments:cancel-expired-proof-reuploads')
        ->expectsOutput('Cancelled 1 expired payment proof order(s).')
        ->assertSuccessful();

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->cancellation_reason)->toBe('Payment proof resubmission deadline expired.')
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Rejected)
        ->and($order->statusLogs()->where('to_status', OrderStatus::Cancelled)->count())->toBe(1);

    $this->artisan('payments:cancel-expired-proof-reuploads')
        ->expectsOutput('Cancelled 0 expired payment proof order(s).')
        ->assertSuccessful();

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->statusLogs()->where('to_status', OrderStatus::Cancelled)->count())->toBe(1);
});

test('payment view shows the rejected proof resubmission deadline', function (): void {
    $this->travelTo('2026-09-17 10:00:00');

    $order = Order::factory()->create(['status' => OrderStatus::WaitingConfirmation]);
    $payment = Payment::factory()->for($order)->create([
        'method' => PaymentMethod::Qris,
    ]);
    PaymentProof::factory()->for($payment)->create();

    $payment->reject($this->actor, 'Proof is not readable.');

    $modal = Livewire::test(ManagePayments::class)
        ->mountTableAction('view', $payment)
        ->assertActionMounted()
        ->getMountedActionModalHtml();

    expect($modal)
        ->toContain('Upload Resubmission Deadline')
        ->toContain('18-09-2026 10:00');
});

test('an order with a waiting confirmation payment can be processed through done', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::WaitingConfirmation,
        'payment_method' => PaymentMethod::Cash,
    ]);
    $payment = Payment::factory()->for($order)->create([
        'method' => PaymentMethod::Cash,
        'status' => PaymentStatus::WaitingConfirm,
    ]);

    expect($order->canStartPacking())->toBeTrue()
        ->and($order->nextStatus())->toBe(OrderStatus::Packing);

    foreach ([
        OrderStatus::Packing,
        OrderStatus::Shipping,
        OrderStatus::Done,
    ] as $status) {
        Livewire::test(ManageOrders::class)
            ->assertTableActionVisible('advanceStatus', $order)
            ->callTableAction('advanceStatus', $order)
            ->assertHasNoTableActionErrors();

        expect($order->refresh()->status)->toBe($status)
            ->and($payment->refresh()->status)->toBe(PaymentStatus::WaitingConfirm);
    }
});

test('payment confirmed does not automatically complete the order', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::WaitingConfirmation]);
    $payment = Payment::factory()->for($order)->create();
    PaymentProof::factory()->for($payment)->create();

    $payment->confirm($this->actor);

    expect($order->refresh()->status)->toBe(OrderStatus::ReadyToProcess)
        ->and($order->status)->not->toBe(OrderStatus::Done);
});

test('order status advances through the full delivery lifecycle', function (): void {
    $order = Order::factory()->delivery()->create(['status' => OrderStatus::Packing]);

    $order->advanceStatus($this->actor); // Packing -> Shipping
    expect($order->refresh()->status)->toBe(OrderStatus::Shipping);

    $order->advanceStatus($this->actor); // Shipping -> Done
    expect($order->refresh()->status)->toBe(OrderStatus::Done);

    expect($order->nextStatus())->toBeNull();
    expect(fn () => $order->advanceStatus($this->actor))
        ->toThrow(RuntimeException::class);
});

test('confirming a non pending payment is an invalid state transition', function (): void {
    $payment = Payment::factory()->create(['status' => PaymentStatus::Paid]);

    expect(fn () => $payment->confirm($this->actor))
        ->toThrow(RuntimeException::class);
});

test('rejecting a non pending payment is an invalid state transition', function (): void {
    $payment = Payment::factory()->create(['status' => PaymentStatus::Rejected]);

    expect(fn () => $payment->reject($this->actor, 'reason'))
        ->toThrow(RuntimeException::class);
});

// ==========================================================================
// Coverage G/H — Cancel
// ==========================================================================

test('coverage G: cancelling a waiting payment order with no payment does not create one', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::WaitingPayment,
        'payment_method' => PaymentMethod::Cash,
    ]);

    $order->cancel($this->actor, 'Customer changed their mind');

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->payment)->toBeNull();
});

test('coverage H: cancelling a waiting confirmation order automatically voids its payment', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::WaitingConfirmation]);
    $payment = Payment::factory()->for($order)->create(['status' => PaymentStatus::WaitingConfirm]);

    $order->cancel($this->actor, 'Duplicate order');

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Void);
});

test('coverage H: cancelling a ready to process order automatically voids its paid payment (Opsi B)', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::ReadyToProcess]);
    $payment = Payment::factory()->for($order)->create(['status' => PaymentStatus::Paid]);

    expect($order->canBeCancelled())->toBeTrue();

    $order->cancel($this->actor, 'Customer wants to switch payment method');

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Void);
});

test('canBeCancelled allows orders through shipping', function (): void {
    expect(Order::factory()->make(['status' => OrderStatus::WaitingPayment])->canBeCancelled())->toBeTrue()
        ->and(Order::factory()->make(['status' => OrderStatus::WaitingConfirmation])->canBeCancelled())->toBeTrue()
        ->and(Order::factory()->make(['status' => OrderStatus::ReadyToProcess])->canBeCancelled())->toBeTrue()
        ->and(Order::factory()->make(['status' => OrderStatus::Packing])->canBeCancelled())->toBeTrue()
        ->and(Order::factory()->make(['status' => OrderStatus::Shipping])->canBeCancelled())->toBeTrue();
});

test('cancel is allowed after the order is done', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::Done]);
    Payment::factory()->for($order)->create(['status' => PaymentStatus::Paid]);

    $order->cancel($this->actor, 'Goods returned');

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->payment->status)->toBe(PaymentStatus::Void);
});

// ==========================================================================
// Coverage I — Void: allowed on WaitingPayment/WaitingConfirmation, blocked
// from Packing onward.
// ==========================================================================

test('coverage I: a payment can be voided while the order is waiting payment, waiting confirmation, or ready to process (Opsi B)', function (): void {
    $waitingPaymentOrder = Order::factory()->create(['status' => OrderStatus::WaitingPayment]);
    $paymentOne = Payment::factory()->for($waitingPaymentOrder)->create(['status' => PaymentStatus::Paid]);

    $waitingConfirmationOrder = Order::factory()->create(['status' => OrderStatus::WaitingConfirmation]);
    $paymentTwo = Payment::factory()->for($waitingConfirmationOrder)->create(['status' => PaymentStatus::WaitingConfirm]);

    $readyToProcessOrder = Order::factory()->create(['status' => OrderStatus::ReadyToProcess]);
    $paymentThree = Payment::factory()->for($readyToProcessOrder)->create(['status' => PaymentStatus::Paid]);

    expect($paymentOne->canBeVoided())->toBeTrue()
        ->and($paymentTwo->canBeVoided())->toBeTrue()
        ->and($paymentThree->canBeVoided())->toBeTrue();

    $paymentOne->void($this->actor);
    $paymentTwo->void($this->actor);
    $paymentThree->void($this->actor);

    expect($paymentOne->refresh()->status)->toBe(PaymentStatus::Void)
        ->and($paymentTwo->refresh()->status)->toBe(PaymentStatus::Void)
        ->and($paymentThree->refresh()->status)->toBe(PaymentStatus::Void);
});

test('coverage I: a payment cannot be voided once the order has started packing', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::Packing]);
    $payment = Payment::factory()->for($order)->create(['status' => PaymentStatus::Paid]);

    expect($payment->canBeVoided())->toBeFalse();

    expect(fn () => $payment->void($this->actor))
        ->toThrow(RuntimeException::class);
});

test('payment order number links to the order detail modal', function (): void {
    $order = Order::factory()->create();
    $payment = Payment::factory()->for($order)->create();
    $orderUrl = OrderResource::getUrl(parameters: [
        'tableAction' => 'view',
        'tableActionRecord' => $order,
    ]);

    $modal = Livewire::test(ManagePayments::class)
        ->mountTableAction('view', $payment)
        ->assertActionMounted()
        ->getMountedActionModalHtml();

    expect($modal)
        ->toContain('Order Information')
        ->toContain('href="'.e($orderUrl).'"', 'payment-order-number-link', 'fi-color-info');
});

test('cancelling an unpaid order marks payment unpaid-cancel and keeps order status cancelled', function (): void {
    $order = Order::factory()->create([
        'payment_method' => PaymentMethod::Cash,
        'status' => OrderStatus::ReadyToProcess,
    ]);
    $payment = Payment::factory()->for($order)->create([
        'method' => PaymentMethod::Cash,
        'status' => PaymentStatus::Unpaid,
    ]);

    $order->cancel($this->actor, 'Customer request');

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::UnpaidCancel);

    Livewire::test(ManageOrders::class)
        ->assertTableColumnFormattedStateSet('status', 'Cancelled', $order);

    $orderModal = Livewire::test(ManageOrders::class)
        ->mountTableAction('view', $order)
        ->assertActionMounted()
        ->getMountedActionModalHtml();

    $paymentModal = Livewire::test(ManagePayments::class)
        ->mountTableAction('view', $payment)
        ->assertActionMounted()
        ->getMountedActionModalHtml();

    expect($orderModal)->toContain('Cancelled')
        ->and($paymentModal)
        ->toContain('Cancelled')
        ->toContain('Payment was unpaid when the order was cancelled and is no longer active.');
});

test('cancelled unpaid payment displays the standard cancelled order status', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::Cancelled]);
    $payment = Payment::factory()->for($order)->create(['status' => PaymentStatus::Unpaid]);

    expect($order->adminStatusLabel())->toBe('Cancelled')
        ->and($payment->order->adminStatusLabel())->toBe('Cancelled');
});

test('order enum resolves the correct next status for delivery workflow', function (): void {
    expect(OrderStatus::WaitingPayment->next(ShippingMethod::Delivery))->toBe(OrderStatus::WaitingConfirmation)
        ->and(OrderStatus::WaitingConfirmation->next(ShippingMethod::Delivery))->toBe(OrderStatus::ReadyToProcess)
        ->and(OrderStatus::ReadyToProcess->next(ShippingMethod::Delivery))->toBe(OrderStatus::Packing)
        ->and(OrderStatus::Packing->next(ShippingMethod::Delivery))->toBe(OrderStatus::Shipping)
        ->and(OrderStatus::Shipping->next(ShippingMethod::Delivery))->toBe(OrderStatus::Done)
        ->and(OrderStatus::Done->next(ShippingMethod::Delivery))->toBeNull()
        ->and(OrderStatus::Cancelled->next(ShippingMethod::Delivery))->toBeNull();
});

test('orders payment filters only expose the approved payment options', function (): void {
    expect(PaymentMethod::cases())->toHaveCount(4)
        ->and(PaymentMethod::cases())->toMatchArray([
            PaymentMethod::Cash,
            PaymentMethod::Qris,
            PaymentMethod::BankTransfer,
            PaymentMethod::Invoice,
        ])
        ->and(PaymentStatus::cases())->toMatchArray([
            PaymentStatus::Unpaid,
            PaymentStatus::WaitingConfirm,
            PaymentStatus::Paid,
            PaymentStatus::Rejected,
            PaymentStatus::UnpaidCancel,
            PaymentStatus::Void,
        ]);
});
