<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Clear timestamps written on insert before Role audit tracking existed.
     */
    public function up(): void
    {
        DB::table('roles')
            ->whereNull('updated_by')
            ->whereNotNull('updated_at')
            ->update(['updated_at' => null]);
    }

    /**
     * Historical timestamps cannot be restored without inventing audit data.
     */
    public function down(): void {}
};
