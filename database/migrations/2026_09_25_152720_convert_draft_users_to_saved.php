<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('record_status', 'draft')
            ->update(['record_status' => 'saved']);

        DB::table('users')
            ->where('previous_record_status', 'draft')
            ->update(['previous_record_status' => 'saved']);
    }

    public function down(): void
    {
        // The original draft records cannot be identified after conversion.
    }
};
