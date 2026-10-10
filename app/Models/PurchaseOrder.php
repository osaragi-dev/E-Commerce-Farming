<?php

namespace App\Models;

use App\Enums\PurchaseOrderApprovalStatus;
use App\Enums\PurchaseOrderCloseApprovalStatus;
use App\Enums\PurchaseOrderPaymentStatus;
use App\Enums\PurchaseOrderReceivedStatus;
use App\Enums\RecordStatus;
use App\Models\Concerns\HasRecordLifecycle;
use App\Models\Concerns\HasUuidPrimaryKey;
use App\Services\ApprovalWorkflow;
use Database\Factories\PurchaseOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'po_number', 'po_date', 'expected_delivery_date', 'purchase_request_id', 'warehouse_id', 'vendor_id',
    'payment_by', 'payment_basis', 'subtotal', 'tax_amount', 'discount_amount', 'total_amount',
    'terms_and_conditions', 'notes', 'quotation_file_path', 'approval_status', 'approval_level',
    'approval_setting_id', 'updated_by',
])]
class PurchaseOrder extends Model
{
    /** @use HasFactory<PurchaseOrderFactory> */
    use HasFactory, HasRecordLifecycle, HasUuidPrimaryKey, SoftDeletes;

    protected function casts(): array
    {
        return [
            'po_date' => 'date',
            'expected_delivery_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'approval_status' => PurchaseOrderApprovalStatus::class,
            'approval_level' => 'integer',
        ];
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class)->withTrashed();
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('sort_order');
    }

    public function approvalSetting(): BelongsTo
    {
        return $this->belongsTo(ApprovalSetting::class)->withTrashed();
    }

    public function approvalLogs(): MorphMany
    {
        return $this->morphMany(ApprovalLog::class, 'approvable');
    }

    public function closeRequests(): HasMany
    {
        return $this->hasMany(PurchaseOrderCloseRequest::class)->latest();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchaseOrderPayment::class)->latest('payment_date');
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class)->latest('received_at');
    }

    public function purchaseInvoices(): HasMany
    {
        return $this->hasMany(PurchaseInvoice::class)->latest('invoice_date');
    }

    protected function latestCloseRequest(): Attribute
    {
        return Attribute::get(fn (): ?PurchaseOrderCloseRequest => $this->closeRequests->first());
    }

    protected function closingStatus(): Attribute
    {
        return Attribute::get(fn (): string => match ($this->latest_close_request?->approval_status) {
            PurchaseOrderCloseApprovalStatus::Requested, PurchaseOrderCloseApprovalStatus::Pending => 'requested',
            PurchaseOrderCloseApprovalStatus::Approved => 'closed',
            PurchaseOrderCloseApprovalStatus::Rejected => 'rejected',
            PurchaseOrderCloseApprovalStatus::Revised => 'revised',
            default => 'open',
        });
    }

    protected function receivedStatus(): Attribute
    {
        return Attribute::get(function (): PurchaseOrderReceivedStatus {
            $ordered = (float) $this->items->sum('qty_order');
            $received = (float) $this->items->sum('accepted_quantity');

            if ($received <= 0) {
                return $this->closing_status === 'closed'
                    ? PurchaseOrderReceivedStatus::NotReceivedClosed
                    : PurchaseOrderReceivedStatus::NotReceived;
            }

            return $received + 0.0001 >= $ordered
                ? PurchaseOrderReceivedStatus::Received
                : PurchaseOrderReceivedStatus::PartiallyReceived;
        });
    }

    protected function paymentTotal(): Attribute
    {
        return Attribute::get(fn (): string => number_format((float) $this->payments->sum('payment_amount'), 2, '.', ''));
    }

    protected function latestPaymentDate(): Attribute
    {
        return Attribute::get(fn (): mixed => $this->payments->first()?->payment_date);
    }

    protected function paymentStatus(): Attribute
    {
        return Attribute::get(fn (): PurchaseOrderPaymentStatus => (float) $this->payment_total <= 0
            ? PurchaseOrderPaymentStatus::Unpaid
            : ((float) $this->remainingAmount() <= 0
                ? PurchaseOrderPaymentStatus::Paid
                : PurchaseOrderPaymentStatus::Partial));
    }

    public function acceptedAmountForBasis(): string
    {
        if ($this->payment_basis === 'purchase_order') {
            return (string) $this->subtotal;
        }

        return $this->goodsReceiptAcceptedAmount();
    }

    public function goodsReceiptAcceptedAmount(): string
    {
        $acceptedAmount = $this->items->sum(fn (PurchaseOrderItem $item): float => (float) $item->accepted_quantity * (float) $item->unit_price,
        );

        return number_format($acceptedAmount, 2, '.', '');
    }

    public function payableAmount(): string
    {
        $amount = (float) $this->acceptedAmountForBasis() + (float) $this->tax_amount - (float) $this->discount_amount;

        return number_format(max(0, $amount), 2, '.', '');
    }

    public function remainingAmount(): string
    {
        return number_format(max(0, (float) $this->payableAmount() - (float) $this->payment_total), 2, '.', '');
    }

    /** @return list<string> */
    protected function lifecycleUniqueAttributes(): array
    {
        return ['po_number'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $purchaseOrder): void {
            if ($purchaseOrder->record_status === RecordStatus::Draft) {
                $purchaseOrder->po_number = null;
                $purchaseOrder->approval_status = PurchaseOrderApprovalStatus::Pending;
                $purchaseOrder->approval_level = 0;
                $purchaseOrder->approval_setting_id = null;

                return;
            }

            $purchaseOrder->po_number ??= self::nextPoNumber();
            $purchaseOrder->startApproval();
        });

        static::updating(function (self $purchaseOrder): void {
            if ($purchaseOrder->record_status === RecordStatus::Draft) {
                $purchaseOrder->po_number = null;

                return;
            }

            if ($purchaseOrder->getRawOriginal('record_status') === RecordStatus::Draft->value
                && $purchaseOrder->record_status === RecordStatus::Saved) {
                $purchaseOrder->po_number ??= self::nextPoNumber();
                $purchaseOrder->startApproval();
            }
        });

        static::created(function (self $purchaseOrder): void {
            if ($purchaseOrder->approval_status === PurchaseOrderApprovalStatus::Requested) {
                app(ApprovalWorkflow::class)->recordRequest($purchaseOrder, auth('web')->id() ?? $purchaseOrder->created_by);
            }
        });

        static::updated(function (self $purchaseOrder): void {
            if ($purchaseOrder->wasChanged('approval_status')
                && $purchaseOrder->approval_status === PurchaseOrderApprovalStatus::Requested) {
                app(ApprovalWorkflow::class)->recordRequest($purchaseOrder, auth('web')->id() ?? $purchaseOrder->updated_by);
            }
        });
    }

    public function approvalProgressLabel(): string
    {
        if ($this->record_status === RecordStatus::Draft) {
            return 'Draft';
        }

        if ($this->approval_status === PurchaseOrderApprovalStatus::Pending && $this->approval_level > 1) {
            return 'Level '.($this->approval_level - 1).' Approved';
        }

        return $this->approval_status?->getLabel() ?? '-';
    }

    private function startApproval(): void
    {
        $requiresApproval = app(ApprovalWorkflow::class)->captureApprovalSetting($this);
        $this->approval_status = $requiresApproval
            ? PurchaseOrderApprovalStatus::Requested
            : PurchaseOrderApprovalStatus::Approved;
        $this->approval_level = $requiresApproval ? 1 : 0;
    }

    private static function nextPoNumber(): string
    {
        $date = now();
        $sequenceDate = $date->toDateString();
        $number = DB::transaction(function () use ($sequenceDate): int {
            DB::table('purchase_order_number_sequences')->insertOrIgnore([
                'sequence_date' => $sequenceDate,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $number = (int) DB::table('purchase_order_number_sequences')
                ->where('sequence_date', $sequenceDate)
                ->lockForUpdate()
                ->value('last_number') + 1;

            DB::table('purchase_order_number_sequences')
                ->where('sequence_date', $sequenceDate)
                ->update(['last_number' => $number, 'updated_at' => now()]);

            return $number;
        });

        return sprintf('PO-%s-%03d', $date->format('Ymd'), $number);
    }
}
