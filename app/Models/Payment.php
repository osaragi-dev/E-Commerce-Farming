<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use App\Services\SystemFreezeGuard;
use Database\Factories\PaymentFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'order_id',
    'attempt_number',
    'invoice_id',
    'payment_number',
    'method',
    'amount',
    'status',
    'created_by',
    'updated_by',
    'verified_by',
    'verified_at',
    'rejection_reason',
    'proof_upload_deadline_at',
])]
class Payment extends Model
{
    private const string EXPIRED_RESUBMISSION_REASON = 'Payment proof resubmission deadline expired.';

    /** @use HasFactory<PaymentFactory> */
    use HasFactory, HasUuidPrimaryKey, SoftDeletes;

    protected $attributes = ['attempt_number' => 1];

    #[Scope]
    protected function currentAttempt(Builder $query): void
    {
        $query->where('payments.attempt_number', function (\Illuminate\Database\Query\Builder $subquery): void {
            $subquery->from('payments as payment_attempts')
                ->selectRaw('max(payment_attempts.attempt_number)')
                ->whereColumn('payment_attempts.order_id', 'payments.order_id');
        });
    }

    public function isCurrentAttempt(): bool
    {
        return self::query()->currentAttempt()->whereKey($this->id)->exists();
    }

    protected static function booted(): void
    {
        static::saving(function (self $payment): void {
            app(SystemFreezeGuard::class)->assertNotFrozen();
        });

        static::creating(function (self $payment): void {
            if (empty($payment->payment_number)) {
                $payment->payment_number = self::generatePaymentNumber();
            }
        });

        static::updating(function (self $payment): void {
            if (! $payment->isDirty('updated_by')) {
                $payment->updated_by = auth('web')->id();
            }
        });
    }

    public function updateTimestamps(): static
    {
        parent::updateTimestamps();

        if (! $this->exists) {
            $this->setUpdatedAt(null);
        }

        return $this;
    }

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'verified_at' => 'datetime',
            'proof_upload_deadline_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return HasMany<PaymentProof, $this> */
    public function proofs(): HasMany
    {
        return $this->hasMany(PaymentProof::class)->latest('uploaded_at');
    }

    public function currentProof(): ?PaymentProof
    {
        return $this->proofs()
            ->where('is_current', true)
            ->first();
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function hasVerifiableProof(): bool
    {
        return $this->status === PaymentStatus::WaitingConfirm
            && $this->method?->requiresProof()
            && $this->currentProof() !== null;
    }

    public function canStartConfirmation(): bool
    {
        if ($this->order?->status === OrderStatus::Cancelled) {
            return false;
        }

        if ($this->status === PaymentStatus::Unpaid) {
            $canStartConfirmation = in_array($this->method, PaymentMethod::cases(), true)
                && $this->order?->payment_method === $this->method
                && ($this->method?->requiresProof()
                    ? in_array($this->order?->status, [
                        OrderStatus::WaitingPayment,
                        OrderStatus::ReadyToProcess,
                        OrderStatus::Packing,
                        OrderStatus::Shipping,
                        OrderStatus::Done,
                    ], true)
                    : in_array($this->order?->status, [
                        OrderStatus::ReadyToProcess,
                        OrderStatus::Packing,
                        OrderStatus::Shipping,
                        OrderStatus::Done,
                    ], true));

            if (! $canStartConfirmation) {
                return false;
            }

            return ! $this->method?->requiresProof() || $this->canSubmitProof();
        }

        return $this->status === PaymentStatus::WaitingConfirm
            && (! $this->method?->requiresProof() || $this->hasVerifiableProof());
    }

    public function canBeConfirmed(): bool
    {
        return $this->canStartConfirmation()
            && (! $this->method?->requiresProof() || $this->currentProof() !== null);
    }

    /**
     * Recorded or paid payments may be voided before Packing.
     * Admin-created unpaid payments may also be voided directly. Order
     * cancellation uses Unpaid-Cancel for unpaid payments.
     */
    protected function voidApproval(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->voidRequests->first()?->approval_status);
    }

    public function voidRequests(): HasMany
    {
        return $this->hasMany(PaymentVoidRequest::class)->latest();
    }

    public function hasActiveVoidRequest(): bool
    {
        return $this->voidRequests()->whereIn('approval_status', PaymentVoidRequest::ACTIVE_STATUSES)->exists();
    }

    public function canBeVoided(): bool
    {
        if ($this->invoice_id !== null) {
            return false;
        }

        $isAdminCreatedUnpaidPayment = $this->status === PaymentStatus::Unpaid
            && $this->createdBy !== null;

        if (! $isAdminCreatedUnpaidPayment
            && ! in_array($this->status, [PaymentStatus::WaitingConfirm, PaymentStatus::Paid], true)) {
            return false;
        }

        return in_array($this->order?->status, [OrderStatus::WaitingPayment, OrderStatus::WaitingConfirmation, OrderStatus::ReadyToProcess], true);
    }

