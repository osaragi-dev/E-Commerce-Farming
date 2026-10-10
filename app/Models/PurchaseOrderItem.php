<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\PurchaseOrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'purchase_order_id', 'purchase_request_item_id', 'item_id', 'qty_requested', 'qty_order', 'unit_code',
    'unit_price', 'total_price', 'specification', 'notes', 'sort_order',
])]
class PurchaseOrderItem extends Model
{
    /** @use HasFactory<PurchaseOrderItemFactory> */
    use HasFactory, HasUuidPrimaryKey;

    protected function casts(): array
    {
        return [
            'qty_requested' => 'decimal:3',
            'qty_order' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'total_price' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function purchaseRequestItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequestItem::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }

    public function goodsReceiptItems(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class)->whereHas('goodsReceipt', fn ($query) => $query
            ->where('record_status', 'saved')
            ->where('approval_status', 'approved'));
    }

    protected function acceptedQuantity(): Attribute
    {
        return Attribute::get(fn (): string => number_format((float) $this->goodsReceiptItems->sum('accepted_quantity'), 3, '.', ''));
    }
}
