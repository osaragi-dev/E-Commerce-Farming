<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TCS revision (22 Aug 2026): "Initial Stock" harus jadi kolom TERPISAH
 * dari "Available" (stock_available) — cuma diisi manual SEKALI waktu
 * Variant dibuat, dan tidak pernah berubah lagi setelahnya (beda dari
 * stock_available yang terus berubah tiap ada Stock Adjustment/Order).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedInteger('initial_stock')->default(0)->after('unit_id');
        });

        // Data lama: initial_stock diisi sama dengan stock_available
        // saat ini, sebagai baseline (tidak ada cara tau angka aslinya
        // dulu waktu variant itu pertama dibuat).
        DB::statement('UPDATE product_variants SET initial_stock = stock_available');
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('initial_stock');
        });
    }
};
