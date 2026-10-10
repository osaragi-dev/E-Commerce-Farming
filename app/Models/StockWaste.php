<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Enums\StockMutationSource;
use App\Enums\StockWasteApprovalStatus;
use App\Enums\StockWasteSource;
use App\Enums\StockWasteStatus;
use App\Enums\StockWasteType;
use App\Models\Concerns\HasRecordLifecycle;
use App\Models\Concerns\HasUuidPrimaryKey;
use App\Services\SystemFreezeGuard;
use Database\Factories\StockWasteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'warehouse_id', 'product_variant_id', 'pic_user_id', 'waste_date', 'source', 'quantity', 'waste_type', 'reason', 'photo_path', 'notes',
    'created_by', 'updated_by', 'record_status', 'previous_record_status', 'approval_status',
    'approval_level', 'approval_setting_id', 'posted_at', 'posted_by',
])]
class StockWaste extends Model
{
    /** @use HasFactory<StockWasteFactory> */
    use HasFactory, HasRecordLifecycle, HasUuidPrimaryKey, SoftDeletes;

    protected function casts(): array
    {
        return [
            'waste_date' => 'date',
            'source' => StockWasteSource::class,
            'quantity' => 'decimal:3',
            'waste_type' => StockWasteType::class,
            'approval_status' => StockWasteApprovalStatus::class,
            'waste_status' => StockWasteStatus::class,
            'approval_level' => 'integer',
            'posted_at' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function picUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pic_user_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function stockMutation(): MorphOne
    {
        return $this->morphOne(StockMutation::class, 'reference')
            ->where('source', StockMutationSource::StockWaste);
    }

    protected static function booted(): void
    {
        static::creating(function (self $waste): void {
            $waste->waste_number ??= self::nextWasteNumber();
            $waste->source ??= StockWasteSource::Item;
            $waste->waste_status = $waste->record_status === RecordStatus::Draft
                ? StockWasteStatus::Draft
                : ($waste->waste_status ?? StockWasteStatus::Saved);
            $waste->approval_status = $waste->record_status === RecordStatus::Draft
                ? StockWasteApprovalStatus::Pending
                : StockWasteApprovalStatus::Approved;
            $waste->approval_level = 0;
            $waste->approval_setting_id = null;

            app(SystemFreezeGuard::class)->assertNotFrozen();
        });

        static::updating(function (self $waste): void {
            if ($waste->record_status === RecordStatus::Draft) {
                $waste->waste_status = StockWasteStatus::Draft;
            } elseif ($waste->getRawOriginal('waste_status') === StockWasteStatus::Draft->value) {
                $waste->waste_status = StockWasteStatus::Saved;
            }
        });

    }

    private static function nextWasteNumber(): string
    {
        $date = now()->toDateString();
        $number = DB::transaction(function () use ($date): int {
            DB::table('stock_waste_number_sequences')->insertOrIgnore([
                'sequence_date' => $date,
                'last_number' => 0,
            ]);

            $number = (int) DB::table('stock_waste_number_sequences')
                ->where('sequence_date', $date)
                ->lockForUpdate()
                ->value('last_number') + 1;

            DB::table('stock_waste_number_sequences')
                ->where('sequence_date', $date)
                ->update(['last_number' => $number]);

            return $number;
        });

        return sprintf('WST-%s-%03d', str_replace('-', '', $date), $number);
    }

    public function isPostable(): bool
    {
        return ! $this->trashed()
            && $this->record_status === RecordStatus::Saved
            && $this->waste_status === StockWasteStatus::Saved
            && $this->posted_at === null;
    }

    public function isCancellable(): bool
    {
        return ! $this->trashed()
            && $this->record_status === RecordStatus::Saved
            && $this->waste_status === StockWasteStatus::Saved
            && $this->posted_at !== null;
    }

    public function post(?string $actorId = null): void
    {
        DB::transaction(function () use ($actorId): void {
            $waste = self::query()->lockForUpdate()->findOrFail($this->getKey());

            if ($waste->posted_at !== null) {
                return;
            }

            if (! $waste->isPostable()) {
                throw new \LogicException('Only approved stock waste records can reduce stock.');
            }

            app(SystemFreezeGuard::class)->assertNotFrozen();

            StockMutation::moveAvailableToOut(
                variant: $waste->productVariant()->firstOrFail(),
                quantity: $waste->quantity,
                referenceType: self::class,
                referenceId: (string) $waste->getKey(),
                recordedBy: $actorId ?? auth()->id(),
                warehouse: $waste->warehouse()->firstOrFail(),
            );

            $waste->forceFill([
                'approval_status' => StockWasteApprovalStatus::Posted,
                'posted_at' => now(),
                'posted_by' => $actorId ?? auth()->id(),
                'updated_by' => $actorId ?? auth()->id(),
            ])->saveQuietly();
        });
    }

    public function cancel(?string $actorId = null): void
    {
        DB::transaction(function () use ($actorId): void {
            $waste = self::query()->lockForUpdate()->findOrFail($this->getKey());

            if ($waste->waste_status === StockWasteStatus::Cancelled) {
                return;
            }

            if (! $waste->isCancellable()) {
                throw new \LogicException('Only saved stock waste records can be cancelled.');
            }

            app(SystemFreezeGuard::class)->assertNotFrozen();

            StockMutation::moveOutToAvailable(
                variant: $waste->productVariant()->firstOrFail(),
                quantity: $waste->quantity,
                referenceType: self::class,
                referenceId: (string) $waste->getKey(),
                recordedBy: $actorId ?? auth()->id(),
                warehouse: $waste->warehouse()->firstOrFail(),
            );

            $waste->forceFill([
                'waste_status' => StockWasteStatus::Cancelled,
                'approval_status' => StockWasteApprovalStatus::Cancelled,
                'updated_by' => $actorId ?? auth()->id(),
            ])->saveQuietly();
        });
    }
}