    /**
     * Confirm receipt once; unpaid orders keep their current fulfillment status.
     */
    public function confirm(User $actor, ?string $proofPath = null): void
    {
        DB::transaction(function () use ($actor, $proofPath): void {
            $order = $this->order()->lockForUpdate()->first();
            $locked = self::query()->lockForUpdate()->findOrFail($this->id);
            $this->setRawAttributes($locked->getAttributes(), true);
            $this->setRelation('order', $order);

            if (! $this->canStartConfirmation()) {
                throw new \RuntimeException('This payment cannot be confirmed.');
            }

            if ($proofPath !== null) {
                if (! $this->method?->requiresProof() || $this->status !== PaymentStatus::Unpaid) {
                    throw new \RuntimeException('Proof upload is only available for unpaid proof-based payments.');
                }

                $this->submitProof($proofPath, $actor);
            }

            if (! $this->canBeConfirmed()) {
                throw new \RuntimeException('This payment cannot be confirmed.');
            }

            $this->forceFill([
                'status' => PaymentStatus::Paid,
                'verified_by' => $actor->id,
                'verified_at' => now(),
                'rejection_reason' => null,
            ])->save();

            $this->currentProof()?->forceFill([
                'status' => PaymentStatus::Paid,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'rejection_reason' => null,
            ])->save();

            $this->order?->markReadyToProcess($actor);
        });
    }

    /**
     * Reject payment proof.
     *
     * Step 2 — Waiting Confirmation update: rejecting a payment now ALSO
     * moves the order back, exactly once, from WaitingConfirmation to
     * WaitingPayment (via Order::markWaitingPayment()) — a rejected proof
     * means the order genuinely still needs a valid payment, so it belongs
     * back in the "needs payment" stage, not left sitting in
     * WaitingConfirmation.
     */
    public function reject(User $actor, string $reason): void
    {
        if ($this->status !== PaymentStatus::WaitingConfirm) {
            throw new \RuntimeException(
                'Only a payment waiting for confirmation can be rejected.'
            );
        }

        if (trim($reason) === '') {
            throw new \InvalidArgumentException(
                'A rejection reason is required.'
            );
        }

        $resubmissionHours = PaymentSetting::current()->proof_resubmission_hours;

        DB::transaction(function () use ($actor, $reason, $resubmissionHours): void {
            $order = $this->order()->lockForUpdate()->first();
            $payment = self::query()->lockForUpdate()->findOrFail($this->id);

            if ($payment->status !== PaymentStatus::WaitingConfirm) {
                throw new \RuntimeException(
                    'Only a payment waiting for confirmation can be rejected.'
                );
            }

            $currentProof = $payment->proofs()
                ->where('is_current', true)
                ->lockForUpdate()
                ->first();

            if ($currentProof === null) {
                throw new \RuntimeException('A current payment proof is required before rejection.');
            }

            $payment->forceFill([
                'status' => PaymentStatus::Rejected,
                'verified_by' => $actor->id,
                'verified_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            $currentProof->forceFill([
                'status' => PaymentStatus::Rejected,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
                'resubmission_deadline_at' => now()->addHours($resubmissionHours),
            ])->save();

            $order?->markWaitingPayment($actor);

            $this->setRawAttributes($payment->getAttributes(), true);
            $this->setRelation('order', $order);
        });
    }

    public function canSubmitProof(): bool
    {
        if (! $this->isCurrentAttempt()) {
            return false;
        }

        if (! $this->method?->requiresProof()
            || ! in_array($this->status, [PaymentStatus::Unpaid, PaymentStatus::WaitingConfirm, PaymentStatus::Rejected, PaymentStatus::Void], true)) {
            return false;
        }

        if ($this->status === PaymentStatus::Unpaid && $this->method?->requiresProof()) {
            return $this->proof_upload_deadline_at === null
                || $this->proof_upload_deadline_at->isFuture();
        }

        if ($this->status !== PaymentStatus::Rejected) {
            return true;
        }

        $deadline = $this->currentProof()?->resubmission_deadline_at;

        return $deadline === null || $deadline->isFuture();
    }

    /**
     * Cancel an unpaid QRIS order whose initial proof upload window expired.
     */
    public function cancelIfInitialProofExpired(): bool
    {
        if (
            $this->status !== PaymentStatus::Unpaid
            || ! $this->method?->requiresProof()
            || $this->proof_upload_deadline_at === null
            || $this->proof_upload_deadline_at->isFuture()
        ) {
            return false;
        }

        return DB::transaction(function (): bool {
            $order = $this->order()->lockForUpdate()->first();
            $payment = self::query()->lockForUpdate()->find($this->id);

            if (
                $order === null
                || $payment === null
                || $payment->status !== PaymentStatus::Unpaid
                || ! $payment->method?->requiresProof()
                || $payment->proof_upload_deadline_at === null
                || $payment->proof_upload_deadline_at->isFuture()
                || $order->status !== OrderStatus::WaitingPayment
            ) {
                return false;
            }

            $order->cancel(null, 'Payment proof upload deadline expired.');

            return true;
        });
    }

