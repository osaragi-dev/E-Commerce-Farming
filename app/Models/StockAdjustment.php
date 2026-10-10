<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Enums\StockAdjustmentApprovalStatus;
use App\Enums\StockAdjustmentReason;
use App\Enums\StockAdjustmentSource;
use App\Enums\StockAdjustmentType;
use App\Enums\StockMutationSource;
use App\Enums\StockOpnameApprovalStatus;
use App\Enums\StockOpnameStatus;
use App\Models\Concerns\HasRecordLifecycle;
use App\Models\Concerns\HasUuidPrimaryKey;
use App\Services\ApprovalWorkflow;
use App\Services\SystemFreezeGuard;
use App\Support\Quantity;
use Database\Factories\StockAdjustmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Stock adjustments are append-only after application. A revised, unapplied
 * adjustment may be corrected by its creator and must pass approval again.
 */
#[Fillable([
    'warehouse_id',
    'product_variant_id',
    'source',
    'adjustment_number',
    'type',
    'quantity',
    'reason',
    'note',
    'adjusted_by',
    'updated_by',
    'created_at',
    'approval_status',
    'approval_level',
    'approval_setting_id',
    'applied_at',
    'applied_by',
])]
class StockAdjustment extends Model
{
    /** @use HasFactory<StockAdjustmentFactory> */
    use HasFactory, HasRecordLifecycle, HasUuidPrimaryKey, SoftDeletes;

    protected function casts(): array
    {
        return [
            'type' => StockAdjustmentType::class,
            'source' => StockAdjustmentSource::class,
            'quantity' => 'decimal:3',
            'reason' => StockAdjustmentReason::class,
            'approval_status' => StockAdjustmentApprovalStatus::class,
            'approval_level' => 'integer',
            'applied_at' => 'datetime',
        ];
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function stockMutations(): MorphMany
    {
        return $this->morphMany(StockMutation::class, 'reference');
    }

    public function stockOpnameItems(): HasMany
    {
        return $this->hasMany(StockOpnameItem::class, 'stock_adjustment_id');
    }

    public function approvalSetting(): BelongsTo
    {
        return $this->belongsTo(ApprovalSetting::class)->withTrashed();
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function approvalLogs(): MorphMany
    {
        return $this->morphMany(ApprovalLog::class, 'approvable');
    }

    public function adjustedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }

    /**
     * Dipakai buat judul halaman View — tanpa ini, Filament nampilin
     * UUID mentah yang panjang & tidak informatif. Dengan ini, judulnya
     * jadi misal "Telur Herbal - Grade A (Aug 20, 2026 14:12)".
     */
    protected function label(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->source === StockAdjustmentSource::StockOpname
                ? sprintf('Stock Opname Adjustment (%s)', $this->adjustment_number ?? '-')
                : sprintf(
                    '%s - %s (%s)',
                    $this->productVariant?->product?->name ?? 'Unknown Product',
                    $this->productVariant?->name ?? 'Unknown Variant',
                    $this->created_at?->format('M j, Y H:i') ?? '-',
                ),
        );
    }

    protected function productSummary(): Attribute
    {
        return Attribute::make(get: function (): string {
            if ($this->source !== StockAdjustmentSource::StockOpname) {
                return sprintf(
                    '%s - %s',
                    $this->productVariant?->product?->name ?? 'Unknown Product',
                    $this->productVariant?->name ?? 'Unknown Variant',
                );
            }

            return $this->stockOpnameItems
                ->map(fn (StockOpnameItem $item): string => sprintf(
                    '%s - %s',
                    $item->productVariant?->product?->name ?? 'Unknown Product',
                    $item->productVariant?->name ?? 'Unknown Variant',
                ))
                ->unique()
                ->implode(', ');
        });
    }

    protected function typeSummary(): Attribute
    {
        return Attribute::make(get: function (): string {
            if ($this->source !== StockAdjustmentSource::StockOpname) {
                return $this->type?->getLabel() ?? '-';
            }

            return $this->stockOpnameItems
                ->map(fn (StockOpnameItem $item): string => (float) $item->variance_qty > 0
                    ? StockAdjustmentType::Tambah->getLabel()
                    : ((float) $item->variance_qty < 0 ? StockAdjustmentType::Kurang->getLabel() : ''))
                ->filter()
                ->unique()
                ->implode(' / ') ?: '-';
        });
    }

