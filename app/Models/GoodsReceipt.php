<?php

namespace App\Models;

use App\Enums\GoodsReceiptApprovalStatus;
use App\Enums\RecordStatus;
use App\Models\Concerns\HasRecordLifecycle;
use App\Models\Concerns\HasUuidPrimaryKey;
use App\Services\ApprovalWorkflow;
use Database\Factories\GoodsReceiptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'purchase_order_id', 'receipt_number', 'received_at', 'delivery_note_number', 'checked_by',
    'documentation_path', 'status', 'notes', 'approval_status', 'approval_level', 'approval_setting_id', 'updated_by',
])]
class GoodsReceipt extends Model
{
    /** @use HasFactory<GoodsReceiptFactory> */
    use HasFactory, HasRecordLifecycle, HasUuidPrimaryKey, SoftDeletes;

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'approval_status' => GoodsReceiptApprovalStatus::class,
            'approval_level' => 'integer',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }

    public function approvalSetting(): BelongsTo
    {
        return $this->belongsTo(ApprovalSetting::class)->withTrashed();
    }

    public function approvalLogs(): MorphMany
    {
        return $this->morphMany(ApprovalLog::class, 'approvable');
    }

    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function totalAccepted(): Attribute
    {
        return Attribute::get(fn (): string => number_format((float) $this->items->sum('accepted_quantity'), 3, '.', ''));
    }

    protected function totalRejected(): Attribute
    {
        return Attribute::get(fn (): string => number_format((float) $this->items->sum('rejected_quantity'), 3, '.', ''));
    }

    /** @return list<string> */
    protected function lifecycleUniqueAttributes(): array
    {
        return ['receipt_number'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $receipt): void {
            if ($receipt->record_status === RecordStatus::Draft) {
                $receipt->receipt_number = null;
                $receipt->approval_status = GoodsReceiptApprovalStatus::Pending;
                $receipt->approval_level = 0;
                $receipt->approval_setting_id = null;
                $receipt->status = RecordStatus::Draft->value;

                return;
            }

            $receipt->receipt_number ??= self::nextReceiptNumber();
            $receipt->startApproval();
            $receipt->status = RecordStatus::Saved->value;
        });

        static::updating(function (self $receipt): void {
            if ($receipt->record_status === RecordStatus::Draft) {
                $receipt->receipt_number = null;
                $receipt->approval_status = GoodsReceiptApprovalStatus::Pending;
                $receipt->approval_level = 0;
                $receipt->approval_setting_id = null;
                $receipt->status = RecordStatus::Draft->value;

                return;
            }

            if ($receipt->getRawOriginal('record_status') === RecordStatus::Draft->value
                && $receipt->record_status === RecordStatus::Saved) {
                $receipt->receipt_number ??= self::nextReceiptNumber();
                $receipt->startApproval();
            }

            $receipt->status = RecordStatus::Saved->value;
        });

        static::created(function (self $receipt): void {
            if ($receipt->approval_status === GoodsReceiptApprovalStatus::Requested) {
                app(ApprovalWorkflow::class)->recordRequest($receipt, $receipt->created_by);
            }
        });

        static::updated(function (self $receipt): void {
            if ($receipt->wasChanged('approval_status')
                && $receipt->approval_status === GoodsReceiptApprovalStatus::Requested) {
                app(ApprovalWorkflow::class)->recordRequest($receipt, $receipt->updated_by);
            }
        });
    }

    public function approvalProgressLabel(): string
    {
        if ($this->record_status === RecordStatus::Draft) {
            return GoodsReceiptApprovalStatus::Pending->getLabel();
        }

        if ($this->approval_status === GoodsReceiptApprovalStatus::Pending && $this->approval_level > 1) {
            return 'Level '.($this->approval_level - 1).' Approved';
        }

        return $this->approval_status?->getLabel() ?? '-';
    }

    public function approvalProgressCountLabel(): string
    {
        if ($this->record_status === RecordStatus::Draft) {
            return 'Pending';
        }

        if (in_array($this->approval_status, [GoodsReceiptApprovalStatus::Rejected, GoodsReceiptApprovalStatus::Revised], true)) {
            return $this->approval_status->getLabel();
        }

        $totalLevels = $this->approvalSetting?->levels->count() ?? 0;

        if ($totalLevels === 0) {
            return $this->approval_status?->getLabel() ?? '-';
        }

        $latestRequest = $this->approvalLogs
            ->whereIn('action', ['requested', 'resubmitted'])
            ->sortByDesc('created_at')
            ->first();
        $completedLevels = $this->approvalLogs
            ->where('action', 'approved')
            ->filter(fn (ApprovalLog $log): bool => $latestRequest === null || $log->created_at?->greaterThanOrEqualTo($latestRequest->created_at))
            ->count();

        return min($completedLevels, $totalLevels).'/'.$totalLevels;
    }

    private function startApproval(): void
    {
        $requiresApproval = app(ApprovalWorkflow::class)->captureApprovalSetting($this);
        $this->approval_status = $requiresApproval
            ? GoodsReceiptApprovalStatus::Requested
            : GoodsReceiptApprovalStatus::Approved;
        $this->approval_level = $requiresApproval ? 1 : 0;
    }

    private static function nextReceiptNumber(): string
    {
        return 'GR-'.now()->format('Ymd').'-'.strtoupper(str()->random(8));
    }
}
