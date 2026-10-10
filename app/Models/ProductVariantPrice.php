<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\ProductVariantPriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

#[Fillable(['product_variant_id', 'price', 'effective_from', 'effective_until'])]
class ProductVariantPrice extends Model
{
    /** @use HasFactory<ProductVariantPriceFactory> */
    use HasFactory, HasUuidPrimaryKey;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $price): void {
            if ($price->effective_until?->lt($price->effective_from)) {
                throw ValidationException::withMessages([
                    'effective_until' => 'The end date must be on or after the start date.',
                ]);
            }

            $overlaps = self::query()
                ->where('product_variant_id', $price->product_variant_id)
                ->when($price->exists, fn ($query) => $query->whereKeyNot($price->getKey()))
                ->where('effective_from', '<=', $price->effective_until ?? '9999-12-31')
                ->where(function ($query) use ($price): void {
                    $query
                        ->whereNull('effective_until')
                        ->orWhere('effective_until', '>=', $price->effective_from);
                })
                ->exists();

            if ($overlaps) {
                throw ValidationException::withMessages([
                    'effective_from' => 'This price period overlaps another period for the same variant.',
                ]);
            }
        });
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function isActiveOn(?Carbon $date = null): bool
    {
        $date ??= today();

        return $this->effective_from?->lte($date)
            && ($this->effective_until === null || $this->effective_until->gte($date));
    }
}
