<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TCS revision (20 Aug 2026): tabel Stock Adjustments perlu 4 kolom
 * audit standar (Created At/By, Updated At/By), bukan cuma 1 kolom
 * "By". created_at/updated_at sudah ada dari awal; adjusted_by
 * sekarang juga dipakai sebagai "Created By" (siapa yang bikin
 * penyesuaian itu — Adjustment memang cuma dibuat sekali, tidak
 * pernah diedit). updated_by ditambahkan buat konsistensi kalau
 * suatu saat fitur edit dibuka.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->foreignUuid('updated_by')->nullable()->after('adjusted_by')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->dropForeign(['updated_by']);
            $table->dropColumn('updated_by');
        });
    }
};
