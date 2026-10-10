<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['generation_token', 'invoice_number', 'sequence_year', 'sequence', 'customer_id', 'customer_name', 'invoice_date', 'due_date', 'period_start', 'period_end', 'total', 'document_payload', 'created_by', 'deleted_by'])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, HasUuidPrimaryKey, SoftDeletes;

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'period_start' => 'date',
            'period_end' => 'date',
            'total' => 'decimal:2',
            'document_payload' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function paymentStatusLabel(): string
    {
        $payments = $this->payments;
        if ($payments->isEmpty() || $payments->contains(fn (Payment $payment): bool => ! in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::Unpaid], true))) {
            return 'Needs Review';
        }
        if ($payments->every(fn (Payment $payment): bool => $payment->status === PaymentStatus::Paid)) {
            return 'Paid';
        }

        return $payments->contains('status', PaymentStatus::Paid) ? 'Partially Paid' : 'Unpaid';
    }
}
