<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\VariantPricing;
use Database\Factories\CartItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sengaja TIDAK menyimpan harga — subtotal() selalu ambil live dari
 * productVariant.effective_price. Kalau admin ubah harga produk, isi Cart yang
 * belum di-checkout ikut menyesuaikan (beda dari OrderItem nanti yang
 * snapshot harga permanen).
 */
#[Fillable(['cart_id', 'product_variant_id', 'quantity'])]
class CartItem extends Model
{
    /** @use HasFactory<CartItemFactory> */
    use HasFactory, HasUuidPrimaryKey;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    protected function subtotal(): Attribute
    {
        return Attribute::make(
            get: fn (): string => VariantPricing::subtotal($this->productVariant?->effective_price ?? '0', $this->quantity, $this->productVariant?->price_quantity ?? '1'),
        );
    }
}
