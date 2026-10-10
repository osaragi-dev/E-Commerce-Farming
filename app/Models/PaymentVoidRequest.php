<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PaymentVoidRequest extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public const array ACTIVE_STATUSES = ['requested', 'pending', 'revised'];

    protected $fillable = ['payment_id', 'requested_by', 'reason', 'cancel_order', 'approval_setting_id', 'approval_status', 'approval_level', 'updated_by'];

    protected function casts(): array
    {
        return ['approval_level' => 'integer', 'cancel_order' => 'boolean'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function approvalSetting(): BelongsTo
    {
        return $this->belongsTo(ApprovalSetting::class)->withTrashed();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function logs(): MorphMany
    {
        return $this->morphMany(ApprovalLog::class, 'approvable')->orderBy('created_at');
    }
}
