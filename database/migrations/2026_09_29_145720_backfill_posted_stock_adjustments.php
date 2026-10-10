<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('stock_adjustments')
            ->where('approval_status', 'approved')
            ->whereNotNull('applied_at')
            ->update(['approval_status' => 'posted']);
    }

    public function down(): void
    {
        DB::table('stock_adjustments')
            ->where('approval_status', 'posted')
            ->whereNotNull('applied_at')
            ->update(['approval_status' => 'approved']);
    }
};