    protected function quantitySummary(): Attribute
    {
        return Attribute::make(get: fn (): string => $this->source === StockAdjustmentSource::StockOpname
            ? $this->stockOpnameItems->count().' items'
            : Quantity::format($this->quantity));
    }

    protected static function booted(): void
    {
        static::creating(function (self $adjustment): void {
            $adjustment->source ??= StockAdjustmentSource::DirectAdjustment;
            $adjustment->adjustment_number ??= self::nextAdjustmentNumber();
            $adjustment->adjusted_by ??= auth('web')->id();
            $adjustment->warehouse_id ??= Warehouse::query()->where('code', 'WH-FARMING')->value('id');

            if ($adjustment->record_status === RecordStatus::Draft) {
                $adjustment->approval_status = StockAdjustmentApprovalStatus::Pending;
                $adjustment->approval_level = 0;
                $adjustment->approval_setting_id = null;

                app(SystemFreezeGuard::class)->assertNotFrozen();

                return;
            }

            $requiresApproval = app(ApprovalWorkflow::class)->captureApprovalSetting($adjustment);
            $adjustment->approval_status = $requiresApproval
                ? StockAdjustmentApprovalStatus::Requested
                : StockAdjustmentApprovalStatus::Approved;
            $adjustment->approval_level = $requiresApproval ? 1 : 0;

            app(SystemFreezeGuard::class)->assertNotFrozen();
        });

        static::updating(function (self $adjustment): void {
            if ($adjustment->getRawOriginal('approval_status') === StockAdjustmentApprovalStatus::Revised->value
                && $adjustment->record_status === RecordStatus::Saved
                && $adjustment->isDirty([
                    'warehouse_id',
                    'product_variant_id',
                    'type',
                    'quantity',
                    'reason',
                    'note',
                    'created_at',
                ])) {
                $requiresApproval = app(ApprovalWorkflow::class)->captureApprovalSetting($adjustment);
                $adjustment->approval_status = $requiresApproval
                    ? StockAdjustmentApprovalStatus::Requested
                    : StockAdjustmentApprovalStatus::Approved;
                $adjustment->approval_level = $requiresApproval ? 1 : 0;
                $adjustment->applied_at = null;
                $adjustment->applied_by = null;

                return;
            }

            if ($adjustment->getRawOriginal('record_status') !== RecordStatus::Draft->value
                || $adjustment->record_status !== RecordStatus::Saved) {
                return;
            }

            $requiresApproval = app(ApprovalWorkflow::class)->captureApprovalSetting($adjustment);
            $adjustment->approval_status = $requiresApproval
                ? StockAdjustmentApprovalStatus::Requested
                : StockAdjustmentApprovalStatus::Approved;
            $adjustment->approval_level = $requiresApproval ? 1 : 0;
            $adjustment->applied_at = null;
            $adjustment->applied_by = null;
        });

        static::created(function (self $adjustment): void {
            if ($adjustment->approval_status === StockAdjustmentApprovalStatus::Requested) {
                app(ApprovalWorkflow::class)->recordRequest($adjustment, $adjustment->adjusted_by);
            }
        });

        static::updated(function (self $adjustment): void {
            if ($adjustment->wasChanged('approval_status')
                && $adjustment->approval_status === StockAdjustmentApprovalStatus::Requested) {
                app(ApprovalWorkflow::class)->recordRequest($adjustment, auth('web')->id() ?? $adjustment->adjusted_by);
            }
        });

    }

    public static function nextAdjustmentNumber(): string
    {
        $date = now()->toDateString();
        $number = DB::transaction(function () use ($date): int {
            DB::table('stock_adjustment_number_sequences')->insertOrIgnore([
                'sequence_date' => $date,
                'last_number' => 0,
            ]);

            $number = (int) DB::table('stock_adjustment_number_sequences')
                ->where('sequence_date', $date)
                ->lockForUpdate()
                ->value('last_number') + 1;

            DB::table('stock_adjustment_number_sequences')
                ->where('sequence_date', $date)
                ->update(['last_number' => $number, 'updated_at' => now()]);

            return $number;
        });

        return sprintf('ADJ-%s-%03d', str_replace('-', '', $date), $number);
    }

