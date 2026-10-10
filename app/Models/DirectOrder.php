<?php

namespace App\Models;

use App\Enums\PurchaseRequestApprovalStatus;
use App\Enums\RecordStatus;
use App\Models\Concerns\HasRecordLifecycle;
use App\Models\Concerns\HasUuidPrimaryKey;
use App\Services\ApprovalWorkflow;
use Database\Factories\DirectOrderFactory;
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
    'do_number', 'do_date', 'payment_by', 'reason', 'warehouse_id', 'vendor_id', 'requested_by', 'notes',
    'subtotal', 'tax_amount', 'discount_amount', 'total_amount', 'terms_conditions', 'transaction_proof',
    'approval_status', 'approval_level', 'approval_setting_id', 'updated_by',
])]
class DirectOrder extends Model
{
    /** @use HasFactory<DirectOrderFactory> */
    use HasFactory, HasRecordLifecycle, HasUuidPrimaryKey, SoftDeletes;

    protected function casts(): array
    {
        return [
            'do_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'approval_status' => PurchaseRequestApprovalStatus::class,
            'approval_level' => 'integer',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
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
        return $this->hasMany(DirectOrderItem::class)->orderBy('sort_order');
    }

    protected function progress(): Attribute
    {
        return Attribute::get(fn (): string => $this->approvalProgressLabel());
    }

    /** @return list<string> */
    protected function lifecycleUniqueAttributes(): array
    {
        return ['do_number'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $directOrder): void {
            $directOrder->requested_by ??= auth('web')->id();

            if ($directOrder->record_status === RecordStatus::Draft) {
                $directOrder->do_number = null;
                $directOrder->approval_status = PurchaseRequestApprovalStatus::Pending;
                $directOrder->approval_level = 0;
                $directOrder->approval_setting_id = null;

                return;
            }

            $directOrder->do_number ??= self::nextDoNumber();
            $directOrder->startApproval();
        });

        static::updating(function (self $directOrder): void {
            if ($directOrder->record_status !== RecordStatus::Draft
                && $directOrder->getRawOriginal('approval_status') === PurchaseRequestApprovalStatus::Revised->value) {
                $directOrder->startApproval();

                return;
            }

            if ($directOrder->getRawOriginal('record_status') === RecordStatus::Draft->value
                && $directOrder->record_status === RecordStatus::Saved) {
                $directOrder->do_number ??= self::nextDoNumber();
                $directOrder->startApproval();
            }
        });

        static::created(function (self $directOrder): void {
            if ($directOrder->approval_status === PurchaseRequestApprovalStatus::Requested) {
                app(ApprovalWorkflow::class)->recordRequest(
                    $directOrder,
                    $directOrder->requested_by ?? $directOrder->created_by,
                );
            }
        });

        static::updated(function (self $directOrder): void {
            if ($directOrder->wasChanged('approval_status')
                && $directOrder->approval_status === PurchaseRequestApprovalStatus::Requested) {
                app(ApprovalWorkflow::class)->recordRequest(
                    $directOrder,
                    auth('web')->id() ?? $directOrder->requested_by,
                );
            }
        });
    }

    public function approvalProgressLabel(): string
    {
        if ($this->record_status === RecordStatus::Draft) {
            return 'Draft';
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

    private static function nextDoNumber(): string
    {
        $date = now();
        $sequenceDate = $date->toDateString();
        $number = DB::transaction(function () use ($sequenceDate): int {
            DB::table('direct_order_number_sequences')->insertOrIgnore([
                'sequence_date' => $sequenceDate,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $number = (int) DB::table('direct_order_number_sequences')
                ->where('sequence_date', $sequenceDate)
                ->lockForUpdate()
                ->value('last_number') + 1;

            DB::table('direct_order_number_sequences')
                ->where('sequence_date', $sequenceDate)
                ->update(['last_number' => $number, 'updated_at' => now()]);

            return $number;
        });

        return sprintf('DO-%s-%04d', $date->format('Ymd'), $number);
    }
}
