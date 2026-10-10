<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Enums\VendorApprovalStatus;
use App\Models\Concerns\HasRecordLifecycle;
use App\Models\Concerns\HasUuidPrimaryKey;
use App\Services\ApprovalWorkflow;
use Database\Factories\VendorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['vendor_code', 'name', 'contact_person', 'phone', 'email', 'address', 'tax_id', 'bank_name', 'bank_account', 'bank_account_name', 'payment_terms', 'lead_time_days', 'notes', 'is_active', 'approval_status', 'approval_level', 'approval_setting_id', 'updated_by'])]
class Vendor extends Model
{
    /** @use HasFactory<VendorFactory> */
    use HasFactory, HasRecordLifecycle, HasUuidPrimaryKey, SoftDeletes;

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'lead_time_days' => 'integer', 'approval_level' => 'integer', 'approval_status' => VendorApprovalStatus::class];
    }

    public function approvalSetting(): BelongsTo
    {
        return $this->belongsTo(ApprovalSetting::class)->withTrashed();
    }

    public function approvalLogs(): MorphMany
    {
        return $this->morphMany(ApprovalLog::class, 'approvable');
    }

    public function approvalProgressLabel(): string
    {
        if ($this->record_status === RecordStatus::Draft) {
            return 'Draft';
        }

        if ($this->approval_status === VendorApprovalStatus::Pending && $this->approval_level > 1) {
            return 'Level '.($this->approval_level - 1).' Approved';
        }

        return $this->approval_status?->getLabel() ?? '-';
    }

    /** @return list<string> */
    protected function lifecycleUniqueAttributes(): array
    {
        return ['vendor_code'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $vendor): void {
            if ($vendor->record_status === RecordStatus::Draft) {
                $vendor->approval_status = VendorApprovalStatus::Pending;
                $vendor->approval_level = 0;
                $vendor->approval_setting_id = null;

                return;
            }

            $requiresApproval = app(ApprovalWorkflow::class)->captureApprovalSetting($vendor);
            $vendor->approval_status = $requiresApproval ? VendorApprovalStatus::Requested : VendorApprovalStatus::Approved;
            $vendor->approval_level = $requiresApproval ? 1 : 0;
        });

        static::updating(function (self $vendor): void {
            if ($vendor->getRawOriginal('approval_status') === VendorApprovalStatus::Revised->value
                && $vendor->record_status === RecordStatus::Saved
                && $vendor->isDirty([
                    'vendor_code', 'name', 'contact_person', 'phone', 'email', 'address', 'tax_id',
                    'bank_name', 'bank_account', 'bank_account_name', 'payment_terms', 'lead_time_days', 'is_active', 'notes',
                ])) {
                $requiresApproval = app(ApprovalWorkflow::class)->captureApprovalSetting($vendor);
                $vendor->approval_status = $requiresApproval ? VendorApprovalStatus::Requested : VendorApprovalStatus::Approved;
                $vendor->approval_level = $requiresApproval ? 1 : 0;

                return;
            }

            if ($vendor->getRawOriginal('record_status') !== RecordStatus::Draft->value
                || $vendor->record_status !== RecordStatus::Saved) {
                return;
            }

            $requiresApproval = app(ApprovalWorkflow::class)->captureApprovalSetting($vendor);
            $vendor->approval_status = $requiresApproval ? VendorApprovalStatus::Requested : VendorApprovalStatus::Approved;
            $vendor->approval_level = $requiresApproval ? 1 : 0;
        });

        static::created(function (self $vendor): void {
            if ($vendor->approval_status === VendorApprovalStatus::Requested) {
                app(ApprovalWorkflow::class)->recordRequest($vendor, $vendor->created_by);
            }
        });

        static::updated(function (self $vendor): void {
            if ($vendor->wasChanged('approval_status') && $vendor->approval_status === VendorApprovalStatus::Requested) {
                app(ApprovalWorkflow::class)->recordRequest($vendor, auth('web')->id() ?? $vendor->updated_by);
            }
        });
    }
}
