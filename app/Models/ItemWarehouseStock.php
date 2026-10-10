<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\ItemWarehouseStockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['item_id', 'warehouse_id', 'stock_available', 'stock_reserved', 'stock_in_transit', 'stock_out'])]
class ItemWarehouseStock extends Model
{
    /** @use HasFactory<ItemWarehouseStockFactory> */
    use HasFactory, HasUuidPrimaryKey;

    protected function casts(): array
    {
        return [
            'stock_available' => 'decimal:3',
            'stock_reserved' => 'decimal:3',
            'stock_in_transit' => 'decimal:3',
            'stock_out' => 'decimal:3',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    protected function stockStatus(): Attribute
    {
        return Attribute::get(fn (): string => (float) $this->stock_available <= 0
            ? 'Out of Stock'
            : ((float) $this->stock_available <= (float) ($this->item?->min_stock ?? 0)
                ? 'Low Stock'
                : 'Available'));
    }
}
