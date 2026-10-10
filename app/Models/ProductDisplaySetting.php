<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['new_badge_days', 'updated_by'])]
class ProductDisplaySetting extends Model
{
    use HasUuidPrimaryKey;

    protected function casts(): array
    {
        return [
            'new_badge_days' => 'integer',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public static function current(): self
    {
        return static::query()->first() ?? static::query()->create([
            'new_badge_days' => 14,
        ]);
    }
}
