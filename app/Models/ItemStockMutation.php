<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\ItemStockMutationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['item_id', 'warehouse_id', 'source', 'reference_type', 'reference_id', 'stock_before', 'stock_after', 'quantity_change', 'recorded_by'])]
class ItemStockMutation extends Model
{
    /** @use HasFactory<ItemStockMutationFactory> */
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'stock_before' => 'decimal:3',
            'stock_after' => 'decimal:3',
            'quantity_change' => 'decimal:3',
            'created_at' => 'datetime',
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

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
