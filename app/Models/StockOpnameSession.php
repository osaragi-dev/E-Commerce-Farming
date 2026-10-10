<?php

namespace App\Models;

use App\Enums\StockOpnameApprovalStatus;
use App\Enums\StockOpnameStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use App\Services\ApprovalWorkflow;
use Database\Factories\StockOpnameSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'opname_number',
    'warehouse_id',
    'period',
    'opname_date',
    'status',
    'checker_id',
    'witness_id',
    'approved_by',
    'approved_at',
    'finalized_at',
    'notes',
    'approval_status',
    'approval_level',
    'approval_setting_id',
    'created_by',
    'updated_by',
    'deleted_by',
])]
class StockOpnameSession extends Model
{
    /** @use HasFactory<StockOpnameSessionFactory> */
    use HasFactory, HasUuidPrimaryKey, SoftDeletes;

    protected $attributes = [
        'status' => StockOpnameStatus::Draft,
        'approval_status' => StockOpnameApprovalStatus::Pending,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $session): void {
            if ($session->opname_number === null) {
                $session->opname_number = $session->status === StockOpnameStatus::Draft
                    ? null
                    : self::savedNumber();
            }

            $session->created_by ??= auth()->id();

            if (! $session->isDirty('approval_status')) {
                $requiresApproval = app(ApprovalWorkflow::class)->captureApprovalSetting($session);
                $session->approval_status = $session->status === StockOpnameStatus::Draft
                    ? StockOpnameApprovalStatus::Pending
                    : ($requiresApproval ? StockOpnameApprovalStatus::Requested : StockOpnameApprovalStatus::Approved);
                $session->approval_level = $session->status === StockOpnameStatus::Draft
                    ? 0
                    : ($requiresApproval ? 1 : 0);
            }
        });

        static::updating(function (self $session): void {
            if ($session->status !== StockOpnameStatus::Draft
                && (blank($session->opname_number)
                    || str_starts_with((string) $session->opname_number, 'DRAFT-'))) {
                $session->opname_number = self::savedNumber();
            }

            if ($session->isDirty('status')
                && $session->getRawOriginal('status') === StockOpnameStatus::Draft->value
                && $session->status !== StockOpnameStatus::Draft) {
                $requiresApproval = app(ApprovalWorkflow::class)->requiresApproval($session);
                $session->approval_status = $requiresApproval
                    ? StockOpnameApprovalStatus::Requested
                    : StockOpnameApprovalStatus::Approved;
                $session->approval_level = $requiresApproval ? 1 : 0;
            }

            if ($session->getRawOriginal('approval_status') === StockOpnameApprovalStatus::Revised->value
                && ($session->isDirty('status')
                    || $session->isDirty([
                        'warehouse_id',
                        'period',
                        'opname_date',
                        'checker_id',
                        'witness_id',
                        'notes',
                    ]))) {
                if ($session->status === StockOpnameStatus::Draft) {
                    $session->approval_status = StockOpnameApprovalStatus::Pending;
                    $session->approval_level = 0;
                } else {
                    $requiresApproval = app(ApprovalWorkflow::class)->requiresApproval($session);
                    $session->approval_status = $requiresApproval
                        ? StockOpnameApprovalStatus::Requested
                        : StockOpnameApprovalStatus::Approved;
                    $session->approval_level = $requiresApproval ? 1 : 0;
                }
            }

            if (! $session->isDirty('updated_by')) {
                $session->updated_by = auth()->id();
            }
        });

        static::deleting(function (self $session): void {
            if (! $session->isForceDeleting()) {
                $session->forceFill(['deleted_by' => auth()->id()])->saveQuietly();
            }
        });

        static::restoring(function (self $session): void {
            $session->deleted_by = null;
        });

        static::created(function (self $session): void {
            $session->logs()->create([
                'action' => 'created',
                'to_status' => $session->status->value,
                'changed_by' => $session->created_by,
                'created_at' => now(),
            ]);

            if ($session->approval_status === StockOpnameApprovalStatus::Requested) {
                app(ApprovalWorkflow::class)->recordRequest($session, $session->created_by);
            }
        });

        static::updated(function (self $session): void {
            if ($session->wasChanged('approval_status')
                && $session->approval_status === StockOpnameApprovalStatus::Requested) {
                app(ApprovalWorkflow::class)->recordRequest($session, $session->updated_by ?? $session->created_by);
            }

            if (! $session->wasChanged('status')) {
                return;
            }

            $session->logs()->create([
                'action' => 'status_changed',
                'from_status' => $session->getOriginal('status'),
                'to_status' => $session->status->value,
                'changed_by' => $session->updated_by,
                'created_at' => now(),
            ]);
        });
    }

    protected function casts(): array
    {
        return [
            'status' => StockOpnameStatus::class,
            'approval_status' => StockOpnameApprovalStatus::class,
            'approval_level' => 'integer',
            'opname_date' => 'date',
            'approved_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    protected function processStatus(): Attribute
    {
        return Attribute::get(fn (): string => $this->processStatusLabel());
    }

    public function processStatusLabel(): string
    {
        return match ($this->status) {
            StockOpnameStatus::Draft => 'Draft',
            StockOpnameStatus::Frozen => 'Frozen',
            StockOpnameStatus::Counting => 'Processing',
            StockOpnameStatus::Submitted => 'Submitted',
            StockOpnameStatus::Approved => 'Ended',
            StockOpnameStatus::Finalized => 'Finalized',
            StockOpnameStatus::Cancelled => 'Cancelled',
        };
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checker_id');
    }

    public function witness(): BelongsTo
    {
        return $this->belongsTo(User::class, 'witness_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockOpnameItem::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(StockOpnameLog::class, 'stock_opname_session_id');
    }

    public function approvalLogs(): MorphMany
    {
        return $this->morphMany(ApprovalLog::class, 'approvable');
    }

    public function approvalSetting(): BelongsTo
    {
        return $this->belongsTo(ApprovalSetting::class)->withTrashed();
    }

    public function isEditableBy(?string $actorId): bool
    {
        return $actorId !== null
            && $this->created_by === $actorId
            && ($this->status === StockOpnameStatus::Draft
                || $this->approval_status === StockOpnameApprovalStatus::Revised);
    }

    public function freeze(?string $actorId = null): void
    {
        SystemFreezeState::activate($this, $actorId);
    }

    public function unfreeze(?string $actorId = null): void
    {
        SystemFreezeState::deactivate($actorId);
    }

    private static function savedNumber(): string
    {
        $prefix = 'STO-'.now()->format('Ymd').'-';
        $lastSequence = self::query()
            ->withTrashed()
            ->where('opname_number', 'like', $prefix.'%')
            ->pluck('opname_number')
            ->map(fn (string $number): int => preg_match('/^STO-\d{8}-(\d+)$/', $number, $matches)
                ? (int) $matches[1]
                : 0)
            ->max() ?? 0;

        return $prefix.str_pad((string) ($lastSequence + 1), 3, '0', STR_PAD_LEFT);
    }
}
