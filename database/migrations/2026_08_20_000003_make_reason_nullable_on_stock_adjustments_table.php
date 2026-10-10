<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TCS revision (20 Aug 2026): field Reason di form sudah dibikin
 * tidak wajib (not required), tapi kolomnya di database masih
 * NOT NULL — bikin error waktu Reason dikosongkan. Ini pasangan
 * perbaikannya di sisi database.
 *
 * Pakai DB::statement() langsung (bukan ->change()) karena project
 * ini belum pasang package doctrine/dbal yang biasanya dibutuhkan
 * buat modifikasi kolom lewat Schema Builder.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE stock_adjustments ALTER COLUMN reason DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_adjustments ALTER COLUMN reason SET NOT NULL');
    }
};
