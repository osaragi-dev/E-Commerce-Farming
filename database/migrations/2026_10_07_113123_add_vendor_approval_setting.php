<?php

use App\Enums\ApprovalModule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::table('approval_settings')->where('module_code', ApprovalModule::Vendor->value)->exists()) {
            return;
        }

        DB::table('approval_settings')->insert([
            'id' => (string) Str::uuid7(),
            'module_code' => ApprovalModule::Vendor->value,
            'is_active' => true,
            'record_status' => 'saved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('approval_settings')->where('module_code', ApprovalModule::Vendor->value)->delete();
    }
};
