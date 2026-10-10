<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\CustomerStatusLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['customer_id', 'event', 'from_status', 'to_status', 'changed_by_user_id', 'created_at'])]
class CustomerStatusLog extends Model
{
    /** @use HasFactory<CustomerStatusLogFactory> */
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'from_status' => RecordStatus::class,
            'to_status' => RecordStatus::class,
            'created_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
