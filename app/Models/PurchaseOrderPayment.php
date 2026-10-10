<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\PurchaseOrderPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'purchase_order_id', 'payment_basis', 'accepted_amount', 'tax_amount', 'discount_amount', 'total_payable',
    'payment_amount', 'remaining_amount', 'payment_date', 'notes', 'created_by',
])]
class PurchaseOrderPayment extends Model
{
    /** @use HasFactory<PurchaseOrderPaymentFactory> */
    use HasFactory, HasUuidPrimaryKey;

    protected function casts(): array
    {
        return [
            'accepted_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_payable' => 'decimal:2',
            'payment_amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
            'payment_date' => 'date',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
