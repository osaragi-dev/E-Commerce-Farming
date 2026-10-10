<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Harga TIDAK disimpan di sini — selalu diambil live dari
 * product_variants.price saat ditampilkan/checkout. Beda dengan
 * order_items nanti yang menyimpan snapshot harga (cart belum jadi
 * transaksi resmi, order sudah).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignUuid('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            // Variant yang sama tidak boleh punya 2 baris terpisah dalam
            // 1 cart — waktu Add to Cart dipanggil ulang, quantity-nya
            // digabung (lihat Cart::addItem()), bukan bikin baris baru.
            $table->unique(['cart_id', 'product_variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
