<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Models\Concerns\HasRecordLifecycle;
use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

#[Fillable(['category_id', 'name', 'slug', 'description', 'is_active', 'is_featured', 'activated_at'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasRecordLifecycle, HasUuidPrimaryKey, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (self $product): void {
            if ($product->getAttribute('is_active') !== false && ! $product->activated_at) {
                $product->activated_at = now();
            }
        });

        static::updating(function (self $product): void {
            if ($product->isDirty('is_active') && $product->is_active) {
                $product->activated_at = now();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'activated_at' => 'datetime',
        ];
    }

    public function scopeAvailableSince(Builder $query, Carbon $since): Builder
    {
        return $query->where(function (Builder $query) use ($since): void {
            $query
                ->where('created_at', '>=', $since)
                ->orWhere('activated_at', '>=', $since);
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    /** Foto umum produk saja — TIDAK termasuk foto yang sudah ditempelin ke Variant tertentu. */
    public function generalImages(): HasMany
    {
        return $this->hasMany(ProductImage::class)->whereNull('product_variant_id')->orderBy('sort_order');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Total quantity terjual dari semua Order yang TIDAK dibatalkan.
     * Kalau sudah di-eager-load lewat withSum('orderItems as total_sold', ...)
     * di query listing (Catalog/Home), ini otomatis pakai hasil itu — nggak
     * query ulang. Kalau belum di-eager-load, baru dia query langsung
     * (buat kasus dipanggil satuan, misal di test).
     */
    protected function totalSold(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->attributes['total_sold'] ?? $this->orderItems()
                ->whereHas('order', fn ($query) => $query->where('status', '!=', OrderStatus::Cancelled))
                ->sum('quantity'),
        );
    }

    /**
     * True kalau produk ini dibuat dalam N hari terakhir (N diatur admin lewat
     * Settings > Product Display). Dihitung on-the-fly, bukan disimpan sebagai
     * kolom — jadi badge "New" otomatis hilang sendiri tanpa perlu scheduled job.
     */
    protected function isNew(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! $this->created_at) {
                    return false;
                }

                $days = ProductDisplaySetting::current()->new_badge_days;

                $newestAt = collect([$this->created_at, $this->activated_at])
                    ->filter()
                    ->sortDesc()
                    ->first();

                return $newestAt?->greaterThanOrEqualTo(now()->subDays($days)->startOfDay()) ?? false;
            },
        );
    }

    /** @return list<string> */
    protected function lifecycleUniqueAttributes(): array
    {
        return ['slug'];
    }
}
