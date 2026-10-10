<?php

namespace App\Models;

use App\Enums\PurchaseOrderCloseApprovalStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\PurchaseOrderCloseRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['purchase_order_id', 'approval_status', 'approval_level', 'approval_setting_id', 'requested_by', 'notes', 'updated_by'])]
class PurchaseOrderCloseRequest extends Model
{
    /** @use HasFactory<PurchaseOrderCloseRequestFactory> */
    use HasFactory, HasUuidPrimaryKey;

    protected function casts(): array
    {
        return [
            'approval_status' => PurchaseOrderCloseApprovalStatus::class,
            'approval_level' => 'integer',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class)->withTrashed();
    }

    public function approvalSetting(): BelongsTo
    {
        return $this->belongsTo(ApprovalSetting::class)->withTrashed();
    }

    public function approvalLogs(): MorphMany
    {
        return $this->morphMany(ApprovalLog::class, 'approvable');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
