<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use App\Services\SystemFreezeGuard;
use Database\Factories\PaymentProofFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'payment_id',
    'file_path',
    'uploaded_at',
    'status',
    'reviewed_by',
    'reviewed_at',
    'rejection_reason',
    'resubmission_deadline_at',
    'is_current',
])]
class PaymentProof extends Model
{
    /** @use HasFactory<PaymentProofFactory> */
    use HasFactory, HasUuidPrimaryKey;

    protected static function booted(): void
    {
        static::saving(function (self $proof): void {
            app(SystemFreezeGuard::class)->assertNotFrozen();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'uploaded_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'resubmission_deadline_at' => 'datetime',
            'is_current' => 'boolean',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
