<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use App\Notifications\OrderStatusChangedForCustomer;
use Database\Factories\OrderStatusLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'from_status', 'to_status', 'changed_by_user_id', 'note', 'created_at'])]
class OrderStatusLog extends Model
{
    /** @use HasFactory<OrderStatusLogFactory> */
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::created(function (self $log): void {
            if ($log->from_status === null || $log->to_status === null || $log->from_status === $log->to_status) {
                return;
            }

            OrderStatusChangedForCustomer::send($log->order, $log->from_status, $log->to_status);
        });
    }

    protected function casts(): array
    {
        return [
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
            'created_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
