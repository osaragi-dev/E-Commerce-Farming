<?php

use App\Enums\ApprovalModule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([ApprovalModule::PurchaseOrder, ApprovalModule::PurchaseOrderClose] as $module) {
            if (DB::table('approval_settings')->where('module_code', $module->value)->exists()) {
                continue;
            }

            DB::table('approval_settings')->insert([
                'id' => (string) Str::uuid7(),
                'module_code' => $module->value,
                'is_active' => true,
                'record_status' => 'saved',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('approval_settings')
            ->whereIn('module_code', [ApprovalModule::PurchaseOrder->value, ApprovalModule::PurchaseOrderClose->value])
            ->delete();
    }
};
