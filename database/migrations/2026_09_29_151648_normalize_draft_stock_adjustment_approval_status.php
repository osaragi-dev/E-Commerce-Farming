<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('stock_adjustments')
            ->where('approval_status', 'draft')
            ->update(['approval_status' => 'pending']);
    }

    public function down(): void
    {
        DB::table('stock_adjustments')
            ->where('record_status', 'draft')
            ->where('approval_status', 'pending')
            ->update(['approval_status' => 'draft']);
    }
};
