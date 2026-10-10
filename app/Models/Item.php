<?php

namespace App\Models;

use App\Enums\ItemType;
use App\Enums\RecordStatus;
use App\Models\Concerns\HasRecordLifecycle;
use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['code', 'sku', 'name', 'item_type', 'unit_id', 'initial_warehouse_id', 'initial_stock', 'min_stock', 'avg_purchase_price', 'purchasable', 'photo_path', 'shelf_life_days', 'notes', 'is_active'])]
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory, HasRecordLifecycle, HasUuidPrimaryKey, SoftDeletes;

    protected function casts(): array
    {
        return [
            'item_type' => ItemType::class,
            'initial_stock' => 'decimal:3',
            'min_stock' => 'decimal:3',
            'avg_purchase_price' => 'decimal:2',
            'purchasable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function initialWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'initial_warehouse_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'initial_warehouse_id');
    }

    public function warehouseStocks(): HasMany
    {
        return $this->hasMany(ItemWarehouseStock::class);
    }

    public function stockMutations(): HasMany
    {
        return $this->hasMany(ItemStockMutation::class);
    }

    protected static function booted(): void
    {
        static::saved(function (self $item): void {
            if ((! $item->wasRecentlyCreated && ! $item->wasChanged('record_status'))
                || $item->record_status !== RecordStatus::Saved
                || blank($item->initial_warehouse_id)) {
                return;
            }

            ItemWarehouseStock::query()->firstOrCreate(
                [
                    'item_id' => $item->id,
                    'warehouse_id' => $item->initial_warehouse_id,
                ],
                ['stock_available' => $item->initial_stock],
            );
        });
    }

    /** @return list<string> */
    protected function lifecycleUniqueAttributes(): array
    {
        return ['code', 'sku'];
    }
}
