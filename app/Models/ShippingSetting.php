<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['flat_shipping_cost', 'updated_by'])]
class ShippingSetting extends Model
{
    use HasUuidPrimaryKey;

    protected function casts(): array
    {
        return [
            'flat_shipping_cost' => 'decimal:2',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public static function current(): self
    {
        return static::query()->first() ?? static::query()->create([
            'flat_shipping_cost' => 15000,
        ]);
    }
}
