<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'product_variant_id', 'path', 'is_primary', 'sort_order'])]
class ProductImage extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $attributes = [
        'created_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'sort_order' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $image): void {
            $image->created_at ??= now();
        });

        // Bab 12: cuma boleh 1 foto utama per produk. Kalau foto ini
        // ditandai primary, batalkan status primary di foto lain milik
        // produk yang sama.
        static::saved(function (self $image): void {
            if (! $image->is_primary) {
                return;
            }

            static::query()
                ->where('product_id', $image->product_id)
                ->whereKeyNot($image->id)
                ->update(['is_primary' => false]);
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
