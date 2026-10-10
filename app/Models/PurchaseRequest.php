<?php

namespace App\Models;

use App\Enums\PurchaseRequestApprovalStatus;
use App\Enums\RecordStatus;
use App\Models\Concerns\HasRecordLifecycle;
use App\Models\Concerns\HasUuidPrimaryKey;
use App\Services\ApprovalWorkflow;
use Database\Factories\PurchaseRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

#[Fillable(['pr_number', 'pr_date', 'warehouse_id', 'requested_by', 'required_date', 'notes', 'approval_status', 'approval_level', 'approval_setting_id', 'updated_by'])]
class PurchaseRequest extends Model
{
    /** @use HasFactory<PurchaseRequestFactory> */
    use HasFactory, HasRecordLifecycle, HasUuidPrimaryKey, SoftDeletes;

    protected function casts(): array
    {
        return [
            'pr_date' => 'date',
            'required_date' => 'date',
            'approval_status' => PurchaseRequestApprovalStatus::class,
            'approval_level' => 'integer',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvalSetting(): BelongsTo
    {
        return $this->belongsTo(ApprovalSetting::class)->withTrashed();
    }

    public function approvalLogs(): MorphMany
    {
        return $this->morphMany(ApprovalLog::class, 'approvable');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequestItem::class)->orderBy('sort_order');
    }

    /** @return list<string> */
    protected function lifecycleUniqueAttributes(): array
    {
        return ['pr_number'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $purchaseRequest): void {
            $purchaseRequest->requested_by ??= auth('web')->id();

            if ($purchaseRequest->record_status === RecordStatus::Draft) {
                $purchaseRequest->pr_number = null;
                $purchaseRequest->approval_status = PurchaseRequestApprovalStatus::Pending;
                $purchaseRequest->approval_level = 0;
                $purchaseRequest->approval_setting_id = null;

                return;
            }

            $purchaseRequest->pr_number ??= self::nextPrNumber();
            $purchaseRequest->startApproval();
        });

        static::updating(function (self $purchaseRequest): void {
            if ($purchaseRequest->record_status !== RecordStatus::Draft
                && $purchaseRequest->getRawOriginal('approval_status') === PurchaseRequestApprovalStatus::Revised->value
            ) {
                $purchaseRequest->startApproval();

                return;
            }

            if ($purchaseRequest->getRawOriginal('record_status') === RecordStatus::Draft->value
                && $purchaseRequest->record_status === RecordStatus::Saved) {
                $purchaseRequest->pr_number ??= self::nextPrNumber();
                $purchaseRequest->startApproval();
            }
        });

        static::created(function (self $purchaseRequest): void {
            if ($purchaseRequest->approval_status === PurchaseRequestApprovalStatus::Requested) {
                app(ApprovalWorkflow::class)->recordRequest($purchaseRequest, $purchaseRequest->requested_by ?? $purchaseRequest->created_by);
            }
        });

        static::updated(function (self $purchaseRequest): void {
            if ($purchaseRequest->wasChanged('approval_status')
                && $purchaseRequest->approval_status === PurchaseRequestApprovalStatus::Requested) {
                app(ApprovalWorkflow::class)->recordRequest($purchaseRequest, auth('web')->id() ?? $purchaseRequest->requested_by);
            }
        });
    }

    public function approvalProgressLabel(): string
    {
        if ($this->record_status === RecordStatus::Draft) {
            return PurchaseRequestApprovalStatus::Pending->getLabel();
        }

        if ($this->approval_status === PurchaseRequestApprovalStatus::Pending && $this->approval_level > 1) {
            return 'L'.($this->approval_level - 1).' Approved';
        }

        return $this->approval_status?->getLabel() ?? '-';
    }

    private function startApproval(): void
    {
        $requiresApproval = app(ApprovalWorkflow::class)->captureApprovalSetting($this);
        $this->approval_status = $requiresApproval
            ? PurchaseRequestApprovalStatus::Requested
            : PurchaseRequestApprovalStatus::Approved;
        $this->approval_level = $requiresApproval ? 1 : 0;
    }

    private static function nextPrNumber(): string
    {
        $date = now();
        $sequenceDate = $date->toDateString();
        $number = DB::transaction(function () use ($sequenceDate): int {
            DB::table('purchase_request_number_sequences')->insertOrIgnore([
                'sequence_date' => $sequenceDate,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $number = (int) DB::table('purchase_request_number_sequences')
                ->where('sequence_date', $sequenceDate)
                ->lockForUpdate()
                ->value('last_number') + 1;

            DB::table('purchase_request_number_sequences')
                ->where('sequence_date', $sequenceDate)
                ->update(['last_number' => $number, 'updated_at' => now()]);

            return $number;
        });

        return sprintf('PR-%s-%03d', $date->format('Ymd'), $number);
    }
}
