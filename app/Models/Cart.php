<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\Quantity;
use Database\Factories\CartFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Setiap customer punya SATU cart aktif yang selalu sama — lihat
 * Cart::forCustomer(). Bukan dibuat baru tiap kali customer belanja.
 */
#[Fillable(['customer_id'])]
class Cart extends Model
{
    /** @use HasFactory<CartFactory> */
    use HasFactory, HasUuidPrimaryKey;

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * Ambil cart aktif milik user; bikin baru kalau belum ada. Ini
     * satu-satunya cara resmi buat dapetin cart seorang customer —
     * jangan query Cart::where('customer_id', ...) manual di tempat lain.
     */
    public static function forCustomer(Customer $customer): self
    {
        return self::query()->firstOrCreate(['customer_id' => $customer->id]);
    }

    /**
     * Tambah variant ke cart. Kalau variant yang sama sudah ada di
     * cart ini, quantity-nya DIGABUNG (ditambah), bukan bikin baris
     * baru — sesuai keputusan produk waktu diskusi requirement Cart.
     */
    public function addItem(ProductVariant $variant, string|int|float $quantity): CartItem
    {
        $item = $this->items()->firstOrNew(['product_variant_id' => $variant->id]);
        $quantity = Quantity::validated($quantity);
        $item->quantity = Quantity::add($item->exists ? $item->quantity : 0, $quantity);
        $item->save();

        return $item;
    }
}
