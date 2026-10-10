<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Models\Concerns\HasRecordLifecycle;
use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\WarehouseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

#[Fillable(['code', 'name', 'address', 'description', 'is_active', 'is_pos'])]
class Warehouse extends Model
{
    /** @use HasFactory<WarehouseFactory> */
    use HasFactory, HasRecordLifecycle, HasUuidPrimaryKey, SoftDeletes;

    protected $attributes = [
        'is_pos' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_pos' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $warehouse): void {
            $recordStatus = $warehouse->record_status ?? RecordStatus::Saved;

            if (! $warehouse->is_pos
                || ! $warehouse->is_active
                || ($recordStatus !== RecordStatus::Saved && $recordStatus !== RecordStatus::Saved->value)) {
                return;
            }

            $query = self::query()
                ->saved()
                ->where('is_active', true)
                ->where('is_pos', true);

            if ($warehouse->exists) {
                $query->whereKeyNot($warehouse->getKey());
            }

            if ($query->exists()) {
                throw ValidationException::withMessages([
                    'is_pos' => 'Only one active saved warehouse can be marked as POS.',
                ]);
            }
        });
    }

    /** @return list<string> */
    protected function lifecycleUniqueAttributes(): array
    {
        return ['code'];
    }

    /** @return HasMany<WarehouseStock, $this> */
    public function stocks(): HasMany
    {
        return $this->hasMany(WarehouseStock::class);
    }

    /** @return HasMany<ItemWarehouseStock, $this> */
    public function itemStocks(): HasMany
    {
        return $this->hasMany(ItemWarehouseStock::class);
    }

    /** @return HasMany<StockMovement, $this> */
    public function outgoingMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'from_warehouse_id');
    }

    /** @return HasMany<StockMovement, $this> */
    public function incomingMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'to_warehouse_id');
    }

    public static function defaultFulfillment(): ?self
    {
        return self::fulfillmentCandidates()->first();
    }

    public static function fulfillmentCandidates(): Builder
    {
        return self::query()
            ->saved()
            ->where('is_active', true)
            ->where('is_pos', true)
            ->orderBy('code');
    }
}