    /**
     * Cancel the order when its current rejected proof missed the upload window.
     *
     * The order and payment are locked before the deadline is checked so the
     * scheduler and a customer upload request converge on one result.
     */
    public function cancelIfResubmissionExpired(): bool
    {
        if ($this->status !== PaymentStatus::Rejected) {
            return false;
        }

        return DB::transaction(function (): bool {
            $order = $this->order()->lockForUpdate()->first();
            $payment = self::query()->lockForUpdate()->find($this->id);

            if ($order === null || $payment === null || $payment->status !== PaymentStatus::Rejected) {
                return false;
            }

            $currentProof = $payment->proofs()
                ->where('is_current', true)
                ->lockForUpdate()
                ->first();

            if (
                $currentProof === null
                || $currentProof->status !== PaymentStatus::Rejected
                || $currentProof->resubmission_deadline_at === null
                || $currentProof->resubmission_deadline_at->isFuture()
                || ! $order->canBeCancelled()
                || ! in_array($order->status, [OrderStatus::WaitingPayment, OrderStatus::WaitingConfirmation, OrderStatus::ReadyToProcess], true)
            ) {
                return false;
            }

            $order->cancel(null, self::EXPIRED_RESUBMISSION_REASON);

            return true;
        });
    }

    /**
     * Void (cancel) this payment.
     *
     * Void != Reject: Reject means the proof was invalid; Void means the
     * payment itself is being cancelled (e.g. customer wants to switch
     * payment method, or a recorded payment's Order was cancelled — see
     * Order::cancel()).
     * Only allowed while the Order has not started Packing, see
     * canBeVoided(). Reuses verified_by/verified_at the same way reject()
     * already does, to record who actioned the payment last — no new
     * columns needed for this.
     */
    public function void(?User $actor = null): void
    {
        DB::transaction(function () use ($actor): void {
            $order = $this->order()->lockForUpdate()->first();
            $payment = self::query()->lockForUpdate()->findOrFail($this->id);
            $this->setRawAttributes($payment->getAttributes(), true);
            $this->setRelation('order', $order);

            if ($this->invoice_id !== null) {
                throw new \RuntimeException('Delete the unpaid invoice before voiding this payment.');
            }

            if ($this->hasActiveVoidRequest()) {
                throw ValidationException::withMessages(['void' => 'Complete the active void approval before voiding this payment.']);
            }

            if (! $this->canBeVoided()) {
                throw new \RuntimeException(
                    'This payment can no longer be voided (Order already started Packing, or payment is not in a voidable state).'
                );
            }

            $this->forceFill([
                'status' => PaymentStatus::Void,
                'verified_by' => $actor?->id,
                'verified_at' => now(),
                'rejection_reason' => null,
            ])->save();
        });
    }

    /**
     * Preserve proof history. QRIS unpaid payments move to waiting
     * confirmation when their first proof is uploaded; rejected or void
     * payments return to waiting confirmation as before.
     */
    public function submitProof(string $filePath, ?User $actor = null): PaymentProof
    {
        return DB::transaction(function () use ($filePath, $actor): PaymentProof {
            $order = $this->order()->lockForUpdate()->first();
            $payment = self::query()->lockForUpdate()->findOrFail($this->id);

            if (! $payment->canSubmitProof()) {
                throw new \RuntimeException('This payment cannot accept a payment proof.');
            }

            $payment->proofs()
                ->where('is_current', true)
                ->update(['is_current' => false]);

            $proof = $payment->proofs()->create([
                'file_path' => $filePath,
                'uploaded_at' => now(),
                'status' => PaymentStatus::WaitingConfirm,
                'is_current' => true,
            ]);

            if ($payment->status === PaymentStatus::Unpaid) {
                $payment->forceFill([
                    'status' => PaymentStatus::WaitingConfirm,
                    'proof_upload_deadline_at' => null,
                    'verified_by' => null,
                    'verified_at' => null,
                    'rejection_reason' => null,
                ])->save();

                $order?->markWaitingConfirmation($actor);
            } elseif ($payment->status !== PaymentStatus::WaitingConfirm) {
                $payment->forceFill([
                    'status' => PaymentStatus::WaitingConfirm,
                    'verified_by' => null,
                    'verified_at' => null,
                    'rejection_reason' => null,
                ])->save();

                $order?->markWaitingConfirmation($actor);
            }

            $this->setRawAttributes($payment->getAttributes(), true);
            $this->setRelation('order', $order);

            return $proof;
        });
    }

    /**
     * Generate payment number dengan format PAY-YYYYMMDD-xxx.
     */
    public static function generatePaymentNumber(?DateTimeInterface $transactionAt = null): string
    {
        $date = $transactionAt === null
            ? now('Asia/Jakarta')
            : Carbon::instance($transactionAt)->timezone('Asia/Jakarta');
        $prefix = 'PAY-'.$date->format('Ymd').'-';

        $lastNumber = self::query()
            ->withTrashed()
            ->where('payment_number', 'like', $prefix.'%')
            ->orderByDesc('payment_number')
            ->value('payment_number');

        $next = $lastNumber ? ((int) Str::afterLast($lastNumber, '-')) + 1 : 1;

        return $prefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }
}
