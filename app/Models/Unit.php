<?php

namespace App\Models;

use App\Models\Concerns\HasRecordLifecycle;
use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable(['code', 'name', 'is_active'])]
class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use HasFactory, HasRecordLifecycle, HasUuidPrimaryKey, SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $unit): void {
            if (blank($unit->code)) {
                $unit->code = Str::slug((string) $unit->name);
            }
        });
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /** @param array<string, mixed> $data */
    public function getLifecycleUniqueAttributeValue(string $attribute, array $data): mixed
    {
        if ($attribute === 'code' && blank($data[$attribute] ?? null)) {
            return Str::slug((string) ($data['name'] ?? ''));
        }

        return $data[$attribute] ?? null;
    }

    /** @return list<string> */
    protected function lifecycleUniqueAttributes(): array
    {
        return ['code'];
    }
}
