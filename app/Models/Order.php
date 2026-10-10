<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ShippingMethod;
use App\Enums\StockReservationStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use App\Services\PaymentVoidWorkflow;
use App\Services\SystemFreezeGuard;
use Closure;
use Database\Factories\OrderFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'order_number',
    'pos_checkout_token',
    'customer_id',
    'fulfillment_warehouse_id',
    'status',
    'shipping_method',
    'payment_method',
    'stock_reservation_status',
    'stock_reservation_error',
    'recipient_name',
    'recipient_phone',
    'shipping_address',
    'shipping_cost',
    'tracking_number',
    'subtotal',
    'discount_amount',
    'total',
    'cancelled_by',
    'cancellation_reason',
    'cancelled_at',
    'notes',
    'created_by',
    'updated_by',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, HasUuidPrimaryKey, SoftDeletes;

    protected $attributes = [
        'stock_reservation_status' => StockReservationStatus::Reserved,
    ];

    private bool $isRunningCreationSetup = false;

    protected static function booted(): void
    {
        static::saving(function (self $order): void {
            app(SystemFreezeGuard::class)->assertNotFrozen();
        });

        static::creating(function (self $order): void {
            $order->created_by ??= auth('web')->id();
            $order->updated_by = null;
            $order->updated_at = null;
        });

        static::updating(function (self $order): void {
            if ($order->isRunningCreationSetup) {
                $order->updated_by = null;

                return;
            }

            if (! $order->isDirty('updated_by')) {
                $order->updated_by = auth('web')->id();
            }
        });
    }

    /**
     * Run the payment and status steps that complete a new order without
     * recording them as a later order update.
     *
     * @param  Closure(): mixed  $callback
     */
    public function runDuringCreation(Closure $callback): mixed
    {
        $wasRunningCreationSetup = $this->isRunningCreationSetup;
        $wasUsingTimestamps = $this->timestamps;
        $this->isRunningCreationSetup = true;
        $this->timestamps = false;

        try {
            return $callback();
        } finally {
            $this->timestamps = $wasUsingTimestamps;
            $this->isRunningCreationSetup = $wasRunningCreationSetup;
        }
    }

    public function updateTimestamps(): static
    {
        if ($this->isRunningCreationSetup && $this->exists) {
            return $this;
        }

        parent::updateTimestamps();

        if (! $this->exists) {
            $this->setUpdatedAt(null);
        }

        return $this;
    }

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'shipping_method' => ShippingMethod::class,
            'payment_method' => PaymentMethod::class,
            'stock_reservation_status' => StockReservationStatus::class,
            'shipping_cost' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function fulfillmentWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'fulfillment_warehouse_id');
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class)->currentAttempt();
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function canRecreatePayment(): bool
    {
        return $this->status !== OrderStatus::Cancelled
            && $this->payment?->status === PaymentStatus::Void
            && $this->payment->invoice_id === null;
    }

    /** @return HasMany<OrderStatusLog, $this> */
    public function statusLogs(): HasMany
    {
        // ->latest('created_at') alone is not deterministic when two log
        // rows land in the same second (created_at here has second-level
        // precision, not microseconds) — e.g. markReadyToProcess() and a
        // manual advanceStatus() firing back-to-back. id is tie-broken
        // here because HasUuidPrimaryKey uses Laravel's HasUuids trait,
        // which generates *ordered* UUIDs (lexicographically sortable by
        // creation time), so this reliably returns true insertion order
        // without needing a DB migration to widen created_at's precision.
        return $this->hasMany(OrderStatusLog::class)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Start packing for ready orders or orders awaiting payment confirmation.
     */
    public function canStartPacking(): bool
    {
        if ($this->payment?->hasActiveVoidRequest()) {
            return false;
        }

        if ($this->stock_reservation_status !== StockReservationStatus::Reserved) {
            return false;
        }

        if ($this->payment?->method?->requiresProof()
            && $this->payment->status === PaymentStatus::Unpaid
            && $this->payment->created_by === null) {
            return false;
        }

        if ($this->status === OrderStatus::WaitingConfirmation) {
            return $this->payment?->status === PaymentStatus::WaitingConfirm;
        }

        return $this->status === OrderStatus::ReadyToProcess
            && ($this->payment?->status === PaymentStatus::Paid
                || $this->payment?->status === PaymentStatus::WaitingConfirm
                || ($this->payment_method !== null
                    && $this->payment?->method === $this->payment_method
                    && $this->payment?->status === PaymentStatus::Unpaid));
    }

    /**
     * Create the cash receivable and mark a newly placed order ready to process.
     */
    public function initializeCashPayment(?User $actor = null): void
    {
        if ($this->payment_method !== PaymentMethod::Cash) {
            throw new \RuntimeException('Only cash orders can initialize a cash payment.');
        }

        $this->initializeUnpaidPayment($actor);
    }

    public function initializeUnpaidPayment(?User $actor = null, ?DateTimeInterface $transactionAt = null): void
    {
        $this->initializeUnpaidPaymentForWorkflow($actor, requiresInitialProof: true, transactionAt: $transactionAt);
    }

    public function initializeAdminUnpaidPayment(?User $actor = null, ?DateTimeInterface $transactionAt = null): void
    {
        if (! $this->payment_method?->requiresProof()) {
            throw new \RuntimeException('Only proof-based orders can initialize an admin unpaid payment.');
        }

        $this->initializeUnpaidPaymentForWorkflow($actor, requiresInitialProof: false, transactionAt: $transactionAt);
    }

    private function initializeUnpaidPaymentForWorkflow(?User $actor, bool $requiresInitialProof, ?DateTimeInterface $transactionAt = null): void
    {
        DB::transaction(function () use ($actor, $requiresInitialProof, $transactionAt): void {
            if (! in_array($this->payment_method, PaymentMethod::cases(), true)
                || $this->status !== OrderStatus::WaitingPayment
                || $this->payment()->exists()) {
                throw new \RuntimeException('Only a new order can initialize an unpaid payment.');
            }

            $this->payment()->create([
                'payment_number' => $transactionAt === null ? null : Payment::generatePaymentNumber($transactionAt),
                'method' => $this->payment_method,
                'amount' => $this->total,
                'status' => PaymentStatus::Unpaid,
                'proof_upload_deadline_at' => $requiresInitialProof && $this->payment_method?->requiresProof()
                    ? now()->addHours(PaymentSetting::current()->proof_resubmission_hours)
                    : null,
                'created_by' => $actor?->id,
            ]);

            if ($requiresInitialProof && $this->payment_method?->requiresProof()) {
                $this->unsetRelation('payment');

                return;
            }

            $this->forceFill([
                'status' => OrderStatus::ReadyToProcess,
                'updated_by' => $actor?->id,
            ])->save();

            $this->statusLogs()->create([
                'from_status' => OrderStatus::WaitingPayment,
                'to_status' => OrderStatus::ReadyToProcess,
                'changed_by_user_id' => $actor?->id,
                'note' => 'Order ready to process; payment remains unpaid.',
                'created_at' => now(),
            ]);
            $this->unsetRelation('payment');
        });
    }

    public function isPaymentConfirmed(): bool
    {
        return $this->payment?->status === PaymentStatus::Paid;
    }

    /**
     * Human-facing "payment stage" label for the Orders table (and anywhere
     * else that needs to show payment status even when no Payment row
     * exists yet). "Not Recorded" is NOT a PaymentStatus enum value — see
     * PaymentStatus::WaitingConfirm's docblock — it describes the absence
     * of a Payment row entirely (e.g. a legacy order), which is
     * an Order-level concept, not something a Payment record itself can be.
     * A fake Payment is never created just so this method has something to
     * read.
     */
    public function paymentStageLabel(): string
    {
        return $this->payment?->status?->getLabel() ?? 'Not Recorded';
    }

    /**
     * Badge color paired with paymentStageLabel() — 'gray' for the
     * Not-Recorded (no Payment yet) case, otherwise the Payment status's
     * own color.
     */
    public function paymentStageColor(): string
    {
        return $this->payment?->status?->getColor() ?? 'gray';
    }

    /**
     * Resolve the admin-facing order status label for the current payment state.
     */
    public function adminStatusLabel(
        ?PaymentStatus $paymentStatus = null,
        bool $collapseWaitingConfirmation = true,
    ): string {
        $status = $collapseWaitingConfirmation
            ? $this->status->forAdminDisplay()
            : $this->status;

        return $status->getLabel();
    }

    public function adminStatusColor(bool $collapseWaitingConfirmation = true): string
    {
        return ($collapseWaitingConfirmation ? $this->status->forAdminDisplay() : $this->status)->getColor();
    }

    public function customerStatusLabel(): string
    {
        return in_array($this->status, [OrderStatus::WaitingPayment, OrderStatus::WaitingConfirmation], true)
            || ($this->status === OrderStatus::ReadyToProcess && $this->payment?->status !== PaymentStatus::Paid)
            ? 'Waiting'
            : $this->status->getLabel();
    }

    public function customerPaymentStatusLabel(): string
    {
        return match ($this->payment?->status) {
            PaymentStatus::Unpaid => 'Pending Payment',
            PaymentStatus::WaitingConfirm => 'Pending Confirmation',
            PaymentStatus::Paid => 'Paid',
            PaymentStatus::Rejected => 'Rejected',
            PaymentStatus::UnpaidCancel => 'Unpaid-Cancel',
            PaymentStatus::Void => 'Void',
            default => 'Not Recorded',
        };
    }

    /**
     * Mengembalikan status berikutnya yang boleh dilakukan Admin.
     *
     * WaitingPayment never has a manual "next" step. WaitingConfirmation
     * normally moves to ReadyToProcess automatically after payment
     * confirmation, but a payment that is still WaitingConfirm may be sent
     * directly to Packing by Admin:
     * - WaitingPayment -> WaitingConfirmation: Order::markWaitingConfirmation()
     *   (QRIS proof submission, including resubmission after rejection).
     * - WaitingConfirmation -> ReadyToProcess: Order::markReadyToProcess()
     *   (Payment::confirm()).
     * - WaitingConfirmation -> WaitingPayment: Order::markWaitingPayment()
     *   (Payment::reject()).
     * - WaitingConfirmation -> Packing: manual fulfillment is allowed while
     *   the payment remains WaitingConfirm.
     */
    public function nextStatus(): ?OrderStatus
    {
        if ($this->payment?->voidRequests()->where('cancel_order', true)->whereIn('approval_status', PaymentVoidRequest::ACTIVE_STATUSES)->exists()) {
            return null;
        }

        if ($this->status === OrderStatus::WaitingPayment) {
            return null;
        }

        if (in_array($this->status, [OrderStatus::WaitingConfirmation, OrderStatus::ReadyToProcess], true)) {
            return $this->canStartPacking()
                ? OrderStatus::Packing
                : null;
        }

        return $this->status->next($this->shipping_method);
    }

    /**
     * Move the order from WaitingPayment to WaitingConfirmation, and record
     * the transition using the same order_status_logs mechanism the other
     * mark*()/advanceStatus()/cancel() methods already use. Called the
     * instant QRIS proof is submitted, either at checkout or after a rejection.
     *
     * Guarded to WaitingPayment only, so this is safely idempotent: calling
     * it again, or calling it on an order that is already further along
     * (WaitingConfirmation, ReadyToProcess, Cancelled, etc.), does nothing
     * instead of throwing or regressing the order backwards.
     */
    public function markWaitingConfirmation(?User $actor = null): void
    {
        if ($this->status !== OrderStatus::WaitingPayment) {
            return;
        }

        DB::transaction(function () use ($actor): void {
            $from = $this->status;

            $this->forceFill([
                'status' => OrderStatus::WaitingConfirmation,
                'updated_by' => $actor?->id,
            ])->save();

            $this->statusLogs()->create([
                'from_status' => $from,
                'to_status' => OrderStatus::WaitingConfirmation,
                'changed_by_user_id' => $actor?->id,
                'note' => 'Payment recorded — order is now waiting for Admin confirmation.',
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Move the order from WaitingConfirmation back to WaitingPayment.
     * Called by Payment::reject() — Business Rule (Step 2 — Waiting
     * Confirmation): rejecting a payment means it was never valid, so the
     * order returns to needing a (new) payment, not forward to Cancelled or
     * anywhere else. Symmetric with markWaitingConfirmation()/
     * markReadyToProcess() — same pattern, no new mechanism.
     *
     * Guarded to WaitingConfirmation only: idempotent, and never regresses
     * an order that has already moved further along (e.g. if somehow
     * already ReadyToProcess/Cancelled).
     */
    public function markWaitingPayment(?User $actor = null): void
    {
        if ($this->status !== OrderStatus::WaitingConfirmation) {
            return;
        }

        DB::transaction(function () use ($actor): void {
            $from = $this->status;

            $this->forceFill([
                'status' => OrderStatus::WaitingPayment,
                'updated_by' => $actor?->id,
            ])->save();

            $this->statusLogs()->create([
                'from_status' => $from,
                'to_status' => OrderStatus::WaitingPayment,
                'changed_by_user_id' => $actor?->id,
                'note' => 'Payment rejected — order returned to waiting payment.',
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Move the order from WaitingConfirmation to ReadyToProcess, and record
     * the transition using the same order_status_logs mechanism
     * advanceStatus()/cancel() already use. Called by Payment::confirm()
     * the instant a payment becomes Paid — Business Rule (Step 2 — Ready to
     * Process): confirming a payment must move the order forward
     * automatically; Admin should never have to do this as a separate
     * manual step.
     *
     * STRICT rule (Step 2 — Waiting Confirmation): guarded to
     * WaitingConfirmation only (no longer WaitingPayment) — a payment
     * cannot be confirmed for an order that was never recorded as waiting
     * confirmation in the first place under the new lifecycle. Idempotent:
     * calling it again, or on an order already further along, does
     * nothing instead of throwing or regressing the order backwards.
     */
    public function markReadyToProcess(?User $actor = null): void
    {
        if ($this->status !== OrderStatus::WaitingConfirmation) {
            return;
        }

        DB::transaction(function () use ($actor): void {
            $from = $this->status;

            $this->forceFill([
                'status' => OrderStatus::ReadyToProcess,
                'updated_by' => $actor?->id,
            ])->save();

            $this->statusLogs()->create([
                'from_status' => $from,
                'to_status' => OrderStatus::ReadyToProcess,
                'changed_by_user_id' => $actor?->id,
                'note' => 'Payment confirmed — order automatically marked ready to process.',
                'created_at' => now(),
            ]);
        });
    }

    /** Completed orders may also be cancelled when they are not invoiced. */
    public function canBeCancelled(): bool
    {
        if ($this->payment?->invoice_id !== null) {
            return false;
        }

        return in_array($this->status, [
            OrderStatus::WaitingPayment,
            OrderStatus::WaitingConfirmation,
            OrderStatus::ReadyToProcess,
            OrderStatus::Packing,
            OrderStatus::Shipping,
            OrderStatus::ReadyForPickup,
            OrderStatus::Done,
            OrderStatus::PickedUp,
        ], true);
    }

    public function canBeCancelledByCustomer(): bool
    {
        return $this->canBeCancelled()
            && in_array($this->status, [OrderStatus::WaitingPayment, OrderStatus::WaitingConfirmation, OrderStatus::ReadyToProcess], true)
            && $this->payment?->status !== PaymentStatus::Paid
            && ! ($this->payment?->hasActiveVoidRequest() ?? false);
    }

    /** Paid cancellations complete only through an approved void request. */
    public function cancel(?User $actor = null, ?string $reason = null, ?PaymentVoidRequest $approvedRequest = null): void
    {
        DB::transaction(function () use ($actor, $reason, $approvedRequest): void {
            app(SystemFreezeGuard::class)->assertNotFrozen();
            $current = self::query()->lockForUpdate()->findOrFail($this->id);
            $payment = $this->payment()->lockForUpdate()->first();
            $this->setRawAttributes($current->getAttributes(), true);
            $this->setRelation('payment', $payment);

            if ($payment?->invoice_id !== null) {
                throw new \RuntimeException('Delete the unpaid invoice before cancelling this order.');
            }

            if (! $this->canBeCancelled()) {
                throw new \RuntimeException('Order cannot be canceled from its current status.');
            }

            if ($approvedRequest !== null) {
                $approvedRequest = PaymentVoidRequest::query()->lockForUpdate()->findOrFail($approvedRequest->id);
                if (! $approvedRequest->cancel_order || $approvedRequest->approval_status !== 'approved'
                    || $approvedRequest->payment_id !== $payment?->id || $payment?->status !== PaymentStatus::Paid) {
                    throw ValidationException::withMessages(['void' => 'An approved order cancellation request is required.']);
                }
                $reason = $approvedRequest->reason;
            } elseif ($actor !== null) {
                Gate::forUser($actor)->authorize('delete', $this);
            } elseif (in_array($this->status, [OrderStatus::Packing, OrderStatus::Shipping, OrderStatus::ReadyForPickup, OrderStatus::Done, OrderStatus::PickedUp], true) || $payment?->status === PaymentStatus::Paid) {
                throw ValidationException::withMessages(['void' => 'An authorized administrator must cancel this order.']);
            }

            if ($payment?->hasActiveVoidRequest()) {
                throw ValidationException::withMessages(['void' => 'Complete or withdraw the active void request before cancelling this order.']);
            }

            if ($payment?->status === PaymentStatus::Paid && $approvedRequest === null) {
                app(PaymentVoidWorkflow::class)->request($payment, $actor, $reason ?? '', cancelOrder: true);
                $this->refresh();

                return;
            }

            $from = $this->status;
            $reservationStatus = $this->stock_reservation_status;
            $cancelledAt = now();

            $this->forceFill([
                'status' => OrderStatus::Cancelled,
                'stock_reservation_status' => StockReservationStatus::Released,
                'stock_reservation_error' => null,
                'cancelled_by' => $actor?->id,
                'cancellation_reason' => $reason,
                'cancelled_at' => $cancelledAt,
                'updated_by' => $actor?->id,
            ])->save();

            $this->statusLogs()->create([
                'from_status' => $from,
                'to_status' => OrderStatus::Cancelled,
                'changed_by_user_id' => $actor?->id,
                'note' => $reason,
                'created_at' => $cancelledAt,
            ]);

            if ($reservationStatus === StockReservationStatus::Reserved) {
                $this->items()
                    ->whereNotNull('product_variant_id')
                    ->get()
                    ->each(function (OrderItem $item) use ($actor, $from): void {
                        $variant = $item->productVariant()->firstOrFail();
                        StockMutation::releaseFromOrder($variant, $item->quantity, $item->id, $actor?->id, $this->fulfillmentWarehouse,
                            fulfilled: in_array($from, [OrderStatus::Done, OrderStatus::PickedUp], true));
                    });
            }

            if ($payment !== null && in_array($payment->status, [PaymentStatus::Unpaid, PaymentStatus::WaitingConfirm, PaymentStatus::Paid], true)) {
                $payment->forceFill([
                    'status' => $payment->status === PaymentStatus::Unpaid
                        ? PaymentStatus::UnpaidCancel
                        : PaymentStatus::Void,
                    'verified_by' => $actor?->id,
                    'verified_at' => now(),
                    'rejection_reason' => null,
                ])->save();
            }
        });
    }

    public function advanceStatus(?User $actor = null, ?string $note = null): void
    {
        $next = $this->nextStatus();

        if ($next === null) {
            throw new \RuntimeException(
                'Order cannot be advanced from its current status.'
            );
        }

        DB::transaction(function () use ($next, $actor, $note): void {
            $current = self::query()->lockForUpdate()->findOrFail($this->id);

            if ($current->payment?->voidRequests()->where('cancel_order', true)->whereIn('approval_status', PaymentVoidRequest::ACTIVE_STATUSES)->exists()) {
                throw ValidationException::withMessages(['void' => 'Order processing is on hold while cancellation approval is active.']);
            }

            if ($next === OrderStatus::Packing) {
                $payment = $current->payment()->lockForUpdate()->first();
                if ($payment?->hasActiveVoidRequest()) {
                    throw ValidationException::withMessages(['void' => 'Packing is on hold while a void payment request is active.']);
                }
            }

            if ($current->status !== $this->status) {
                throw new \RuntimeException('Order status has already changed. Refresh before continuing.');
            }

            if ($next === OrderStatus::Shipping && $current->stock_reservation_status !== StockReservationStatus::Reserved) {
                throw ValidationException::withMessages([
                    'stock_reservation_status' => 'Reserve this order\'s stock with Retry Reservation before shipping.',
                ]);
            }

            if ($next === OrderStatus::Shipping || $next === OrderStatus::Done) {
                $items = $this->items()->whereNotNull('product_variant_id')->get();

                foreach ($items as $item) {
                    $variant = $item->productVariant()->firstOrFail();

                    if ($next === OrderStatus::Shipping) {
                        StockMutation::shipForOrder(
                            $variant,
                            $item->quantity,
                            $item->id,
                            $actor?->id,
                            $this->fulfillmentWarehouse,
                        );
                    } else {
                        StockMutation::fulfillForOrder(
                            $variant,
                            $item->quantity,
                            $item->id,
                            $actor?->id,
                            $this->fulfillmentWarehouse,
                        );
                    }
                }
            }

            $from = $this->status;

            $this->forceFill([
                'status' => $next,
                'updated_by' => $actor?->id,
            ])->save();

            $this->statusLogs()->create([
                'from_status' => $from,
                'to_status' => $next,
                'changed_by_user_id' => $actor?->id,
                'note' => $note,
                'created_at' => now(),
            ]);

        });
    }
}
