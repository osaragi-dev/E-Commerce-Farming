<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\DirectOrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['direct_order_id', 'item_id', 'quantity', 'unit_price', 'total_price', 'notes', 'sort_order'])]
class DirectOrderItem extends Model
{
    /** @use HasFactory<DirectOrderItemFactory> */
    use HasFactory, HasUuidPrimaryKey;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'total_price' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function directOrder(): BelongsTo
    {
        return $this->belongsTo(DirectOrder::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
