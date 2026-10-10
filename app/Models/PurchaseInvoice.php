<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Models\Concerns\HasRecordLifecycle;
use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\PurchaseInvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable([
    'invoice_number', 'purchase_order_id', 'vendor_id', 'invoice_date', 'subtotal', 'tax_amount', 'discount_amount',
    'grand_total', 'vendor_invoice_file_path', 'notes', 'record_status', 'updated_by',
])]
class PurchaseInvoice extends Model
{
    /** @use HasFactory<PurchaseInvoiceFactory> */
    use HasFactory, HasRecordLifecycle, HasUuidPrimaryKey, SoftDeletes;

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class)->withTrashed();
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceItem::class);
    }

    /** @return list<string> */
    protected function lifecycleUniqueAttributes(): array
    {
        return ['invoice_number'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $invoice): void {
            if ($invoice->record_status === RecordStatus::Draft) {
                $invoice->invoice_number = null;
            } else {
                $invoice->invoice_number ??= self::nextInvoiceNumber();
            }
        });

        static::updating(function (self $invoice): void {
            if ($invoice->getRawOriginal('record_status') === RecordStatus::Draft->value
                && $invoice->record_status === RecordStatus::Saved) {
                $invoice->invoice_number ??= self::nextInvoiceNumber();
            }
        });
    }

    private static function nextInvoiceNumber(): string
    {
        return 'PINV-'.now()->format('Ymd').'-'.strtoupper(Str::random(8));
    }
}