    public function isPostable(): bool
    {
        return ! $this->trashed()
            && $this->record_status === RecordStatus::Saved
            && $this->approval_status === StockAdjustmentApprovalStatus::Approved
            && $this->applied_at === null;
    }

    public function approvalStatusLabel(): string
    {
        if ($this->approval_status === StockAdjustmentApprovalStatus::Pending && $this->approval_level > 1) {
            return 'L'.($this->approval_level - 1).' Approved';
        }

        return $this->approval_status?->getLabel() ?? '-';
    }

    public function applyToStock(?string $appliedBy = null): void
    {
        DB::transaction(function () use ($appliedBy): void {
            $adjustment = self::query()->lockForUpdate()->findOrFail($this->id);

            if ($adjustment->applied_at !== null) {
                return;
            }

            if (! $adjustment->isPostable()) {
                throw new \LogicException('Only approved stock adjustments can change stock.');
            }

            app(SystemFreezeGuard::class)->assertNotFrozen();

            if ($adjustment->source === StockAdjustmentSource::StockOpname) {
                $opnameItems = $adjustment->stockOpnameItems()
                    ->where('variance_qty', '!=', 0)
                    ->with('productVariant')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($opnameItems->isEmpty()) {
                    throw new \LogicException('Stock opname adjustments must contain variance items.');
                }

                $warehouse = Warehouse::query()->findOrFail($adjustment->warehouse_id);

                foreach ($opnameItems as $item) {
                    StockMutation::record(
                        variant: $item->productVariant,
                        source: StockMutationSource::ManualAdjustment,
                        quantityChange: (string) $item->variance_qty,
                        referenceType: self::class,
                        referenceId: $adjustment->id,
                        recordedBy: $adjustment->adjusted_by,
                        warehouse: $warehouse,
                    );
                }
            } else {
                $signedQuantity = $adjustment->type === StockAdjustmentType::Tambah
                    ? $adjustment->quantity
                    : Quantity::subtract('0', $adjustment->quantity);

                StockMutation::record(
                    variant: $adjustment->productVariant()->firstOrFail(),
                    source: StockMutationSource::ManualAdjustment,
                    quantityChange: $signedQuantity,
                    referenceType: self::class,
                    referenceId: $adjustment->id,
                    recordedBy: $adjustment->adjusted_by,
                    warehouse: Warehouse::find($adjustment->warehouse_id),
                );
            }

            $adjustment->forceFill([
                'approval_status' => StockAdjustmentApprovalStatus::Posted,
                'applied_at' => now(),
                'applied_by' => $appliedBy ?? auth()->id(),
                'updated_by' => $appliedBy ?? auth()->id(),
            ])->saveQuietly();

            $sessionIds = StockOpnameItem::query()
                ->where('stock_adjustment_id', $adjustment->id)
                ->distinct()
                ->pluck('stock_opname_session_id');

            foreach ($sessionIds as $sessionId) {
                $session = StockOpnameSession::query()->lockForUpdate()->find($sessionId);

                if (! $session || ! in_array($session->status, [StockOpnameStatus::Approved, StockOpnameStatus::Finalized], true)) {
                    continue;
                }

                $items = $session->items()->where('variance_qty', '!=', 0);
                $adjustmentIds = (clone $items)->whereNotNull('stock_adjustment_id')->pluck('stock_adjustment_id');
                $hasUnadjustedVariance = (clone $items)->whereNull('stock_adjustment_id')->exists();

                if ($adjustmentIds->isEmpty()
                    || $hasUnadjustedVariance
                    || self::query()
                        ->whereKey($adjustmentIds)
                        ->where('approval_status', '!=', StockAdjustmentApprovalStatus::Posted->value)
                        ->exists()) {
                    continue;
                }

                $session->forceFill([
                    'status' => StockOpnameStatus::Finalized,
                    'approval_status' => StockOpnameApprovalStatus::Posted,
                    'finalized_at' => $session->finalized_at ?? now(),
                    'updated_by' => $appliedBy ?? auth()->id() ?? $session->updated_by,
                ])->save();
            }
        });
    }

    public function isEditableBy(?string $actorId): bool
    {
        return $actorId !== null
            && ($this->record_status === RecordStatus::Draft
                || ($this->record_status === RecordStatus::Saved
                    && $this->approval_status === StockAdjustmentApprovalStatus::Revised))
            && $this->adjusted_by === $actorId
            && $this->applied_at === null;
    }
}
