<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TCS revision (konfirmasi Nabila, 20 Aug 2026): tiap Product Variant
 * bisa punya Unit sendiri-sendiri (misal Grade A pakai Box, Grade B
 * pakai Pack dalam 1 Product yang sama) — bukan 1 Unit untuk seluruh
 * Product lagi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->foreignUuid('unit_id')->nullable()->after('product_id')->constrained('units');
        });

        // Backfill: tiap variant yang sudah ada, warisi unit_id dari
        // product induknya (baru setelah ini admin bisa ubah per-variant).
        DB::statement('
            UPDATE product_variants
            SET unit_id = products.unit_id
            FROM products
            WHERE product_variants.product_id = products.id
        ');

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['unit_id']);
            $table->dropColumn('unit_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignUuid('unit_id')->nullable()->after('category_id')->constrained('units');
        });

        DB::statement('
            UPDATE products
            SET unit_id = (
                SELECT unit_id FROM product_variants
                WHERE product_variants.product_id = products.id
                LIMIT 1
            )
        ');

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropForeign(['unit_id']);
            $table->dropColumn('unit_id');
        });
    }
};
