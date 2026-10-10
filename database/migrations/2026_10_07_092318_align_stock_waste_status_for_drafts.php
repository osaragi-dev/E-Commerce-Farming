<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('stock_wastes')
            ->where('record_status', 'draft')
            ->where('waste_status', 'saved')
            ->update(['waste_status' => 'draft']);
    }

    public function down(): void
    {
        DB::table('stock_wastes')
            ->where('record_status', 'draft')
            ->where('waste_status', 'draft')
            ->update(['waste_status' => 'saved']);
    }
};
