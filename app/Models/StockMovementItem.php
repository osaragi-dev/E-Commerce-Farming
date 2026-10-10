<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\StockMovementItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'stock_movement_id',
    'product_variant_id',
    'quantity',
    'quantity_shipped',
    'quantity_received',
    'unit',
    'notes',
])]
class StockMovementItem extends Model
{
    /** @use HasFactory<StockMovementItemFactory> */
    use HasFactory, HasUuidPrimaryKey;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'quantity_shipped' => 'decimal:3',
            'quantity_received' => 'decimal:3',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $item): void {
            $unit = ProductVariant::query()
                ->with('unit')
                ->findOrFail($item->product_variant_id)
                ->unit;

            $item->unit = $unit?->code ?? $unit?->name;
        });
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
